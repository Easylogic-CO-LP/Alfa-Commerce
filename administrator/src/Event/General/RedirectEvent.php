<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\General;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Base for events that may ask the component to redirect after the plugin runs.
 *
 * This is the "redirect" capability tier of the event hierarchy:
 *   GeneralEvent (data only) → RedirectEvent (this) → LayoutEvent (redirect + layout)
 *
 * Use it for hooks fired outside a view whose only presentational power is to send
 * the buyer somewhere (e.g. the gateway-return handler `onPaymentResponse`). Pure
 * data/side-effect hooks (order place/save, shipping-cost calculation) must extend
 * GeneralEvent instead, so they carry no redirect; view-rendered hooks extend
 * LayoutEvent, which adds the layout capability on top of this one.
 *
 * @since  1.0.0
 */
abstract class RedirectEvent extends GeneralEvent
{
    /**
     * Joomla event setter hook for the redirect URL — delegates to setRedirectUrl().
     *
     * @param string $url The URL to redirect to
     *
     * @since  1.0.0
     */
    public function onSetRedirectUrl(string $url): void
    {
        $this->setRedirectUrl($url);
    }

    /**
     * Store the redirect URL raw (unrouted); SEF routing is deferred to getRedirectUrl().
     *
     * @param string $url The URL to redirect to
     *
     * @since  1.0.0
     */
    public function setRedirectUrl(string $url): void
    {
        // Store exactly what the plugin passes (raw). Routing to SEF happens lazily
        // in getRedirectUrl(), so the event always keeps the original URL.
        $this->arguments['redirectUrl'] = $url;
    }

    /**
     * Get the redirect URL if one was set.
     *
     * @since  1.0.0
     */
    public function getRedirectUrl(): ?string
    {
        $url = $this->arguments['redirectUrl'] ?? null;

        // SEF-route only RAW internal Joomla URLs: those on this site
        // (Uri::isInternal — host check) AND still in raw "index.php?..." form
        // (the only thing Route::_ can build a SEF link from). This lets a plugin
        // set a plain "index.php?option=com_alfa&view=cart&layout=..." without
        // calling Route::_() itself. External gateway URLs (host check fails) and
        // already-final/SEF internal URLs (no "index.php") pass through unchanged,
        // so they're never double-routed.
        if (is_string($url) && str_contains($url, 'index.php') && Uri::isInternal($url)) {
            return Route::_($url, false);
        }

        return $url;
    }

    /**
     * Get the redirect URL exactly as the plugin set it — raw / unrouted.
     *
     * Use this when you need the original value (logging, or re-routing yourself
     * with custom flags such as Route::TLS_FORCE / absolute). For redirecting,
     * use getRedirectUrl(), which SEF-routes raw internal URLs.
     *
     * @since  1.0.0
     */
    public function getRawRedirectUrl(): ?string
    {
        return $this->arguments['redirectUrl'] ?? null;
    }

    /**
     * Check whether a redirect URL has been set.
     *
     * @since  1.0.0
     */
    public function hasRedirect(): bool
    {
        return !empty($this->arguments['redirectUrl']);
    }

    /**
     * Joomla event setter hook for the redirect HTTP status code — delegates to setRedirectCode().
     *
     * Common codes: 301 Moved Permanently, 302 Found, 303 See Other,
     * 307 Temporary Redirect, 308 Permanent Redirect.
     *
     * @param int $code The HTTP status code to use for the redirect
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function onSetRedirectCode(int $code)
    {
        $this->setRedirectCode($code);
    }

    /**
     * Store the HTTP status code to use when the redirect is performed.
     *
     * @param int $code The HTTP status code
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function setRedirectCode(int $code)
    {
        $this->arguments['redirectCode'] = $code;
    }

    /**
     * Get the redirect HTTP status code if one was set.
     *
     * @return int|null The status code, or null when none was set
     *
     * @since  1.0.0
     */
    public function getRedirectCode(): ?int
    {
        return $this->arguments['redirectCode'] ?? null;
    }
}
