<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\DocumentTypes\DocumentTypeRegistry;
use Alfa\Component\Alfa\Administrator\Service\DocumentTypes\PairingRefusedException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Document type admin model: the merchant-facing surface of the
 * registry.
 *
 * Merchants curate PRESENTATION (translated names, icon, color,
 * ordering, visibility) and, on a credit type of their own, which sales
 * types it corrects. The semantic columns are registry-owned: this model
 * strips them from every save so no request can change them, and so is the
 * pairing of a type a provider registered (core's or a plugin's): its
 * provider declares it. Core rows are never deletable, and no type is
 * deleted while a credit type is set to correct it.
 *
 * @since  1.3.0
 */
class DocumenttypeModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity this model serves.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'documenttype';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() merges the raw per-language name keys and strips the registry-owned
     *   semantic columns BEFORE parent::save(), so onAlfaAdminBeforeSave sees the
     *   already-stripped data: a plugin cannot smuggle a protected column back in.
     * - onAlfaAdminAfterSave fires right after the main row write, BEFORE
     *   saveMultilingualData() persists the per-language name rows.
     * - canDelete() (core / referenced guards) runs inside parent::delete() per pk,
     *   so onAlfaAdminBeforeDelete sees the full requested batch, including ids the
     *   guards will subsequently refuse.
     */

    /**
     * @var string Message prefix for translations.
     * @since  1.3.0
     */
    protected $text_prefix = 'COM_ALFA_DOCUMENTTYPE';

    /**
     * Registry-owned columns a form POST may never touch.
     *
     * @var string[]
     * @since  1.3.0
     */
    private const PROTECTED_COLUMNS = [
        'alias', 'family', 'quantity_pool', 'shows_prices', 'movement_mode',
        'requires_original', 'requires_buyer_tax_id', 'stock_effect',
        'default_layout', 'is_core', 'params',
    ];

    /**
     * Build the edit form.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load the form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false
     *
     * @since  1.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.documenttype', 'documenttype', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        return $form ?: false;
    }

    /**
     * The record, with the sales types it corrects as a list.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  1.14.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && !empty($item->alias)) {
            $item->corrects = DocumentHelper::correctedTypes(creditType: (string) $item->alias);
        }

        return $item;
    }

    /**
     * Load the data for the form (edited record or saved user state).
     *
     * @return mixed
     *
     * @since  1.3.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.documenttype.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Save presentation fields + per-language names; semantics stripped.
     *
     * @param array $data The form data.
     *
     * @return bool
     *
     * @since  1.3.0
     */
    public function save($data)
    {
        // Recover the per-language flat keys (name_en_gb, ...) the form
        // filter drops.
        $rawData = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, (array) $rawData, $this->getForm($data, false) ?: null);

        // The registry owns the semantics: a form POST may never carry them.
        foreach (self::PROTECTED_COLUMNS as $column) {
            unset($data[$column]);
        }

        // Which sales types a credit type corrects: a provider's type corrects
        // what its provider declares, so a post never changes it; a type of
        // the merchant's own is set here, by the registry's one rule. The form
        // always posts it (an emptied list as none); a save that does not
        // carry it leaves it as it is.
        if (\array_key_exists('corrects', $data)) {
            $type = $this->getTable();
            $type->load((int) ($data['id'] ?? 0));

            if (DocumentHelper::pairingIsDeclared(row: (object) ['is_core' => $type->is_core ?? 0, 'provider' => $type->provider ?? ''])) {
                unset($data['corrects']);
            } else {
                try {
                    $data['corrects'] = (new DocumentTypeRegistry())->pairing(
                        alias:    (string) ($type->alias ?? ''),
                        family:   (string) ($type->family ?? ''),
                        corrects: (array) $data['corrects'],
                    );
                } catch (PairingRefusedException $e) {
                    $this->setError(self::pairingRefusal(refusal: $e));

                    return false;
                }
            }
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = (int) ($data['id'] ?? 0) ?: (int) $this->getState($this->getName() . '.id');

        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_document_type',
            tableName:         '#__alfa_document_types',
            data:              $data,
            aliasFields:       [],
        );

        return true;
    }

    /**
     * A refused pairing in the merchant's words: the types named by label.
     *
     * @param PairingRefusedException $refusal The registry's refusal.
     *
     * @return string HTML-safe: the message queue prints it as given.
     *
     * @since  1.14.0
     */
    private static function pairingRefusal(PairingRefusedException $refusal): string
    {
        $named = implode(', ', array_map(
            static fn (string $alias): string => htmlspecialchars(
                DocumentHelper::typeRow($alias) !== null ? DocumentHelper::typeLabel($alias) : $alias,
                ENT_QUOTES,
                'UTF-8',
            ),
            $refusal->named,
        ));

        return match ($refusal->reason) {
            PairingRefusedException::NOT_CREDIT => Text::_('COM_ALFA_DOCUMENTTYPE_CORRECTS_ERROR_NOT_CREDIT'),
            PairingRefusedException::TOO_LONG => Text::_('COM_ALFA_DOCUMENTTYPE_CORRECTS_ERROR_TOO_MANY'),
            default => Text::sprintf('COM_ALFA_DOCUMENTTYPE_CORRECTS_ERROR_NOT_SALES', $named),
        };
    }

    /**
     * Deletion policy: core rows never; provider rows only while nothing
     * references them (documents or series) and no credit type is set to
     * correct them.
     *
     * @param object $record The record to test.
     *
     * @return bool
     *
     * @since  1.3.0
     */
    protected function canDelete($record)
    {
        if ((int) ($record->is_core ?? 0) === 1) {
            $this->setError(Text::_('COM_ALFA_DOCUMENTTYPE_ERROR_DELETE_CORE'));

            return false;
        }

        if ($this->isReferenced(alias: (string) $record->alias)) {
            $this->setError(Text::_('COM_ALFA_DOCUMENTTYPE_ERROR_DELETE_REFERENCED'));

            return false;
        }

        // Refused, not stripped: a provider's credit type corrects what its
        // provider declares, and a deleted type would leave a name in it that
        // a later type of that alias would inherit.
        $pairedBy = $this->pairedBy(alias: (string) $record->alias);

        if ($pairedBy !== []) {
            $this->setError(Text::sprintf(
                'COM_ALFA_DOCUMENTTYPE_ERROR_DELETE_PAIRED',
                implode(', ', array_map(
                    static fn (string $alias): string => htmlspecialchars(DocumentHelper::typeLabel($alias), ENT_QUOTES, 'UTF-8'),
                    $pairedBy,
                )),
            ));

            return false;
        }

        return parent::canDelete($record);
    }

    /**
     * The credit types whose stored pairing names a type, read from the table.
     *
     * @param string $alias The type alias.
     *
     * @return string[]
     *
     * @since  1.14.0
     */
    private function pairedBy(string $alias): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__alfa_document_types'))
            ->where('FIND_IN_SET(:alias, ' . $db->quoteName('corrects') . ') > 0')
            ->bind(':alias', $alias)
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        $db->setQuery(query: $query);

        return $db->loadColumn() ?: [];
    }

    /**
     * Whether any document or series stores this alias.
     *
     * @param string $alias The type alias.
     *
     *
     * @since  1.3.0
     */
    private function isReferenced(string $alias): bool
    {
        $db = $this->getDatabase();

        foreach (['#__alfa_documents', '#__alfa_document_series'] as $table) {
            $query = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName($table))
                ->where($db->quoteName('doc_type') . ' = :alias')
                ->bind(':alias', $alias)
                ->setLimit(1);
            $db->setQuery(query: $query);

            if ($db->loadResult()) {
                return true;
            }
        }

        return false;
    }
}
