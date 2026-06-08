<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderEmailHelper;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\EditorField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use SimpleXMLElement;
use Throwable;

/**
 * EmailPositionsField — "email composer"
 *
 * Owns the full per-status, per-language email configuration for ONE
 * recipient role (customer or admin), rendered as a self-contained
 * composer (the OpeningtimesField pattern: custom getInput + custom
 * tabs + own CSS/JS asset — no joomla-tab).
 *
 * Layout:
 *   • Custom language tabs (one per installed content language) — no
 *     joomla-tab, so no MutationObserver / recall freeze.
 *   • Per language: a single-line Subject text input, then one Joomla
 *     WYSIWYG editor per position, with the items/totals block placed in
 *     the SAME order the layout renders them (the discovered sequence
 *     from OrderEmailHelper::discoverSequence).
 *   • A "variables" palette that inserts {tokens} into the focused editor
 *     / subject input (else copies to clipboard).
 *
 * The editors / subject input are the real form inputs, named
 * `jform[<fieldname>_<langCode>_<position>]`;
 * OrderstatusModel::bundleEmailPositionsIntoLanguageKeys harvests those
 * flat keys into the per-language JSON column.
 *
 * Required XML attributes
 * -----------------------
 *   multilingual_table  Base table for the per-language JSON column.
 *   multilingual_pk     PK column in the per-language tables.
 *
 * Optional XML attributes
 * -----------------------
 *   email_layout        Fallback layout id driving discovery when no
 *                       sibling email_layout_<recipient> selection
 *                       exists. Defaults to 'emails.order.default'.
 *
 * @since   1.0.4
 */
class EmailPositionsField extends EditorField
{
    /**
     * Field type id — must match filename + XML `type` attribute.
     *
     * @var string
     */
    public $type = 'EmailPositions';

    /**
     * Default layout id when none is provided in the XML.
     */
    private const DEFAULT_LAYOUT_ID = 'emails.order.default';

    /**
     * User-state key where FormController::reload stashes the submitted
     * (unsaved) form data before redirecting. Single-consumer: this field
     * is used only on the orderstatus edit form (context 'orderstatus').
     */
    private const EDIT_STATE_KEY = 'com_alfa.edit.orderstatus.data';

    /**
     * Active languages cache for the lifetime of one render pass.
     *
     * @var array<string, object>|null
     */
    private ?array $languagesCache = null;

    // =========================================================================
    //  Lifecycle
    // =========================================================================

    /**
     * Load existing position values from the per-language tables into
     *   $this->value = [ '<lang_snake>' => [ '<position>' => '<html>' ] ].
     *
     * Overlays submitted-but-unsaved values (from a form reload) on top
     * of the DB so content survives the layout-change reload.
     *
     * @param SimpleXMLElement $element XML field definition.
     * @param mixed $value Form-bound value (ignored).
     * @param string|null $group Field group.
     *
     *
     * @since   1.0.4
     */
    public function setup(SimpleXMLElement $element, $value, $group = null): bool
    {
        $result = parent::setup($element, $value, $group);

        $itemId = (int) $this->form->getValue('id', null);
        $table = (string) ($this->element['multilingual_table'] ?? '');
        $pk = (string) ($this->element['multilingual_pk'] ?? '');
        $column = $this->fieldname;
        $layoutId = $this->resolveSelectedLayout();

        $languages = $this->getLanguages();
        $perLang = [];

        // FormController::reload stashes the POSTed jform in user state and
        // REDIRECTS (GET), so on the reloaded request the unsaved data lives
        // in user state, not POST — read state first, fall back to POST for
        // the in-place redraw case.
        $app = Factory::getApplication();
        $posted = (array) $app->getUserState(self::EDIT_STATE_KEY, []);

        if (empty($posted)) {
            $posted = (array) $app->getInput()->post->get('jform', [], 'raw');
        }

        foreach ($languages as $langCode => $language) {
            $suffix = $this->snakeLang(langCode: (string) $langCode);

            // Virgin = the per-language aux row does not exist yet — the first
            // appearance of this status in this language. A saved status always
            // has a row (the `name` field is required), so row-presence is a
            // reliable "has been saved in this language" flag. Virgin languages
            // seed from the layout's defaults; saved languages NEVER re-seed,
            // so an emptied position stays empty. The "Restore defaults" button
            // is the only way to reseed after a save.
            $rowExists = $itemId > 0 && $table !== '' && $pk !== ''
                && $this->langRowExists(table: $table . '_' . $suffix, pk: $pk, itemId: $itemId);

            if ($rowExists) {
                $json = $this->loadJsonValue(table: $table . '_' . $suffix, pk: $pk, column: $column, itemId: $itemId);
                $decoded = $json !== null && $json !== '' ? json_decode($json, true) : null;
                $perLang[$suffix] = is_array($decoded) ? $decoded : [];
            } else {
                $perLang[$suffix] = OrderEmailHelper::defaultsForLanguage(layoutId: $layoutId, langTag: (string) $langCode);
            }

            // Overlay POSTed values (form reload / in-place redraw): keys are
            // <fieldname>_<suffix>_<position>. The admin's unsaved edits win
            // over both the DB and the seeded defaults.
            $prefix = $column . '_' . $suffix . '_';

            foreach ($posted as $key => $val) {
                if (str_starts_with((string) $key, $prefix)) {
                    $perLang[$suffix][substr((string) $key, strlen($prefix))] = (string) $val;
                }
            }
        }

        $this->value = $perLang;

        return $result;
    }

    // =========================================================================
    //  Rendering
    // =========================================================================

    /**
     * Build the composer: custom language tabs + one email-shaped
     * envelope per language + the variables palette.
     *
     *
     * @since   1.0.4
     */
    protected function getInput(): string
    {
        $layoutId = $this->resolveSelectedLayout();
        $sequence = OrderEmailHelper::discoverSequence($layoutId);
        $languages = $this->getLanguages();
        $values = is_array($this->value) ? $this->value : [];

        $this->ensureAssetsLoaded();

        if (empty($languages)) {
            return '';
        }

        $editor = $this->getEditor();
        $idEsc = htmlspecialchars($this->id, ENT_COMPAT, 'UTF-8');

        // Emit this field's per-language defaults for the "Restore defaults"
        // button. Keyed by field id (so customer + admin composers don't
        // collide); the JS fills the active language tab's editors + subject.
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
            $html .= $this->renderEnvelope(
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
     * Custom language tabs (flag + native name). A single language still
     * renders a single tab so the JS contract is uniform.
     *
     * @param array<string, object> $languages
     *
     *
     * @since   1.0.4
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
        $html .= $this->renderRestoreButton();
        $html .= $this->renderActions();
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * "Restore defaults" button — always shown (even before first save). The
     * JS reads this field's emitted defaults and refills the ACTIVE language
     * tab only, after a confirm. Scoped to one language so it never clobbers
     * other tabs the admin has already authored.
     *
     *
     * @since   1.0.4
     */
    private function renderRestoreButton(): string
    {
        return '<button type="button" class="btn btn-sm btn-outline-secondary aec-restore-btn"'
            . ' data-field="' . htmlspecialchars($this->id, ENT_COMPAT, 'UTF-8') . '">'
            . '<span class="icon-loop" aria-hidden="true"></span> '
            . Text::_('COM_ALFA_ORDEREMAIL_RESTORE_DEFAULTS_BUTTON') . '</button>';
    }

    /**
     * Push this field's per-language seed defaults into script options under
     * 'alfaEmailDefaults'[fieldId][langSuffix] = { position: html }, and
     * register the confirm string for the JS.
     *
     * @param string $layoutId Active layout id.
     * @param array<string, object> $languages Installed content languages.
     *
     *
     * @since   1.0.4
     */
    private function emitDefaults(string $layoutId, array $languages): void
    {
        $defaults = [];

        foreach ($languages as $langCode => $language) {
            $suffix = $this->snakeLang(langCode: (string) $langCode);
            $defaults[$suffix] = OrderEmailHelper::defaultsForLanguage(
                layoutId: $layoutId,
                langTag:  (string) $langCode,
            );
        }

        Factory::getApplication()->getDocument()
            ->addScriptOptions('alfaEmailDefaults', [$this->id => $defaults]);

        Text::script('COM_ALFA_ORDEREMAIL_RESTORE_DEFAULTS_CONFIRM');
    }

    /**
     * Preview + Send-test buttons, right-aligned in the tab bar. They
     * open the per-recipient modals rendered by orderstatus/edit.php
     * (keyed by recipient derived from this field's name). Shown only
     * once the status exists (a saved id is needed to preview/test).
     *
     *
     * @since   1.0.4
     */
    private function renderActions(): string
    {
        $statusId = (int) $this->form->getValue('id', null);

        if ($statusId <= 0) {
            return '';
        }

        $prefix = 'email_positions_';
        $recipient = str_starts_with($this->fieldname, $prefix)
            ? substr($this->fieldname, strlen($prefix))
            : 'customer';
        $r = htmlspecialchars($recipient, ENT_COMPAT, 'UTF-8');

        // Base task URLs WITHOUT a language — the JS appends &lang=<active
        // composer tab> on click, so Preview/Send-test always target the
        // language you're editing (no in-modal language/recipient pickers).
        $root = Uri::base(true) . '/index.php?option=com_alfa&id=' . $statusId . '&recipient=' . $recipient;
        $previewBase = htmlspecialchars($root . '&task=orderstatus.previewEmail&format=raw', ENT_COMPAT, 'UTF-8');
        $testBase = htmlspecialchars($root . '&task=orderstatus.sendTestForm&tmpl=component', ENT_COMPAT, 'UTF-8');

        return '<div class="aec-actions">'
            . '<a href="#alfaEmailPreviewModal_' . $r . '" data-bs-toggle="modal"'
            . ' class="btn btn-sm btn-outline-secondary aec-preview-btn"'
            . ' data-frame="aecPreviewFrame_' . $r . '" data-base="' . $previewBase . '">'
            . '<span class="icon-eye" aria-hidden="true"></span> ' . Text::_('COM_ALFA_ORDERSTATUS_PREVIEW_EMAIL_BUTTON') . '</a>'
            . '<a href="#alfaEmailSendTestModal_' . $r . '" data-bs-toggle="modal"'
            . ' class="btn btn-sm btn-primary aec-test-btn"'
            . ' data-frame="aecTestFrame_' . $r . '" data-base="' . $testBase . '">'
            . '<span class="icon-envelope" aria-hidden="true"></span> ' . Text::_('COM_ALFA_ORDEREMAIL_TEST_BUTTON') . '</a>'
            . '</div>';
    }

    /**
     * One language's editing canvas — subject + the layout's positions
     * (Joomla WYSIWYG editors) and the items/totals struct block, placed
     * in the order the layout requests them (the discovered sequence).
     *
     * @param \Joomla\CMS\Editor\Editor $editor Editor instance.
     * @param string $suffix Snake-cased lang code.
     * @param array<int, array{type:string,name?:string}> $sequence Discovered order.
     * @param array<string, string> $values Saved values for this lang.
     * @param bool $active Visible panel?
     *
     *
     * @since   1.0.4
     */
    private function renderEnvelope(
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

        // Subject — plain single-line input (not in the layout body).
        $html .= $this->renderSubjectInput(
            suffix: $suffix,
            value:  (string) ($values[OrderEmailHelper::SUBJECT_POSITION] ?? ''),
        );

        // Walk the sequence: position → editor slot; struct → items/totals
        // block, exactly where the layout renders them.
        $positions = [];
        foreach ($sequence as $entry) {
            if (($entry['type'] ?? '') === 'struct') {
                $sid = (string) ($entry['name'] ?? '');
                $html .= $this->renderStruct(
                    layoutId: $sid,
                    enabled:  $structState[$this->structSlug(layoutId: $sid)] ?? true,
                );
                continue;
            }

            $position = (string) ($entry['name'] ?? '');
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
     * Single-line subject input (plain text, click-to-insert target).
     *
     *
     *
     * @since   1.0.4
     */
    private function renderSubjectInput(string $suffix, string $value): string
    {
        $name = $this->buildInputName(suffix: $suffix, position: OrderEmailHelper::SUBJECT_POSITION);
        $id = $this->id . '_' . $suffix . '_' . OrderEmailHelper::SUBJECT_POSITION;
        $label = htmlspecialchars($this->positionLabel(position: OrderEmailHelper::SUBJECT_POSITION), ENT_COMPAT, 'UTF-8');
        $ph = htmlspecialchars($this->positionPlaceholder(position: OrderEmailHelper::SUBJECT_POSITION), ENT_COMPAT, 'UTF-8');

        return '<section class="aec-slot aec-slot--subject" data-pos="subject">'
            . '<div class="aec-slot-head"><span class="aec-tag">' . $label . '</span></div>'
            . '<input type="text" class="form-control aec-subject" data-aec-input="1"'
            . ' id="' . htmlspecialchars($id, ENT_COMPAT, 'UTF-8') . '"'
            . ' name="' . htmlspecialchars($name, ENT_COMPAT, 'UTF-8') . '"'
            . ' placeholder="' . $ph . '"'
            . ' value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . '</section>';
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
     * @since   1.0.4
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
        // buttons = false: hide the xtd-editor button row (Article/Image/…),
        // keep just the editor's own toolbar.
        $html .= $editor->display($name, $value, '100%', 200, 60, 8, false, $id);
        $html .= '</section>';

        return $html;
    }

    /**
     * The layout-owned items + totals block (not editable). Conveys that
     * the table is rendered by the layout, not authored here.
     *
     * @return string
     *
     * @since   1.0.4
     */
    /**
     * Per-section visibility state: slug => bool, one entry per structural
     * block the layout renders. Resolved once from the full saved values
     * (shared across languages; default ON). Feeds the hidden posting inputs
     * and the per-block eye toggles.
     *
     * @param array<int, array{type:string,name?:string}> $sequence
     * @param array<string, array<string,string>> $values
     *
     * @return array<string, bool>
     *
     * @since   1.0.4
     */
    private function collectStructState(array $sequence, array $values): array
    {
        $state = [];

        foreach ($sequence as $entry) {
            if (($entry['type'] ?? '') !== 'struct') {
                continue;
            }

            $slug = $this->structSlug(layoutId: (string) ($entry['name'] ?? ''));

            if ($slug !== '' && !isset($state[$slug])) {
                $state[$slug] = $this->structEnabled(values: $values, slug: $slug);
            }
        }

        return $state;
    }

    /**
     * Hidden posting inputs for the section eye-toggles — ONE per section,
     * emitted once (outside the language panels). The eye buttons inside the
     * struct blocks are JS controls that flip the matching input's value, so
     * there is exactly one posted value per section regardless of how many
     * language panels repeat the block.
     *
     * Posts as jform[<field>_showstruct_<slug>] = 1|0; the model fans it into
     * every language's JSON as _show_<slug>.
     *
     * @param array<string, bool> $structState
     *
     *
     * @since   1.0.4
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
     * Reduce a layout/partial id to a flat slug for the show-flag key:
     * lower-cased, every run of non-alphanumerics → single underscore.
     * Mirrors OrderEmailHelper's render-time slug so flags round-trip.
     *
     *
     *
     * @since   1.0.4
     */
    private function structSlug(string $layoutId): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $layoutId), '_'));
    }

    /**
     * Is a struct block enabled? Reads `_show_<slug>` from any language's
     * saved values (the flag is fanned out identically across languages).
     * Default ON: only an explicit '0' hides. Drives the eye toggle's
     * initial state per section.
     *
     * @param array<string, array<string,string>> $values
     *
     *
     * @since   1.0.4
     */
    private function structEnabled(array $values, string $slug): bool
    {
        foreach ($values as $perLang) {
            if (is_array($perLang) && array_key_exists('_show_' . $slug, $perLang)) {
                return (string) $perLang['_show_' . $slug] !== '0';
            }
        }

        return true;
    }

    /**
     * Render a structural (non-positioned) email block: a title derived from the
     * layout id, a hint, and an eye toggle whose data-slug links every per-language
     * copy of the block to the single shared visibility flag. With multiple
     * languages the toggle gains an "all languages" note to signal it isn't per-language.
     *
     * @param string $layoutId Layout/partial id, e.g. 'emails.partials.order_items'
     * @param bool $enabled Whether the block is currently shown
     *
     * @return string The block HTML
     *
     * @since   1.0.4
     */
    private function renderStruct(string $layoutId = '', bool $enabled = true): string
    {
        // Label derived from the rendered partial id itself — no hardcoded
        // family list, no per-block ini keys. Take the last dot-segment,
        // turn _/- into spaces and title-case it, faithful to the layout
        // file: 'emails.partials.order_items' → "Order Items",
        // 'emails.partials.order_payments' → "Order Payments". Empty id →
        // generic fallback.
        $title = $this->humaniseLayoutId(layoutId: $layoutId);
        $hint = Text::_('COM_ALFA_ORDEREMAIL_STRUCT_HINT');
        $slug = $this->structSlug(layoutId: $layoutId);

        // Eye toggle replaces the old static icon. data-slug links every copy
        // of this block (it repeats per language tab) + the hidden flag input,
        // so the JS dims them all and flips the single posted value together.
        // .aec-struct--off dims the block; the eye-slash icon invites reopen.
        $offClass = $enabled ? '' : ' aec-struct--off';
        $eyeIcon = $enabled ? 'icon-eye' : 'icon-eye-slash';

        // Visibility is per-recipient and SHARED across languages (one flag,
        // fanned to all). With 2+ languages the eye sits inside a language
        // panel, so signal that it isn't per-language: clearer tooltip + a
        // small "all languages" note. Single-language shops see neither.
        $multiLang = count($this->getLanguages()) > 1;
        $eyeTitle = htmlspecialchars(
            Text::_($multiLang ? 'COM_ALFA_ORDEREMAIL_SECTION_TOGGLE_ALL_LANGS' : 'COM_ALFA_ORDEREMAIL_SECTION_TOGGLE'),
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
     * Humanise a layout/partial id into a struct-block label: the last
     * dot-segment with underscores/hyphens turned to spaces and title-cased
     * — faithful to the layout file name. Falls back to a generic label for
     * an empty id.
     *
     * @param string $layoutId e.g. 'emails.partials.order_items'.
     *
     *
     * @since   1.0.4
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
     * "Carried over from a previous layout" — non-empty positions the
     * current layout no longer declares, rendered as editable slots so the
     * admin can migrate then clear them (empty ones self-clean on save).
     *
     * @param \Joomla\CMS\Editor\Editor $editor Editor instance.
     * @param string[] $livePositions
     * @param array<string, string> $values
     *
     *
     * @since   1.0.4
     */
    private function renderStale(\Joomla\CMS\Editor\Editor $editor, string $suffix, array $livePositions, array $values): string
    {
        $skip = array_merge([OrderEmailHelper::SUBJECT_POSITION], $livePositions);
        $stale = [];

        foreach ($values as $position => $value) {
            // `_`-prefixed keys (e.g. _show_<slug> section flags) are state,
            // not content — never treat them as stale positions.
            if (str_starts_with((string) $position, '_')) {
                continue;
            }

            if (!in_array($position, $skip, true) && $this->hasContent(html: (string) $value)) {
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
     * The variables palette — grouped {token} chips that the JS inserts
     * at the caret of the focused slot. Sourced from availableTokens().
     *
     *
     * @since   1.0.4
     */
    private function renderVarsPalette(): string
    {
        $groups = OrderEmailHelper::availableTokens();

        $groupLabels = [
            'order' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_GROUP_ORDER'),
            'user' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_GROUP_USER'),
            'fields' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_GROUP_FIELDS'),
            'site' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_GROUP_SITE'),
        ];

        $html = '<aside class="aec-vars">';
        $html .= '<div class="aec-vars-panel">';
        $html .= '<div class="aec-vars-head">' . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PICKER_TITLE'), ENT_COMPAT, 'UTF-8')
            . ' <small>' . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PICKER_HINT'), ENT_COMPAT, 'UTF-8') . '</small></div>';
        $html .= '<input type="text" class="aec-vars-search" placeholder="'
            . htmlspecialchars(Text::_('JSEARCH_FILTER'), ENT_COMPAT, 'UTF-8') . '">';
        $html .= '<div class="aec-vars-scroll">';

        foreach ($groups as $groupKey => $tokens) {
            if (empty($tokens)) {
                continue;
            }

            $html .= '<div class="aec-vars-group">';
            $html .= '<div class="aec-vars-glabel">' . htmlspecialchars($groupLabels[$groupKey] ?? $groupKey, ENT_COMPAT, 'UTF-8') . '</div>';
            $html .= '<div class="aec-chips">';

            foreach ($tokens as $token => $label) {
                $tokenEsc = htmlspecialchars($token, ENT_COMPAT, 'UTF-8');
                $labelEsc = htmlspecialchars($label, ENT_COMPAT, 'UTF-8');
                $html .= '<button type="button" class="aec-chip" data-token="' . $tokenEsc . '" title="' . $labelEsc . '">'
                    . '<code>' . $tokenEsc . '</code><span>' . $labelEsc . '</span></button>';
            }

            $html .= '</div></div>';
        }

        $html .= '</div>'; // scroll
        $html .= '<div class="aec-vars-foot">' . htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PICKER_FOOT'), ENT_COMPAT, 'UTF-8') . '</div>';
        $html .= '</div></aside>';

        return $html;
    }

    // =========================================================================
    //  Private — helpers
    // =========================================================================

    /**
     * Tab chip markup — flag image + native name.
     *
     *
     *
     * @since   1.0.4
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
     * Build the flat input name: jform[<fieldname>_<langCode>_<position>].
     *
     * @param string $suffix Snake-cased lang code.
     * @param string $position Position name (incl. 'subject').
     *
     *
     * @since   1.0.4
     */
    private function buildInputName(string $suffix, string $position): string
    {
        $base = $this->group !== null && $this->group !== ''
            ? 'jform[' . $this->group . ']'
            : 'jform';

        return $base . '[' . $this->fieldname . '_' . $suffix . '_' . $position . ']';
    }

    /**
     * Human label for a position (COM_ALFA_ORDEREMAIL_POSITION_<UPPER>),
     * falling back to a title-cased name.
     *
     *
     *
     * @since   1.0.4
     */
    private function positionLabel(string $position): string
    {
        $key = 'COM_ALFA_ORDEREMAIL_POSITION_' . strtoupper($position);
        $label = Text::_($key);

        if ($label === $key) {
            return ucfirst(str_replace('_', ' ', $position));
        }

        return $label;
    }

    /**
     * Placeholder text for an empty slot (COM_ALFA_ORDEREMAIL_PH_<UPPER>),
     * falling back to a generic hint.
     *
     *
     *
     * @since   1.0.4
     */
    private function positionPlaceholder(string $position): string
    {
        $key = 'COM_ALFA_ORDEREMAIL_PH_' . strtoupper($position);
        $ph = Text::_($key);

        return $ph === $key ? Text::_('COM_ALFA_ORDEREMAIL_PH_DEFAULT') : $ph;
    }

    /**
     * Resolve the layout driving discovery — the sibling
     * email_layout_<recipient> selection, else the XML / class default.
     *
     *
     * @since   1.0.4
     */
    private function resolveSelectedLayout(): string
    {
        $prefix = 'email_positions_';
        $final = (string) ($this->element['email_layout'] ?? self::DEFAULT_LAYOUT_ID);

        if (str_starts_with($this->fieldname, $prefix)) {
            $recipient = substr($this->fieldname, strlen($prefix));
            $selectorVal = (string) ($this->form->getValue('email_layout_' . $recipient, $this->group) ?? '');

            if ($selectorVal !== '') {
                $final = $selectorVal;
            }
        }

        return $final;
    }

    /**
     * Does a value carry meaningful content? Strips tags/scripts via
     * AlfaHelper::cleanContent; media-only values count as content.
     *
     *
     *
     * @since   1.0.4
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
     * @since   1.0.4
     */
    private function getLanguages(): array
    {
        if ($this->languagesCache === null) {
            $this->languagesCache = LanguageHelper::getLanguages('lang_code') ?: [];
        }

        return $this->languagesCache;
    }

    /**
     * 'en-GB' → 'en_gb' (per-language table + form-key suffix).
     *
     *
     *
     * @since   1.0.4
     */
    private function snakeLang(string $langCode): string
    {
        return strtolower(str_replace('-', '_', $langCode));
    }

    /**
     * Read a single JSON column value from a per-language table.
     *
     *
     * @return string|null Raw JSON, '' when the column is empty, or null
     *                     on a missing row / error.
     *
     * @since   1.0.4
     */
    private function loadJsonValue(string $table, string $pk, string $column, int $itemId): ?string
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName($column))
                ->from($db->quoteName($table))
                ->where($db->quoteName($pk) . ' = ' . (int) $itemId);

            $db->setQuery(query: $query);

            $result = $db->loadResult();

            return $result === null ? null : (string) $result;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Does the per-language aux row exist for this status? Used to detect a
     * language's first appearance (virgin → seed defaults). Distinct from a
     * present-but-empty positions column, which must NOT reseed.
     *
     * @param string $table Per-language aux table (with `_<suffix>`).
     * @param string $pk PK column in the aux table.
     * @param int $itemId Status id.
     *
     * @return bool True when a row exists; false on absence / error.
     *
     * @since   1.0.4
     */
    private function langRowExists(string $table, string $pk, int $itemId): bool
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName($table))
                ->where($db->quoteName($pk) . ' = ' . (int) $itemId);

            $db->setQuery(query: $query, offset: 0, limit: 1);

            return $db->loadResult() !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Push the composer's CSS + JS into the web-asset manager.
     *
     *
     * @since   1.0.4
     */
    private function ensureAssetsLoaded(): void
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useStyle('com_alfa.email-positions')
            ->useScript('com_alfa.email-positions');
    }
}
