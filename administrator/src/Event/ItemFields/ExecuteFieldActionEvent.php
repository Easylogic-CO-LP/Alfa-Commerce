<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Execute Field Action Event
 *
 * Carries the INPUT (action, scope + row, field, posted data) and the OUTPUT
 * (layout, message, refresh, redirect): the exact ExecutePaymentActionEvent
 * contract, so the same actions.js and the same JSON response drive every
 * surface. The event is a pure carrier: reading/writing this row's config is
 * done by the plugin via $this->getConfig($event) / $this->saveConfig($event, $data).
 *
 *   public function onExecuteFieldAction($event) {
 *       if ($event->getAction() === 'configure') {
 *           $event->setLayout('configure')->setLayoutData([...]);          // open popup
 *       }
 *       if ($event->getAction() === 'configure_save') {
 *           $this->saveConfig($event, $event->getData());           // persist
 *           $event->setRefresh(true);
 *       }
 *   }
 *
 * @since  1.3.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Helper\FieldParams;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class ExecuteFieldActionEvent extends AbstractImmutableEvent implements ScopedFieldEvent
{
    // Input

    /**
     * The action id requested (e.g. 'configure', 'configure_save').
     *
     * @since  1.3.0
     */
    public function getAction(): string
    {
        return $this->arguments['action'];
    }

    /**
     * The surface: 'item' | 'combination' | 'option' | 'order'.
     *
     * @since  1.3.0
     */
    public function getScope(): string
    {
        return $this->arguments['scope'] ?? FieldParams::SCOPE_ITEM;
    }

    /**
     * The scope row id.
     *
     * @since  1.3.0
     */
    public function getScopeId(): int
    {
        return (int) ($this->arguments['scopeId'] ?? 0);
    }

    /**
     * The owning item id.
     *
     * @since  1.3.0
     */
    public function getItemId(): int
    {
        return (int) ($this->arguments['itemId'] ?? 0);
    }

    /**
     * The field definition row.
     *
     * @since  1.3.0
     */
    public function getField(): object
    {
        return $this->arguments['field'];
    }

    /**
     * Additional data sent from JS (e.g. a configure popup's form values).
     *
     * @since  1.3.0
     */
    public function getData(): array
    {
        return $this->arguments['data'] ?? [];
    }

    // Output

    /**
     * @param bool $success Whether the action succeeded.
     *
     * @since  1.3.0
     */
    public function setSuccess(bool $success): static
    {
        $this->arguments['success'] = $success;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function isSuccess(): bool
    {
        return $this->arguments['success'] ?? true;
    }

    /**
     * @param string $message Message for the admin.
     *
     * @since  1.3.0
     */
    public function setMessage(string $message): static
    {
        $this->arguments['message'] = $message;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getMessage(): string
    {
        return $this->arguments['message'] ?? '';
    }

    /**
     * @param string $message Error message (marks the action failed).
     *
     * @since  1.3.0
     */
    public function setError(string $message): static
    {
        $this->arguments['success'] = false;
        $this->arguments['message'] = $message;

        return $this;
    }

    /**
     * @param bool $refresh Reload the page after the action.
     *
     * @since  1.3.0
     */
    public function setRefresh(bool $refresh): static
    {
        $this->arguments['refresh'] = $refresh;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getRefresh(): bool
    {
        return $this->arguments['refresh'] ?? false;
    }

    /**
     * @param string $url Redirect URL (overrides refresh).
     *
     * @since  1.3.0
     */
    public function setRedirectUrl(string $url): static
    {
        $this->arguments['redirect'] = $url;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getRedirectUrl(): ?string
    {
        return $this->arguments['redirect'] ?? null;
    }

    /**
     * Set the layout (without .php), rendered by the controller via
     * PluginLayoutHelper and returned as modal HTML.
     *
     * @param string $layout Layout name.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setLayout(string $layout): static
    {
        $this->arguments['layout'] = $layout;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getLayout(): string
    {
        return $this->arguments['layout'] ?? '';
    }

    /**
     * @param array $data Data passed to the layout's $displayData.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setLayoutData(array $data): static
    {
        $this->arguments['layoutData'] = $data;

        return $this;
    }

    /**
     * @since  1.3.0
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
     *   $event->addButton('Next', ['action' => 'step2', 'data' => [...]]);
     *
     * @param string $label Button label.
     * @param array $opts ['action'=>string, 'data'=>array, 'reload'=>bool, 'class'=>string]
     *
     * @return static For chaining.
     *
     * @since  1.3.0
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
     * @since  1.3.0
     */
    public function getButtons(): array
    {
        return $this->arguments['buttons'] ?? [];
    }

    /**
     * @param string $title Modal popup title.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setModalTitle(string $title): static
    {
        $this->arguments['modalTitle'] = $title;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getModalTitle(): ?string
    {
        return $this->arguments['modalTitle'] ?? null;
    }

    /**
     * Set rendered HTML (controller use, not plugins).
     *
     * @param string $html The rendered layout.
     *
     * @since  1.3.0
     */
    public function setHtml(string $html): static
    {
        $this->arguments['html'] = $html;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getHtml(): ?string
    {
        return $this->arguments['html'] ?? null;
    }

    /**
     * Attach structured result data for the caller's JS (e.g. an upload
     * action returning the stored file's URL). Serialized as `payload`.
     *
     * @param array $payload The result data.
     *
     * @since  1.3.0
     */
    public function setPayload(array $payload): static
    {
        $this->arguments['payload'] = $payload;

        return $this;
    }

    /**
     * @since  1.3.0
     */
    public function getPayload(): ?array
    {
        return $this->arguments['payload'] ?? null;
    }

    /**
     * Serialize the OUTPUT fields to the JSON response array.
     *
     * @since  1.3.0
     */
    public function toResponseArray(): array
    {
        $response = array_filter([
            'message' => $this->getMessage(),
            'refresh' => $this->getRefresh(),
            'html' => $this->getHtml(),
            'modal_title' => $this->getModalTitle(),
            'redirect' => $this->getRedirectUrl(),
            'payload' => $this->getPayload(),
        ], static fn ($v) => $v !== null && $v !== false && $v !== '' && $v !== []);

        // success is ALWAYS present, even when false: the filter above would strip a
        // false value, and the client checks `res.success !== false`, so a stripped
        // key reads a rejected action as a success.
        $response['success'] = $this->isSuccess();

        return $response;
    }

    /**
     * @since  1.3.0
     */
    public function toResponseJson(): string
    {
        return json_encode($this->toResponseArray());
    }
}
