<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Documents;

defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * DocumentVoidedEvent: 'onDocumentVoided'
 *
 * Dispatched AFTER COMMIT of a void. The document keeps its number
 * (gapless invariant) and its content; only status/void audit changed.
 * Read-only: getters only; listener failures never affect the void.
 *
 * Consumers: tax-authority transmission plugins (a void must be transmitted as a
 * cancellation referencing the original MARK), notifications.
 *
 * @since  1.3.0
 */
final class DocumentVoidedEvent extends AbstractImmutableEvent
{
    /**
     * The voided document header row (post-commit state, status = voided).
     *
     * @return object The `#__alfa_documents` row.
     *
     * @since  1.3.0
     */
    public function getDocument(): object
    {
        return $this->arguments['document'];
    }

    /**
     * The recorded void reason.
     *
     *
     * @since  1.3.0
     */
    public function getReason(): string
    {
        return (string) $this->arguments['reason'];
    }

    /**
     * The acting admin user id.
     *
     *
     * @since  1.3.0
     */
    public function getActorId(): int
    {
        return (int) $this->arguments['actorId'];
    }
}
