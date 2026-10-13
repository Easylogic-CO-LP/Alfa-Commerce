<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Tools;

defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * Runs one maintenance action, in the plugin that declared it.
 *
 * Delivered to a SINGLE booted plugin, never broadcast. The controller does not
 * branch on the action id, so twenty extensions do not become twenty `if` arms in
 * one method; the mapping from action to code lives in the registry, which learned
 * it from the plugin itself.
 *
 * CHUNKING IS THE CALLER'S SHAPE, NOT THIS EVENT'S. A tool that cannot finish inside
 * one request answers with {@see self::setTotal()} and a cursor, and is called again
 * with {@see self::getOffset()} advanced. A tool that finishes immediately simply
 * never touches either, and the same endpoint serves both, which is what keeps the
 * client contract identical for a one-shot cleanup and a hundred-thousand-row
 * reindex.
 *
 * @since  2.0.0
 */
class ExecuteToolActionEvent extends AbstractEvent
{
    //  INPUT: set by the controller, read by the plugin

    /**
     * The action to run, e.g. `search.rebuild`.
     *
     * Already validated against the registry: it exists, it is enabled, and this
     * plugin is the one that declared it. A handler still guards on it, because one
     * plugin may declare several.
     *
     *
     * @since  2.0.0
     */
    public function getAction(): string
    {
        return (string) ($this->arguments['action'] ?? '');
    }

    /**
     * How many units this call has already processed, 0 on the first call.
     *
     *
     * @since  2.0.0
     */
    public function getOffset(): int
    {
        return (int) ($this->arguments['offset'] ?? 0);
    }

    /**
     * Whether this call is the planning pass that reports the size of the work.
     *
     *
     * @since  2.0.0
     */
    public function isPlanning(): bool
    {
        return (bool) ($this->arguments['planning'] ?? false);
    }

    /**
     * Free-form payload from the client, for actions that take parameters.
     *
     * UNTRUSTED. Whatever a handler reads here arrives from the browser and must be
     * validated by the handler, exactly as request input would be.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    public function getData(): array
    {
        return (array) ($this->arguments['data'] ?? []);
    }

    //  OUTPUT: set by the plugin, read by the controller

    /**
     * Report the total amount of work, turning this action into a chunked one.
     *
     * @param int $total Units of work; 0 means there is nothing to do.
     *
     *
     * @since  2.0.0
     */
    public function setTotal(int $total): static
    {
        $this->arguments['total'] = max(0, $total);

        return $this;
    }

    /**
     * @return int|null Null when the action never declared a total.
     *
     * @since  2.0.0
     */
    public function getTotal(): ?int
    {
        return isset($this->arguments['total']) ? (int) $this->arguments['total'] : null;
    }

    /**
     * Where the next call should resume.
     *
     * Must strictly exceed {@see self::getOffset()} while work remains: a cursor that
     * fails to advance is the one failure mode that turns a progress bar into an
     * infinite loop, so the registry refuses it rather than letting the browser spin.
     *
     * @param int $offset New cursor.
     *
     *
     * @since  2.0.0
     */
    public function setNextOffset(int $offset): static
    {
        $this->arguments['nextOffset'] = max(0, $offset);

        return $this;
    }

    /**
     * @since  2.0.0
     */
    public function getNextOffset(): ?int
    {
        return isset($this->arguments['nextOffset']) ? (int) $this->arguments['nextOffset'] : null;
    }

    /**
     * Message shown to the merchant when the action finishes.
     *
     * @param string $message Already-translated text.
     *
     *
     * @since  2.0.0
     */
    public function setMessage(string $message): static
    {
        $this->arguments['message'] = $message;

        return $this;
    }

    /**
     * @since  2.0.0
     */
    public function getMessage(): string
    {
        return (string) ($this->arguments['message'] ?? '');
    }

    /**
     * Ask the Tools screen to reload once the action completes.
     *
     * @param bool $refresh Whether to reload.
     *
     *
     * @since  2.0.0
     */
    public function setRefresh(bool $refresh = true): static
    {
        $this->arguments['refresh'] = $refresh;

        return $this;
    }

    /**
     * @since  2.0.0
     */
    public function getRefresh(): bool
    {
        return (bool) ($this->arguments['refresh'] ?? false);
    }

    /**
     * Record that the action failed, without throwing.
     *
     * A maintenance tool reporting "could not reach the search server" is a normal
     * outcome the merchant needs to read, not a stack trace.
     *
     * @param string $error Already-translated text.
     *
     *
     * @since  2.0.0
     */
    public function setError(string $error): static
    {
        $this->arguments['error'] = $error;

        return $this;
    }

    /**
     * @return string '' when the action succeeded.
     *
     * @since  2.0.0
     */
    public function getError(): string
    {
        return (string) ($this->arguments['error'] ?? '');
    }
}
