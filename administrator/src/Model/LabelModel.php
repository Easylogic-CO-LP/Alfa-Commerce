<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemLabels;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use RuntimeException;
use Throwable;

/**
 * One of the shop's labels: a badge a product ticks on its Labels tab, with its name per language, its look, where it
 * shows and whether the storefront filters offer it ({@see \Alfa\Component\Alfa\Site\Service\Catalog\Labels}).
 *
 * @since  2.3.0
 */
class LabelModel extends AlfaAdminModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('label').
     * @since  2.3.0
     */
    protected string $alfaEntity = 'label';

    /**
     * @var string The prefix to use with controller messages.
     * @since  2.3.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control
     * @since  2.3.0
     */
    public $typeAlias = 'com_alfa.label';

    /**
     * @var null Item data
     * @since  2.3.0
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
     * @since  2.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.label',
            'label',
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
     * Method to save the form data: the row, then its name per language.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since  2.3.0
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // Recover the per-language flat keys (name_en_gb) and other undeclared keys the form filter drops, without
        // overwriting any validated field or resurrecting the housekeeping fields the form unsets.
        $rawData = $input->post->get('jform', [], 'array');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        $pk = $data['id'] ?? (int) $this->getState($this->getName() . '.id');
        $isNew = $pk <= 0;

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        // MULTILINGUAL: the name per language. A label has no alias.
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_label',
            tableName:         '#__alfa_labels',
            data:              $data,
            aliasFields:       [],
        );

        return true;
    }

    /**
     * Method to delete one or more records, with the rows of the products that tick them and their names.
     *
     * @param array &$pks An array of record primary keys.
     *
     * @return bool True on success.
     *
     * @since  2.3.0
     */
    public function delete(&$pks)
    {
        // ApiController::delete() hands the model a SCALAR id; only parent::delete() normalises, and everything below
        // runs before it.
        $pks = array_values(array_filter(array_map('intval', (array) $pks), static fn (int $id): bool => $id > 0));

        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        if ($pks === []) {
            return true;
        }

        // Every delete veto, Alfa's and Joomla's, before the transaction: no plugin code runs under its locks.
        if (!$this->alfaAskBeforeDelete(pks: $pks)) {
            return false;
        }

        $db = $this->alfaDriver();
        $db->transactionStart(true);

        try {
            $this->alfaDeleteAsked = true;

            if (!parent::delete($pks)) {
                TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The labels were not deleted.'));

                return false;
            }

            // The products' ticks of the deleted labels.
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(ItemLabels::TABLE))
                    ->whereIn($db->quoteName('label_id'), $pks),
            )->execute();

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        // MULTILINGUAL: remove the per-language rows of the deleted labels.
        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_label',
            tableName:         '#__alfa_labels',
        );

        return true;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  2.3.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.label.data',
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
     * Prepare and sanitise the table prior to saving: a new label stands last; empty dates are none.
     *
     * @param Table $table Table Object
     *
     * @return void
     *
     * @since  2.3.0
     */
    protected function prepareTable($table)
    {
        $table->modified = Factory::getDate()->toSql();

        if (empty($table->id)) {
            $table->ordering = $table->getNextOrder();
        }

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        return parent::prepareTable($table);
    }
}
