<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchPayload;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchRequest;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchResults;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchService;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchIntent;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Throwable;

/**
 * The component's public search boundary.
 *
 * ONE PLACE THE APPLICATION EXPOSES SEARCH, AND IT RETURNS DATA, NOT HTML. That
 * distinction is the whole design, so it is worth stating plainly: a component controller
 * must never render a module's template, because the moment it does, the component owns
 * that module's presentation and a second presentation package becomes impossible. So this
 * boundary publishes the semantic result ({@see CatalogSearchPayload}); each surface renders
 * it however it likes.
 *
 * WHICH MEANS A PRESENTATION PACKAGE STILL KEEPS ITS OWN RENDER TRANSPORT: `mod_alfa_search`
 * continues to answer `com_ajax` with its own HTML dropdown, and the premium module will do
 * the same with its own markup. That is NOT the duplication the architecture warns about:
 * duplicated SEARCH LOGIC is the hazard, and it is now impossible, because every path goes
 * through {@see CatalogSearchService}. Duplicated RENDERING is not a hazard; it is the
 * product.
 *
 * Consumers this exists for: the catalogue filter input, the premium module, third-party
 * templates, and any API integration.
 *
 * NO CSRF TOKEN, DELIBERATELY. These are side-effect-free reads of published catalogue data
 * (the same rows the storefront renders to anonymous visitors) and requiring a session
 * token would break exactly the consumers this is for (a cached template, a third-party
 * integration) while protecting nothing: there is no state to forge. Abuse is bounded
 * instead by what actually bounds it: the keyword ceiling in
 * {@see CatalogSearchService::MAX_KEYWORD_LENGTH}, the clamped limits below, and the
 * engine's own per-request lookup budget.
 *
 * @since  2.0.0
 */
class SearchController extends BaseController
{
    /**
     * Largest number of rows a caller may request per section.
     *
     * CLAMPED, NOT VALIDATED: an out-of-range limit is quietly reduced rather than refused,
     * because a caller asking for too much wants results, not an error, and refusing turns
     * a tuning mistake into a broken search box. The cap exists because the limit reaches a
     * SQL `LIMIT` and an unbounded one is a cheap way to make the server assemble a very
     * large response.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const MAX_LIMIT = 50;

    /**
     * Completions for a prefix.
     *
     * Separate from {@see search()} because it is a genuinely different request: it runs on
     * every keystroke, it touches only the term dictionary, and it must never pay for a
     * catalogue query. Folding it into `search()` with a flag would mean a type-ahead
     * request built product rows it then discarded.
     *
     *
     * @since  2.0.0
     */
    public function suggest(): void
    {
        $this->respond(function (): array {
            $keyword = $this->keyword();

            if ($keyword === '') {
                // Same keys as the populated path: empty response shape == populated
                // response shape is the wire's headline invariant.
                return ['contract' => CatalogSearchPayload::CONTRACT, 'query' => '', 'suggestions' => []];
            }

            // Dictionary only, NOT a catalogue search. Going through search() would execute
            // retrieval whose rows are discarded and, worse, would record an analytics search
            // event per keystroke. See CatalogSearchService::suggest().
            // `suggestions_limit`, matching the search endpoint's vocabulary; on that
            // sibling endpoint `suggestions` is the on/off FLAG, so reading it here as a
            // count would turn a consumer's copied `suggestions=1` into "one completion".
            $suggestions = CatalogSearchService::create()->suggest(
                keyword: $keyword,
                lang: $this->app->getLanguage()->getTag(),
                limit: $this->limit(name: 'suggestions_limit', default: 8),
            );

            return [
                'contract' => CatalogSearchPayload::CONTRACT,
                'query' => $keyword,
                'suggestions' => CatalogSearchPayload::suggestions($suggestions),
            ];
        });
    }

    /**
     * Search the catalogue and return the semantic result.
     *
     *
     * @since  2.0.0
     */
    public function search(): void
    {
        $this->respond(function (): array {
            $keyword = $this->keyword();

            if ($keyword === '') {
                return CatalogSearchPayload::fromResults(results: new CatalogSearchResults(), query: '');
            }

            $input = $this->input;

            $fields = $this->fields();

            $started = microtime(true);

            $results = CatalogSearchService::create()->search(new CatalogSearchRequest(
                keyword: $keyword,
                lang: $this->app->getLanguage()->getTag(),
                productLimit: $this->limit(name: 'limit', default: 20),
                withImage: \in_array('image', $fields, true),
                withPrice: \in_array('price', $fields, true),
                withCategories: (bool) $input->getInt('categories', 1),
                categoryLimit: $this->limit(name: 'categories_limit', default: 6),
                withManufacturers: (bool) $input->getInt('manufacturers', 1),
                manufacturerLimit: $this->limit(name: 'manufacturers_limit', default: 6),
                withSuggestions: (bool) $input->getInt('suggestions', 1),
                suggestionLimit: $this->limit(name: 'suggestions_limit', default: 8),
                withLayouts: false,
                // DEFAULTS TO TYPEAHEAD, because that is what actually calls this endpoint:
                // the storefront widget queries it per keystroke, while a search the shopper
                // SUBMITS goes to the catalogue listing page, which declares SEARCH itself.
                // A consumer rendering its own results page (a third-party template, an
                // integration) declares `intent=search` to be counted.
                //
                // IT NO LONGER ONLY SELECTS RECORDING. An engine may read the intent to
                // choose retrieval WIDTH (the Enterprise engine completes the final token
                // as a prefix for a shopper still typing, and refuses to for a search they
                // expressed) and this endpoint's candidate cap follows it. Still safely
                // spoofable: both settings are legitimate answers to the same catalogue,
                // and the only search a caller can affect is its own. What a declaration
                // buys is the right semantics for the surface making it; what a false one
                // costs is worse completions for that caller alone.
                intent: SearchIntent::fromDeclared($input->getCmd('intent', '')),
            ));

            return CatalogSearchPayload::fromResults(
                results: $results,
                query: $keyword,
                elapsedMs: (microtime(true) - $started) * 1000,
                fields: $fields,
            );
        });
    }

    /**
     * The requested row enrichments, reduced to what this shop actually publishes.
     *
     * OPT-IN BY REQUEST (`fields=image,price`), per the payload's enrichment doctrine:
     * the base row stays `{id, name, url, description}` and a consumer that renders
     * pictures or prices declares so; nobody else pays for them. Unknown names are
     * silently dropped (a typo wants results, not an error).
     *
     * PRICE IS ALSO GATED BY THE SHOP: when the storefront's own price settings hide
     * the final price (a call-for-price catalogue, a members-only shop), the search
     * wire must not leak what the product page refuses to show: the field is removed
     * HERE, so the service never even runs the pricing pass.
     *
     * @return string[] Subset of {image, price}.
     *
     * @since  2.0.0
     */
    private function fields(): array
    {
        $fields = array_values(array_intersect(
            array_map('trim', explode(',', $this->input->getString('fields', ''))),
            ['image', 'price'],
        ));

        if (\in_array('price', $fields, true) && empty(PriceSettings::get()['final_price_show'])) {
            $fields = array_values(array_diff($fields, ['price']));
        }

        return $fields;
    }

    /**
     * The keyword from the request, cleaned by the component's own hygiene.
     *
     * Accepts `q` or `query`: `q` is the conventional name for a public search endpoint,
     * while `query` is what the shipped module's JS already sends, and rejecting one of them
     * would be a gratuitous incompatibility.
     *
     * No MINIMUM is enforced here; that is each surface's UX choice, per the split
     * documented on {@see CatalogSearchService}. An empty keyword returns an empty result
     * rather than an error, because a cleared search box is a normal state, not a failure.
     *
     * @return string The cleaned keyword; '' when there is nothing to search for.
     *
     * @since  2.0.0
     */
    private function keyword(): string
    {
        $raw = $this->input->getString('q', '') ?: $this->input->getString('query', '');

        return CatalogSearchService::sanitize($raw);
    }

    /**
     * A clamped positive limit from the request.
     *
     * @param string $name Request variable name.
     * @param int $default Value when absent or non-positive.
     *
     * @return int Between 1 and {@see MAX_LIMIT}.
     *
     * @since  2.0.0
     */
    private function limit(string $name, int $default): int
    {
        $requested = $this->input->getInt($name, $default);

        return max(1, min(self::MAX_LIMIT, $requested > 0 ? $requested : $default));
    }

    /**
     * Run a handler and send its return value as JSON, converting any failure into a JSON
     * error rather than an HTML error page.
     *
     * A JSON CONSUMER MUST NEVER RECEIVE HTML. Joomla's default error handling would render
     * a themed error page, which a `fetch()` caller parses as a broken response with no
     * usable reason: the failure mode is a search box that silently stops working. The
     * reason it answers with is a stable one, and the failure's own words go to the log:
     * no endpoint here throws deliberately, so everything that arrives is infrastructure.
     *
     * @param callable():array $handler Produces the response payload.
     *
     *
     * @since  2.0.0
     */
    private function respond(callable $handler): void
    {
        try {
            $payload = $handler();
            $response = new JsonResponse($payload, null, false);
        } catch (Throwable $e) {
            // NOTHING HERE THROWS ON PURPOSE. Every exception that reaches this
            // point is the machinery failing, so the reasoning this method was
            // written with (that the message reveals no more than the request
            // already carried) does not describe what actually arrives: a
            // driver failure names tables, and depending on where the connection
            // died, the account the site connects as. A caller still gets 400
            // and a reason it can act on; the rest is in the log.
            AlfaLog::register();
            Log::add('Search endpoint failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.search');

            $this->app->setHeader('status', 400, true);
            $response = new JsonResponse(null, Text::_('COM_ALFA_SEARCH_FAILED'), true);
        }

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();

        echo $response;

        $this->app->close();
    }
}
