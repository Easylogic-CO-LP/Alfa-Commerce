<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartPersistenceException;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use Throwable;

/**
 * Currency model.
 *
 * @since  1.0.0
 */
class CurrencyModel extends AlfaAdminModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('currency').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'currency';
    /**
     * @var string The prefix to use with controller messages.
     *
     * @since  1.0.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control
     *
     * @since  1.0.0
     */
    public $typeAlias = 'com_alfa.currency';

    protected $formName = 'currency';

    /**
     * @var null Item data
     *
     * @since  1.0.0
     */
    protected $item = null;

    /**
     * Block deletion of a currency referenced by persistent records: orders (incl.
     * their payment currency), order payments / shipments, product prices and
     * places, since those rows freeze or depend on the currency and would break
     * without it. Transient (cart) and rebuildable (price index) references are not
     * guarded. In-use ids are dropped from the batch with a message; the rest delete.
     *
     * @param array $pks The ids to delete.
     *
     * @return bool
     * @since  2.0.0
     */
    public function delete(&$pks)
    {
        $pks = array_values(array_filter(array_map('intval', (array) $pks)));

        // Permission first: the guards below read the store and name protected or in-use
        // currencies, which a user who may not delete must not be shown.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            // The same refusal as AlfaAdminModel::delete(): a message the controller shows, and
            // nothing reported as deleted.
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        if (!$this->guardProtectedCurrencies($pks)) {
            return false;
        }

        $blocked = [];

        foreach ($pks as $id) {
            if ($id > 0 && $this->currencyInUse($id)) {
                $blocked[] = $id;
            }
        }

        if ($blocked) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_ALFA_CURRENCY_IN_USE_CANNOT_DELETE', implode(', ', $blocked)),
                'error',
            );

            $pks = array_values(array_diff($pks, $blocked));

            if (!$pks) {
                return false;
            }
        }

        $deleted = $pks;

        if (!parent::delete($pks)) {
            return false;
        }

        $this->repointCartsToDefaultCurrency($deleted);

        return true;
    }

    /**
     * Re-point live carts off a currency that has just been deleted.
     *
     * A cart is temporary operational state, so it deliberately does NOT block
     * the merchant in currencyInUse(), but leaving the pointer dangling is not
     * free either: every such row then resolves its currency through
     * HydratedCart::currency()'s fallback for the rest of its life, and the
     * column says something untrue. The demo carried 24 carts pointing at a
     * currency id that had never existed.
     *
     * The row is corrected to what the shopper is ALREADY being shown; the
     * repository does not stamp it, because this is maintenance and not the
     * shopper's activity.
     *
     * The default currency itself can never be in $ids: guardProtectedCurrencies()
     * refuses to delete it.
     *
     * @param int[] $ids The currency ids that were deleted.
     *
     *
     * @since  2.0.0
     */
    protected function repointCartsToDefaultCurrency(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        try {
            $default = (int) Currency::getDefault()->getId();

            if ($default <= 0 || \in_array($default, $ids, true)) {
                return;
            }

            (new CartRepository($this->getDatabase()))->repointCurrency($ids, $default);
        } catch (Throwable $e) {
            // The driver's own text, whether it arrived wrapped by the
            // repository or raw from the currency lookup.
            $detail = $e instanceof CartPersistenceException
                ? (string) $e->getPrevious()?->getMessage()
                : $e->getMessage();

            // The currency is already gone and the carts still render through
            // the fallback. A tidy-up that fails is untidy, never wrong.
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_ALFA_CURRENCY_CART_REPOINT_FAILED', $detail),
                'warning',
            );
        }
    }

    /**
     * Publish/unpublish/archive/trash. Publishing is always safe; anything that takes
     * a currency OUT of service runs the same guards as delete: the store's default
     * currency and the last published currency must stay standing.
     *
     * @param array &$pks The ids to change.
     * @param int $value The new state value.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    public function publish(&$pks, $value = 1)
    {
        if ((int) $value !== 1) {
            $pks = array_values(array_filter(array_map('intval', (array) $pks)));

            // Permission first, so a refused user is not told which currencies are protected.
            if (!AlfaHelper::can(section: $this->alfaEntity, action: 'edit.state', user: $this->getCurrentUser())) {
                Log::add(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), Log::WARNING, 'jerror');

                // Nothing changed: an empty list keeps the controller from reporting a count.
                $pks = [];

                return false;
            }

            if (!$this->guardProtectedCurrencies($pks)) {
                return false;
            }
        }

        return parent::publish($pks, $value);
    }

    /**
     * Drop protected ids from a take-out-of-service batch, with a message per rule:
     * the DEFAULT currency (component Options) is never removable, and the batch may
     * not take the LAST published currency out of service. Filters $pks in place;
     * false when nothing survives (the caller refuses the whole operation).
     *
     * @param array &$pks The candidate ids, already normalised to ints.
     *
     * @return bool Whether any ids remain to operate on.
     *
     * @since  2.0.0
     */
    protected function guardProtectedCurrencies(array &$pks): bool
    {
        if (!$pks) {
            return false;
        }

        $app = Factory::getApplication();
        $db = $this->getDatabase();

        // The param stores the ISO-4217 NUMBER; resolve it to the row id the batch
        // actually carries.
        $defaultNumber = (int) ComponentHelper::getParams('com_alfa')->get('default_currency', 0);
        $defaultId = 0;

        if ($defaultNumber > 0) {
            $defaultId = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__alfa_currencies'))
                    ->where($db->quoteName('number') . ' = ' . $defaultNumber),
                0,
                1,
            )->loadResult();
        }

        if ($defaultId > 0 && \in_array($defaultId, $pks, true)) {
            $app->enqueueMessage(Text::sprintf('COM_ALFA_CURRENCY_ERR_DEFAULT_PROTECTED', $defaultId), 'error');

            $pks = array_values(array_diff($pks, [$defaultId]));

            if (!$pks) {
                return false;
            }
        }

        $activeIds = array_map('intval', (array) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_currencies'))
                ->where($db->quoteName('state') . ' = 1'),
        )->loadColumn());

        $affectedActive = array_values(array_intersect($pks, $activeIds));

        // The batch would take every published currency out of service: keep the
        // active ones standing and let the rest of the batch proceed.
        if ($activeIds && \count($affectedActive) >= \count($activeIds)) {
            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_CURRENCY_ERR_LAST_ACTIVE', implode(', ', $affectedActive)),
                'error',
            );

            $pks = array_values(array_diff($pks, $affectedActive));
        }

        return $pks !== [];
    }

    /**
     * Whether a currency id is referenced by any persistent record that depends on
     * it (so it must not be deleted).
     *
     * @param int $id The currency id.
     *
     * @since  2.0.0
     */
    protected function currencyInUse(int $id): bool
    {
        $db = $this->getDatabase();

        $references = [
            '#__alfa_orders' => ['id_currency', 'id_payment_currency'],
            '#__alfa_order_payments' => ['id_currency'],
            '#__alfa_order_shipments' => ['id_currency'],
            '#__alfa_items_prices' => ['currency_id'],
            '#__alfa_places' => ['currency_id'],
        ];

        foreach ($references as $table => $columns) {
            $conditions = [];

            foreach ($columns as $column) {
                $conditions[] = $db->quoteName($column) . ' = ' . $id;
            }

            try {
                $found = $db->setQuery(
                    $db->getQuery(true)
                        ->select('1')
                        ->from($db->quoteName($table))
                        ->where('(' . implode(' OR ', $conditions) . ')'),
                    0,
                    1,
                )->loadResult();

                if ($found !== null) {
                    return true;
                }
            } catch (Throwable $e) {
                // Table absent on a partial install; skip this reference.
            }
        }

        return false;
    }

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return Form|bool A Form object on success, false on failure
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.currency.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  1.0.0
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            if (isset($item->params)) {
                $item->params = json_encode($item->params);
            }
        }

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since  1.0.0
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        // Currencies are not URL-routed and #__alfa_currencies has no alias
        // column; there used to be dead slug-generation code here.

        if (!str_contains($data['format_pattern'], '{number}')) {
            $app->enqueueMessage(Text::_('COM_ALFA_ERROR_FORMAT_PATTERN'), 'error');
            return false;
        }

        // decimal_place is Alfa's authoritative monetary scale for this
        // currency, and the merchant owns it: ISO 4217 is not consulted, and a
        // EUR row at 0 or 4 places is a configuration, never an error. The
        // range is the one the implementation supports, and CurrencyTable::check()
        // enforces it for every writer; this only turns it into a message the
        // operator can read.
        $rawScale = $data['decimal_place'] ?? null;

        if (
            !is_numeric($rawScale)
            || (string) (int) $rawScale !== (string) $rawScale
            || (int) $rawScale < Currency::SCALE_MIN
            || (int) $rawScale > Currency::SCALE_MAX
        ) {
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_ALFA_ERROR_CURRENCY_DECIMAL_PLACE_RANGE',
                    Currency::SCALE_MIN,
                    Currency::SCALE_MAX,
                    var_export($rawScale, true),
                ),
                'error',
            );

            return false;
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = 0;
        if ($data['id'] > 0) { //not a new
            $currentId = intval($data['id']);
        } else { // is new
            $currentId = intval($this->getState($this->getName() . '.id'));//get the id from setted joomla state
        }

        return true;
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param Table $table Table Object
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function prepareTable($table)
    {
        $user = $this->getCurrentUser();

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = $user->id;

        return parent::prepareTable($table);
    }
}
