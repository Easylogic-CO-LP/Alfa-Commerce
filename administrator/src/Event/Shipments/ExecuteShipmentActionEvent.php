<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Execute Shipment Action Event
 *
 * Carries both the INPUT (action, shipment, order, data) and the
 * OUTPUT (layout, message, refresh, redirect), same pattern as
 * LayoutEvent in the frontend. No separate result object needed.
 *
 * Input (set by controller, read by plugin):
 *   $event->getAction()    -> 'mark_shipped', 'track', etc.
 *   $event->getShipment()  -> Shipment record with params->type
 *   $event->getOrder()     -> Order record
 *   $event->getData()      -> Additional data from JS
 *
 * Output (set by plugin, read by controller):
 *   $event->setLayout('action_tracking');
 *   $event->setLayoutData(['shipment' => $shipment, 'order' => $order]);
 *   $event->setModalTitle('Tracking #12345');
 *   $event->setMessage('Shipment marked as shipped');
 *   $event->setRefresh(true);
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Order\Shipment;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class ExecuteShipmentActionEvent extends AbstractImmutableEvent
{
    //  INPUT: set by controller, read by plugin

    /**
     * Get the action ID requested (e.g. 'mark_shipped', 'track').
     *
     *
     * @since  1.0.0
     */
    public function getAction(): string
    {
        return $this->arguments['action'];
    }

    /**
     * Get the shipment record.
     *
     *
     * @since  1.0.0
     */
    public function getShipment(): object
    {
        return $this->arguments['shipment'];
    }

    /**
     * The shipment this event is about, as the canonical reader.
     *
     *     $shipment = $event->getOrderShipment();
     *     $locker = $shipment->pluginData()->getString(key: 'locker_id', default: '');
     *
     * THE EXACT ONE, not "a shipment of this order". An order can be split
     * across parcels, so a handler that went to the order and took the first
     * shipment would work until the day a shop split one, and then quietly
     * label the wrong parcel. This event knows which shipment it concerns and
     * hands that one over.
     *
     * {@see self::getShipment()} remains the raw record, which is what carries
     * the method's own settings (`->params`); this is the same row read through
     * the reader, so nothing is decoded twice.
     *
     *
     * @since  2.0.0
     */
    public function getOrderShipment(): Shipment
    {
        return Shipment::of($this->arguments['shipment']);
    }

    /**
     * Get the order record.
     *
     *
     * @since  1.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }

    /**
     * Get additional data sent from JS.
     *
     *
     * @since  1.0.0
     */
    public function getData(): array
    {
        return $this->arguments['data'] ?? [];
    }

    //  OUTPUT: set by plugin, read by controller

    // Success / Error

    /**
     * Set whether the action succeeded. Default is true.
     *
     *
     *
     * @since  1.0.0
     */
    public function setSuccess(bool $success): void
    {
        $this->arguments['success'] = $success;
    }

    /**
     * @since  1.0.0
     */
    public function isSuccess(): bool
    {
        return $this->arguments['success'] ?? true;
    }

    /**
     * Set a message to display to the admin.
     *
     *
     *
     * @since  1.0.0
     */
    public function setMessage(string $message): void
    {
        $this->arguments['message'] = $message;
    }

    /**
     * @since  1.0.0
     */
    public function getMessage(): string
    {
        return $this->arguments['message'] ?? '';
    }

    /**
     * Shortcut: mark this event as a failed action.
     *
     * @param string $message Error message
     *
     *
     * @since  1.0.0
     */
    public function setError(string $message): void
    {
        $this->arguments['success'] = false;
        $this->arguments['message'] = $message;
    }

    // Navigation

    /**
     * Set whether to reload the page after the action.
     *
     *
     *
     * @since  1.0.0
     */
    public function setRefresh(bool $refresh): void
    {
        $this->arguments['refresh'] = $refresh;
    }

    /**
     * @since  1.0.0
     */
    public function getRefresh(): bool
    {
        return $this->arguments['refresh'] ?? false;
    }

    /**
     * Set a redirect URL. Overrides refresh.
     *
     * Matches GeneralEvent::setRedirectUrl()
     *
     *
     *
     * @since  1.0.0
     */
    public function setRedirectUrl(string $url): void
    {
        $this->arguments['redirect'] = $url;
    }

    /**
     * @since  1.0.0
     */
    public function getRedirectUrl(): ?string
    {
        return $this->arguments['redirect'] ?? null;
    }

    // Layout (same pattern as LayoutEvent)

    /**
     * Set the layout filename (without .php).
     *
     * Matches LayoutEvent::setLayout()
     *
     * @param string $layout e.g. 'action_tracking'
     *
     *
     * @since  1.0.0
     */
    public function setLayout(string $layout): void
    {
        $this->arguments['layout'] = $layout;
    }

    /**
     * Matches LayoutEvent::getLayout()
     *
     * @since  1.0.0
     */
    public function getLayout(): string
    {
        return $this->arguments['layout'] ?? '';
    }

    /**
     * Set the data passed to the layout's $displayData.
     *
     * Matches LayoutEvent::setLayoutData()
     *
     *
     *
     * @since  1.0.0
     */
    public function setLayoutData(array $data): void
    {
        $this->arguments['layoutData'] = $data;
    }

    /**
     * Matches LayoutEvent::getLayoutData()
     *
     * @since  1.0.0
     */
    public function getLayoutData(): array
    {
        return $this->arguments['layoutData'] ?? [];
    }

    /**
     * Add a footer button to the action popup, declared here, not inside the
     * layout HTML. A button can close the popup, close + refresh, or RUN
     * another action of THIS entity in the SAME popup (for confirm dialogs and
     * step-by-step flows: each step saves, then loads the next via its action).
     *
     *   $event->addButton('Cancel');                                  // close
     *   $event->addButton('Done', ['reload' => true]);                // close + refresh
     *   $event->addButton('Mark delivered', ['action' => 'mark_delivered']);
     *
     * @param string $label Button label.
     * @param array $opts ['action'=>string, 'data'=>array, 'reload'=>bool, 'class'=>string]
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function addButton(string $label, array $opts = []): static
    {
        $buttons = $this->arguments['buttons'] ?? [];
        $buttons[] = [
            'label' => $label,
            'action' => $opts['action'] ?? null,
            'data' => $opts['data'] ?? [],
            'reload' => !empty($opts['reload']),
            'class' => $opts['class'] ?? 'btn-primary',
        ];
        $this->arguments['buttons'] = $buttons;

        return $this;
    }

    /**
     * @return array The declared footer buttons.
     *
     * @since  2.0.0
     */
    public function getButtons(): array
    {
        return $this->arguments['buttons'] ?? [];
    }

    /**
     * Set the modal title for when the layout is shown in a popup.
     *
     *
     *
     * @since  1.0.0
     */
    public function setModalTitle(string $title): void
    {
        $this->arguments['modalTitle'] = $title;
    }

    /**
     * @since  1.0.0
     */
    public function getModalTitle(): ?string
    {
        return $this->arguments['modalTitle'] ?? null;
    }

    // HTML (set by controller after rendering layout)

    /**
     * Set rendered HTML. Used by the controller, not by plugins.
     * Plugins use setLayout() + setLayoutData().
     *
     *
     *
     * @since  1.0.0
     */
    public function setHtml(string $html): void
    {
        $this->arguments['html'] = $html;
    }

    /**
     * @since  1.0.0
     */
    public function getHtml(): ?string
    {
        return $this->arguments['html'] ?? null;
    }

    //  SERIALIZATION (used by controller to build JSON response)

    /**
     * Serialize the response fields to an array.
     *
     *
     * @since  1.0.0
     */
    public function toResponseArray(): array
    {
        $result = [
            'message' => $this->getMessage(),
            'refresh' => $this->getRefresh(),
            'html' => $this->getHtml(),
            'modal_title' => $this->getModalTitle(),
            'redirect' => $this->getRedirectUrl(),
        ];

        $result = array_filter($result, function ($v) {
            return $v !== null && $v !== false && $v !== '';
        });

        // success is ALWAYS present, even when false: the filter strips a false value
        // and a client checking `res.success !== false` would read a failure as success.
        $result['success'] = $this->isSuccess();

        return $result;
    }

    /**
     * Serialize the response to JSON.
     *
     *
     * @since  1.0.0
     */
    public function toResponseJson(): string
    {
        return json_encode($this->toResponseArray());
    }
}
