<?php

/**
 * Alfa Commerce
 *
 * @copyright  (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\General;

use BadMethodCallException;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Class for CustomFields events
 *
 * @since  5.0.0
 */
abstract class GeneralEvent extends AbstractImmutableEvent
{
    /**
     * Constructor.
     *
     * @param string $name The event name.
     * @param array $arguments The event arguments.
     *
     * @throws BadMethodCallException
     *
     * @since   5.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\array_key_exists('subject', $this->arguments)) {
            throw new BadMethodCallException("Main argument 'subject' of event {$name} is required but has not been provided");
        }
    }

    /**
     * Getter for the field.
     *
     *
     * @since  5.0.0
     */
    public function getSubject()
    {
        return $this->arguments['subject'];
    }

    /**
     * Setter for the subject argument.
     *
     * @param object $value The value to set
     *
     *
     * @since  5.0.0
     */
    protected function onSetSubject(object $value): object
    {
        return $value;
    }

    /**
     * Set a redirect URL to be handled after the plugin finishes.
     *
     * @param string $url The URL to redirect to
     *
     * @since  5.0.0
     */
    public function onSetRedirectUrl(string $url): void
    {
        $this->setRedirectUrl($url);
    }

    /**
     * Store the redirect URL raw (unrouted); SEF routing is deferred to getRedirectUrl().
     *
     * @param   string  $url  The URL to redirect to
     *
     * @return  void
     *
     * @since  5.0.0
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
     *
     * @since  5.0.0
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
     */
    public function getRawRedirectUrl(): ?string
    {
        return $this->arguments['redirectUrl'] ?? null;
    }

    /**
     * Check whether a redirect URL has been set.
     *
     *
     * @since  5.0.0
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
     * @param   int  $code  The HTTP status code to use for the redirect
     *
     * @return  void
     *
     * @since  5.0.0
     */
    public function onSetRedirectCode(int $code)
    {
        $this->setRedirectCode($code);
    }

    /**
     * Store the HTTP status code to use when the redirect is performed.
     *
     * @param   int  $code  The HTTP status code
     *
     * @return  void
     *
     * @since  5.0.0
     */
    public function setRedirectCode(int $code)
    {
        $this->arguments['redirectCode'] = $code;
    }

    /**
     * Get the redirect HTTP status code if one was set.
     *
     * @return  int|null  The status code, or null when none was set
     *
     * @since  5.0.0
     */
    public function getRedirectCode(): ?int
    {
        return $this->arguments['redirectCode'] ?? null;
    }
}
