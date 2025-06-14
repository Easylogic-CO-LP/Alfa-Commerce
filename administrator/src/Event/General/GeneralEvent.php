<?php

/**
 * Alfa Commerce
 *
 * @copyright  (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Event\General;

use Joomla\CMS\Event\AbstractImmutableEvent;

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
     * @param   string  $name       The event name.
     * @param   array   $arguments  The event arguments.
     *
     * @throws  \BadMethodCallException
     *
     * @since   5.0.0
     */
    public function __construct($name, array $arguments = [])
    {

        parent::__construct($name, $arguments);

        if (!\array_key_exists('subject', $this->arguments)) {
            throw new \BadMethodCallException("Main argument 'subject' of event {$name} is required but has not been provided");
        }
    }

    /**
     * Getter for the field.
     *
     * @return
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
     * @param   object  $value  The value to set
     *
     * @return  object
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
     * @param   string  $url  The URL to redirect to
     *
     * @since  5.0.0
     */
    public function onSetRedirectUrl(string $url): void
    {
        $this->setRedirectUrl($url);
    }
    public function setRedirectUrl(string $url): void
    {
        $this->arguments['redirectUrl'] = $url;
    }

    /**
     * Get the redirect URL if one was set.
     *
     * @return  string|null
     *
     * @since  5.0.0
     */
    public function getRedirectUrl(): ?string
    {
        return $this->arguments['redirectUrl'] ?? null;
    }

    /**
     * Check whether a redirect URL has been set.
     *
     * @return  bool
     *
     * @since  5.0.0
     */
    public function hasRedirect(): bool
    {
        return !empty($this->arguments['redirectUrl']);
    }



    // 301	Moved Permanently
    // 302	Found
    // 303	See Other
    // 307	Temporary Redirect
    // 308	Permanent Redirect
    public function onSetRedirectCode(int $code)
    {
        $this->setRedirectCode($code);
    }
    public function setRedirectCode(int $code)
    {
        $this->arguments['redirectCode'] = $code;
    }

    public function getRedirectCode(): ?int
    {
        return $this->arguments['redirectCode'] ?? null;
    }

}
