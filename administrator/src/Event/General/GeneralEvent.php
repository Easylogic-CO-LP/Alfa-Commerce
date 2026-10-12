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
use Joomla\CMS\Event\AbstractImmutableEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Base event carrying only the subject (the order/cart/item the hook acts on).
 *
 * This is the "data" tier of the Alfa event hierarchy:
 *   GeneralEvent (data only) -> RedirectEvent (+ redirect) -> LayoutEvent (+ layout)
 *
 * Pure side-effect / data hooks fired OUTSIDE any view (order place/save, shipping
 * cost calculation) extend this, so they carry NO redirect and NO layout (a plugin
 * cannot accidentally try to navigate from them). Hooks that may send the buyer
 * somewhere extend RedirectEvent; view-rendered hooks extend LayoutEvent.
 *
 * @since  1.0.0
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
     * @since  1.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\array_key_exists('subject', $this->arguments)) {
            throw new BadMethodCallException("Main argument 'subject' of event {$name} is required but has not been provided");
        }
    }

    /**
     * The event's MAIN OBJECT: whatever this hook is acting on right now.
     *
     * The key is always 'subject'; what it holds changes per event (a product
     * on an item-view hook, the cart on a cart hook, the order after placement).
     * Every event therefore also declares a domain-named ALIAS over this method
     * (getItem(), getCart(), getOrder()), and listeners should call the alias:
     * it names what was actually received. Both return the same object.
     *
     * Writing an alias: name it get<DomainNoun>(), take no parameters, declare
     * no return type, and let the body be nothing but `return $this->getSubject();`.
     * Never name it after an ARGUMENT KEY: Joomla's AbstractEvent routes
     * get<Key>()/set<Key>() through getArgument()/setArgument() as deprecated
     * value hooks, so such a method silently becomes that argument's accessor.
     *
     * @return mixed The event subject
     *
     * @since  1.0.0
     */
    public function getSubject()
    {
        return $this->arguments['subject'];
    }

    /**
     * Setter for the subject argument.
     *
     * NULL IS A LEGITIMATE SUBJECT, and the key is still required: "there is
     * no subject yet" is a different statement from "this event forgot to
     * declare one", and only the first is allowed. Inbound gateway events are
     * the case: the payload is untrusted until the plugin has authenticated
     * it, so the dispatcher genuinely cannot say which order the message is
     * about, and guessing would be the dispatcher choosing a payment on a
     * gateway's behalf.
     *
     * Typed ?object here rather than worked around per event: while this
     * demanded an object, each event that needed a null re-implemented the
     * same trick (ride a placeholder through the constructor, restore the
     * null after), and PaymentResponseEvent, which never did, threw a
     * TypeError on every gateway return.
     *
     * @param ?object $value The value to set
     *
     *
     * @since  1.0.0
     */
    protected function onSetSubject(?object $value): ?object
    {
        return $value;
    }
}
