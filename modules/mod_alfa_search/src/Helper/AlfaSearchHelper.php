<?php

namespace Alfa\Module\AlfaSearch\Site\Helper;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Helper for mod_alfa_search
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
     * Maximum keyword length to prevent DoS
     */
    private const MAX_KEYWORD_LENGTH = 100;

    /**
     * Handle AJAX search requests
     *
     * @return void
     * @since 1.0.0
     */
    public static function getAjax()
    {
        try {
            // Set the execution time limit to prevent server hanging
            @set_time_limit(self::MAX_EXECUTION_TIME);

            // Initialize application
            $app = Factory::getApplication();

            // Get module
            $module = ModuleHelper::getModule('mod_alfa_search');
            if (!$module) {
                self::sendError(Text::_('MOD_ALFA_SEARCH_ERROR_MODULE_NOT_FOUND'), 404);
                return;
            }

            // Load language
            $app->getLanguage()->load('mod_alfa_search', JPATH_SITE);

            // Get module parameters
            $params = new Registry($module->params);

            // Get and validate search keyword
            $keyword = self::getAndValidateKeyword($app, $params);

            // Initialize component
            $mvcFactory = self::initializeComponent($app);

            // Perform searches based on module parameters
            $results = self::performSearches($mvcFactory, $keyword, $params);

            // Render and send results (layout handles empty check)
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
     * Get and validate search keyword
     *
     * @param object $app Application instance
     * @param Registry $params Module parameters
     *
     * @return string Validated keyword
     * @throws Exception
     * @since   1.0.0
     */
    private static function getAndValidateKeyword($app, Registry $params): string
    {
        $keyword = trim($app->input->getString('query', ''));
        $keyword = self::sanitizeKeyword($keyword);

        $minCharacters = (int) $params->get('minCharacters', 2);

        if (empty($keyword)) {
            throw new Exception(Text::_('MOD_ALFA_SEARCH_ERROR_EMPTY'), 400);
        }

        if (mb_strlen($keyword) < $minCharacters) {
            throw new Exception(
                Text::sprintf('MOD_ALFA_SEARCH_ERROR_TOO_SHORT', $minCharacters),
                400,
            );
        }

        if (mb_strlen($keyword) > self::MAX_KEYWORD_LENGTH) {
            throw new Exception(Text::_('MOD_ALFA_SEARCH_ERROR_TOO_LONG'), 400);
        }

        return $keyword;
    }

    /**
     * Sanitize search keyword
     *
     * @param string $keyword Raw keyword
     *
     * @return string Sanitized keyword
     * @since   1.0.0
     */
    private static function sanitizeKeyword(string $keyword): string
    {
        $keyword = strip_tags($keyword);
        $keyword = str_replace("\0", '', $keyword);
        $keyword = preg_replace('/\s+/', ' ', $keyword);

        return trim($keyword);
    }

    /**
     * Initialize component and get MVC factory
     *
     * @param object $app Application instance
     *
     * @return mixed MVC Factory instance
     * @throws Exception
     * @since   1.0.0
     */
    private static function initializeComponent($app)
    {
        $component = $app->bootComponent('com_alfa');
        if (!$component) {
            throw new Exception('Component initialization failed', 500);
        }

        $mvcFactory = $component->getMVCFactory();
        if (!$mvcFactory) {
            throw new Exception('MVC Factory not available', 500);
        }

        return $mvcFactory;
    }

    /**
     * Perform all enabled searches based on module parameters
     *
     * @param mixed $mvcFactory MVC Factory instance
     * @param string $keyword Search keyword
     * @param Registry $params Module parameters
     *
     * @return array Search results
     * @since   1.0.0
     */
    private static function performSearches($mvcFactory, string $keyword, Registry $params): array
    {
        $results = [
            'products' => [],
            'categories' => [],
            'manufacturers' => [],
        ];

        // Search products (always enabled)
        try {
            $results['products'] = self::searchProducts($mvcFactory, $keyword);
        } catch (Exception $e) {
            self::logError('Product search failed', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }

        // Search categories if enabled
        if ($params->get('show_categories', 0)) {
            try {
                $categoryLimit = (int) $params->get('categories_limit', 6);
                $results['categories'] = self::searchCategories($mvcFactory, $keyword, $categoryLimit);
            } catch (Exception $e) {
                self::logError('Category search failed', [
                    'keyword' => $keyword,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Search manufacturers if enabled
        if ($params->get('show_manufacturers', 0)) {
            try {
                $manufacturerLimit = (int) $params->get('manufacturers_limit', 6);
                $results['manufacturers'] = self::searchManufacturers($mvcFactory, $keyword, $manufacturerLimit);
            } catch (Exception $e) {
                self::logError('Manufacturer search failed', [
                    'keyword' => $keyword,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Search products
     *
     * @param mixed $mvcFactory MVC Factory
     * @param string $keyword Search keyword
     * @param int $limit Result limit
     *
     * @return array Products array
     * @since   1.0.0
     */
    private static function searchProducts($mvcFactory, string $keyword, int $limit = 50): array
    {
        $model = $mvcFactory->createModel('Items', 'Site', ['ignore_request' => true]);
        if (!$model) {
            return [];
        }

        $model->getState('list.ordering');
        $model->setState('filter.state', '1');
        $model->setState('filter.search', $keyword);
        $model->setState('list.limit', $limit);
        $model->setState('list.start', 0);

        $items = $model->getItems();
        return is_array($items) ? $items : [];
    }

    /**
     * Search categories
     *
     * @param mixed $mvcFactory MVC Factory
     * @param string $keyword Search keyword
     * @param int $limit Result limit from XML
     *
     * @return array Categories array
     * @since   1.0.0
     */
    private static function searchCategories($mvcFactory, string $keyword, int $limit): array
    {
        $model = $mvcFactory->createModel('Categories', 'Site', ['ignore_request' => true]);
        if (!$model) {
            return [];
        }

        $model->getState('list.ordering');
        $model->setState('filter.state', '1');
        $model->setState('filter.search', $keyword);
        $model->setState('list.limit', $limit);
        $model->setState('list.start', 0);

        $items = $model->getItems();
        return is_array($items) ? $items : [];
    }

    /**
     * Search manufacturers
     *
     * @param mixed $mvcFactory MVC Factory
     * @param string $keyword Search keyword
     * @param int $limit Result limit from XML
     *
     * @return array Manufacturers array
     * @since   1.0.0
     */
    private static function searchManufacturers($mvcFactory, string $keyword, int $limit): array
    {
        $model = $mvcFactory->createModel('Manufacturers', 'Site', ['ignore_request' => true]);
        if (!$model) {
            return [];
        }

        $model->getState('list.ordering');
        $model->setState('filter.state', '1');
        $model->setState('filter.search', $keyword);
        $model->setState('list.limit', $limit);
        $model->setState('list.start', 0);

        $items = $model->getItems();
        return is_array($items) ? $items : [];
    }

    /**
     * Render layout and send JSON response
     *
     * @param string $keyword Search keyword
     * @param array $results Search results
     * @param Registry $params Module parameters
     *
     * @since   1.0.0
     */
    private static function renderAndSend(string $keyword, array $results, Registry $params): void
    {
        try {
            $layout = new FileLayout('result', JPATH_ROOT . '/modules/mod_alfa_search/tmpl');

            $layoutData = [
                'params' => $params,
                'products' => $results['products'],
                'categories' => $results['categories'],
                'manufacturers' => $results['manufacturers'],
            ];

            $html = $layout->render($layoutData);

            $totalCount = count($results['products'])
                + count($results['categories'])
                + count($results['manufacturers']);

            $data = [
                'query' => $keyword,
                'html' => $html,
                'count' => $totalCount,
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
     * @since   1.0.0
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
     * @since   1.0.0
     */
    //	TODO: CHECK LOG ERROR IS WORKING FINE WITH JOOMLA
    private static function logError(string $message, array $context = []): void
    {
        try {
            $contextString = !empty($context) ? ' | ' . json_encode($context) : '';
            Log::add($message . $contextString, Log::ERROR, 'mod_alfa_search');
        } catch (Exception $e) {
            error_log('mod_alfa_search: ' . $message);
        }
    }
}
