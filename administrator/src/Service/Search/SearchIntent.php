<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * WHY a search is being run: declared by the surface, never inferred by the engine.
 *
 * THIS EXISTS BECAUSE INFERRING INTENT FROM THE EXECUTION PATH FAILED IN PRODUCTION.
 * Analytics recording used to ask "which option was in the request?" and treat
 * `option=com_ajax` as type-ahead. That correlated with intent only while ONE surface
 * existed. The moment a second one appeared, the guard stopped guarding and every
 * keystroke was recorded as a deliberate search, fabricating zero-result rows for
 * prefixes nobody submitted, in the one report the analytics subsystem exists to produce.
 *
 * A provider CANNOT know why it was called. The surface always does. So intent travels
 * with the request, exactly as the rest of this subsystem already works:
 *  - `data-alfa-search`: enhancement declared in markup, not inferred from DOM shape.
 *  - {@see StandardAnalyzer::VOCABULARY_FIELDS}: suggestion eligibility declared, not
 *    inferred from token shape.
 *  - {@see StandardAnalyzer::IDENTIFIER_FIELDS}: code semantics declared by field
 *    provenance, not by pattern-matching the string.
 *
 * NOTE WHAT IS DELIBERATELY *NOT* A CASE HERE: there is no `API`. "It arrived over the
 * API" is a TRANSPORT, and confusing transport with intent is the precise mistake this
 * enum exists to end: an API caller may be running a genuine shopper search or polling
 * type-ahead, and only that caller can say which. Adding a case whose recording policy is
 * ambiguous would reintroduce the guessing one layer higher.
 *
 * @since  1.6.0
 */
enum SearchIntent: string
{
    /**
     * Interactive completion while the shopper is still typing.
     *
     * THE SAFE DEFAULT EVERYWHERE. A query in flight is not a query the shopper has
     * expressed: they are mid-word, and the next keystroke replaces it.
     *
     * @since  1.6.0
     */
    case TYPEAHEAD = 'typeahead';

    /**
     * A search the shopper deliberately expressed: submitted a form, followed a search
     * URL, or landed on a results page.
     *
     * The only intent that is worth a merchant's attention, and therefore the only one
     * recorded.
     *
     * @since  1.6.0
     */
    case SEARCH = 'search';

    /**
     * Machine-initiated: indexing, a rebuild probe, a CLI task, an export, an admin tool.
     *
     * Never recorded: a report about the shop's own maintenance traffic tells a merchant
     * nothing and dilutes the counts that do.
     *
     * @since  1.6.0
     */
    case SYSTEM = 'system';

    /**
     * The SAME expressed search, continued: page 2, a sort change, a re-render of results
     * the shopper already asked for.
     *
     * Not recorded, and this case exists precisely because recording it was a live defect:
     * every deeper page re-ran the search and wrote another identical row, so one shopper
     * paging through "balsamic" counted as three searches, inflating volume and, worse,
     * DILUTING CTR (clicked-searches / searches gains phantom denominators that can never
     * be clicked as searches). One expressed search = one event; its continuations are the
     * same fact re-read.
     *
     * @since  1.7.0
     */
    case CONTINUATION = 'continuation';

    /**
     * Whether a search run with this intent belongs in the analytics record.
     *
     * THE POLICY LIVES ON THE INTENT, not in the recorder, so adding a case forces its
     * policy to be decided here rather than defaulting silently somewhere downstream.
     *
     * FALSE FOR EVERYTHING EXCEPT AN EXPRESSED SEARCH, and the asymmetry is deliberate: a
     * missing event means "we have less data", a fabricated one means "we have MISLEADING
     * data". For merchant-facing analytics the second is far more expensive, because it
     * drives decisions: a zero-result report full of half-typed prefixes invites someone
     * to add synonyms for words no shopper ever finished typing.
     *
     * @return bool True only for {@see self::SEARCH}.
     *
     * @since  1.6.0
     */
    public function recordsAnalytics(): bool
    {
        return $this === self::SEARCH;
    }

    /**
     * Resolve a declared value, falling back to the safe default.
     *
     * Used where the intent arrives as a string (model state or a request parameter), so
     * an unknown or absent value degrades to {@see self::TYPEAHEAD} rather than throwing.
     * A malformed declaration must not be able to CREATE analytics data; failing closed is
     * the whole point.
     *
     * @param mixed $value Declared intent, or anything else.
     *
     * @return self The matching case, or {@see self::TYPEAHEAD}.
     *
     * @since  1.6.0
     */
    public static function fromDeclared(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return \is_string($value) ? (self::tryFrom($value) ?? self::TYPEAHEAD) : self::TYPEAHEAD;
    }
}
