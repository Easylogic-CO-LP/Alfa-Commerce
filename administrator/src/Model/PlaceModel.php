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
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RuleScopeGuard;
use Alfa\Component\Alfa\Administrator\Service\Place\PlaceSeed;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Place model.
 *
 * @since  1.0.0
 */
class PlaceModel extends AlfaAdminModel
{
    /**
     * How many products the warning of a deleted place names before it counts the rest (2.2.1).
     *
     * @since  2.2.1
     */
    private const PRODUCTS_NAMED = 20;

    /**
     * @var string The entity a plugin targets with $context->targets('place').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'place';
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
    public $typeAlias = 'com_alfa.place';

    protected $formName = 'place';

    /**
     * @var null Item data
     *
     * @since  1.0.0
     */
    protected $item = null;

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
            stateKey: 'com_alfa.edit.place.data',
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
            // Do any procesing on fields here if needed
        }

        return $item;
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
    }

    /**
     * Save a place. A place that takes another parent changes the chain of every place below it, so the price index
     * slots kept at or below it, priced with the old chain, owe the catalogue a rebuild (2.2.1): it runs in Tools >
     * Tasks and staff are told.
     *
     * @param array $data The form data.
     *
     * @return bool True on success.
     *
     * @since  2.2.1
     */
    public function save($data)
    {
        $id = (int) ($data['id'] ?? 0);
        $before = $id > 0 ? $this->parentOf(placeId: $id) : null;

        if (!parent::save($data)) {
            return false;
        }

        if ($before !== null && $before !== $this->parentOf(placeId: $id) && (new PriceIndexSyncService())->oweChainRebuild(placeIds: [$id])) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_PLACE_PARENT_CHANGED_REBUILD'), 'warning');
        }

        return true;
    }

    /**
     * A place's parent as stored, or null for a place that does not exist.
     *
     * @param int $placeId The place.
     *
     * @since  2.2.1
     */
    private function parentOf(int $placeId): ?int
    {
        $db = $this->getDatabase();
        $parent = $db->setQuery(
            $db->getQuery(true)
                ->select('COALESCE(' . $db->quoteName('parent_id') . ', 0)')
                ->from($db->quoteName('#__alfa_places'))
                ->where($db->quoteName('id') . ' = ' . $placeId),
        )->loadResult();

        return $parent === null ? null : (int) $parent;
    }
    /**
     * Delete places and every row scoped by them: the four module pivots
     * and the price-index rows of the place axis (the ranked read falls
     * back to the 0-place rows until the next rebuild).
     *
     * Refused, with nothing written, when the set is the last place of a
     * discount or tax rule: with no place left the rule would apply in every
     * place ({@see RuleScopeGuard}). Only the requested places that exist are
     * deleted and stripped.
     *
     * A product's price rows that name a deleted place are kept: they apply to
     * no buyer, the prices editor marks the place as deleted, and an edit that
     * would widen one to every place is refused (2.2.1). The delete stands and
     * names them, a warning per place with its rows and their products.
     *
     * Refused, with nothing written, while a place in the set has places inside
     * it that are not deleted with it (2.2.1): they would be left without the
     * country or region above them, outside its rules and carrier zones. The
     * refusal names them; staff move them under another place or delete them
     * with it, as Joomla asks for a category.
     *
     * @param array &$pks Record primary keys.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function delete(&$pks)
    {
        $pks = array_map('intval', (array) $pks);

        // Permission first: the refusal below names rules, which a user who may
        // not delete places must neither trigger nor read.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $db = $this->getDatabase();

        // ONE TRANSACTION: the rule-scope judgement, the rows and every pivot that
        // names them. The judgement locks the rule pivots it reads, so no rule editor
        // can empty a scope between it and the delete, and a refusal or a failure
        // leaves every row where it was.
        $requested = $pks;

        // Every delete veto, Alfa's and Joomla's, before the transaction and its locks (2.1.4, design 0.2): no plugin code
        // runs under them.
        if (!$this->alfaAskBeforeDelete(pks: (array) $pks)) {
            return false;
        }

        $nested = TransactionState::isOpen(db: $db);
        $pricedOn = [];
        $db->transactionStart(true);

        try {
            // Only the places that exist, locked: the rows deleted, judged and
            // stripped below are the same set.
            $pks = RuleScopeGuard::lockExisting(db: $db, axis: RuleScopeGuard::AXIS_PLACE, ids: $pks);

            if ($pks !== []) {
                // The places inside the set that the set does not delete: a delete never leaves a place without the
                // place above it (2.2.1).
                $inside = $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName(['id', 'name', 'parent_id']))
                        ->from($db->quoteName('#__alfa_places'))
                        ->whereIn($db->quoteName('parent_id'), $pks)
                        ->whereNotIn($db->quoteName('id'), $pks)
                        ->order($db->quoteName('id')),
                )->loadObjectList() ?: [];

                if ($inside !== []) {
                    throw $this->insideRefusal(db: $db, inside: $inside);
                }

                // Zero place rows do not mean "nowhere": the engine reads them as
                // "everywhere". A discount or tax whose last place is in the set would
                // apply in every place, so the delete is refused, naming the rules.
                RuleScopeGuard::assertScopesSurvive(db: $db, axis: RuleScopeGuard::AXIS_PLACE, ids: $pks);

                // The price rows the delete leaves on the places, read with the rows it deletes.
                $pricedOn = $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName('country_id', 'place'))
                        ->select('COUNT(*) AS ' . $db->quoteName('rows'))
                        ->select('COUNT(DISTINCT ' . $db->quoteName('item_id') . ') AS ' . $db->quoteName('products'))
                        ->select('GROUP_CONCAT(DISTINCT ' . $db->quoteName('item_id') . ' ORDER BY ' . $db->quoteName('item_id') . ') AS ' . $db->quoteName('items'))
                        ->from($db->quoteName('#__alfa_items_prices'))
                        ->whereIn($db->quoteName('country_id'), $pks)
                        ->group($db->quoteName('country_id')),
                )->loadObjectList() ?: [];

                $this->alfaDeleteAsked = true;

                if (!parent::delete($pks)) {
                    // Every close of this level discards what was deferred inside it.
                    TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The places were not deleted.'));

                    return false;
                }

                foreach (['#__alfa_discount_places', '#__alfa_payment_places', '#__alfa_shipment_places', '#__alfa_tax_places'] as $pivotTable) {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->quoteName($pivotTable))
                            ->whereIn($db->quoteName('place_id'), $pks),
                    )->execute();
                }
            }

            TransactionState::commitLevel(db: $db);
        } catch (DeleteRefusedException $refused) {
            // A refusal is answered only while a caller's transaction still stands.
            if (TransactionState::rollBackLevel(db: $db, failure: $refused) && $nested) {
                throw $refused;
            }

            $this->setError($refused);

            return false;
        } catch (Throwable $e) {
            // After a deadlock the whole transaction is gone, on the server and in
            // the driver's count.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        // None of the requested places exists: core answers that itself.
        if ($pks === []) {
            $this->alfaDeleteAsked = true;

            return parent::delete($requested);
        }

        // The index rows priced for the deleted places. Every rule they scoped
        // kept another place, so no other row moved.
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_items_price_index'))
                ->whereIn($db->quoteName('place_id'), $pks),
        )->execute();

        foreach ($pricedOn as $priced) {
            $products = (int) $priced->products;
            $named = \array_slice(explode(',', (string) $priced->items), 0, self::PRODUCTS_NAMED);

            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'COM_ALFA_PLACE_DELETED_PRICE_ROWS',
                    (int) $priced->place,
                    (int) $priced->rows,
                    $products,
                    '#' . implode(', #', $named) . ($products > \count($named) ? Text::sprintf('COM_ALFA_PLACE_DELETED_PRICE_ROWS_MORE', $products - \count($named)) : ''),
                ),
                'warning',
            );
        }

        return true;
    }

    /**
     * The refusal of a delete that would leave places without the place above them, naming them (2.2.1).
     *
     * @param DatabaseInterface $db The connection.
     * @param object[] $inside The places left behind: id, name and parent_id.
     *
     * @since  2.2.1
     */
    private function insideRefusal(DatabaseInterface $db, array $inside): DeleteRefusedException
    {
        $parents = array_values(array_unique(array_map(static fn (object $place): int => (int) $place->parent_id, $inside)));
        $parentNames = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'name']))
                ->from($db->quoteName('#__alfa_places'))
                ->whereIn($db->quoteName('id'), $parents),
        )->loadAssocList('id', 'name') ?: [];

        $sentences = [];
        $plain = [];

        foreach ($parents as $parent) {
            $children = array_values(array_filter($inside, static fn (object $place): bool => (int) $place->parent_id === $parent));
            $named = implode(', ', array_map(static fn (object $place): string => '#' . (int) $place->id . ' ' . $place->name, $children));
            $label = '#' . $parent . ' ' . ($parentNames[$parent] ?? '');
            $plain[] = Text::sprintf('COM_ALFA_PLACE_DELETE_HAS_PLACES_INSIDE', $label, $named);
            $sentences[] = Text::sprintf(
                'COM_ALFA_PLACE_DELETE_HAS_PLACES_INSIDE',
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($named, ENT_QUOTES, 'UTF-8'),
            );
        }

        return new DeleteRefusedException(implode(' ', $sentences), implode(' ', $plain));
    }

    /**
     * Add, unpublished, the places Alfa Commerce ships inside the chosen countries ({@see PlaceSeed::SUBDIVISIONS}:
     * Greece's 13 regions and Mount Athos; the Canary Islands, Ceuta and Melilla inside Spain), each with its ISO 3166-2
     * code and no country codes (2.2.1). A code the country already has a place for, directly inside it, is skipped;
     * nothing is matched by name, so a place staff made without a code is not recognised. The places of one country are
     * added together or not at all.
     *
     * @param int[] $pks The chosen places.
     *
     * @return array<int, array{name: string, outcome: string, added: string[]}> Per chosen place that exists: its name,
     *         the outcome ('added'; 'present', every code already there; 'none', not a country Alfa ships places
     *         inside) and the names of the places added.
     *
     * @throws RuntimeException When the user may not create places.
     *
     * @since  2.2.1
     */
    public function addSubdivisions(array $pks): array
    {
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'create', user: $this->getCurrentUser())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_CREATE_RECORD_NOT_PERMITTED'));
        }

        $db = $this->alfaDriver();
        $summary = [];

        foreach (array_values(array_unique(array_map('intval', $pks))) as $pk) {
            $country = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'name', 'code2']))
                    ->select('COALESCE(' . $db->quoteName('parent_id') . ', 0) AS ' . $db->quoteName('parent_id'))
                    ->from($db->quoteName('#__alfa_places'))
                    ->where($db->quoteName('id') . ' = ' . $pk),
            )->loadObject();

            if ($country === null) {
                continue;
            }

            $shipped = (int) $country->parent_id === 0 ? PlaceSeed::SUBDIVISIONS[strtoupper(trim((string) $country->code2))] ?? [] : [];

            if ($shipped === []) {
                $summary[$pk] = ['name' => (string) $country->name, 'outcome' => 'none', 'added' => []];

                continue;
            }

            $present = array_map('strtoupper', $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('subdivision_code'))
                    ->from($db->quoteName('#__alfa_places'))
                    ->where($db->quoteName('parent_id') . ' = ' . $pk)
                    ->where($db->quoteName('subdivision_code') . ' IS NOT NULL'),
            )->loadColumn() ?: []);
            $missing = array_diff_key($shipped, array_flip($present));

            if ($missing === []) {
                $summary[$pk] = ['name' => (string) $country->name, 'outcome' => 'present', 'added' => []];

                continue;
            }

            $db->transactionStart(true);

            try {
                foreach ($missing as $code => $name) {
                    $table = $this->getTable();
                    $table->bind(['id' => 0, 'name' => $name, 'parent_id' => $pk, 'subdivision_code' => $code, 'currency_id' => 0,
                        'state' => 0, 'ordering' => 0, 'created_by' => $this->getCurrentUser()->id, 'modified_by' => $this->getCurrentUser()->id]);

                    if (!$table->check() || !$table->store()) {
                        throw new RuntimeException(Text::sprintf('COM_ALFA_PLACES_SUBDIVISION_NOT_ADDED', $name, (string) $table->getError()));
                    }
                }

                TransactionState::commitLevel(db: $db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }

            $summary[$pk] = ['name' => (string) $country->name, 'outcome' => 'added', 'added' => array_values($missing)];
        }

        return $summary;
    }
}
