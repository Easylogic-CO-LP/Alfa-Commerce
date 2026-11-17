<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Orders;

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\SelectionContributorsInterface;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\PlacementEventContext;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * Checkout validation event (`onOrderPlaceValidate`), before the order draft is
 * built: delivered in turn to each `alfa` plugin the CUSTOMER's view levels
 * admit, in their order, through the plugin's own targeted declaration (2.2.0), never the shared dispatcher. A plugin may VETO the placement with
 * a customer-facing message, naming itself; the checkout aborts and shows every
 * collected rejection, and a plugin that throws refuses the placement too:
 *
 *   public function onOrderPlaceValidate(OrderPlaceValidateEvent $event): void
 *   {
 *       if ($cartNeedsAnAccount && $event->getContext()->customerId === 0) {
 *           $event->reject('alfa.accounts', Text::_('PLG_..._LOGIN_REQUIRED'));
 *       }
 *   }
 *
 * One shared event travels through all plugins (rejections accumulate). A
 * rejection naming another plugin than the one answering is dropped and logged.
 *
 * A listener that would rather throw refuses with words by throwing
 * {@see OrderPlaceRefusedException} with the shopper's sentence, read as a
 * rejection is (2.2.1). Any other exception refuses the placement as a check
 * that could not run: the shopper is told which plugin's check, and its own
 * words stay in the log.
 *
 * @since  2.0.0
 */
class OrderPlaceValidateEvent extends AbstractImmutableEvent implements SelectionContributorsInterface
{
    /**
     * @var string[] Customer-facing rejection messages.
     * @since  2.0.0
     */
    private array $messages = [];

    /**
     * The plugin the selection loop is calling now; null outside it.
     *
     * @since  2.2.0
     */
    private ?PluginId $answeringPlugin = null;

    /**
     * The cart being converted, as a plugin may see it: its id, its lines and
     * the customer it is for, as values. Nothing here reaches the cart itself,
     * and nothing here can hand a different one back.
     *
     *
     * @since  2.0.0
     */
    public function getContext(): PlacementEventContext
    {
        return $this->arguments['context'];
    }

    /**
     * Veto the placement with a customer-facing message.
     *
     * @param string $contributor The refusing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $message Translated message shown to the customer.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function reject(string $contributor, string $message): void
    {
        $owner = PluginId::of($contributor);

        if ($this->answeringPlugin !== null && $this->answeringPlugin->key() !== $owner->key()) {
            Log::add('Placement: ' . $this->answeringPlugin . ' refused the placement in the name of ' . $owner . '; dropped.', Log::WARNING, 'com_alfa.orders');

            return;
        }

        Log::add('Placement: ' . $owner . ' refused the placement: ' . $message, Log::INFO, 'com_alfa.orders');
        $this->messages[] = $message;
    }

    /**
     * The plugin whose handler runs now in the selection loop; null once it returned.
     *
     * @internal The plugin helper calls it around each handler of a selection loop; a plugin never calls it.
     *
     * @param PluginId|null $plugin The plugin.
     *
     * @since  2.2.0
     */
    public function answering(?PluginId $plugin): void
    {
        $this->answeringPlugin = $plugin;
    }

    /**
     * Whether any plugin vetoed the placement.
     *
     *
     * @since  2.0.0
     */
    public function isRejected(): bool
    {
        return $this->messages !== [];
    }

    /**
     * The collected rejection messages.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
