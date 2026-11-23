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

use BadMethodCallException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View tier: the method being drawn, and the redirect (RedirectEvent). Hooks
 * dispatched while an HtmlView is built (the product page, the cart, the order
 * process and completed pages, the item fields on a page or a cart line) extend
 * this: they draw through their own surface builders (HasSurfaces, one typed
 * accessor per slot, such as paymentMethods() or buyForm()) or send the buyer
 * away (setRedirectUrl).
 *
 * @since  1.0.0
 */
abstract class LayoutEvent extends RedirectEvent
{
    // Output travels through the surface builders and setRedirectUrl, so this
    // event does NOT use Joomla's Result* traits/interface (multi-listener result
    // collection) nor the ReshapeArgumentsAware positional-argument layer;
    // listeners receive named arguments only.

    /**
     * Constructor.
     *
     * @param string $name The event name.
     * @param array $arguments The event arguments.
     *
     * @throws BadMethodCallException
     *
     * @since  1.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\array_key_exists('method', $this->arguments)) {
            throw new BadMethodCallException("Argument 'method' of event {$name} is required but has not been provided");
        }
    }

    /**
     * Setter for the fieldset argument.
     *
     *
     *
     * @since  1.0.0
     */
    protected function onSetMethod($value)
    {
        return $value;
    }

    /**
     * Getter for the fieldset.
     *
     *
     * @since  1.0.0
     */
    public function getMethod()
    {
        return $this->arguments['method'];
    }
}
