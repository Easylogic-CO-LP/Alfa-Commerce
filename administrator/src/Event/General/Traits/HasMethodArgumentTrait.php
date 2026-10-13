<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\General\Traits;

use BadMethodCallException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The 'method' argument: which plugin element an inbound event addresses.
 *
 * For the gateway/carrier channels this is the ROUTING ADDRESS, not optional
 * context: the site controller reads it from the URL (&method=<element>) and
 * boots the plugin with it, so an event that reached a listener at all was
 * addressed by name. Hence requireMethodArgument(): absent is a dispatcher
 * bug, never a runtime condition to tolerate.
 *
 * A trait rather than a base-class member because most events have no method
 * (item view, cart view, order placement), and putting it on GeneralEvent
 * would make every one of them answer a question it has no business answering.
 *
 * @since  2.0.0
 */
trait HasMethodArgumentTrait
{
    /**
     * Assert the argument is present. Call from the constructor, after parent.
     *
     * @param string $name Event name, for the message
     *
     * @throws BadMethodCallException When 'method' was not provided
     *
     * @since  2.0.0
     */
    protected function requireMethodArgument(string $name): void
    {
        if (!\array_key_exists('method', $this->arguments)) {
            throw new BadMethodCallException(
                "Argument 'method' of event {$name} is required but has not been provided",
            );
        }
    }

    /**
     * Setter validator for the 'method' argument.
     *
     * @param string $value Plugin element
     *
     *
     * @since  2.0.0
     */
    protected function onSetMethod(string $value): string
    {
        return $value;
    }

    /**
     * The plugin element this event was addressed to.
     *
     *
     * @since  2.0.0
     */
    public function getMethod(): string
    {
        return (string) $this->arguments['method'];
    }
}
