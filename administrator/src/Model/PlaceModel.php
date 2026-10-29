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
use Alfa\Component\Alfa\Administrator\Service\Catalog\RuleScopeGuard;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ScopeDeleteRefusedException;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
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
        $db->transactionStart(true);

        try {
            // Only the places that exist, locked: the rows deleted, judged and
            // stripped below are the same set.
            $pks = RuleScopeGuard::lockExisting(db: $db, axis: RuleScopeGuard::AXIS_PLACE, ids: $pks);

            if ($pks !== []) {
                // Zero place rows do not mean "nowhere": the engine reads them as
                // "everywhere". A discount or tax whose last place is in the set would
                // apply in every place, so the delete is refused, naming the rules.
                RuleScopeGuard::assertScopesSurvive(db: $db, axis: RuleScopeGuard::AXIS_PLACE, ids: $pks);

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
        } catch (ScopeDeleteRefusedException $refused) {
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

        return true;
    }
}
