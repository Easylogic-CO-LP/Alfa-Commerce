<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Panel;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\Input\Input;

/**
 * Shared shape of a panel MUTATION: what a plugin's save/delete/custom-task handler tells
 * the shared controller to do next, without the plugin touching the application, the token
 * or the redirect machinery.
 *
 * A handler reads its input, does the work through its own service, then declares the
 * outcome fluently:
 *
 *   $event->addMessage(Text::_('...SAVED'))
 *         ->setRedirect($event->getInput()->getCmd('return', ''));
 *
 * The controller (which already validated the CSRF token, and accepts a save, a delete and
 * every task its panel does not declare a lookup from a POST alone) enqueues the messages,
 * follows the redirect, and, for a custom task, treats an unhandled event as "unknown task".
 * The plugin never calls enqueueMessage, setRedirect on the app, or checkToken itself.
 *
 * @since  2.0.0
 */
abstract class PanelResultEvent extends AbstractEvent
{
    /**
     * Who this request is for.
     *
     *
     * @since  2.0.0
     */
    public function getIdentity(): PanelIdentity
    {
        return $this->arguments['identity'];
    }

    /**
     * The request input: the handler reads its own fields here.
     *
     *
     * @since  2.0.0
     */
    public function getInput(): Input
    {
        return $this->arguments['input'];
    }

    /**
     * Queue a user message the controller will enqueue after the mutation.
     *
     * @param string $text A translated message.
     * @param string $type 'message' | 'error' | 'warning' | 'notice'.
     *
     *
     * @since  2.0.0
     */
    public function addMessage(string $text, string $type = 'message'): static
    {
        $messages = $this->arguments['messages'] ?? [];
        $messages[] = ['text' => $text, 'type' => $type];

        $this->arguments['messages'] = $messages;

        return $this;
    }

    /**
     * The queued messages, each ['text' => ..., 'type' => ...].
     *
     * @return array<int, array{text:string, type:string}>
     *
     * @since  2.0.0
     */
    public function getMessages(): array
    {
        return $this->arguments['messages'] ?? [];
    }

    /**
     * Where to send the browser after the mutation.
     *
     * @param string $url A URL (relative to the site is fine).
     *
     *
     * @since  2.0.0
     */
    public function setRedirect(string $url): static
    {
        $this->arguments['redirect'] = $url;

        return $this;
    }

    /**
     * The chosen redirect, or '' to let the controller fall back to the panel's list.
     *
     *
     * @since  2.0.0
     */
    public function getRedirect(): string
    {
        return $this->arguments['redirect'] ?? '';
    }

    /**
     * Declare that the plugin handled this request (mainly for custom tasks; an
     * unhandled task is treated as unknown).
     *
     *
     * @since  2.0.0
     */
    public function markHandled(): static
    {
        $this->arguments['handled'] = true;

        return $this;
    }

    /**
     * Did the plugin handle this request.
     *
     *
     * @since  2.0.0
     */
    public function isHandled(): bool
    {
        return (bool) ($this->arguments['handled'] ?? false);
    }
}
