<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;

/**
 * Document series admin model.
 *
 * Rules owned here:
 *   - is_default is a per-doc_type cross-row singleton (Orderstatus
 *     is_initial pattern): nominating a default demotes the previous one.
 *   - next_number is editable only while the series has NO issued documents
 *     (migration seeding); afterwards the counter belongs to the issue
 *     transaction alone.
 *   - a series with issued documents can never be deleted; retire it by
 *     unpublishing (state 0) instead.
 *
 * @since  1.3.0
 */
class SeriesModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'series';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() merges the composer's raw POST keys and bundles `positions` BEFORE
     *   parent::save(), so onAlfaAdminBeforeSave sees the finished payload; but
     *   onAlfaAdminAfterSave fires inside parent::save(), i.e. BEFORE the is_default
     *   cross-row demotion runs: a handler reading sibling rows there sees the
     *   pre-demotion defaults.
     * - onAlfaAdminValidate sees the validated data WITHOUT the composer keys:
     *   they are undeclared in the form XML and recovered from the raw POST only
     *   inside save().
     */

    /**
     * @var string Message prefix for translations.
     * @since  1.3.0
     */
    protected $text_prefix = 'COM_ALFA_SERIES';

    /**
     * Build the series edit form.
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
        $form = $this->loadForm('com_alfa.series', 'series', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        if (empty($form)) {
            return false;
        }

        $id = (int) $this->getState('series.id', Factory::getApplication()->getInput()->getInt('id', 0));

        if ($id > 0 && $this->hasIssuedDocuments(seriesId: $id)) {
            // The counter belongs to the issue transaction now.
            $form->setFieldAttribute('next_number', 'readonly', 'true');
            $form->setFieldAttribute('next_number', 'filter', 'unset');

            // doc_type changes would orphan the issued documents' semantics.
            // filter=unset drops the value from the validated data (the row
            // keeps its stored type), so `required` must be lifted too or
            // server-side validation rejects every re-save of the series.
            $form->setFieldAttribute('doc_type', 'readonly', 'true');
            $form->setFieldAttribute('doc_type', 'filter', 'unset');
            $form->setFieldAttribute('doc_type', 'required', 'false');
        }

        return $form;
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
            stateKey: 'com_alfa.edit.series.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Save with the is_default cross-row enforcement.
     *
     * @param array $data The form data.
     *
     * @return bool
     *
     * @since  1.3.0
     */
    public function save($data)
    {
        // The document composer (DocumentPositionsField) submits one flat key
        // per language x position plus one per section eye-toggle, all
        // undeclared in the form XML, so the form filter drops them. Recover
        // them from the raw POST (the orderstatus email-composer pattern),
        // then bundle into the `positions` JSON column.
        $rawData = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, (array) $rawData, $this->getForm($data, false) ?: null);

        self::bundlePositionsIntoJson(data: $data);

        // Un-scoping a series that already issued year-scoped documents is
        // refused: its per-year numbers (1..n per year) would re-enter the
        // year-0 namespace and collide with any pre-scoping rows, wedging
        // issuance on the duplicate key exactly like the year-rollover defect
        // this guard accompanies. Scoping a series (0 -> 1) is safe: existing
        // documents live in year 0 and can never collide with a real year.
        if (!empty($data['id']) && array_key_exists('year_scoped', $data) && (int) $data['year_scoped'] === 0) {
            $db = $this->getDatabase();
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('year_scoped'))
                    ->from($db->quoteName('#__alfa_document_series'))
                    ->where($db->quoteName('id') . ' = ' . (int) $data['id']),
            );

            if ((int) $db->loadResult() === 1) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select('COUNT(*)')
                        ->from($db->quoteName('#__alfa_documents'))
                        ->where([
                            $db->quoteName('id_series') . ' = ' . (int) $data['id'],
                            $db->quoteName('number_year') . ' > 0',
                        ]),
                );

                if ((int) $db->loadResult() > 0) {
                    Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_SERIES_UNSCOPE_WITH_DOCUMENTS'), 'error');

                    return false;
                }
            }
        }

        if (!parent::save($data)) {
            return false;
        }

        // Cross-row singleton: the new default demotes every other row of
        // the same doc_type (same pattern as Orderstatus is_initial).
        if (!empty($data['is_default'])) {
            $id = (int) $this->getState($this->getName() . '.id');
            $db = $this->getDatabase();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_document_series'))
                ->set($db->quoteName('is_default') . ' = 0')
                ->where([
                    $db->quoteName('doc_type') . ' = ' . $db->quote((string) $data['doc_type']),
                    $db->quoteName('id') . ' != ' . $id,
                ]);
            $db->setQuery(query: $query);
            $db->execute();
        }

        return true;
    }

    /**
     * Bundle the document composer's flat POST keys into the `positions`
     * JSON column: {langTag: {slot: html, _show_<slug>: '0'|'1'}}.
     *
     * DocumentPositionsField submits `positions_<langSnake>_<slot>` per
     * language x position and ONE `positions_showstruct_<slug>` per
     * structural section; the section flags are fanned into every
     * language's payload so render-time visibility resolves regardless of
     * the document's language (the email composer convention). Consumed
     * keys are removed from `$data`; with no composer keys posted the
     * column is left untouched.
     *
     * @param array $data Form payload (modified by reference).
     *
     *
     * @since  1.3.0
     */
    private static function bundlePositionsIntoJson(array &$data): void
    {
        $showFlags = [];
        $showPrefix = 'positions_showstruct_';

        foreach (array_keys($data) as $key) {
            if (!str_starts_with($key, $showPrefix)) {
                continue;
            }

            $slug = substr($key, strlen($showPrefix));

            if ($slug !== '') {
                $showFlags['_show_' . $slug] = ((int) $data[$key]) === 1 ? '1' : '0';
            }

            unset($data[$key]);
        }

        $languages = LanguageHelper::getLanguages('lang_code') ?: [];
        $bundle = [];
        $sawAny = $showFlags !== [];

        foreach ($languages as $langCode => $language) {
            $suffix = strtolower(str_replace('-', '_', (string) $langCode));
            $prefix = 'positions_' . $suffix . '_';
            $perLanguage = [];

            // Harvest every posted key under this language prefix rather
            // than a fixed slot list; works for any layout's positions AND
            // for stale slots the composer re-rendered for migration.
            // (Snapshot the keys first: we unset as we go.)
            foreach (array_keys($data) as $key) {
                if (!str_starts_with($key, $prefix)) {
                    continue;
                }

                $slot = substr($key, strlen($prefix));

                if ($slot === '') {
                    continue;
                }

                $perLanguage[$slot] = (string) $data[$key];
                $sawAny = true;
                unset($data[$key]);
            }

            if ($showFlags !== []) {
                $perLanguage += $showFlags;
            }

            if ($perLanguage !== []) {
                $bundle[(string) $langCode] = $perLanguage;
            }
        }

        // No composer keys in the POST (e.g. a layout exposing no slots):
        // leave the stored column as-is.
        if ($sawAny) {
            $data['positions'] = json_encode($bundle, JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Block deletion of series that have issued documents.
     *
     * @param object $record The record to test.
     *
     * @return bool
     *
     * @since  1.3.0
     */
    protected function canDelete($record)
    {
        if (!empty($record->id) && $this->hasIssuedDocuments(seriesId: (int) $record->id)) {
            $this->setError(Text::_('COM_ALFA_SERIES_ERROR_DELETE_HAS_DOCUMENTS'));

            return false;
        }

        return parent::canDelete($record);
    }

    /**
     * Whether any document was ever issued from this series.
     *
     * Voided documents count too: their numbers came from this counter.
     *
     * @param int $seriesId The series id.
     *
     *
     * @since  1.3.0
     */
    private function hasIssuedDocuments(int $seriesId): bool
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_documents'))
            // Drafts count too: they hold id_series and would fail to
            // issue against a vanished counter.
            ->where($db->quoteName('id_series') . ' = :seriesId')
            ->bind(':seriesId', $seriesId)
            ->setLimit(1);
        $db->setQuery(query: $query);

        return (bool) $db->loadResult();
    }
}
