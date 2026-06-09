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

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Base event carrying only the subject (the order/cart/item the hook acts on).
 *
 * This is the "data" tier of the Alfa event hierarchy:
 *   GeneralEvent (data only) → RedirectEvent (+ redirect) → LayoutEvent (+ layout)
 *
 * Pure side-effect / data hooks fired OUTSIDE any view — order place/save, shipping
 * cost calculation — extend this, so they carry NO redirect and NO layout (a plugin
 * cannot accidentally try to navigate from them). Hooks that may send the buyer
 * somewhere extend RedirectEvent; view-rendered hooks extend LayoutEvent.
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
}
