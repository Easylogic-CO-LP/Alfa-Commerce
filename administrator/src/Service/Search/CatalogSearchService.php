<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Multi-entity catalogue search, as a capability the component owns.
 *
 * WHY THIS IS IN THE COMPONENT AND NOT IN A MODULE. Searching the catalogue across
 * products, categories and manufacturers (with per-entity caps, per-entity failure
 * isolation and engine-discovered completions) is a CAPABILITY. A module is a
 * LOCATION: somewhere on a page that a capability gets rendered into. While this logic
 * lived inside `mod_alfa_search`, every other surface that wanted the same behaviour had
 * exactly two options, and both were wrong: reimplement it, or reach into a module and
 * inherit its parameters, its language keys and its layout path. The storefront filter
 * input and the premium Enterprise search module are both such surfaces.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: each of these belongs to the calling surface, and
 * lumping them in here is what made the original class unreusable:
 *  - **No transport.** No JSON, no headers, no `Application::close()`. A caller that wants
 *    an AJAX endpoint writes one; a caller rendering server-side never pays for it.
 *  - **No presentation.** No `FileLayout`, no template path. Two modules render the same
 *    results differently; that is the entire point of a second presentation package.
 *  - **No minimum length.** A hard DoS ceiling lives here because it protects the server;
 *    a minimum is a UX choice each surface makes (a dropdown wants 2, a full results page
 *    may accept 1), so it is enforced by the caller.
 *  - **No language keys.** Failures surface as return values or a typed exception, so the
 *    component never owns a `MOD_*` string.
 *
 * WHAT IT RETURNS IS TYPED ({@see CatalogSearchResults}): sections of
 * {@see CatalogSearchHit} in presentation order, {@see SearchSuggestion} completions
 * with DECLARED entity labels, the engine's attribution handle, and the (still dormant)
 * merchant redirect channel. The service is the assembly point: the only place the
 * engine's answer, the models' hydrated rows and the catalogue's own vocabulary meet.
 *
 * @since  2.0.0
 */
final class CatalogSearchService
{
    /**
     * Longest keyword that will be executed at all.
     *
     * A HARD CEILING, NOT A PREFERENCE, which is why it is here and not a parameter.
     * The keyword reaches token expansion, and cost grows with the number of tokens, so an
     * unbounded string is a cheap way to make the server do expensive work. Compare
     * `TypoExpander`'s per-request lookup budget: the same class of defect, guarded twice
     * because one guard is at the surface and one is inside the engine.
     *
     * @var int
     *
     * @since  2.0.0
     */
    public const MAX_KEYWORD_LENGTH = 100;

    /**
     * How many candidate ids an auxiliary-entity ENGINE query asks for.
     *
     * OVER-FETCHED WELL PAST THE SECTION CAPS (6 by default) on purpose: the engine
     * holds no visibility, so the entity model's own state/ACL rules run AFTER
     * retrieval: asking for exactly the section size would let a hidden row underfill
     * the section while visible matches ranked just below the cut existed. The same
     * reasoning the item path's 1000-candidate cap has always encoded, scaled to
     * catalogues of hundreds rather than thousands.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const AUX_CANDIDATE_LIMIT = 50;

    /**
     * Joomla log category for everything this service reports.
     *
     * @var string
     *
     * @since  2.0.0
     */
    private const LOG_CATEGORY = 'com_alfa';

    /**
     * The active engine, resolved once per service instance.
     *
     * null = not resolved yet, false = resolved to "no active engine". One request's
     * search touches this up to three times (two aux entities + suggestions), and
     * every resolution repeats plugin discovery plus the engine's readiness probes,
     * per keystroke, on the hot path. Memoising also keeps the typo expander's
     * per-request lookup budget meaning what it says: one provider instance, one
     * budget.
     *
     *
     * @since  2.0.0
     */
    private SearchEngineInterface|false|null $activeEngine = null;

    /**
     * The catalogue provider, built once per service instance for the same reason.
     *
     *
     * @since  2.0.0
     */
    private ?SearchProviderInterface $catalogProvider = null;

    /**
     * @param MVCFactoryInterface $mvcFactory Factory the entity models are created from.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly MVCFactoryInterface $mvcFactory)
    {
    }

    /**
     * The active, ready engine (or null), memoised either way.
     *
     *
     * @since  2.0.0
     */
    private function activeEngine(): ?SearchEngineInterface
    {
        if ($this->activeEngine === null) {
            $this->activeEngine = (new SearchEngineRegistry())->active() ?? false;
        }

        return $this->activeEngine === false ? null : $this->activeEngine;
    }

    /**
     * The memoised catalogue provider.
     *
     *
     * @since  2.0.0
     */
    private function catalogProvider(): SearchProviderInterface
    {
        return $this->catalogProvider ??= (new SearchProviderFactory())->getCatalogProvider();
    }

    /**
     * Build a service against the running application's com_alfa factory.
     *
     * Convenience for the common case; the constructor stays available so a test or a CLI
     * consumer can supply its own factory without an application in the way.
     *
     *
     * @throws InvalidArgumentException When com_alfa cannot be booted.
     *
     * @since  2.0.0
     */
    public static function create(): self
    {
        $component = Factory::getApplication()->bootComponent('com_alfa');

        if (!method_exists($component, 'getMVCFactory')) {
            throw new InvalidArgumentException('com_alfa does not expose an MVC factory.');
        }

        return new self(mvcFactory: $component->getMVCFactory());
    }

    /**
     * Strip a raw user keyword down to something safe to store, echo and search with.
     *
     * PUBLIC BECAUSE CALLERS MUST MEASURE THE CLEANED STRING, NOT THE RAW ONE. A surface
     * enforcing its own minimum length has to apply this first, or `"  a  "` passes a
     * two-character minimum and then searches for `"a"`.
     *
     * NOT NORMALISATION: {@see QueryNormalizer} lowercases and gates on length, and runs
     * downstream inside the models. This only removes what must never travel: markup, NUL
     * bytes, and runs of whitespace. Case is PRESERVED on purpose, because the surface
     * still has to show the shopper what they typed.
     *
     * @param string $keyword Raw keyword straight from the request.
     *
     * @return string The cleaned keyword; '' when nothing usable remains.
     *
     * @since  2.0.0
     */
    public static function sanitize(string $keyword): string
    {
        return PlainText::collapse(str_replace("\0", '', strip_tags($keyword)));
    }

    /**
     * Run the request across every requested entity.
     *
     * PER-ENTITY FAILURE ISOLATION IS THE CONTRACT: one entity throwing is logged and
     * yields no section for that entity only. A shopper searching for a product must
     * still get products when the manufacturer model is broken, and a search box that
     * blanks entirely because one auxiliary lookup failed is the worse outcome. The
     * ceiling breach is the single exception: it is refused before any work starts.
     *
     * SECTION ORDER IS DECIDED HERE, AT ASSEMBLY: today's policy is the canonical
     * products -> categories -> manufacturers; the list shape is what lets a future
     * (premium) policy put the section a query names first without any contract change.
     *
     * @param CatalogSearchRequest $request What to search for, and how widely.
     *
     * @return CatalogSearchResults Typed sections in presentation order, completions,
     *                              and the attribution handle.
     *
     * @throws InvalidArgumentException When the keyword exceeds {@see MAX_KEYWORD_LENGTH}.
     *
     * @since  2.0.0
     */
    public function search(CatalogSearchRequest $request): CatalogSearchResults
    {
        $keyword = self::sanitize($request->keyword);

        if ($keyword === '') {
            return new CatalogSearchResults();
        }

        // ONE CHARACTER IS A SEARCH INTENT, NOT AN EMPTY QUERY. The models downstream
        // treat a keyword the normaliser rejects as "no search at all", correct for a
        // LISTING (a stray letter must not blank the catalogue), catastrophic here: the
        // unfiltered first page of the catalogue would be presented AS RESULTS for "s".
        // Refuse the search whole instead: every section answers empty, honestly.
        // The real sub-minimum retrieval (high-value fields, capped, popularity-ranked)
        // is designed as its own slice; see DESIGN-Incremental-Retrieval-Pipeline.
        if ((new QueryNormalizer())->normalize($keyword) === '') {
            return new CatalogSearchResults();
        }

        if (self::length($keyword) > self::MAX_KEYWORD_LENGTH) {
            throw new InvalidArgumentException(
                \sprintf('Search keyword exceeds %d characters.', self::MAX_KEYWORD_LENGTH),
            );
        }

        $sections = [];

        // Products: the engine path. The model's passback carries the analytics handle
        // out of retrieval; this envelope is how it reaches the SURFACE, which is the
        // only party that can stamp it on a click beacon.
        ['rows' => $rows, 'eventId' => $eventId] = $this->itemRows(
            keyword:     $keyword,
            limit:       $request->productLimit,
            withLayouts: $request->withLayouts,
            withImage:   $request->withImage,
            withPrice:   $request->withPrice,
            intent:      $request->intent,
        );

        $this->appendSection($sections, SearchEntity::ITEM, $rows);

        if ($request->withCategories) {
            $this->appendSection(
                $sections,
                SearchEntity::CATEGORY,
                $this->auxiliaryRows(
                    entity: SearchEntity::CATEGORY,
                    keyword: $keyword,
                    lang: $request->lang,
                    limit: $request->categoryLimit,
                    intent: $request->intent,
                ),
            );
        }

        if ($request->withManufacturers) {
            $this->appendSection(
                $sections,
                SearchEntity::MANUFACTURER,
                $this->auxiliaryRows(
                    entity: SearchEntity::MANUFACTURER,
                    keyword: $keyword,
                    lang: $request->lang,
                    limit: $request->manufacturerLimit,
                    intent: $request->intent,
                ),
            );
        }

        return new CatalogSearchResults(
            sections:    $this->orderByIntent($sections, $keyword, $request->lang),
            suggestions: $request->withSuggestions
                ? $this->suggestions(keyword: $keyword, lang: $request->lang, limit: $request->suggestionLimit)
                : [],
            eventId:     $eventId,
        );
    }

    /**
     * Completions only, WITHOUT running a catalogue search.
     *
     * SEPARATE ENTRY POINT BECAUSE THE COSTS ARE NOT COMPARABLE. Autocomplete runs on every
     * keystroke and touches only the term dictionary. Obtaining completions via
     * {@see self::search()} with a small `productLimit` looks equivalent and is not: it
     * executes catalogue retrieval whose rows are then discarded, and (the reason this
     * method exists) retrieval is what RECORDS A SEARCH EVENT. Routing type-ahead through
     * it wrote one analytics row per keystroke, fabricating zero-result rows for prefixes
     * nobody submitted and corrupting the single report the analytics subsystem exists to
     * produce.
     *
     * Never throws: autocomplete assists the search box and must never break it.
     *
     * @param string $keyword Raw prefix as typed; hygiene is applied here.
     * @param string $lang Language tag in display form.
     * @param int $limit Maximum completions.
     *
     * @return SearchSuggestion[] Suggestions, most relevant first, entity-labelled where
     *                            the catalogue declares one; [] when the active engine
     *                            keeps no dictionary.
     *
     * @since  2.0.0
     */
    public function suggest(string $keyword, string $lang, int $limit = 8): array
    {
        $keyword = self::sanitize($keyword);

        if ($keyword === '' || self::length($keyword) > self::MAX_KEYWORD_LENGTH) {
            return [];
        }

        return $this->suggestions(keyword: $keyword, lang: $lang, limit: $limit);
    }

    /**
     * Put the section the query NAMES first: the declared order-by-intent policy.
     *
     * DECLARED, NEVER INFERRED, like every classification in this subsystem: the only
     * evidence honoured is the normalised query exactly matching a stored, published
     * category or manufacturer name in this language, the same "ask the catalogue"
     * probe the suggestion labels use. A shopper typing a category's own name is
     * looking for the category; everyone else keeps the canonical order. One indexed
     * lookup, only when there is anything to reorder; any failure keeps the order.
     *
     * @param CatalogSearchSection[] $sections Sections in canonical order.
     * @param string $keyword Cleaned keyword.
     * @param string $lang Language tag in display form.
     *
     * @return CatalogSearchSection[]
     *
     * @since  2.0.0
     */
    private function orderByIntent(array $sections, string $keyword, string $lang): array
    {
        if (\count($sections) < 2) {
            return $sections;
        }

        try {
            $normalized = (new QueryNormalizer())->normalize($keyword);

            if ($normalized === '') {
                return $sections;
            }

            $claimed = $this->labelByDeclaredName(
                suggestions: [new SearchSuggestion(text: $normalized)],
                lang: $lang,
            )[0]->entity ?? null;

            if ($claimed === null || $claimed === SearchEntity::ITEM) {
                return $sections;
            }

            usort(
                $sections,
                static fn (CatalogSearchSection $a, CatalogSearchSection $b): int =>
                    (int) ($b->entity === $claimed) <=> (int) ($a->entity === $claimed),
            );
        } catch (Throwable $e) {
            // Ordering is presentation; a failed probe must never cost the results.
        }

        return $sections;
    }

    /**
     * Wrap one entity's rows into a section and append it, when it has any.
     *
     * EMPTY SECTIONS ARE NOT CARRIED (whether a section exists is a property of the
     * result), and a row the hit factory refuses (no positive id) is skipped and
     * logged rather than blanking its whole section: inside an assembly loop, one
     * malformed row must cost one row.
     *
     * @param CatalogSearchSection[] $sections The list being assembled, by reference.
     * @param SearchEntity $entity Whose rows these are.
     * @param object[] $rows Hydrated model rows, in rank order.
     *
     * @since  2.0.0
     */
    private function appendSection(array &$sections, SearchEntity $entity, array $rows): void
    {
        $hits = [];

        foreach ($rows as $row) {
            try {
                $hits[] = CatalogSearchHit::fromRow(entity: $entity, row: $row);
            } catch (InvalidArgumentException $e) {
                $this->log(\sprintf('%s row skipped', $entity->value), ['error' => $e->getMessage()]);
            }
        }

        if ($hits !== []) {
            $sections[] = new CatalogSearchSection(entity: $entity, hits: $hits);
        }
    }

    /**
     * Search the Items Site model and carry the engine's attribution handle out with
     * the rows.
     *
     * THE PASSBACK IS THE POINT: the provider mints the analytics event deep inside
     * `getListQuery()` and the model surfaces it via `getSearchEventId()`; without
     * reading it HERE, every service consumer would be structurally unable to attribute
     * a click, because the handle would die inside the model.
     *
     * @param string $keyword Cleaned keyword.
     * @param int $limit Maximum rows.
     * @param bool $withLayouts Item-field display surfaces on the rows.
     * @param SearchIntent $intent Why this search runs; reaches the engine's recorder.
     *
     * @return array{rows: object[], eventId: int|string|null}
     *
     * @since  2.0.0
     */
    private function itemRows(string $keyword, int $limit, bool $withLayouts, bool $withImage, bool $withPrice, SearchIntent $intent): array
    {
        try {
            $model = $this->mvcFactory->createModel('Items', 'Site', ['ignore_request' => true]);

            if (!$model) {
                return ['rows' => [], 'eventId' => null];
            }

            // Under `ignore_request` the constructor marks state as already set, so
            // populateState() NEVER runs on this model: the setters below are the only
            // state it will ever have, and the model's own request-driven intent
            // declaration cannot overwrite this one. (A prime read is kept harmless.)
            $model->getState('list.ordering');

            $model->setState('search.intent', $intent->value);
            $model->setState('filter.state', '1');
            $model->setState('filter.search', $keyword);
            $model->setState('list.limit', max(1, $limit));
            $model->setState('list.start', 0);
            $model->setState('plugin.layouts', $withLayouts);
            // Enrichment opt-outs, same idiom as plugin.layouts: a typeahead that
            // shows neither pictures nor prices must not pay the media query or the
            // per-user pricing batch on every keystroke.
            $model->setState('list.media', $withImage);
            $model->setState('list.price', $withPrice);

            $items = $model->getItems();

            return [
                'rows' => \is_array($items) ? $items : [],
                'eventId' => method_exists($model, 'getSearchEventId') ? $model->getSearchEventId() : null,
            ];
        } catch (Throwable $e) {
            $this->log('Items search failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return ['rows' => [], 'eventId' => null];
        }
    }

    /**
     * Auxiliary-entity rows: through the ENGINE when it can serve them, through the
     * model-LIKE path that has always answered otherwise.
     *
     * THE ROUTING RULE: the engine path is taken only when the active engine DECLARES
     * the capability for this entity ({@see EntitySearchInterface}), which an honest
     * engine ties to what its serving generation actually contains. Everything else (
     * no engine, engine not ready, capability absent, a keyword the normaliser rejects,
     * any failure) falls back to the LIKE path, so the floor contract never degrades.
     * An EMPTY engine answer is NOT a fallback trigger: the entity is indexed and
     * nothing matched, and re-asking LIKE would just second-guess the engine with a
     * weaker matcher.
     *
     * THE HYDRATION RULE (over-fetch, hydrate ALL, reorder, THEN slice): every ranked
     * candidate is re-read through the entity's own Site model (state + ACL stay with
     * the catalogue) with `list.limit = count(ids)`, so the model's own ORDER BY can
     * never decide WHICH candidates survive; the engine's rank is restored in PHP and
     * only then is the section cap applied. Anything else re-creates the
     * truncation-reorder defect the item path already documents.
     *
     * @param SearchEntity $entity CATEGORY or MANUFACTURER.
     * @param string $keyword Cleaned keyword.
     * @param string $lang Language tag in display form.
     * @param int $limit Section cap.
     * @param SearchIntent $intent Why this search runs - reaches the engine query, so
     *                             an aux section widens (or refuses to) exactly as the
     *                             item section does for the same request.
     *
     * @return object[] Hydrated rows in engine rank order; [] when nothing matched.
     *
     * @since  2.0.0
     */
    private function auxiliaryRows(SearchEntity $entity, string $keyword, string $lang, int $limit, SearchIntent $intent): array
    {
        $modelName = $entity === SearchEntity::CATEGORY ? 'Categories' : 'Manufacturers';

        $ranked = $this->engineEntityIds(entity: $entity, keyword: $keyword, lang: $lang, intent: $intent);

        if ($ranked === null) {
            return $this->rows(entity: $modelName, keyword: $keyword, limit: $limit);
        }

        if ($ranked === []) {
            return [];
        }

        try {
            $model = $this->mvcFactory->createModel($modelName, 'Site', ['ignore_request' => true]);

            if (!$model) {
                return [];
            }

            $model->getState('list.ordering');

            $model->setState(
                $entity === SearchEntity::CATEGORY ? 'filter.categories' : 'filter.manufacturers',
                $ranked,
            );
            $model->setState('list.limit', \count($ranked));
            $model->setState('list.start', 0);

            $items = $model->getItems();
            $rows = \is_array($items) ? $items : [];

            $rank = array_flip($ranked);

            usort(
                $rows,
                static fn (object $a, object $b): int =>
                    ($rank[(int) ($a->id ?? 0)] ?? PHP_INT_MAX) <=> ($rank[(int) ($b->id ?? 0)] ?? PHP_INT_MAX),
            );

            return \array_slice($rows, 0, max(1, $limit));
        } catch (Throwable $e) {
            $this->log(\sprintf('%s hydration failed', $entity->value), ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Ranked candidate ids for an auxiliary entity from the active engine, or null
     * when the engine path is UNAVAILABLE (which is not the same as empty).
     *
     * Null routes the caller to the LIKE fallback; [] is an honest "indexed, no
     * match". The result's echoed entity is verified before its ids are trusted:
     * ids from the wrong domain would hydrate someone else's rows.
     *
     * @param SearchEntity $entity CATEGORY or MANUFACTURER.
     * @param string $keyword Cleaned keyword (service hygiene already applied).
     * @param string $lang Language tag in display form.
     * @param SearchIntent $intent The request's declared intent. THIS MUST TRAVEL: the
     *                             intent now selects retrieval width (TYPEAHEAD carries
     *                             the enterprise prefix group), so an aux query left on
     *                             the constructor default would hand prefix-widened
     *                             sections to a declared SEARCH, and the fail-closed
     *                             default would fail OPEN into the widest retrieval.
     *
     * @return int[]|null Ranked ids, [] for no match, null for "use the fallback".
     *
     * @since  2.0.0
     */
    private function engineEntityIds(SearchEntity $entity, string $keyword, string $lang, SearchIntent $intent): ?array
    {
        try {
            $engine = $this->activeEngine();

            if (!$engine instanceof EntitySearchInterface || !$engine->canSearch($entity)) {
                return null;
            }

            // The same normalisation the item path applies before ITS provider call:
            // a keyword the normaliser rejects (too short, whitespace) is "no search
            // at all", which on this path means the LIKE fallback keeps answering.
            $normalized = (new QueryNormalizer())->normalize($keyword);

            if ($normalized === '') {
                return null;
            }

            $result = $this->catalogProvider()->search(
                SearchQuery::forCurrentVisitor(
                    keyword: $normalized,
                    lang:    $lang,
                    limit:   self::AUX_CANDIDATE_LIMIT,
                    intent:  $intent,
                    entity:  $entity,
                ),
            );

            if ($result->entity !== $entity) {
                $this->log(\sprintf(
                    'Search provider answered entity "%s" to a %s query.',
                    $result->entity->value,
                    $entity->value,
                ));

                return null;
            }

            return array_map('intval', $result->ids());
        } catch (Throwable $e) {
            $this->log(\sprintf('%s engine search failed', $entity->value), ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Search one auxiliary entity's Site list model: the LIKE path, and the permanent
     * fallback of {@see auxiliaryRows()}.
     *
     * `ignore_request` is not optional, and it does TWO jobs: the model would otherwise
     * populate its state from the current request (a shopper on a filtered category page
     * would get those filters silently applied to an unrelated search), and it marks the
     * state as already set, so `populateState()` never runs and cannot overwrite the
     * setters below.
     *
     * @param string $entity Model name: `Categories` or `Manufacturers`.
     * @param string $keyword Cleaned keyword.
     * @param int $limit Maximum rows.
     *
     * @return object[] Matched rows; [] when the model is unavailable or threw.
     *
     * @since  2.0.0
     */
    private function rows(string $entity, string $keyword, int $limit): array
    {
        try {
            $model = $this->mvcFactory->createModel($entity, 'Site', ['ignore_request' => true]);

            if (!$model) {
                return [];
            }

            $model->getState('list.ordering');

            $model->setState('filter.state', '1');
            $model->setState('filter.search', $keyword);
            $model->setState('list.limit', max(1, $limit));
            $model->setState('list.start', 0);

            $items = $model->getItems();

            return \is_array($items) ? $items : [];
        } catch (Throwable $e) {
            $this->log(\sprintf('%s search failed', $entity), ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Completions for the typed keyword, from the active engine's dictionary, with
     * DECLARED entity labels layered on.
     *
     * Progressive enhancement discovered by CAPABILITY, not by configuration: only an
     * engine that keeps a term dictionary implements
     * {@see SuggestionProviderInterface}, and only a ready, configured engine is returned
     * as active. The database baseline, the flat Indexed engine and an engine mid-rebuild
     * all fall through to []. Never throws: autocomplete assists the search box and must
     * never be able to break it.
     *
     * A stale engine returning bare strings is tolerated (wrapped, unlabelled) rather
     * than fatal: the interface changed shape in 1.9.0 and a lagging third-party
     * implementor must degrade, not break the box.
     *
     * @param string $keyword Cleaned keyword, used here as a completion PREFIX.
     * @param string $lang Language tag in display form.
     * @param int $limit Maximum completions.
     *
     * @return SearchSuggestion[] Suggestions, most relevant first.
     *
     * @since  2.0.0
     */
    private function suggestions(string $keyword, string $lang, int $limit): array
    {
        try {
            $engine = $this->activeEngine();

            if (!$engine instanceof SuggestionProviderInterface) {
                return [];
            }

            $suggestions = [];

            foreach ($engine->suggest($keyword, $lang, max(1, $limit)) as $offered) {
                if ($offered instanceof SearchSuggestion) {
                    $suggestions[] = $offered;
                } elseif (\is_string($offered) && trim($offered) !== '') {
                    $suggestions[] = new SearchSuggestion(text: $offered);
                }
            }

            return $this->labelByDeclaredName(suggestions: $suggestions, lang: $lang);
        } catch (Throwable $e) {
            $this->log('Suggestions failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Label suggestions whose text the catalogue DECLARES to be an entity name.
     *
     * DECLARED, NEVER INFERRED (the identifier-guard discipline): a label is attached
     * only when the text exactly matches a published category or manufacturer name in
     * the searched language (one query over both name tables for the whole batch,
     * collation doing the case folding). Statistical inference from posting-field
     * dominance was rejected: brand words live in titles too, so dominance coin-flips
     * on real catalogues and the wrong label reaches shoppers.
     *
     * A name found in BOTH tables is ambiguous and stays unlabelled; null means "no
     * claim made". Any failure returns the suggestions untouched: a label is an
     * enhancement, never a dependency.
     *
     * @param SearchSuggestion[] $suggestions The engine's completions.
     * @param string $lang Language tag in display form.
     *
     * @return SearchSuggestion[] The same suggestions, labelled where declared.
     *
     * @since  2.0.0
     */
    private function labelByDeclaredName(array $suggestions, string $lang): array
    {
        if ($suggestions === []) {
            return [];
        }

        try {
            $suffix = LanguageTag::from(LanguageTag::normalize($lang))->tableSuffix();

            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get('DatabaseDriver');

            // THE PROBE TEXTS THEMSELVES ARE THE JOIN SOURCE, and the query returns THEM,
            // never the stored names. The name columns' collation (utf8mb4_unicode_ci)
            // does all the folding (case, accents, final sigma) INSIDE the join, and
            // the claims map keys on our own suggestion bytes, so no PHP re-fold has to
            // mirror MySQL's rules. Re-folding in PHP was the first implementation and it
            // diverged exactly where the collation is cleverer than mb_strtolower: 'Café'
            // (category) + 'Cafe' (manufacturer) landed under different keys and the
            // ambiguity rule never fired.
            // Hand-quoted literals (house rule: bindings do not survive query-string
            // assembly, and a wrongly typed dataType binds silently as 0).
            $probes = implode(' UNION ALL ', array_map(
                static fn (SearchSuggestion $s): string => 'SELECT ' . $db->q($s->text) . ' AS ' . $db->qn('probe'),
                $suggestions,
            ));

            $claimArm = static fn (string $entityValue, string $langTable, string $base, string $fk): string =>
                'SELECT DISTINCT ' . $db->qn('x.probe') . ', ' . $db->q($entityValue) . ' AS ' . $db->qn('entity')
                . ' FROM (' . $probes . ') AS ' . $db->qn('x')
                . ' INNER JOIN ' . $db->qn($langTable, 'l')
                . ' ON ' . $db->qn('l.name') . ' = ' . $db->qn('x.probe')
                . ' INNER JOIN ' . $db->qn($base, 'b')
                . ' ON ' . $db->qn('b.id') . ' = ' . $db->qn('l.' . $fk)
                . ' AND ' . $db->qn('b.state') . ' = 1';

            $sql = $claimArm(SearchEntity::CATEGORY->value, '#__alfa_categories_' . $suffix, '#__alfa_categories', 'id_category')
                . ' UNION ALL '
                . $claimArm(SearchEntity::MANUFACTURER->value, '#__alfa_manufacturers_' . $suffix, '#__alfa_manufacturers', 'id_manufacturer');

            $claims = [];

            foreach ($db->setQuery($sql)->loadAssocList() ?: [] as $row) {
                $key = (string) $row['probe'];
                $entity = SearchEntity::from((string) $row['entity']);

                // Two different claims on one probe = ambiguous = no claim. array_key_exists,
                // not isset: an ambiguity already recorded as null must stay null when a
                // third row arrives, and isset() cannot see a null entry.
                $claims[$key] = \array_key_exists($key, $claims) && $claims[$key] !== $entity ? null : $entity;
            }

            if ($claims === []) {
                return $suggestions;
            }

            return array_map(
                static function (SearchSuggestion $s) use ($claims): SearchSuggestion {
                    $claim = $claims[$s->text] ?? null;

                    return $claim === null ? $s : new SearchSuggestion(text: $s->text, entity: $claim);
                },
                $suggestions,
            );
        } catch (Throwable $e) {
            $this->log('Suggestion labelling failed', ['error' => $e->getMessage()]);

            return $suggestions;
        }
    }

    /**
     * Keyword length in characters, matching how the ceiling is documented.
     *
     * @param string $keyword Cleaned keyword.
     *
     * @return int Character count.
     *
     * @since  2.0.0
     */
    private static function length(string $keyword): int
    {
        return \function_exists('mb_strlen') ? mb_strlen($keyword) : \strlen($keyword);
    }

    /**
     * Record a non-fatal failure without letting logging itself become one.
     *
     * @param string $message Short description.
     * @param array<string, mixed> $context Extra detail, JSON-encoded onto the line.
     *
     * @since  2.0.0
     */
    private function log(string $message, array $context = []): void
    {
        try {
            Log::add(
                $message . ($context !== [] ? ' | ' . json_encode($context) : ''),
                Log::ERROR,
                self::LOG_CATEGORY,
            );
        } catch (Throwable) {
            error_log('com_alfa search: ' . $message);
        }
    }
}
