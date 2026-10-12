<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;
use RuntimeException;
use Throwable;

/**
 * DocumentRenderer: THE single rendering entry point for documents.
 *
 * One definition, three consumers: the browser print view now,
 * the dompdf renderer and the email attachment; all call
 * render() and get the identical standalone HTML page, composed from the
 * FROZEN entity (header + lines + VAT summary). Live catalog/order data
 * never enters a rendered document.
 *
 * Layout resolution: template override -> per-series `layout`
 * column -> `documents.<doc_type>.default`. Layouts obey the dompdf-safe
 * subset documented in layouts/documents/partials/style.php (header docblock).
 *
 * @since  1.3.0
 */
final class DocumentRenderer
{
    /**
     * Language-constant prefix for shipped position defaults:
     * COM_ALFA_DOCUMENT_DEFAULT_<POSITION> (the email-composer naming
     * convention, documented in the Templating tab's help note).
     */
    private const DEFAULT_KEY_PREFIX = 'COM_ALFA_DOCUMENT_DEFAULT_';

    /**
     * Render a document to its complete standalone HTML page.
     *
     * @param int $documentId The document id (any status; voided prints
     *                        with the voided banner).
     *
     * @return string Full HTML (doctype -> </html>).
     *
     * @throws RuntimeException When the document does not exist.
     *
     * @since  1.3.0
     */
    public static function render(int $documentId): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_documents'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $documentId);
        $db->setQuery(query: $query);
        $document = $db->loadObject();

        if ($document === null) {
            throw new RuntimeException("DocumentRenderer: document #{$documentId} does not exist.");
        }

        // Labels print in the DOCUMENT's language: merge that language's
        // com_alfa strings over the current ones (falls back to en-GB
        // while translations are pending).
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_alfa', JPATH_ADMINISTRATOR, (string) $document->language, true);

        $series = self::loadSeries(db: $db, seriesId: (int) $document->id_series);
        $layoutId = self::layoutId(document: $document, series: $series);

        $displayData = [
            'document' => $document,
            'series' => $series,
            'lines' => self::loadLines(db: $db, documentId: $documentId),
            'taxes' => self::loadTaxes(db: $db, documentId: $documentId),
            'corrects' => $document->id_corrects
                ? self::loadHeader(db: $db, documentId: (int) $document->id_corrects)
                : null,
        ];

        // Merchant text slots (the email positions pattern): the layout asks
        // via $position()/$hasPosition(); content is authored per series and
        // language, tokens resolved here. Empty slot = the block renders
        // nothing; positions surround the frozen core, never replace it.
        $content = self::positionContent(series: $series, document: $document, layoutId: $layoutId);
        $displayData['position'] = static fn (string $name): string => $content[$name] ?? '';
        $displayData['hasPosition'] = static fn (string $name): bool => trim($content[$name] ?? '') !== '';

        // Structural partials route through this closure so the composer's
        // per-section eye toggles apply: a partial whose `_show_<slug>` flag
        // is '0' in the series' positions JSON renders nothing, no matter the
        // layout. Default ON (absent flag -> shown). Slug transform mirrors
        // DocumentPositionsField::structSlug so flags round-trip.
        $flags = self::structFlags(series: $series);
        $dataContext = $displayData;
        $displayData['render'] = static function (string $layoutId, ?array $data = null) use ($flags, $dataContext): string {
            $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $layoutId), '_'));

            if (($flags[$slug] ?? '1') === '0') {
                return '';
            }

            return (string) LayoutHelper::render(
                $layoutId,
                $data ?? $dataContext,
                null,
                ['component' => 'com_alfa', 'client' => 1],
            );
        };

        return (string) LayoutHelper::render(
            $layoutId,
            $displayData,
            null,
            ['component' => 'com_alfa', 'client' => 1],
        );
    }

    /**
     * Discover a document layout's editing sequence: the merchant-text
     * positions AND the structural partials, in the order the layout
     * emits them, the exact mechanism the email composer uses
     * (EmailService::discoverSequence). Memoised per request.
     *
     * Renders the layout once with collector closures over a dummy
     * document skeleton: $position() records a position entry, $render()
     * records a struct entry (and renders nothing).
     *
     * @param string $layoutId Layout id (e.g. 'documents.invoice.default').
     *
     * @return array<int, array{type: string, name: string}> Ordered entries,
     *                                                       type 'position'|'struct'; struct names are partial layout ids.
     *
     * @since  1.3.0
     */
    public static function discoverSequence(string $layoutId): array
    {
        static $cache = [];

        if (isset($cache[$layoutId])) {
            return $cache[$layoutId];
        }

        $seq = [];
        $seen = [];

        $displayData = [
            'document' => self::skeletonDocument(),
            'series' => null,
            'lines' => [],
            'taxes' => [],
            'corrects' => null,
            'position' => static function (string $name) use (&$seq, &$seen): string {
                if ($name !== '' && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $seq[] = ['type' => 'position', 'name' => $name];
                }

                return '';
            },
            // TRUE so position calls inside guarded blocks still execute.
            'hasPosition' => static fn (string $name): bool => true,
            // Struct collector: records the partial id, renders nothing
            // (positions live at the composition level, so nothing inside
            // the partial needs to execute). Deduped per id.
            'render' => static function (string $id, ?array $data = null) use (&$seq, &$seen): string {
                if ($id !== '' && !isset($seen['struct:' . $id])) {
                    $seen['struct:' . $id] = true;
                    $seq[] = ['type' => 'struct', 'name' => $id];
                }

                return '';
            },
        ];

        try {
            LayoutHelper::render($layoutId, $displayData, null, ['component' => 'com_alfa', 'client' => 1]);
        } catch (Throwable) {
            // A broken custom layout must not take the series form down;
            // it simply exposes no slots.
            $seq = [];
        }

        return $cache[$layoutId] = $seq;
    }

    /**
     * Merchant-text position names a layout asks for, in layout order:
     * the position entries of discoverSequence().
     *
     * @param string $layoutId Layout id (e.g. 'documents.invoice.default').
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public static function discoverPositions(string $layoutId): array
    {
        $names = [];

        foreach (self::discoverSequence(layoutId: $layoutId) as $entry) {
            if ($entry['type'] === 'position') {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    /**
     * Seedable default content for ONE position in ONE content language.
     *
     * Resolves COM_ALFA_DOCUMENT_DEFAULT_<POSITION> through a language
     * fallback chain (requested -> en-GB -> first installed content
     * language) with the untranslated-key guard, so a raw COM_ALFA_...
     * constant is never seeded or printed. Defaults are plain HTML
     * carrying document tokens (e.g. {number}); tokens resolve at
     * render time. The email-composer mechanism, verbatim.
     *
     * @param string $position Position name (e.g. 'generated_note').
     * @param string $langTag Requested content language (e.g. 'el-GR').
     *
     * @return string Default HTML, or '' when no language in the chain
     *                translates the key.
     *
     * @since  1.3.0
     */
    public static function defaultContent(string $position, string $langTag): string
    {
        return MultilingualHelper::firstTranslated(
            key:     self::DEFAULT_KEY_PREFIX . strtoupper($position),
            langTag: $langTag,
        );
    }

    /**
     * Every seedable default for a language: each position the layout
     * declares, keyed by position name; empty defaults omitted so seeding
     * and "Restore defaults" only touch meaningful slots.
     *
     * @param string $layoutId Layout whose positions to seed.
     * @param string $langTag Content language to resolve defaults in.
     *
     * @return array<string, string> position => default HTML.
     *
     * @since  1.3.0
     */
    public static function defaultsForLanguage(string $layoutId, string $langTag): array
    {
        $defaults = [];

        foreach (self::discoverPositions(layoutId: $layoutId) as $position) {
            $value = self::defaultContent(position: $position, langTag: $langTag);

            if ($value !== '') {
                $defaults[$position] = $value;
            }
        }

        return $defaults;
    }

    /**
     * Per-section visibility flags from the series' positions JSON:
     * slug => '0'|'1'. The composer fans one flag per structural block
     * into every language's payload as `_show_<slug>`; any occurrence
     * wins (they are written identically across languages).
     *
     * @param object|null $series The series row (carries positions JSON).
     *
     * @return array<string, string>
     *
     * @since  1.3.0
     */
    private static function structFlags(?object $series): array
    {
        $all = json_decode((string) ($series->positions ?? ''), true);
        $flags = [];

        if (!\is_array($all)) {
            return $flags;
        }

        foreach ($all as $perLang) {
            if (!\is_array($perLang)) {
                continue;
            }

            foreach ($perLang as $key => $value) {
                if (str_starts_with((string) $key, '_show_')) {
                    $flags[substr((string) $key, 6)] = (string) $value;
                }
            }
        }

        return $flags;
    }

    /**
     * Resolve the slot content for a document: the series-authored text in
     * the document's language (site default, then any authored language as
     * fallbacks), the shipped defaults for slots the series never touched,
     * and the document tokens substituted.
     *
     * @param object|null $series The series row (carries positions JSON).
     * @param object $document The document row (token source).
     * @param string $layoutId The resolved layout (declares the slots).
     *
     * @return array<string, string> position name => resolved HTML.
     *
     * @since  1.3.0
     */
    private static function positionContent(?object $series, object $document, string $layoutId): array
    {
        $all = json_decode((string) ($series->positions ?? ''), true);
        $all = \is_array($all) ? $all : [];

        // Per-slot language fallback: document language -> site default ->
        // any authored language. An untranslated slot shows the fallback
        // text rather than silently disappearing; a slot left empty in
        // EVERY language stays hidden.
        $languageChain = array_unique(array_filter([
            (string) $document->language,
            DocumentHelper::defaultLanguageTag(),
            ...array_keys($all),
        ]));

        $content = [];

        foreach ($languageChain as $langTag) {
            foreach ((array) ($all[$langTag] ?? []) as $slot => $html) {
                // `_`-prefixed keys (e.g. _show_<slug> section flags) are
                // state, not content.
                if (str_starts_with((string) $slot, '_')) {
                    continue;
                }

                if (trim((string) $html) !== '' && !isset($content[$slot])) {
                    $content[$slot] = (string) $html;
                }
            }
        }

        // Shipped defaults (COM_ALFA_DOCUMENT_DEFAULT_<SLOT>) for slots the
        // series has NO key for in any language, i.e. never seen by the
        // composer. A key that exists but is empty was deliberately cleared
        // by the admin and stays hidden (the email-composer semantics).
        foreach (self::discoverPositions(layoutId: $layoutId) as $slot) {
            if (isset($content[$slot])) {
                continue;
            }

            $authored = false;

            foreach ($all as $perLang) {
                if (\is_array($perLang) && \array_key_exists($slot, $perLang)) {
                    $authored = true;
                    break;
                }
            }

            if (!$authored) {
                $default = self::defaultContent(position: $slot, langTag: (string) $document->language);

                if ($default !== '') {
                    $content[$slot] = $default;
                }
            }
        }

        // Every slot these land in is HTML (the print view and the PDF), and the
        // buyer's name is whatever the customer typed at checkout; escaped here
        // rather than at each slot, since the map has no plain-text consumer.
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $tokens = [
            '{number}' => $escape($document->number_formatted),
            '{date}' => $escape($document->issue_date_local),
            '{order_id}' => $escape($document->id_order),
            '{buyer_name}' => $escape($document->buyer_name),
            '{seller_name}' => $escape($document->seller_name),
        ];

        return array_map(
            static fn (string $html): string => strtr($html, $tokens),
            $content,
        );
    }

    /**
     * Dummy document row for position discovery: every field the shipped
     * partials read, with renderable empties.
     *
     *
     * @since  1.3.0
     */
    private static function skeletonDocument(): object
    {
        return (object) [
            'id' => 0, 'doc_type' => DocumentHelper::TYPE_INVOICE, 'status' => 1, 'is_combined' => 0,
            'number_formatted' => '', 'issue_date_local' => '2000-01-01', 'id_order' => 0,
            'id_corrects' => null, 'language' => 'en-GB', 'currency_code' => '',
            'buyer_name' => '', 'buyer_vat_number' => '', 'buyer_tax_office' => '',
            'buyer_activity' => '', 'buyer_country' => '', 'buyer_email' => '', 'buyer_address' => null,
            'seller_name' => '', 'seller_vat_number' => '', 'seller_tax_office' => '',
            'seller_activity' => '', 'seller_address' => null, 'seller_extra' => null,
            'payment_method_name' => '', 'authority_payment_type' => null,
            'movement_purpose' => null, 'carrier_name' => null, 'vehicle_number' => null,
            'movement_start' => null, 'ship_from' => null, 'ship_to' => null,
            'total_tax_excl' => 0.0, 'total_tax' => 0.0, 'total_tax_incl' => 0.0,
            'authority_mark' => null, 'authority_uid' => null, 'qr_url' => null,
            'void_reason' => null, 'note' => null, 'print_note' => null,
        ];
    }

    /**
     * Resolve the layout id for a document.
     *
     * @param object $document The document row.
     * @param object|null $series Its series row (carries the override).
     *
     * @return string Layout id, e.g. 'documents.invoice.default'.
     *
     * @since  1.3.0
     */
    public static function layoutId(object $document, ?object $series): string
    {
        if ($series !== null && trim((string) $series->layout) !== '' && self::layoutExists((string) $series->layout)) {
            return (string) $series->layout;
        }

        return self::defaultLayoutId(docType: (string) $document->doc_type);
    }

    /**
     * The default layout id for a document type: the registry row's
     * default_layout override, else `documents.<alias>.default`, else the
     * generic composition: package types render even before (or without)
     * shipping any layout of their own.
     *
     * @param string $docType The type alias.
     *
     * @return string A layout id guaranteed to resolve.
     *
     * @since  1.3.0
     */
    public static function defaultLayoutId(string $docType): string
    {
        $row = DocumentHelper::typeRow($docType);
        $override = trim((string) ($row->default_layout ?? ''));

        if ($override !== '' && self::layoutExists($override)) {
            return $override;
        }

        $candidate = 'documents.' . $docType . '.default';

        return self::layoutExists($candidate) ? $candidate : 'documents.generic.default';
    }

    /**
     * Whether a layout id resolves to a file: component layouts or an
     * enabled administrator template's overrides.
     *
     * @param string $layoutId Layout id in dot notation.
     *
     *
     * @since  1.3.0
     */
    private static function layoutExists(string $layoutId): bool
    {
        $rel = str_replace('.', '/', $layoutId) . '.php';

        if (is_file(JPATH_ADMINISTRATOR . '/components/com_alfa/layouts/' . $rel)) {
            return true;
        }

        foreach (self::enabledAdminTemplates() as $template) {
            if (is_file(JPATH_ADMINISTRATOR . '/templates/' . $template . '/html/layouts/com_alfa/' . $rel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Element names of enabled administrator templates, cached.
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    private static function enabledAdminTemplates(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where([
                $db->quoteName('client_id') . ' = 1',
                $db->quoteName('type') . ' = ' . $db->quote('template'),
                $db->quoteName('enabled') . ' = 1',
            ]);
        $db->setQuery(query: $query);

        return $cache = ($db->loadColumn() ?: []);
    }

    /**
     * Load a series row (null when the document has none yet: drafts).
     *
     * @since  1.3.0
     */
    private static function loadSeries(object $db, int $seriesId): ?object
    {
        if ($seriesId <= 0) {
            return null;
        }

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_series'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $seriesId);
        $db->setQuery(query: $query);

        return $db->loadObject();
    }

    /**
     * Load a bare document header (corrective reference display).
     *
     * @since  1.3.0
     */
    private static function loadHeader(object $db, int $documentId): ?object
    {
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_documents'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $documentId);
        $db->setQuery(query: $query);

        return $db->loadObject();
    }

    /**
     * Load the document's lines in sort order.
     *
     * @since  1.3.0
     */
    private static function loadLines(object $db, int $documentId): array
    {
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_items'))
            ->where($db->quoteName('id_document') . ' = :id')
            ->order($db->quoteName('sort_order') . ' ASC, ' . $db->quoteName('id') . ' ASC')
            ->bind(':id', $documentId);
        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Load the frozen per-rate VAT summary rows.
     *
     * @since  1.3.0
     */
    private static function loadTaxes(object $db, int $documentId): array
    {
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_taxes'))
            ->where($db->quoteName('id_document') . ' = :id')
            ->order($db->quoteName('tax_rate') . ' ASC')
            ->bind(':id', $documentId);
        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }
}
