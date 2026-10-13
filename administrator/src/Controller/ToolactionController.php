<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasToolsAjaxTrait;
use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\ToolRegistry;
use Joomla\CMS\MVC\Controller\BaseController;
use Throwable;

/**
 * The one endpoint every plugin-contributed maintenance action goes through.
 *
 * NO BRANCHING ON ACTION IDS. This controller never learns what `search.rebuild`
 * means; it validates, delegates and formats. Twenty installed extensions therefore
 * add twenty entries to a registry rather than twenty arms to a conditional, which
 * is the difference between an extension point and a growing switch statement.
 *
 * SPEAKS THE EXISTING CLIENT CONTRACT. The Tools script already knows two shapes: a
 * one-shot call that reports a message, and a plan-then-chunk loop driven by
 * `{processed, next, total, done}`. Both are reproduced here exactly, so a
 * plugin-contributed button reuses the shipped JavaScript unchanged and no extension
 * has to ship a script merely to draw a progress bar.
 *
 * @since  2.0.0
 */
class ToolactionController extends BaseController
{
    use HasToolsAjaxTrait;

    /**
     * Run an action that completes within one request.
     *
     *
     * @since  2.0.0
     */
    public function run(): void
    {
        $this->ajaxGuard();

        if ($this->refuseUnsafe()) {
            return;
        }

        try {
            $event = ToolRegistry::execute(
                actionId:  $this->input->getCmd('action', ''),
                data:      (array) $this->input->get('data', [], 'array'),
                ownerHint: $this->input->getString('owner', ''),
            );
        } catch (Throwable $e) {
            $this->ajaxRespond([], false, $e->getMessage());

            return;
        }

        if ($event->getError() !== '') {
            $this->ajaxRespond([], false, $event->getError());

            return;
        }

        // The message belongs in the DATA payload, not the envelope: the Tools script
        // reads `data.message` and falls back to a generic completion label when it is
        // absent, so an envelope-only message is silently replaced by "Backfill
        // complete." - the action appearing to succeed while saying nothing it meant.
        $this->ajaxRespond([
            'message' => $event->getMessage(),
            'refresh' => $event->getRefresh(),
        ], true, $event->getMessage());
    }

    /**
     * Ask an action how much work it has, in the plan shape the client expects.
     *
     * An action that declares no total is reported as a single unit, so a tool that
     * simply does not know its size still drives the progress bar to completion
     * instead of dividing by zero.
     *
     *
     * @since  2.0.0
     */
    public function plan(): void
    {
        $this->ajaxGuard();

        if ($this->refuseUnsafe()) {
            return;
        }

        $action = $this->input->getCmd('action', '');

        try {
            $event = ToolRegistry::execute(
                actionId:  $action,
                planning:  true,
                data:      (array) $this->input->get('data', [], 'array'),
                ownerHint: $this->input->getString('owner', ''),
            );
        } catch (Throwable $e) {
            $this->ajaxRespond([], false, $e->getMessage());

            return;
        }

        if ($event->getError() !== '') {
            $this->ajaxRespond([], false, $event->getError());

            return;
        }

        $this->ajaxRespond([
            'plan' => [[
                'table' => $action,
                'total' => $event->getTotal() ?? 1,
            ]],
        ]);
    }

    /**
     * Advance a chunked action by one step.
     *
     *
     * @since  2.0.0
     */
    public function chunk(): void
    {
        $this->ajaxGuard();

        if ($this->refuseUnsafe()) {
            return;
        }

        $offset = $this->input->getInt('offset', 0);

        try {
            $event = ToolRegistry::execute(
                actionId:  $this->input->getCmd('action', ''),
                offset:    $offset,
                data:      (array) $this->input->get('data', [], 'array'),
                ownerHint: $this->input->getString('owner', ''),
            );
        } catch (Throwable $e) {
            $this->ajaxRespond([], false, $e->getMessage());

            return;
        }

        if ($event->getError() !== '') {
            $this->ajaxRespond([], false, $event->getError());

            return;
        }

        $total = $event->getTotal() ?? 0;
        $next = $event->getNextOffset();

        // No cursor back means the action considers itself finished, and ONLY that.
        // The handler is the authority on its own completion; second-guessing it with
        // `next >= total` ended a rebuild early whenever the catalogue grew mid-walk
        // (the frozen total was reached while enumeration still had records), so the
        // final reconcile-and-activate step silently never ran. The total is for the
        // progress bar; the cursor is for control flow. Never cross them.
        $done = $next === null;

        $this->ajaxRespond([
            'processed' => max(0, ($next ?? $total) - $offset),
            'next' => $next ?? $total,
            'total' => $total,
            'done' => $done,
            'message' => $event->getMessage(),
            'refresh' => $event->getRefresh(),
        ]);
    }

    /**
     * Refuse a state-changing action that arrives as a bare GET or without a
     * one-time key, kept on this path exactly as the order-action dispatcher
     * keeps it. Every action changes state unless its provider declared it
     * read-only ({@see \Alfa\Component\Alfa\Administrator\Plugin\PluginAction::readOnly()}).
     *
     * EVERY REQUEST OF SUCH AN ACTION IS HELD TO IT, a chunk included: the
     * Tools script presents a fresh key each time, so a replayed URL runs
     * nothing and a replayed submission runs nothing twice. A read-only action
     * is not asked, and an action nobody declares is held to it as well.
     *
     * @return bool True when the request was refused and answered.
     *
     * @since  2.0.0
     */
    private function refuseUnsafe(): bool
    {
        $refusal = ActionRegistry::refuseUnsafeRequest(
            mutating: ToolRegistry::mutates(
                actionId: $this->input->getCmd('action', ''),
                ownerHint: $this->input->getString('owner', ''),
            ),
            input: $this->input,
        );

        if ($refusal === null) {
            return false;
        }

        $this->ajaxRespond([], false, $refusal);

        return true;
    }
}
