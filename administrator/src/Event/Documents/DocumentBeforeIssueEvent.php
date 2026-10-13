<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Documents;

defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * onDocumentBeforeIssue, the BEFORE event of the document write path:
 * subscribers may MODIFY the draft (updateDraft) or VETO the issuance by
 * throwing (the exception surfaces as the issue failure: manual issue
 * shows it, auto-issue logs and skips). Dispatched before the issue
 * transaction opens, so a veto never burns a number.
 *
 * Counterpart of the AFTER events (onDocumentIssued / onDocumentVoided),
 * which are post-commit side-effect hooks and can never block.
 *
 * The mutator is deliberately NOT named set<Argument>(); Joomla's Event
 * magic would shadow it.
 *
 * @since  2.0.0
 */
class DocumentBeforeIssueEvent extends AbstractEvent
{
    /**
     * The document draft as the issuer will consume it.
     *
     *
     * @since  2.0.0
     */
    public function getDraft(): array
    {
        return (array) $this->arguments['draft'];
    }

    /**
     * Replace the draft (validated downstream by the issue helper;
     * subscribers cannot bypass the guards, only feed them).
     *
     * @param array $draft The amended draft.
     *
     *
     * @since  2.0.0
     */
    public function updateDraft(array $draft): void
    {
        $this->arguments['draft'] = $draft;
    }
}
