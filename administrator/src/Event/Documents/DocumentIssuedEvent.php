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
 * DocumentIssuedEvent: 'onDocumentIssued'
 *
 * Dispatched AFTER COMMIT of the issue transaction (terminal position:
 * listeners observe a fully persisted, immutable document). Read-only by
 * design: getters only, no set* methods (the setX-magic-null trap), and a
 * listener failure never affects the already-committed document.
 *
 * Consumers: tax-authority transmission plugins (future), notifications, vendor
 * ledger: anything that reacts to a legal document coming into existence.
 *
 * @since  2.0.0
 */
final class DocumentIssuedEvent extends AbstractImmutableEvent
{
    /**
     * The issued document header row (fresh from the DB, post-commit).
     *
     * @return object The `#__alfa_documents` row.
     *
     * @since  2.0.0
     */
    public function getDocument(): object
    {
        return $this->arguments['document'];
    }

    /**
     * The document's line rows in sort order.
     *
     * @return object[] `#__alfa_document_items` rows.
     *
     * @since  2.0.0
     */
    public function getLines(): array
    {
        return $this->arguments['lines'];
    }

    /**
     * The parent order id.
     *
     *
     * @since  2.0.0
     */
    public function getOrderId(): int
    {
        return (int) $this->arguments['orderId'];
    }
}
