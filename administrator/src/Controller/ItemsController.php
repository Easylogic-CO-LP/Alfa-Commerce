<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\User\User;

/**
 * Items list controller class.
 *
 * @since  1.0.0
 */
class ItemsController extends AdminController
{
    /**
     * The state each of the list's state tasks sets, as the platform maps them.
     *
     * @var array<string, int>
     *
     * @since  1.14.0
     */
    private const STATE_TASKS = ['publish' => 1, 'unpublish' => 0, 'archive' => 2, 'trash' => -2, 'report' => -3];

    /**
     * The list's state tasks: publish, unpublish, archive or trash the selected
     * products.
     *
     * The platform's list task, except that a request the item model refused
     * whole reports no count. The model then leaves nothing in the selection and
     * has already shown its reason in the message queue (an archived product that
     * must be restored first, or a product whose stock keeps it out of the
     * archive), so a count of products that failed to publish would only repeat
     * that reason without its words. A request that leaves products in the
     * selection is reported as the platform reports it.
     *
     *
     * @since  1.14.0
     */
    public function publish(): void
    {
        $this->checkToken();

        $cid = array_filter((array) $this->input->get(name: 'cid', default: [], filter: 'int'));
        $value = self::STATE_TASKS[$this->getTask()] ?? 0;

        $this->setRedirect(url: $this->getRedirectUrlToList());

        if ($cid === []) {
            $this->getLogger()->warning(message: Text::_($this->text_prefix . '_NO_ITEM_SELECTED'), context: ['category' => 'jerror']);

            return;
        }

        $model = $this->getModel();

        try {
            $model->publish(pks: $cid, value: $value);
        } catch (Exception $e) {
            $this->setMessage(text: $e->getMessage(), type: 'error');

            return;
        }

        $count = \count($cid);

        // Refused whole: nothing is left in the selection, and the reason is shown.
        if ($count === 0) {
            return;
        }

        if ($value === 1 && $model->getErrors()) {
            $this->app->enqueueMessage(
                msg:  Text::plural($this->text_prefix . '_N_ITEMS_FAILED_PUBLISHING', $count),
                type: CMSWebApplicationInterface::MSG_ERROR,
            );

            return;
        }

        $this->setMessage(text: Text::plural(
            $this->text_prefix . match ($value) {
                1 => '_N_ITEMS_PUBLISHED',
                0 => '_N_ITEMS_UNPUBLISHED',
                2 => '_N_ITEMS_ARCHIVED',
                default => '_N_ITEMS_TRASHED',
            },
            $count,
        ));
    }

    /**
     * Restore the selected archived products to the active catalogue.
     *
     * An archived product is kept for the history of earlier orders, and
     * publishing never brings it back: this is the one way back. The item model
     * has each product judged by the inventory service and, when it passes,
     * written unpublished with one recorded operation; it reports every product's
     * outcome in the message queue.
     *
     * An explicit operator action: a POST with the form token, by a Super User.
     *
     *
     * @throws NotAllowed Without a POST, the form token or Super User rights.
     *
     * @since  1.14.0
     */
    public function restoreArchived(): void
    {
        if (strtoupper((string) $this->input->getMethod()) !== 'POST') {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 405);
        }

        if (!$this->checkToken(method: 'post', redirect: false)) {
            throw new NotAllowed(message: Text::_('JINVALID_TOKEN_NOTICE'), code: 403);
        }

        $user = $this->app->getIdentity();

        if (!$user instanceof User || !$user->authorise(action: 'core.admin', assetname: 'com_alfa')) {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 403);
        }

        $this->setRedirect(url: $this->getRedirectUrlToList());

        $pks = array_values(array_filter(
            array_map('intval', (array) $this->input->get(name: 'cid', default: [], filter: 'array')),
            static fn (int $id): bool => $id > 0,
        ));

        if ($pks === []) {
            $this->setMessage(text: Text::_('JGLOBAL_NO_ITEM_SELECTED'), type: 'warning');

            return;
        }

        $this->getModel()->restoreArchived(pks: $pks);
    }

    /**
     * Proxy for getModel.
     *
     * @param string $name Optional. Model name
     * @param string $prefix Optional. Class prefix
     * @param array $config Optional. Configuration array for model
     *
     * @return object The Model
     *
     * @since  1.0.0
     */
    public function getModel($name = 'Item', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }
}
