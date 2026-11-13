<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;

/**
 * How a storefront view says "do not store me" to Joomla's System - Page Cache.
 *
 * The page cache stores the page a visitor who is not logged in opens, keyed by its address alone, and shows it to the
 * next visitors for the cache time. A view whose response holds one visitor's own data at an address every visitor
 * shares (an order of the session, a form re-filled from the session) calls excludeResponse() while it renders: for
 * this request the page cache's question onPageCacheIsExcluded, asked after the render and before anything is stored,
 * is answered true. It needs no plugin of Alfa's to be enabled. allowCache(false) would not do it: the page cache never
 * reads it.
 *
 * It keeps the page from being stored; it cannot keep an earlier stored page of the same address from being served,
 * which is why it belongs to responses no visitor's page is ever stored under.
 *
 * @since  2.2.1
 */
final class PageCache
{
    /**
     * This request's response is never stored in Joomla's page cache.
     *
     * @since  2.2.1
     */
    public static function excludeResponse(): void
    {
        AlfaPluginHelper::get()->listen('onPageCacheIsExcluded', static function ($event): void {
            $event->addResult(true);
        });
    }
}
