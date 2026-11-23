<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * A webhook: a consumer of the shop's changes to whose address core posts them, signed (the consumers table, kind
 * webhook; a plugin consumer's row is kept by core and never edited here).
 *
 * @since  2.3.0
 */
class WebhookTable extends Table
{
    /**
     * Content type alias for history/tags integrations.
     *
     * @var string
     *
     * @since  2.3.0
     */
    public $typeAlias = 'com_alfa.webhook';

    /**
     * Bind the table to its database table.
     *
     * @param DatabaseDriver $db Database connector.
     *
     * @since  2.3.0
     */
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct(OutboundFeed::CONSUMERS, 'id', $db);

        // The list's enable and disable write through the generic 'published' alias; the column is 'state'.
        $this->setColumnAlias('published', 'state');
    }
}
