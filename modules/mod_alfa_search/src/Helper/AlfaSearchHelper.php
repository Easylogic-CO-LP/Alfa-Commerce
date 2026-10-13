<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaSearch\Site\Helper;

use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchHit;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchRequest;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchResults;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogSearchService;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEntity;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchSuggestion;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Registry\Registry;
use Throwable;

\defined('_JEXEC') or die;

/**
 * AJAX transport for mod_alfa_search.
 *
 * TRANSPORT AND PRESENTATION ONLY. Searching the catalogue is a component capability
 * ({@see CatalogSearchService}): this class maps THIS module's parameters onto a request,
 * renders THIS module's layout, and speaks HTTP. That split is what lets a second
 * presentation package (the premium Enterprise search module) and the storefront filter
 * input offer the same search without reimplementing it or reaching into this module.
 *
 * Reached by convention, not by a reference: `com_ajax` resolves
 * `option=com_ajax&module=alfa_search&method=get` to `getAjax()` on this class. Grep will
 * therefore never show a caller; renaming or moving it breaks the endpoint silently.
 *
 * @since  1.0.0
 */
class AlfaSearchHelper
{
    /**
     * Maximum execution time in seconds
     */
    private const MAX_EXECUTION_TIME = 25;

    /**
     * Handle AJAX search requests
     *
     * @return void
     * @since  1.0.0
     */
    public static function getAjax()
    {
        try {
            // Set the execution time limit to prevent server hanging
            @set_time_limit(self::MAX_EXECUTION_TIME);

            $app = Factory::getApplication();

            $module = ModuleHelper::getModule('mod_alfa_search');

            if (!$module) {
                self::sendError(Text::_('MOD_ALFA_SEARCH_ERROR_MODULE_NOT_FOUND'), 404);
                return;
            }

            $app->getLanguage()->load('mod_alfa_search', JPATH_SITE);

            $params = new Registry($module->params);
            $keyword = self::getAndValidateKeyword($app, $params);

            $results = CatalogSearchService::create()->search(
                self::request(keyword: $keyword, lang: $app->getLanguage()->getTag(), params: $params),
            );

            self::renderAndSend($keyword, $results, $params);
        } catch (Exception $e) {
            self::logError('AJAX request failed', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            self::sendError(Text::_('MOD_ALFA_SEARCH_ERROR_GENERAL'), 500);
        }
    }

    /**
     * Map this module's parameters onto a component search request.
     *
     * The mapping is explicit rather than a factory on the request, so the component never
     * has to know that a parameter called `show_categories` exists anywhere.
     *
     * `withLayouts: false` keeps this a data-only lookup: the dropdown shows name, sku and
     * price, so rendering item-field selectors and cart layouts would be work thrown away.
     * A results page that renders add-to-cart controls passes true.
     *
     * @param string $keyword Validated keyword.
     * @param string $lang Current language tag.
     * @param Registry $params Module parameters.
     *
     *
     * @since  2.0.0
     */
    private static function request(string $keyword, string $lang, Registry $params): CatalogSearchRequest
    {
        return new CatalogSearchRequest(
            keyword: $keyword,
            lang: $lang,
            withCategories: (bool) $params->get('show_categories', 0),
            categoryLimit: (int) $params->get('categories_limit', 6),
            withManufacturers: (bool) $params->get('show_manufacturers', 0),
            manufacturerLimit: (int) $params->get('manufacturers_limit', 6),
            withSuggestions: (bool) $params->get('show_suggestions', 1),
            suggestionLimit: max(1, (int) $params->get('suggestions_limit', 8)),
            withLayouts: false,
        );
    }

    /**
     * Get and validate search keyword
     *
     * The MINIMUM is this module's call (a dropdown that fires on one character is noise),
     * so it is enforced here. The hard upper ceiling is the component's, because it exists
     * to protect the server rather than to shape the UX, but it is still reported with
     * this module's own message.
     *
     * @param object $app Application instance
     * @param Registry $params Module parameters
     *
     * @return string Validated keyword
     * @throws Exception
     * @since  1.0.0
     */
    private static function getAndValidateKeyword($app, Registry $params): string
    {
        $keyword = CatalogSearchService::sanitize($app->input->getString('query', ''));

        // FLOOR OF TWO, whatever the merchant configured: the search service refuses a
        // keyword below its own minimum outright (one character is a search intent it
        // cannot yet answer honestly, and answering it with the unfiltered catalogue was
        // the defect that closed the question). A module set to 1 would accept the
        // keystroke here and then have nothing to show for it, so the too-short message
        // is the truthful answer rather than an empty dropdown.
        $minCharacters = max(2, (int) $params->get('minCharacters', 2));

        if (empty($keyword)) {
            throw new Exception(Text::_('MOD_ALFA_SEARCH_ERROR_EMPTY'), 400);
        }

        if (mb_strlen($keyword) < $minCharacters) {
            throw new Exception(
                Text::sprintf('MOD_ALFA_SEARCH_ERROR_TOO_SHORT', $minCharacters),
                400,
            );
        }

        if (mb_strlen($keyword) > CatalogSearchService::MAX_KEYWORD_LENGTH) {
            throw new Exception(Text::_('MOD_ALFA_SEARCH_ERROR_TOO_LONG'), 400);
        }

        return $keyword;
    }

    /**
     * Render layout and send JSON response
     *
     * @param string $keyword Search keyword
     * @param CatalogSearchResults $results Search results
     * @param Registry $params Module parameters
     *
     * @since  1.0.0
     */
    private static function renderAndSend(string $keyword, CatalogSearchResults $results, Registry $params): void
    {
        try {
            $layout = new FileLayout('result', JPATH_ROOT . '/modules/mod_alfa_search/tmpl');

            // THIS MODULE'S WIRE AND MARKUP ARE ITS OWN MINI-CONTRACT and deliberately did
            // NOT adopt the typed shapes when the service did: the layout keeps rendering
            // the models' rows and the wire keeps plain suggestion strings, byte-identical
            // to what shipped. The typed result is unwrapped here, at the presentation
            // boundary, which is exactly where entity-specific rendering belongs.
            $rowsFor = static fn (SearchEntity $entity): array => array_map(
                static fn (CatalogSearchHit $hit): object => $hit->row,
                $results->section($entity)?->hits ?? [],
            );

            $suggestionTexts = array_map(
                static fn (SearchSuggestion $suggestion): string => $suggestion->text,
                array_values($results->suggestions),
            );

            $html = $layout->render([
                'params' => $params,
                'products' => $rowsFor(SearchEntity::ITEM),
                'categories' => $rowsFor(SearchEntity::CATEGORY),
                'manufacturers' => $rowsFor(SearchEntity::MANUFACTURER),
                'suggestions' => $suggestionTexts,
            ]);

            $totalCount = $results->total();

            $data = [
                'query' => $keyword,
                'html' => $html,
                'count' => $totalCount,
                'suggestions' => $suggestionTexts,
            ];

            header('Content-Type: application/json; charset=utf-8');

            $message = $totalCount > 0
                ? Text::sprintf('MOD_ALFA_SEARCH_SUCCESS_COUNT', $totalCount)
                : Text::_('MOD_ALFA_SEARCH_NO_RESULTS');

            $response = new JsonResponse($data, $message, false);
            echo $response;

            Factory::getApplication()->close();
        } catch (Exception $e) {
            self::logError('Render failed', ['error' => $e->getMessage()]);
            self::sendError(Text::_('MOD_ALFA_SEARCH_ERROR_RENDER'), 500);
        }
    }

    /**
     * Send error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     *
     * @since  1.0.0
     */
    private static function sendError(string $message, int $statusCode = 500): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $response = new JsonResponse(null, $message, true);
        echo $response;

        Factory::getApplication()->close();
    }

    /**
     * Log error with context
     *
     * @param string $message Error message
     * @param array $context Additional context
     *
     * @since  1.0.0
     */
    private static function logError(string $message, array $context = []): void
    {
        try {
            $contextString = !empty($context) ? ' | ' . json_encode($context) : '';
            Log::add($message . $contextString, Log::ERROR, 'mod_alfa_search');
        } catch (Throwable $e) {
            error_log('mod_alfa_search: ' . $message);
        }
    }
}
