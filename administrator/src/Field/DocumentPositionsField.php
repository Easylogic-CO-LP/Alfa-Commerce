<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRenderer;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\EditorField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

/**
 * DocumentPositionsField: "document composer"
 *
 * The document counterpart of EmailPositionsField: the SAME composer
 * experience (custom language tabs with flags, one Joomla WYSIWYG editor
 * per merchant-text position, structural blocks with per-section eye
 * toggles, stale-slot migration, restore-defaults, variables palette),
 * rendered from the series' selected layout via
 * DocumentRenderer::discoverSequence(). Reuses the email composer's
 * CSS/JS asset; the markup contract (.aec classes) is identical.
 *
 * Differences from the email composer:
 *   - No subject slot and no preview/send-test actions (documents are
 *     printed from real issued data, not simulated).
 *   - Storage is the series row's `positions` JSON column
 *     ({langTag: {slot: html, _show_<slug>: '0'|'1'}}), not per-language
 *     aux tables. The editors post flat keys
 *     `jform[<fieldname>_<langSnake>_<slot>]` and the eye toggles ONE
 *     `jform[<fieldname>_showstruct_<slug>]` per section;
 *     SeriesModel::save bundles them back into the JSON column.
 *   - Defaults come from COM_ALFA_DOCUMENT_DEFAULT_<SLOT> language
 *     constants (the email naming convention): slots a language has never
 *     saved open pre-seeded with the text that would print, and "Restore
 *     defaults" rewrites exactly those slots. Slots without a constant
 *     seed empty and are skipped by restore.
 *
 * Structural blocks never lose their layout ownership: the eye hides a
 * block from the rendered document (DocumentRenderer honours the
 * `_show_<slug>` flag); restyling or replacing markup remains a layout
 * override concern (dompdf-safe rules: layouts/documents/partials/style.php header).
 *
 * @since  1.3.0
 */
class DocumentPositionsField extends EditorField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    public $type = 'DocumentPositions';

    /**
     * User-state key where FormController::reload stashes the submitted
     * (unsaved) form data before redirecting. Single-consumer: this field
     * is used only on the series edit form (context 'series').
     */
    private const EDIT_STATE_KEY = 'com_alfa.edit.series.data';

    /**
     * Active languages cache for the lifetime of one render pass.
     *
     * @var array<string, object>|null
     */
    private ?array $languagesCache = null;

    //  Lifecycle

    /**
     * Normalise the bound value into
     *   $this->value = [ '<lang_snake>' => [ '<slot>' => '<html>' ] ].
     *
     * The DB binds the `positions` JSON column keyed by language TAG
     * ('en-GB'); a validation-failure rebind may carry an array. Unsaved
     * flat keys from a form reload (layout change) overlay both, so
     * authored content survives the reload.
     *
     * @param SimpleXMLElement $element XML field definition.
     * @param mixed $value Form-bound value (positions JSON string or array).
     * @param string|null $group Field group.
     *
     *
     * @since  1.3.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null): bool
    {
        $result = parent::setup($element, $value, $group);

        $decoded = \is_array($this->value)
            ? $this->value
            : (json_decode((string) $this->value, true) ?: []);

        // FormController::reload stashes the POSTed jform in user state and
        // REDIRECTS (GET), so on the reloaded request the unsaved data lives
        // in user state, not POST: read state first, fall back to POST for
        // the in-place redraw case. Only this series' own post: another
        // series' kept edits never overlay this composer.
        $app = Factory::getApplication();
        $posted = KeptPost::forRecord(stateKey: self::EDIT_STATE_KEY, recordId: (int) $this->form->getValue('id', null));

        if (empty($posted)) {
            $posted = (array) $app->getInput()->post->get('jform', [], 'raw');
        }

        $perLang = [];
        $layoutId = $this->resolveSelectedLayout();

        foreach ($this->getLanguages() as $langCode => $language) {
            $suffix = $this->snakeLang(langCode: (string) $langCode);
            $perLang[$suffix] = \is_array($decoded[$langCode] ?? null) ? $decoded[$langCode] : [];

            // Seed shipped defaults (COM_ALFA_DOCUMENT_DEFAULT_<SLOT>) into
            // slots this language has NO key for; the composer has never
            // shown them, so the editor opens with the text that would print.
            // A key that exists (even empty = deliberately cleared) is never
            // re-seeded; "Restore defaults" is the only way back after that.
            foreach (DocumentRenderer::defaultsForLanguage(layoutId: $layoutId, langTag: (string) $langCode) as $slot => $default) {
                if (!\array_key_exists($slot, $perLang[$suffix])) {
                    $perLang[$suffix][$slot] = $default;
                }
            }

            // Overlay POSTed values (form reload / in-place redraw): keys are
            // <fieldname>_<suffix>_<slot>. The admin's unsaved edits win.
            $prefix = $this->fieldname . '_' . $suffix . '_';

            foreach ($posted as $key => $val) {
                if (str_starts_with((string) $key, $prefix)) {
                    $perLang[$suffix][substr((string) $key, strlen($prefix))] = (string) $val;
                }
            }
        }

        // Overlay unsaved section flags (posted once, fanned to every
        // language, the same shape the model persists).
        $showPrefix = $this->fieldname . '_showstruct_';

        foreach ($posted as $key => $val) {
            if (!str_starts_with((string) $key, $showPrefix)) {
                continue;
            }

            $slug = substr((string) $key, strlen($showPrefix));

            if ($slug === '') {
                continue;
            }

            foreach ($perLang as $suffix => $slots) {
                $perLang[$suffix]['_show_' . $slug] = ((int) $val) === 1 ? '1' : '0';
            }
        }

        $this->value = $perLang;

        return $result;
    }

    //  Rendering

    /**
     * Build the composer: custom language tabs + one document-shaped
     * panel per language + the variables palette.
     *
     *
     * @since  1.3.0
     */
    protected function getInput(): string
    {
        $layoutId = $this->resolveSelectedLayout();
        $sequence = DocumentRenderer::discoverSequence(layoutId: $layoutId);
        $languages = $this->getLanguages();
        $values = \is_array($this->value) ? $this->value : [];

        if (empty($languages)) {
            return '';
        }

        if ($sequence === []) {
            return '<div class="alert alert-info mb-0">' . Text::_('COM_ALFA_SERIES_POSITIONS_NONE') . '</div>';
        }

        $this->ensureAssetsLoaded();

        $editor = $this->getEditor();
        $idEsc = htmlspecialchars($this->id, ENT_COMPAT, 'UTF-8');

        // Emit this field's per-language defaults for the "Restore defaults"
        // button: the shipped COM_ALFA_DOCUMENT_DEFAULT_<SLOT> content.
        // Slots without a default constant are skipped (never cleared), the
        // email-composer semantics.
        $this->emitDefaults(layoutId: $layoutId, languages: $languages);

        // Per-section visibility state (slug => bool), resolved once from the
        // full saved values and shared across language tabs. Drives both the
        // hidden posting inputs and each struct block's eye + dimmed state.
        $structState = $this->collectStructState(sequence: $sequence, values: $values);

        $html = '<div class="aec" id="' . $idEsc . '" data-field-id="' . $idEsc . '">';
        $html .= $this->renderTabs(languages: $languages);
        $html .= $this->renderSectionFlags(structState: $structState);
        $html .= '<div class="aec-stage">';
        $html .= '<div class="aec-panels">';

        $first = true;
        foreach ($languages as $langCode => $language) {
            $suffix = $this->snakeLang(langCode: (string) $langCode);
            $html .= $this->renderPanel(
                editor:      $editor,
                suffix:      $suffix,
                sequence:    $sequence,
                values:      $values[$suffix] ?? [],
                active:      $first,
                structState: $structState,
            );
            $first = false;
        }

        $html .= '</div>'; // panels
        $html .= $this->renderVarsPalette();
        $html .= '</div>'; // stage
        $html .= '</div>'; // aec

        return $html;
    }

    /**
     * Custom language tabs (flag + native name) with the restore button.
     * A single language still renders a single tab so the JS contract is
     * uniform.
     *
     * @param array<string, object> $languages
     *
     *
     * @since  1.3.0
     */
    private function renderTabs(array $languages): string
    {
        $html = '<div class="aec-bar">';
        $html .= '<nav class="aec-tabs" role="tablist" aria-label="'
            . htmlspecialchars(Text::_('JLANGUAGE'), ENT_COMPAT, 'UTF-8') . '">';

        $first = true;
        foreach ($languages as $langCode => $language) {
            $suffix = $this->snakeLang(langCode: (string) $langCode);
            $html .= '<button type="button" class="aec-tab" role="tab"'
                . ' data-lang="' . htmlspecialchars($suffix, ENT_COMPAT, 'UTF-8') . '"'
                . ' data-lang-tag="' . htmlspecialchars((string) $langCode, ENT_COMPAT, 'UTF-8') . '"'
                . ' aria-selected="' . ($first ? 'true' : 'false') . '">'
                . $this->langChip(language: $language)
                . '</button>';
            $first = false;
        }

        $html .= '</nav>';
        $html .= '<div class="aec-bar-actions">';
        $html .= '<button type="button" class="btn btn-sm btn-outline-secondary aec-restore-btn"'
            . ' data-field="' . htmlspecialchars($this->id, ENT_COMPAT, 'UTF-8') . '">'
            . '<span class="icon-loop" aria-hidden="true"></span> '
            . Text::_('COM_ALFA_ORDEREMAIL_RESTORE_DEFAULTS_BUTTON') . '</button>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Push this field's per-language defaults into script options under
     * 'alfaEmailDefaults'[fieldId][langSuffix] = { slot: html } (the shared
     * composer JS reads this key), and register the confirm string. The
     * defaults are the shipped COM_ALFA_DOCUMENT_DEFAULT_<SLOT> constants.
     *
     * @param string $layoutId Active layout id.
     * @param array<string, object> $languages Installed content languages.
     *
     *
     * @since  1.3.0
     */
    private function emitDefaults(string $layoutId, array $languages): void
    {
        $defaults = [];

        foreach ($languages as $langCode => $language) {
            $defaults[$this->snakeLang(langCode: (string) $langCode)] = DocumentRenderer::defaultsForLanguage(
                layoutId: $layoutId,
                langTag:  (string) $langCode,
            );
        }

        Factory::getApplication()->getDocument()
            ->addScriptOptions('alfaEmailDefaults', [$this->id => $defaults]);

        Text::script('COM_ALFA_ORDEREMAIL_RESTORE_DEFAULTS_CONFIRM');
    }

    /**
     * One language's editing canvas: the layout's positions (Joomla
     * WYSIWYG editors) and structural blocks, in the order the layout
     * renders them (the discovered sequence).
     *
     * @param \Joomla\CMS\Editor\Editor $editor Editor instance.
     * @param string $suffix Snake-cased lang code.
     * @param array<int, array{type: string, name: string}> $sequence Discovered order.
     * @param array<string, string> $values Saved values for this lang.
     * @param bool $active Visible panel?
     * @param array<string, bool> $structState Per-section visibility.
     *
     *
     * @since  1.3.0
     */
    private function renderPanel(
        \Joomla\CMS\Editor\Editor $editor,
        string $suffix,
        array $sequence,
        array $values,
        bool $active,
        array $structState = [],
    ): string {
        $html = '<div class="aec-panel" data-lang="' . htmlspecialchars($suffix, ENT_COMPAT, 'UTF-8') . '"'
            . ($active ? '' : ' hidden') . '>';
        $html .= '<div class="aec-slots">';

        // Walk the sequence: position -> editor slot; struct -> layout block,
        // exactly where the layout renders them.
        $positions = [];
        foreach ($sequence as $entry) {
            if ($entry['type'] === 'struct') {
                $html .= $this->renderStruct(
                    layoutId: $entry['name'],
                    enabled:  $structState[$this->structSlug(layoutId: $entry['name'])] ?? true,
                );
                continue;
            }

            $position = $entry['name'];
            $positions[] = $position;
            $html .= $this->renderEditorSlot(
                editor:   $editor,
                suffix:   $suffix,
                position: $position,
                value:    (string) ($values[$position] ?? ''),
            );
        }

        // Stale positions (content saved under a previous layout).
        $html .= $this->renderStale(editor: $editor, suffix: $suffix, livePositions: $positions, values: $values);

        $html .= '</div>';   // slots
        $html .= '</div>';   // panel

        return $html;
    }

    /**
     * One position = label + the Joomla WYSIWYG editor (the form input).
     *
     * @param \Joomla\CMS\Editor\Editor $editor Editor instance.
     * @param string $suffix Snake-cased lang code.
     * @param string $position Position name.
     * @param string $value Current HTML value.
     *
     *
     * @since  1.3.0
     */
    private function renderEditorSlot(
        \Joomla\CMS\Editor\Editor $editor,
        string $suffix,
        string $position,
        string $value,
    ): string {
        $name = $this->buildInputName(suffix: $suffix, position: $position);
        $id = $this->id . '_' . $suffix . '_' . $position;
        $label = htmlspecialchars($this->positionLabel(position: $position), ENT_COMPAT, 'UTF-8');

        $html = '<section class="aec-slot" data-pos="' . htmlspecialchars($position, ENT_COMPAT, 'UTF-8') . '">';
        $html .= '<div class="aec-slot-head"><span class="aec-tag">' . $label . '</span></div>';
        // buttons = false: hide the xtd-editor button row (Article/Image/...),
        // keep just the editor's own toolbar.
        $html .= $editor->display($name, $value, '100%', 200, 60, 8, false, $id);
        $html .= '</section>';

        return $html;
    }

    /**
     * Per-section visibility state: slug => bool, one entry per structural
     * block the layout renders. Resolved once from the full saved values
     * (shared across languages; default ON).
     *
     * @param array<int, array{type: string, name: string}> $sequence
     * @param array<string, array<string, string>> $values
     *
     * @return array<string, bool>
     *
     * @since  1.3.0
     */
    private function collectStructState(array $sequence, array $values): array
    {
        $state = [];

        foreach ($sequence as $entry) {
            if ($entry['type'] !== 'struct') {
                continue;
            }

            $slug = $this->structSlug(layoutId: $entry['name']);

            if ($slug !== '' && !isset($state[$slug])) {
                $state[$slug] = $this->structEnabled(values: $values, slug: $slug);
            }
        }

        return $state;
    }

    /**
     * Hidden posting inputs for the section eye-toggles: ONE per section,
     * emitted once (outside the language panels). The eye buttons inside the
     * struct blocks are JS controls that flip the matching input's value, so
     * there is exactly one posted value per section regardless of how many
     * language panels repeat the block.
     *
     * Posts as jform[<field>_showstruct_<slug>] = 1|0; the model fans it
     * into every language's JSON as _show_<slug>.
     *
     * @param array<string, bool> $structState
     *
     *
     * @since  1.3.0
     */
    private function renderSectionFlags(array $structState): string
    {
        if (empty($structState)) {
            return '';
        }

        $base = $this->group !== null && $this->group !== ''
            ? 'jform[' . $this->group . ']'
            : 'jform';

        $html = '<div class="aec-section-flags" hidden>';

        foreach ($structState as $slug => $enabled) {
            $name = $base . '[' . $this->fieldname . '_showstruct_' . $slug . ']';

            $html .= '<input type="hidden" class="aec-section-flag"'
                . ' data-field="' . htmlspecialchars($this->id, ENT_COMPAT, 'UTF-8') . '"'
                . ' data-slug="' . htmlspecialchars($slug, ENT_COMPAT, 'UTF-8') . '"'
                . ' name="' . htmlspecialchars($name, ENT_COMPAT, 'UTF-8') . '"'
                . ' value="' . ($enabled ? '1' : '0') . '">';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a structural (non-positioned) document block: a title derived
     * from the partial id, a hint, and an eye toggle whose data-slug links
     * every per-language copy of the block to the single shared visibility
     * flag.
     *
     * @param string $layoutId Partial id, e.g. 'documents.partials.lines'.
     * @param bool $enabled Whether the block is currently shown.
     *
     *
     * @since  1.3.0
     */
    private function renderStruct(string $layoutId, bool $enabled = true): string
    {
        $title = $this->humaniseLayoutId(layoutId: $layoutId);
        $hint = Text::_('COM_ALFA_ORDEREMAIL_STRUCT_HINT');
        $slug = $this->structSlug(layoutId: $layoutId);

        $offClass = $enabled ? '' : ' aec-struct--off';
        $eyeIcon = $enabled ? 'icon-eye' : 'icon-eye-slash';

        // Visibility is per-series and SHARED across languages (one flag,
        // fanned to all); signal that inside multi-language panels.
        $multiLang = \count($this->getLanguages()) > 1;
        $eyeTitle = htmlspecialchars(
            Text::_($multiLang ? 'COM_ALFA_DOCUMENT_SECTION_TOGGLE_ALL_LANGS' : 'COM_ALFA_DOCUMENT_SECTION_TOGGLE'),
            ENT_COMPAT,
            'UTF-8',
        );
        $allLangsNote = $multiLang
            ? ' <span class="aec-struct-alllangs">'
                . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_SECTION_ALL_LANGS'), ENT_COMPAT, 'UTF-8')
                . '</span>'
            : '';

        return '<div class="aec-struct' . $offClass . '" data-slug="'
            . htmlspecialchars($slug, ENT_COMPAT, 'UTF-8') . '">'
            . '<button type="button" class="aec-struct-eye" data-slug="'
            . htmlspecialchars($slug, ENT_COMPAT, 'UTF-8') . '"'
            . ' aria-pressed="' . ($enabled ? 'true' : 'false') . '" title="' . $eyeTitle . '">'
            . '<span class="' . $eyeIcon . '" aria-hidden="true"></span></button>'
            . '<div><b>' . htmlspecialchars($title, ENT_COMPAT, 'UTF-8') . $allLangsNote . '</b>'
            . '<small>' . htmlspecialchars($hint, ENT_COMPAT, 'UTF-8') . '</small></div>'
            . '</div>';
    }

    /**
     * "Carried over from a previous layout": non-empty positions the
     * current layout no longer declares, rendered as editable slots so the
     * admin can migrate then clear them (empty ones self-clean on save).
     *
     * @param \Joomla\CMS\Editor\Editor $editor Editor instance.
     * @param string $suffix Snake-cased lang code.
     * @param string[] $livePositions
     * @param array<string, string> $values
     *
     *
     * @since  1.3.0
     */
    private function renderStale(\Joomla\CMS\Editor\Editor $editor, string $suffix, array $livePositions, array $values): string
    {
        $stale = [];

        foreach ($values as $position => $value) {
            // `_`-prefixed keys (e.g. _show_<slug> section flags) are state,
            // not content; never treat them as stale positions.
            if (str_starts_with((string) $position, '_')) {
                continue;
            }

            if (!\in_array($position, $livePositions, true) && $this->hasContent(html: (string) $value)) {
                $stale[$position] = (string) $value;
            }
        }

        if (empty($stale)) {
            return '';
        }

        $html = '<div class="aec-stale">';
        $html .= '<div class="aec-stale-head"><span class="icon-warning" aria-hidden="true"></span> '
            . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_STALE_POSITIONS_HEADING'), ENT_COMPAT, 'UTF-8') . '</div>';
        $html .= '<div class="aec-stale-hint">'
            . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_STALE_POSITIONS_HINT'), ENT_COMPAT, 'UTF-8') . '</div>';

        foreach ($stale as $position => $value) {
            $html .= $this->renderEditorSlot(editor: $editor, suffix: $suffix, position: $position, value: $value);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * The variables palette: {token} chips the JS inserts at the caret of
     * the focused slot. Documents expose the frozen-entity tokens
     * DocumentRenderer resolves at render time.
     *
     *
     * @since  1.3.0
     */
    private function renderVarsPalette(): string
    {
        $tokens = [
            '{number}' => Text::_('COM_ALFA_DOCUMENT_TOKEN_NUMBER'),
            '{date}' => Text::_('COM_ALFA_DOCUMENT_TOKEN_DATE'),
            '{order_id}' => Text::_('COM_ALFA_DOCUMENT_TOKEN_ORDER_ID'),
            '{buyer_name}' => Text::_('COM_ALFA_DOCUMENT_TOKEN_BUYER_NAME'),
            '{seller_name}' => Text::_('COM_ALFA_DOCUMENT_TOKEN_SELLER_NAME'),
        ];

        $html = '<aside class="aec-vars">';
        $html .= '<div class="aec-vars-panel">';
        $html .= '<div class="aec-vars-head">' . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PICKER_TITLE'), ENT_COMPAT, 'UTF-8')
            . ' <small>' . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PICKER_HINT'), ENT_COMPAT, 'UTF-8') . '</small></div>';
        $html .= '<input type="text" class="aec-vars-search" placeholder="'
            . htmlspecialchars(Text::_('JSEARCH_FILTER'), ENT_COMPAT, 'UTF-8') . '">';
        $html .= '<div class="aec-vars-scroll">';

        $html .= '<div class="aec-vars-group">';
        $html .= '<div class="aec-vars-glabel">' . htmlspecialchars(Text::_('COM_ALFA_DOCUMENT_TOKEN_GROUP_DOCUMENT'), ENT_COMPAT, 'UTF-8') . '</div>';
        $html .= '<div class="aec-chips">';

        foreach ($tokens as $token => $label) {
            $tokenEsc = htmlspecialchars($token, ENT_COMPAT, 'UTF-8');
            $labelEsc = htmlspecialchars($label, ENT_COMPAT, 'UTF-8');
            $html .= '<button type="button" class="aec-chip" data-token="' . $tokenEsc . '" title="' . $labelEsc . '">'
                . '<code>' . $tokenEsc . '</code><span>' . $labelEsc . '</span></button>';
        }

        $html .= '</div></div>';

        $html .= '</div>'; // scroll
        $html .= '<div class="aec-vars-foot">' . htmlspecialchars(Text::_('COM_ALFA_DOCUMENT_TOKEN_PICKER_FOOT'), ENT_COMPAT, 'UTF-8') . '</div>';
        $html .= '</div></aside>';

        return $html;
    }

    //  Private: helpers

    /**
     * Tab chip markup: flag image + native name.
     *
     *
     * @since  1.3.0
     */
    private function langChip(object $language): string
    {
        $flag = !empty($language->image)
            ? HTMLHelper::_(
                'image',
                'mod_languages/' . $language->image . '.gif',
                $language->title_native ?? '',
                ['style' => 'margin-right:6px;vertical-align:middle;'],
                true,
            )
            : '<span class="badge bg-secondary me-1">' . htmlspecialchars($language->sef ?? '', ENT_COMPAT, 'UTF-8') . '</span>';

        return $flag . htmlspecialchars($language->title_native ?? '', ENT_COMPAT, 'UTF-8');
    }

    /**
     * Build the flat input name: jform[<fieldname>_<langSnake>_<slot>].
     *
     * @param string $suffix Snake-cased lang code.
     * @param string $position Position name.
     *
     *
     * @since  1.3.0
     */
    private function buildInputName(string $suffix, string $position): string
    {
        $base = $this->group !== null && $this->group !== ''
            ? 'jform[' . $this->group . ']'
            : 'jform';

        return $base . '[' . $this->fieldname . '_' . $suffix . '_' . $position . ']';
    }

    /**
     * Humanise a partial id into a struct-block label: the last
     * dot-segment with underscores/hyphens turned to spaces and
     * title-cased ('documents.partials.taxes_totals' -> "Taxes Totals").
     *
     * @param string $layoutId e.g. 'documents.partials.lines'.
     *
     *
     * @since  1.3.0
     */
    private function humaniseLayoutId(string $layoutId): string
    {
        $segments = explode('.', $layoutId);
        $last = (string) end($segments);
        $words = trim(str_replace(['_', '-'], ' ', $last));

        if ($words === '') {
            return Text::_('COM_ALFA_ORDEREMAIL_STRUCT_GENERIC');
        }

        return ucwords($words);
    }

    /**
     * Reduce a partial id to a flat slug for the show-flag key:
     * lower-cased, every run of non-alphanumerics -> single underscore.
     * Mirrors DocumentRenderer's render-time slug so flags round-trip.
     *
     *
     * @since  1.3.0
     */
    private function structSlug(string $layoutId): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $layoutId), '_'));
    }

    /**
     * Is a struct block enabled? Reads `_show_<slug>` from any language's
     * saved values (the flag is fanned out identically across languages).
     * Default ON: only an explicit '0' hides.
     *
     * @param array<string, array<string, string>> $values
     *
     *
     * @since  1.3.0
     */
    private function structEnabled(array $values, string $slug): bool
    {
        foreach ($values as $perLang) {
            if (\is_array($perLang) && \array_key_exists('_show_' . $slug, $perLang)) {
                return (string) $perLang['_show_' . $slug] !== '0';
            }
        }

        return true;
    }

    /**
     * Human label for a position (COM_ALFA_DOCUMENT_POSITION_<UPPER>),
     * falling back to a title-cased name so layout-added slots read
     * sensibly without a language string.
     *
     *
     * @since  1.3.0
     */
    private function positionLabel(string $position): string
    {
        $key = 'COM_ALFA_DOCUMENT_POSITION_' . strtoupper($position);
        $label = Text::_($key);

        if ($label === $key) {
            return ucfirst(str_replace('_', ' ', $position));
        }

        return $label;
    }

    /**
     * Resolve the layout driving discovery: the sibling `layout`
     * selection, else the doc_type default.
     *
     *
     * @since  1.3.0
     */
    private function resolveSelectedLayout(): string
    {
        $layoutId = trim((string) $this->form->getValue('layout'));

        if ($layoutId !== '') {
            return $layoutId;
        }

        $docType = (string) ($this->form->getValue('doc_type') ?: DocumentHelper::TYPE_INVOICE);

        // Registry default resolution incl. the generic fallback, so the
        // composer discovers slots even for types without shipped layouts.
        return DocumentRenderer::defaultLayoutId(docType: $docType);
    }

    /**
     * Does a value carry meaningful content? Strips tags/scripts via
     * AlfaHelper::cleanContent; media-only values count as content.
     *
     *
     * @since  1.3.0
     */
    private function hasContent(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (AlfaHelper::cleanContent(html: $html, removeTags: true, removeScripts: true) !== '') {
            return true;
        }

        return (bool) preg_match('/<(img|iframe|video|source|audio|embed)\b/i', $html);
    }

    /**
     * Active content languages, memoised for the render pass.
     *
     * @return array<string, object>
     *
     * @since  1.3.0
     */
    private function getLanguages(): array
    {
        if ($this->languagesCache === null) {
            $this->languagesCache = LanguageHelper::getLanguages('lang_code') ?: [];
        }

        return $this->languagesCache;
    }

    /**
     * 'en-GB' -> 'en_gb' (form-key suffix).
     *
     *
     * @since  1.3.0
     */
    private function snakeLang(string $langCode): string
    {
        return strtolower(str_replace('-', '_', $langCode));
    }

    /**
     * Load the shared composer CSS/JS (the email composer asset; the
     * markup contract is identical).
     *
     *
     * @since  1.3.0
     */
    private function ensureAssetsLoaded(): void
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useStyle('com_alfa.email-positions')
            ->useScript('com_alfa.email-positions');
    }
}
