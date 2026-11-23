<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Closure;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * How a storefront view says "do not store me" to Joomla's System - Page Cache.
 *
 * The page cache stores the page a visitor who is not logged in opens, keyed by its address alone, and shows it to the
 * next visitors for the cache time. A view whose response holds one visitor's own data at an address every visitor
 * shares (a cart, the checkout details typed, an order of the session, a form re-filled from the session, prices of a
 * currency or place of the visitor's own) calls excludeResponse() while it renders: for this request the page cache's
 * question onPageCacheIsExcluded, asked after the render and before anything is stored, is answered true. It needs no
 * plugin of Alfa's to be enabled. allowCache(false) would not do it: the page cache never reads it.
 *
 * It keeps the page from being stored; it cannot keep an earlier stored page of the same address from being served
 * (the page cache serves before any view runs). So a page another visitor stored is never one of a visitor's own, and a
 * view whose every response is a visitor's own (the cart view) excludes every response, the empty one included. Keeping
 * a visitor with a cart from being served a stored page is System - Alfa Commerce Sync's rule
 * (onPageCacheSetCaching), which only a plugin can answer.
 *
 * @since  2.2.1
 */
final class PageCache
{
    /**
     * The one answer every call registers.
     *
     * @since  2.2.1
     */
    private static ?Closure $answer = null;

    /**
     * This request's response is never stored in Joomla's page cache. Asked any number of times in a request (each
     * price drawn for a visitor's own currency asks), the answer is listening once.
     *
     * @api BEST EFFORT: keeps this request's response out of Joomla's page cache (System - Page Cache), and nothing
     *      more: core's own views and modules call it, and an extension calls it only for a response of its own (its
     *      view, module or plugin output holding one visitor's data at an address every visitor shares). It cannot keep
     *      a page stored earlier from being served, nor another cache (a server's, a CDN's, a browser's) from keeping
     *      the response. A failure to register the answer is logged in com_alfa and never thrown into the caller.
     *
     * @since  2.2.1
     */
    public static function excludeResponse(): void
    {
        try {
            $plugins = AlfaPluginHelper::get();
            $answer = self::$answer ??= static function ($event): void {
                $event->addResult(true);
            };

            $plugins->forget('onPageCacheIsExcluded', $answer);
            $plugins->listen('onPageCacheIsExcluded', $answer);
        } catch (Throwable $e) {
            Log::add('This response could not be kept out of Joomla\'s page cache: ' . ErrorText::of($e), Log::ERROR, 'com_alfa');
        }
    }

    /**
     * Drop every page Joomla's page cache stored for the site (its `page` group), so the next visitor of each page is
     * served it as the shop is now (online sales switched on or off; a stored guest page would keep its prices
     * and its buy buttons, or keep showing none). Joomla's own Options save cleans the component records, never the
     * stored pages. The pages are cleaned where System - Page Cache stores them: Joomla's cache folder (the Global
     * Configuration's cache path, administrator/cache when none is set), which the site and the administrator share. A
     * cache that cannot be cleaned is said in the log and expires on its own.
     *
     * @since  2.2.2
     */
    public static function forget(): void
    {
        try {
            Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'page'])
                ->clean();
        } catch (Throwable $e) {
            Log::add('The stored pages of Joomla\'s page cache could not be dropped: ' . ErrorText::of($e), Log::WARNING, 'com_alfa');
        }
    }
}
