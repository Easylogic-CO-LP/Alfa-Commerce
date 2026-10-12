<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Document\CreditCalculator;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentIssuer;
use DateTimeZone;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use RuntimeException;
use Throwable;

/**
 * DocumentHelper: read side of the sales-documents subsystem.
 *
 * Type vocabulary comes from the DOCUMENT TYPE REGISTRY ( * `#__alfa_document_types`): a protected semantic core seeded by the
 * installer and extended only by packages. The engine asks PROPERTIES:
 * family (sales|credit|logistics|informational) and quantity_pool
 * (sales|credit|independent|none), never aliases; the TYPE_* constants
 * below are references to the core-seeded rows, not semantics.
 *
 * Pool algebra: all `sales`-pool types share ONE pool per
 * order line (the same unit is never sold on two documents); `credit`
 * draws against issued sales-pool quantity; `independent` counts its own
 * per-alias pool; `none` is uncounted.
 *
 * The WRITE side lives exclusively in DocumentIssuer; nothing here
 * mutates rows.
 *
 * @since  1.3.0
 */
final class DocumentHelper
{
    /** Core-seeded type aliases (immutable published contract). */
    public const TYPE_INVOICE = 'core.invoice';
    public const TYPE_RETAIL_RECEIPT = 'core.retail_receipt';
    public const TYPE_CREDIT_INVOICE = 'core.credit_invoice';
    public const TYPE_CREDIT_RECEIPT = 'core.credit_receipt';
    public const TYPE_DELIVERY_NOTE = 'core.delivery_note';

    /** Layer-1 families (closed engine axis). */
    public const FAMILY_SALES = 'sales';
    public const FAMILY_CREDIT = 'credit';
    public const FAMILY_LOGISTICS = 'logistics';
    public const FAMILY_INFORMATIONAL = 'informational';

    /** Layer-1 quantity pools (closed engine axis). */
    public const POOL_SALES = 'sales';
    public const POOL_CREDIT = 'credit';
    public const POOL_INDEPENDENT = 'independent';
    public const POOL_NONE = 'none';

    public const STOCK_EFFECT_NONE = 'none';
    public const STOCK_EFFECT_DEDUCT = 'deduct';
    public const STOCK_EFFECT_RESTORE = 'restore';

    /**
     * Document lifecycle: two states, and every writer names one.
     *
     * ISSUED is immutable and there is no unvoid; VOIDED keeps the number and
     * the content, so a void preserves history instead of erasing it. There is
     * no draft in v1: a document exists only once DocumentIssuer has issued it.
     * The column carries NO database default either, so a writer that forgets
     * the lifecycle intent fails loudly instead of persisting a silent zero.
     *
     * @since  1.3.0
     */
    public const STATUS_ISSUED = 1;
    public const STATUS_VOIDED = 2;

    /** Line types. */
    public const LINE_ITEM = 'item';
    public const LINE_SHIPPING = 'shipping';
    public const LINE_FEE = 'fee';
    public const LINE_ADJUSTMENT = 'adjustment';

    /**
     * The registry rows, keyed by alias, ordered for display. Cached for
     * the request. Includes DISABLED types (state 0): existing documents
     * of a hidden type must keep resolving; pickers filter on state.
     *
     * @return array<string, object>
     *
     * @since  1.3.0
     */
    public static function typeRows(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_types'))
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        $db->setQuery(query: $query);

        $cache = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $cache[(string) $row->alias] = $row;
        }

        return $cache;
    }

    /**
     * One registry row by alias, or null for an unknown alias.
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function typeRow(string $docType): ?object
    {
        return self::typeRows()[$docType] ?? null;
    }

    /**
     * Human label for a type: the registry row's translated name (current
     * language -> site default, from the per-language aux tables), else the
     * shipped ini constant for core types (COM_ALFA_DOCTYPE_<LAST_SEGMENT>),
     * else the humanised last alias segment; pack types read sensibly
     * without a language string. Memoised per request.
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function typeLabel(string $docType, string $langTag = ''): string
    {
        static $cache = [];
        $cacheKey = $docType . '|' . $langTag;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $row = self::typeRow($docType);

        if ($row !== null) {
            try {
                $translated = MultilingualHelper::getTranslatedValue(
                    id:                (int) $row->id,
                    tableName:         '#__alfa_document_types',
                    primaryColumnName: 'id_document_type',
                    field:             'name',
                    langTag:           $langTag,
                );
            } catch (Throwable) {
                // Aux tables absent (pre-sync installs): fall through.
                $translated = '';
            }

            if ($translated !== '') {
                return $cache[$cacheKey] = $translated;
            }
        }

        $segments = explode('.', $docType);
        $last = (string) end($segments);

        $key = 'COM_ALFA_DOCTYPE_' . strtoupper($last);
        $label = \Joomla\CMS\Language\Text::_($key);

        if ($label !== $key) {
            return $cache[$cacheKey] = $label;
        }

        return $cache[$cacheKey] = ucwords(str_replace(['_', '-'], ' ', $last));
    }

    /**
     * Every visible type alias, in registry display order.
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public static function types(): array
    {
        $aliases = [];

        foreach (self::typeRows() as $alias => $row) {
            if ((int) $row->state === 1) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * Aliases counting in the shared SALES pool (property-driven).
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public static function salesTypes(): array
    {
        return self::aliasesByPool(pool: self::POOL_SALES);
    }

    /**
     * Aliases counting in the CREDIT pool (draw against issued sales).
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public static function creditTypes(): array
    {
        return self::aliasesByPool(pool: self::POOL_CREDIT);
    }

    /**
     * Aliases of a quantity pool; includes disabled types, since pool
     * math must keep counting documents of a later-hidden type.
     *
     * @param string $pool One of the POOL_* constants.
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public static function aliasesByPool(string $pool): array
    {
        $aliases = [];

        foreach (self::typeRows() as $alias => $row) {
            if ((string) $row->quantity_pool === $pool) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * The sales types a credit type corrects, as its registry row states them.
     *
     * The one reader of the stored pairing: a comma set of sales type aliases,
     * empty when the type corrects none. Which document corrects which is a
     * fact the shop states on the credit type, never guessed from an alias or
     * from the order of a list.
     *
     * @param string $creditType The credit type alias.
     *
     * @return string[] The sales type aliases; empty for none or an unknown alias.
     *
     * @since   1.14.0
     */
    public static function correctedTypes(string $creditType): array
    {
        $set = (string) (self::typeRow($creditType)->corrects ?? '');

        return $set === '' ? [] : explode(',', $set);
    }

    /**
     * The credit type that corrects documents of a sales type.
     *
     * Every enabled credit type whose pairing names the sales type: exactly
     * one is the answer; none, or more than one, is no answer, and the caller
     * refuses with the types named. A disabled type is not issued, so it
     * corrects nothing; it is listed apart, so a refusal can say that the
     * type set to correct this one is disabled.
     *
     * @param string $salesType The type of the document being corrected.
     *
     * @return object  {alias: string, paired: string[], disabled: string[]} - alias is
     *                                                                       the one enabled paired credit type, '' when none or several are;
     *                                                                       paired lists the enabled ones and disabled the disabled ones, each
     *                                                                       in registry order.
     *
     * @since   1.14.0
     */
    public static function pairedCreditType(string $salesType): object
    {
        $paired = [];
        $disabled = [];

        foreach (self::typeRows() as $alias => $row) {
            if ((string) $row->quantity_pool !== self::POOL_CREDIT
                || !\in_array($salesType, self::correctedTypes($alias), true)) {
                continue;
            }

            if ((int) $row->state === 1) {
                $paired[] = $alias;
            } else {
                $disabled[] = $alias;
            }
        }

        return (object) [
            'alias' => \count($paired) === 1 ? $paired[0] : '',
            'paired' => $paired,
            'disabled' => $disabled,
        ];
    }

    /**
     * Whether a type's pairing is its provider's to state.
     *
     * A type a provider registered (core's own, seeded by the installer, or
     * a plugin's) corrects what its provider declares, and every sync of the
     * provider restores that declaration. Only a type with no provider is the
     * merchant's own, and only its pairing is set on the type's form.
     *
     * @param object $row The registry row.
     *
     *
     * @since   1.14.0
     */
    public static function pairingIsDeclared(object $row): bool
    {
        return (int) ($row->is_core ?? 0) === 1 || (string) ($row->provider ?? '') !== '';
    }

    /**
     * A type's quantity pool ('' for unknown aliases).
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function poolOf(string $docType): string
    {
        return (string) (self::typeRow($docType)->quantity_pool ?? '');
    }

    /**
     * A type's semantic family ('' for unknown aliases).
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function familyOf(string $docType): string
    {
        return (string) (self::typeRow($docType)->family ?? '');
    }

    /**
     * A type's physical stock effect on issue ('none' for unknown aliases):
     * none | deduct | restore. Fulfilment asks this semantic axis, never
     * the alias.
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.4.0
     */
    public static function stockEffectForDocument(string $docType, bool $isCombined): string
    {
        $own = self::stockEffectOf($docType);

        if (!$isCombined) {
            return $own;
        }

        // A COMBINED DOCUMENT ALSO IS A DELIVERY NOTE, AND THIS FILE SAYS SO.
        //
        // The type it stands in for is not chosen here: the printed header
        // appends typeLabel(TYPE_DELIVERY_NOTE) to the title, the schema calls
        // the column "Fiscal doc that also serves as delivery note", and the
        // issue validator demands the movement block of it exactly as of a real
        // delivery note. The stock effect simply has to follow the same
        // declaration.
        //
        // Only when the document's own type has NO effect of its own. The modal
        // offers the switch for `family === 'sales' && movement`, and every
        // sales type carries stock_effect 'none', so this is the only case the
        // product can produce; a forced flag on a type that already deducts or
        // restores keeps its own effect rather than silently gaining a second,
        // contradictory one.
        if ($own !== self::STOCK_EFFECT_NONE) {
            return $own;
        }

        return self::stockEffectOf(self::TYPE_DELIVERY_NOTE);
    }

    /**
     * What issuing THIS TYPE does to physical stock, from the registry row.
     *
     * Per type only. A document may carry more than its type's effect; see
     * stockEffectForDocument(), which is what the issue path resolves through.
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function stockEffectOf(string $docType): string
    {
        return (string) (self::typeRow($docType)->stock_effect ?? self::STOCK_EFFECT_NONE);
    }

    /**
     * Whether the type carries money totals: the sales and credit
     * families do; logistics and informational documents do not.
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function isFiscal(string $docType): bool
    {
        return \in_array(self::familyOf($docType), [self::FAMILY_SALES, self::FAMILY_CREDIT], true);
    }

    /**
     * Whether THIS type must carry a buyer tax ID at issuance.
     *
     * GLOBAL-FIRST: a per-type registry flag, seeded OFF on the core
     * types; jurisdiction packages flip it on the rows they govern.
     * The buyer NAME requirement on invoice-style types is universal and
     * stays unconditional (see DocumentIssuer::validateDraft()).
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function requiresBuyerVat(string $docType): bool
    {
        return (int) (self::typeRow($docType)->requires_buyer_tax_id ?? 0) === 1;
    }

    /**
     * Whether THIS type must carry the movement block at issuance
     * (movement_mode = 'required' on the registry row).
     *
     * GLOBAL-FIRST: seeded 'optional'; a packing slip needs no
     * "movement purpose"; jurisdictions with regulated transport
     * documents flip the row to 'required'. Combined fiscal documents
     * (is_combined = 1) follow the same row flag; that check is the
     * issue helper's, since it is per-document.
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function requiresMovement(string $docType): bool
    {
        return (string) (self::typeRow($docType)->movement_mode ?? 'none') === 'required';
    }

    /**
     * Whether the movement block applies to the type at all
     * (movement_mode 'optional' or 'required').
     *
     * @param string $docType The type alias.
     *
     *
     * @since  1.3.0
     */
    public static function supportsMovement(string $docType): bool
    {
        return (string) (self::typeRow($docType)->movement_mode ?? 'none') !== 'none';
    }

    /**
     * Master switch: does com_alfa issue documents at all?
     *
     * `documents_issuer` config: 'com_alfa' (default) or 'external'; shops
     * whose ERP issues the legal documents set external and every trigger
     * and manual button goes inert.
     *
     * @return bool True when com_alfa is the issuer.
     *
     * @since  1.3.0
     */
    public static function isIssuerEnabled(): bool
    {
        return ComponentHelper::getParams('com_alfa')->get('documents_issuer', 'com_alfa') === 'com_alfa';
    }

    /**
     * One document by id, with its series code: the shape getForOrder()
     * returns for each row.
     *
     * Used where a screen acts on a single named document rather than on an
     * order's set: the void modal shows the number it is about to void, and
     * refuses outright if the id names nothing.
     *
     * @param int $documentId The document PK.
     *
     * @return object|null The row, or null when no such document exists.
     *
     * @since   1.3.0
     */
    public static function getById(int $documentId): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select(['d.*', $db->quoteName('s.code', 'series_code')])
            ->from($db->quoteName('#__alfa_documents', 'd'))
            ->join('LEFT', $db->quoteName('#__alfa_document_series', 's') . ' ON s.id = d.id_series')
            ->where($db->quoteName('d.id') . ' = :documentId')
            ->bind(':documentId', $documentId);
        $db->setQuery(query: $query);

        return $db->loadObject() ?: null;
    }

    /**
     * All documents of an order, newest first, with series info joined.
     *
     * @param int $orderId The order id.
     *
     * @return object[] Header rows + series_code/series_prefix.
     *
     * @since  1.3.0
     */
    public static function getForOrder(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select(['d.*', $db->quoteName('s.code', 'series_code')])
            ->from($db->quoteName('#__alfa_documents', 'd'))
            ->join('LEFT', $db->quoteName('#__alfa_document_series', 's') . ' ON s.id = d.id_series')
            ->where($db->quoteName('d.id_order') . ' = :orderId')
            ->order($db->quoteName('d.id') . ' DESC')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * The replacement chain a document belongs to, derived by traversal.
     *
     * Only `id_replaces` is persisted (each replacement names its immediate
     * source), so the chain is DERIVED, with no depth limit and no second
     * column. The rows are read in ONE query and
     * walked in memory both ways with a visited set; no walk takes more steps
     * than there are rows, so corrupt data ends it instead of looping.
     *
     * READ-ONLY, AND IT NEVER THROWS ON BAD DATA. What the Void & Replace
     * workflow never writes is reported as an anomaly at the row carrying the
     * bad link, and the walk stops at the damage: `cycle`, `self_loop`, `fork`
     * (two successors of one document), `dangling` (names a missing document),
     * `foreign_order`, `type_mismatch`, `non_monotonic` (names a later
     * document). The writer refuses to extend a chain that has any; a screen
     * can show them.
     *
     * @param int $documentId Any member of the chain.
     *
     * @return object {id: int, members: int[] (root to head), root: int, head: int,
     *                live: int (the head when issued, else 0),
     *                predecessors: int[] (nearest first), successors: int[] (nearest first),
     *                anomalies: array<int, array{kind: string, at: int}>}.
     *                A document that does not exist reads as an empty chain.
     *
     * @since   1.14.0
     */
    public static function replacementChain(int $documentId): object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $table = $db->quoteName('#__alfa_documents');
        $select = 'SELECT ' . implode(', ', $db->quoteName(['d.id', 'd.id_replaces', 'd.status', 'd.doc_type', 'd.id_order', 'd.number_formatted']))
            . ' FROM ' . $table . ' AS ' . $db->quoteName('d');
        $orderOf = '(SELECT ' . $db->quoteName('s.id_order') . ' FROM ' . $table . ' AS ' . $db->quoteName('s')
            . ' WHERE ' . $db->quoteName('s.id') . ' = ' . $documentId . ')';

        // One statement, three index-backed parts: the order's own documents,
        // any document one of them names, and any document naming one of
        // them; the last two wherever they live, so a link that leaves the
        // order reads as foreign rather than as missing.
        $db->setQuery(
            $select . ' WHERE ' . $db->quoteName('d.id_order') . ' = ' . $orderOf
            . ' UNION ' . $select . ' JOIN ' . $table . ' AS ' . $db->quoteName('r')
            . ' ON ' . $db->quoteName('r.id_replaces') . ' = ' . $db->quoteName('d.id')
            . ' WHERE ' . $db->quoteName('r.id_order') . ' = ' . $orderOf
            . ' UNION ' . $select . ' JOIN ' . $table . ' AS ' . $db->quoteName('m')
            . ' ON ' . $db->quoteName('d.id_replaces') . ' = ' . $db->quoteName('m.id')
            . ' WHERE ' . $db->quoteName('m.id_order') . ' = ' . $orderOf,
        );

        $rows = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $rows[(int) $row->id] = $row;
        }

        $chain = (object) [
            'id' => $documentId,
            'members' => [],
            'root' => 0,
            'head' => 0,
            'live' => 0,
            'predecessors' => [],
            'successors' => [],
            'anomalies' => [],
        ];

        if (!isset($rows[$documentId])) {
            return $chain;
        }

        $orderId = (int) $rows[$documentId]->id_order;
        $anomalies = [];
        $flag = static function (string $kind, int $at) use (&$anomalies): void {
            $anomalies[$kind . ':' . $at] = ['kind' => $kind, 'at' => $at];
        };

        // Who names whom: successors by the id they name, a self-loop apart.
        $successorsOf = [];

        foreach ($rows as $id => $row) {
            $named = (int) ($row->id_replaces ?? 0);

            if ($named > 0 && $named !== $id) {
                $successorsOf[$named][] = $id;
            }
        }

        $visited = [$documentId => true];
        $limit = \count($rows);

        // Back to the root
        $predecessors = [];
        $current = $documentId;

        for ($step = 0; $step < $limit; $step++) {
            $named = (int) ($rows[$current]->id_replaces ?? 0);

            if ($named <= 0) {
                break;
            }

            $kind = match (true) {
                $named === $current => 'self_loop',
                !isset($rows[$named]) => 'dangling',
                (int) $rows[$named]->id_order !== $orderId => 'foreign_order',
                isset($visited[$named]) => 'cycle',
                default => '',
            };

            if ($kind !== '') {
                $flag($kind, $current);

                break;
            }

            if ((string) $rows[$named]->doc_type !== (string) $rows[$current]->doc_type) {
                $flag('type_mismatch', $current);
            }

            if ($named >= $current) {
                $flag('non_monotonic', $current);
            }

            $visited[$named] = true;
            $predecessors[] = $named;
            $current = $named;
        }

        // On to the head
        $successors = [];
        $current = $documentId;

        for ($step = 0; $step < $limit; $step++) {
            $next = $successorsOf[$current] ?? [];

            // A fork is flagged below, where every member is asked.
            if (\count($next) !== 1) {
                break;
            }

            $child = $next[0];

            $kind = match (true) {
                (int) $rows[$child]->id_order !== $orderId => 'foreign_order',
                isset($visited[$child]) => 'cycle',
                default => '',
            };

            if ($kind !== '') {
                $flag($kind, $child);

                break;
            }

            if ((string) $rows[$child]->doc_type !== (string) $rows[$current]->doc_type) {
                $flag('type_mismatch', $child);
            }

            if ($child <= $current) {
                $flag('non_monotonic', $child);
            }

            $visited[$child] = true;
            $successors[] = $child;
            $current = $child;
        }

        $members = array_merge(array_reverse($predecessors), [$documentId], $successors);

        // One successor per document, ever: asked of every member, so a fork
        // behind the document read is found as surely as one ahead of it.
        foreach ($members as $member) {
            if (\count($successorsOf[$member] ?? []) > 1) {
                $flag('fork', $member);
            }
        }

        $head = (int) end($members);

        $chain->members = $members;
        $chain->root = $members[0];
        $chain->head = $head;
        $chain->live = (int) $rows[$head]->status === self::STATUS_ISSUED ? $head : 0;
        $chain->predecessors = $predecessors;
        $chain->successors = $successors;
        $chain->anomalies = array_values($anomalies);

        return $chain;
    }

    /**
     * Did issuing THIS document move physical stock?
     *
     * Asked of the document, not of its type, on two counts. Its effect is the
     * document's own (stockEffectForDocument(): a combined invoice moved the
     * goods though its type moves none). And an effect moves only goods: a
     * document that carries no item line (a credit of shipping or a fee
     * alone) had nothing to move, whatever its type restores or deducts.
     *
     * @param object $document The document row (id, doc_type, is_combined).
     *
     *
     * @since   1.14.0
     */
    public static function movesStock(object $document): bool
    {
        if (self::stockEffectForDocument((string) $document->doc_type, (int) ($document->is_combined ?? 0) === 1)
            === self::STOCK_EFFECT_NONE) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $documentId = (int) $document->id;
        $item = self::LINE_ITEM;

        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_document_items'))
            ->where($db->quoteName('id_document') . ' = :documentId')
            ->where($db->quoteName('line_type') . ' = :item')
            ->setLimit(1)
            ->bind(':documentId', $documentId)
            ->bind(':item', $item);
        $db->setQuery(query: $query);

        return (int) $db->loadResult() === 1;
    }

    /**
     * Credit already issued against each of the given documents.
     *
     * Only ISSUED credits count (a voided credit frees its amount), exactly as
     * the issuer's cap counts them. One query for the whole set of ids given;
     * creditedTotal() asks it for one document.
     *
     * @param int[] $documentIds Corrected-document ids.
     *
     * @return array<int, float> Document id => credited total, tax included.
     *
     * @since  1.10.0
     */
    public static function creditedTotals(array $documentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds))));

        if ($ids === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id_corrects'),
                'COALESCE(SUM(' . $db->quoteName('total_tax_incl') . '), 0) AS credited',
            ])
            ->from($db->quoteName('#__alfa_documents'))
            ->whereIn($db->quoteName('id_corrects'), $ids)
            ->where($db->quoteName('status') . ' = ' . self::STATUS_ISSUED)
            ->group($db->quoteName('id_corrects'));
        $db->setQuery(query: $query);

        $credited = array_fill_keys($ids, 0.0);

        foreach ($db->loadObjectList() ?: [] as $row) {
            $credited[(int) $row->id_corrects] = (float) $row->credited;
        }

        return $credited;
    }

    /**
     * Credit already issued against one document: the sum the issuer's
     * cumulative credit cap is computed on.
     *
     * @param int $documentId The corrected document's id.
     *
     * @return float Credited total, tax included.
     *
     * @since  1.10.0
     */
    public static function creditedTotal(int $documentId): float
    {
        return self::creditedTotals(documentIds: [$documentId])[$documentId] ?? 0.0;
    }

    /**
     * Of an order's documents, the issued sales documents a credit may still
     * correct: the whole offer of the order screen's corrects picker.
     *
     * Asked before the operator is shown a choice: the document is ISSUED and
     * a sales type, and a credit of it could still be issued. The same order
     * is the caller's query. Order is preserved, so the picker still lists
     * newest first.
     *
     * A credit of it could still be issued when it has money left to give
     * back, as the issuer's own invoice-total cap measures it
     * (DocumentIssuer::creditLeft()), or, with no money left, while a
     * quantity the order no longer holds can still be corrected on it: that
     * credit is issued at zero and carries the quantity. A document with
     * neither is not offered: every credit of it would be refused as having no
     * refundable amount, and the picker offers no document whose only outcome
     * is a refused submit. The quantity is asked only of a document that has
     * no money left, so an order whose documents all hold money asks nothing
     * more.
     *
     * Hiding a document that cannot take another credit never decides WHICH
     * document is corrected: that stays the operator's explicit choice.
     *
     * @param object[] $documents The order's documents, as getForOrder() returns them.
     *
     * @return object[] The correctable subset, in the given order.
     *
     * @since  1.10.0
     */
    public static function correctableDocuments(array $documents): array
    {
        $issued = array_values(array_filter(
            $documents,
            static fn (object $d): bool => (int) $d->status === self::STATUS_ISSUED
                && \in_array((string) $d->doc_type, self::salesTypes(), true),
        ));

        return array_values(array_filter(
            $issued,
            // What a document can still credit is the issuer's own measure, at
            // its own frozen currency, so the picker and the issuer never differ.
            static fn (object $d): bool => DocumentIssuer::creditLeft(target: $d)->isPositive()
                || CreditCalculator::quantityCorrectionAllowance(orderId: (int) $d->id_order, correctsId: (int) $d->id) !== [],
        ));
    }

    /**
     * Quantity a document family has already consumed per order line.
     *
     * Counts ISSUED (voided excluded) document lines of the given types,
     * grouped by id_order_item. Used by the over-issue guards and by the
     * issue popup's "remaining" hints.
     *
     * @param int $orderId The order id.
     * @param string[] $docTypes Types whose consumption is summed.
     *
     * @return array<int, float> id_order_item => consumed quantity.
     *
     * @since  1.3.0
     */
    public static function consumedQuantities(int $orderId, array $docTypes): array
    {
        if ($docTypes === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('i.id_order_item'),
                'SUM(' . $db->quoteName('i.quantity') . ') AS consumed',
            ])
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
            ->where([
                $db->quoteName('d.id_order') . ' = :orderId',
                $db->quoteName('d.status') . ' = ' . self::STATUS_ISSUED,
                $db->quoteName('i.id_order_item') . ' IS NOT NULL',
            ])
            ->group($db->quoteName('i.id_order_item'))
            ->bind(':orderId', $orderId);

        // A COMBINED DOCUMENT CONSUMES THE DELIVERY-NOTE POOL TOO.
        //
        // Counting by doc_type alone let a combined invoice, which prints as a
        // delivery note, is required to carry the movement block, and is
        // described by its own column as "also serves as delivery note",
        // consume nothing from the logistics pool. A second, real delivery note
        // could then be issued for the same goods, each burning a gapless
        // number from its series for one physical movement.
        //
        // This is the single place consumption is computed, so every consumer
        // of remainingQuantities() (the issue guard, auto-issue, the order
        // screen, charge lines and the RMA pool) is corrected at once.
        $typeMatch = $db->quoteName('d.doc_type') . ' IN ('
            . implode(',', array_map([$db, 'quote'], $docTypes)) . ')';

        if (\in_array(self::TYPE_DELIVERY_NOTE, $docTypes, true)) {
            $typeMatch = '(' . $typeMatch . ' OR ' . $db->quoteName('d.is_combined') . ' = 1)';
        }

        $query->where($typeMatch);

        $db->setQuery(query: $query);

        $rows = $db->loadObjectList() ?: [];
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->id_order_item] = (float) $row->consumed;
        }

        return $map;
    }

    /**
     * Money a document family has already stated per order line.
     *
     * The amount twin of {@see self::consumedQuantities()}: ISSUED document
     * lines only, so a VOIDED document releases the money it claimed exactly
     * as it releases the quantity. Both tax axes are summed, because the
     * issuer stores each line's tax as the difference of the two.
     *
     * No combined-document clause: a delivery note carries no money
     * (DocumentIssuer zeroes it), so the logistics pool adds nothing to a sum
     * of money.
     *
     * @param int $orderId The order id.
     * @param string[] $docTypes Types whose statements are summed.
     *
     * @return array<int, array{incl: float, excl: float}> id_order_item => documented value.
     *
     * @since  1.10.0
     */
    public static function consumedAmounts(int $orderId, array $docTypes): array
    {
        if ($docTypes === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('i.id_order_item'),
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS incl',
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS excl',
            ])
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
            ->where([
                $db->quoteName('d.id_order') . ' = :orderId',
                $db->quoteName('d.status') . ' = ' . self::STATUS_ISSUED,
                $db->quoteName('i.id_order_item') . ' IS NOT NULL',
                $db->quoteName('d.doc_type') . ' IN (' . implode(',', array_map([$db, 'quote'], $docTypes)) . ')',
            ])
            ->group($db->quoteName('i.id_order_item'))
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        $map = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $map[(int) $row->id_order_item] = ['incl' => (float) $row->incl, 'excl' => (float) $row->excl];
        }

        return $map;
    }

    /**
     * Remaining issuable quantity per order line for a target doc type,
     * PROPERTY-driven by the type's quantity pool:
     *
     *   sales        ordered - consumption of ALL sales-pool types
     *   credit       issued sales-pool quantity - credit-pool consumption
     *   independent  ordered - THIS alias' own consumption
     *   none         ordered, uncounted (never limits)
     *
     * @param int $orderId The order id.
     * @param string $docType Target document type alias.
     *
     * @return array<int, array{ordered: float, remaining: float}> keyed by order line id.
     *
     * @since  1.3.0
     */
    public static function remainingQuantities(int $orderId, string $docType): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('quantity')])
            ->from($db->quoteName('#__alfa_order_items'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);
        $orderLines = $db->loadObjectList('id') ?: [];

        $ordered = array_map(static fn (object $l): float => (float) $l->quantity, $orderLines);

        switch (self::poolOf($docType)) {
            case self::POOL_CREDIT:
                // Credits draw on what sales documents actually issued.
                $pool = self::consumedQuantities(orderId: $orderId, docTypes: self::salesTypes());
                $consumed = self::consumedQuantities(orderId: $orderId, docTypes: self::creditTypes());
                break;

            case self::POOL_INDEPENDENT:
                $pool = $ordered;
                $consumed = self::consumedQuantities(orderId: $orderId, docTypes: [$docType]);
                break;

            case self::POOL_NONE:
                $pool = $ordered;
                $consumed = [];
                break;

            default: // POOL_SALES (and safest for unknown aliases)
                $pool = $ordered;
                $consumed = self::consumedQuantities(orderId: $orderId, docTypes: self::salesTypes());
                break;
        }

        $result = [];

        foreach ($orderLines as $lineId => $line) {
            $available = (float) ($pool[$lineId] ?? 0.0);
            $result[(int) $lineId] = [
                'ordered' => (float) $line->quantity,
                'remaining' => max(0.0, $available - (float) ($consumed[$lineId] ?? 0.0)),
            ];
        }

        return $result;
    }

    /**
     * The default (is_default = 1, published) series id for a doc type.
     *
     * @param string $docType The document type.
     *
     * @return int|null Series PK, or null when none is nominated.
     *
     * @since  1.3.0
     */
    public static function defaultSeriesId(string $docType): ?int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_document_series'))
            ->where([
                $db->quoteName('doc_type') . ' = :docType',
                $db->quoteName('is_default') . ' = 1',
                $db->quoteName('state') . ' = 1',
            ])
            ->bind(':docType', $docType)
            ->setLimit(1);
        $db->setQuery(query: $query);

        $id = (int) $db->loadResult();

        return $id > 0 ? $id : null;
    }

    /**
     * Published series rows for a doc type (manual-issue picker).
     *
     * @param string $docType The document type.
     *
     * @return object[]
     *
     * @since  1.3.0
     */
    public static function seriesForType(string $docType): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_series'))
            ->where([
                $db->quoteName('doc_type') . ' = :docType',
                $db->quoteName('state') . ' = 1',
            ])
            ->order($db->quoteName('is_default') . ' DESC, ' . $db->quoteName('code') . ' ASC')
            ->bind(':docType', $docType);
        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * The ONE formatted-number rule, frozen per document at issue:
     * prefix + (year_scoped ? 'YYYY-' : '') + zero-padded number.
     *
     * @param object $series The series row (prefix, padding, year_scoped).
     * @param int $number The raw counter value.
     * @param int $localYear The shop-timezone year of issuance.
     *
     *
     * @since  1.3.0
     */
    public static function formatNumber(object $series, int $number, int $localYear): string
    {
        $yearPart = ((int) $series->year_scoped === 1) ? $localYear . '-' : '';

        return (string) $series->prefix . $yearPart
            . str_pad((string) $number, max(1, (int) $series->padding), '0', STR_PAD_LEFT);
    }

    /**
     * The shop timezone, for the document domain.
     *
     * Documents compute legal dates and year scoping in the SHOP's clock, never
     * in UTC; this is where document code asks for it, so nothing under
     * Service/Document has to reach across to a general helper.
     *
     * The definition itself is {@see AlfaHelper::shopTimezone()} and stays there:
     * one shop clock, so a document date and an "orders today" window can never
     * disagree. This method is the seam: if documents ever need their own
     * (a per-series or per-legal-entity timezone), it changes HERE and the rest
     * of the component is untouched.
     *
     *
     * @since   1.3.0
     */
    public static function shopTimezone(): DateTimeZone
    {
        return AlfaHelper::shopTimezone();
    }

    /**
     * The default document language: the SITE default content language.
     *
     * Documents are issued in one language (frozen line text + printed
     * labels); callers may override per document, this is the fallback.
     *
     * @return string Language tag, e.g. 'en-GB'.
     *
     * @since  1.3.0
     */
    public static function defaultLanguageTag(): string
    {
        try {
            // The component's canonical resolver honours the configured site
            // language and the published content-language set, but returns the
            // storage form ('en_gb'); documents want the display form ('en-GB').
            $tag = MultilingualHelper::getDefaultLanguageTag();
            [$lang, $region] = array_pad(explode('_', $tag, 2), 2, '');

            return $region !== '' ? $lang . '-' . strtoupper($region) : ($lang ?: 'en-GB');
        } catch (Throwable) {
            return 'en-GB';
        }
    }

    /**
     * The language a credit of a document is issued in: that document's own.
     *
     * The rule: "a credit note is stamped with
     * the language of the document it corrects, never with the language of the
     * screen that issues it." The stored tag is returned verbatim, whatever it
     * is: there is no second rule and no default.
     *
     * @param int $correctsId The corrected document.
     *
     * @return string Its stored `language`.
     *
     * @throws RuntimeException When the document does not exist.
     *
     * @since   1.14.0
     */
    public static function creditLanguageTag(int $correctsId): string
    {
        $document = self::getById($correctsId);

        if ($document === null) {
            throw new RuntimeException("document #{$correctsId} does not exist, so a credit of it has no language to take.");
        }

        return (string) $document->language;
    }
}
