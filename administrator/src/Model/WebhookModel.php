<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundAddressGuard;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundAddressRefused;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Alfa\Component\Alfa\Administrator\Service\Outbound\WebhookSignature;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * A webhook's edit model: the address (https, a public host, checked when it is saved), the topics, whether it
 * delivers, and its secret. The secret is made by the shop (32 random bytes as hex) when the webhook is created and
 * whenever staff ask for a new one; it is shown once, in the message after that save, and never again: no read of
 * this model, no list and no REST route returns it. The form is handed a mask that says only whether one is set.
 *
 * @since  2.3.0
 */
class WebhookModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'webhook';

    /**
     * @var string Message prefix for translations.
     *
     * @since  2.3.0
     */
    protected $text_prefix = 'COM_ALFA_WEBHOOK';

    /**
     * The columns the form never writes: what core keeps for the delivery, and the secret, which only the shop makes.
     *
     * @var string[]
     *
     * @since  2.3.0
     */
    private const PROTECTED_COLUMNS = ['kind', 'plugin', 'secret', 'last_attempt_at', 'last_ok', 'last_result', 'created', 'created_by', 'modified', 'modified_by'];

    /**
     * The fields of the form that are not columns: the ask for a new secret, and the mask that stands for the secret.
     *
     * @var string[]
     *
     * @since  2.3.0
     */
    private const FORM_ONLY = ['new_secret', 'secret_mask'];

    /**
     * Build the edit form.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load the form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false
     *
     * @since  2.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.webhook', 'webhook', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    /**
     * Load the data for the form (edited record or saved user state), never the secret: a kept post is handed the
     * record's mask, since a post carries none.
     *
     * @return mixed
     *
     * @since  2.3.0
     */
    protected function loadFormData()
    {
        $data = KeptPost::forRecord(stateKey: 'com_alfa.edit.webhook.data', recordId: (int) $this->getState($this->getName() . '.id'));

        if (empty($data)) {
            $data = $this->getItem();
        }

        if (\is_array($data)) {
            unset($data['secret']);
            $item = $this->getItem();
            $data['secret_mask'] = \is_object($item) ? (string) $item->secret_mask : '';
        }

        return $data;
    }

    /**
     * One webhook, its topics as a list, without its secret (a mask stands for it when one is set); a plugin
     * consumer's row is not a webhook.
     *
     * @param int|null $pk The webhook.
     *
     * @return object|false
     *
     * @since  2.3.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (!\is_object($item)) {
            return $item;
        }

        if ((int) ($item->id ?? 0) > 0 && (string) ($item->kind ?? '') !== OutboundConsumers::KIND_WEBHOOK) {
            $this->setError(Text::_('COM_ALFA_WEBHOOK_ERROR_NOT_WEBHOOK'));

            return false;
        }

        $item->topics = OutboundTopics::parse(topics: (string) ($item->topics ?? ''));
        $item->has_secret = (string) ($item->secret ?? '') !== '';
        unset($item->secret);
        $item->secret_mask = $item->has_secret ? str_repeat("\u{2022}", 16) : '';
        $item->new_secret = 0;

        return $item;
    }

    /**
     * Save a webhook: the address checked (https, its host public), the topics kept in the shop's order, a secret made
     * when the webhook is new or staff ask for a new one and shown once, the consumers of the shop's changes read again.
     *
     * @param array $data The form data.
     *
     * @return bool
     *
     * @since  2.3.0
     */
    public function save($data)
    {
        $id = (int) ($data['id'] ?? 0);

        foreach (self::PROTECTED_COLUMNS as $column) {
            unset($data[$column]);
        }

        if ($id > 0) {
            $stored = OutboundConsumers::find(id: $id);

            if ($stored === null || (string) $stored->kind !== OutboundConsumers::KIND_WEBHOOK) {
                $this->setError(Text::_('COM_ALFA_WEBHOOK_ERROR_NOT_WEBHOOK'));

                return false;
            }
        }

        try {
            OutboundAddressGuard::vet(url: (string) ($data['url'] ?? ''));
        } catch (OutboundAddressRefused $e) {
            $this->setError(Text::sprintf('COM_ALFA_WEBHOOK_ERROR_URL', Text::_($e->reason)));

            return false;
        }

        $topics = OutboundTopics::parse(topics: (array) ($data['topics'] ?? []));

        if ($topics === []) {
            $this->setError(Text::_('COM_ALFA_WEBHOOK_ERROR_TOPICS'));

            return false;
        }

        $user = $this->getCurrentUser();
        $now = Factory::getDate()->toSql();
        $secret = $id === 0 || !empty($data['new_secret']) ? WebhookSignature::newSecret() : null;

        foreach (self::FORM_ONLY as $field) {
            unset($data[$field]);
        }

        $data['topics'] = implode(',', $topics);
        $data['kind'] = OutboundConsumers::KIND_WEBHOOK;
        $data['modified'] = $now;
        $data['modified_by'] = (int) $user->id;

        if ($id === 0) {
            $data['created'] = $now;
            $data['created_by'] = (int) $user->id;
        }

        if (!parent::save($data)) {
            return false;
        }

        // The secret is written after the save, so no listener of the save's events is handed it.
        if ($secret !== null) {
            $db = $this->getDatabase();
            $saved = (int) $this->getState($this->getName() . '.id');
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'UPDATE ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' SET ' . $db->quoteName('secret') . ' = :secret WHERE ' . $db->quoteName('id') . ' = ' . $saved,
                )->bind(':secret', $secret),
            )->execute();
            Factory::getApplication()->enqueueMessage(Text::sprintf('COM_ALFA_WEBHOOK_SECRET_ONCE', $secret), 'warning');
        }

        OutboundFeed::forget();

        return true;
    }

    /**
     * Delete webhooks and everything they were owed.
     *
     * @param array $pks Record ids (by reference, as the parent contract).
     *
     * @return bool
     *
     * @since  2.3.0
     */
    public function delete(&$pks)
    {
        $pks = array_map('intval', (array) $pks);
        $webhooks = array_values(array_filter($pks, static fn (int $id): bool => (string) (OutboundConsumers::find(id: $id)->kind ?? '') === OutboundConsumers::KIND_WEBHOOK));

        if ($webhooks !== $pks) {
            $this->setError(Text::_('COM_ALFA_WEBHOOK_ERROR_NOT_WEBHOOK'));

            return false;
        }

        if (!parent::delete($pks)) {
            return false;
        }

        OutboundConsumers::removeDeliveries(ids: $pks);

        return true;
    }

    /**
     * Enable or pause webhooks, the consumers of the shop's changes read again.
     *
     * @param array $pks Record ids (by reference, as the parent contract).
     * @param int $value 1 to enable, 0 to pause.
     *
     * @return bool
     *
     * @since  2.3.0
     */
    public function publish(&$pks, $value = 1)
    {
        $done = parent::publish($pks, $value);
        OutboundFeed::forget();

        return $done;
    }
}
