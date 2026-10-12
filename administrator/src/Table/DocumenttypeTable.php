<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Document type registry table.
 *
 * Rows form the protected semantic core: created by the installer or by
 * package providers, never by merchants. The admin form edits only the
 * presentation columns; the model strips everything else before save.
 *
 * @since  1.3.0
 */
class DocumenttypeTable extends Table
{
    /**
     * Content type alias for history/tags integrations.
     *
     * @var string
     */
    public $typeAlias = 'com_alfa.documenttype';

    /**
     * Bind the table to its database table.
     *
     * @param DatabaseDriver $db Database connector.
     *
     * @since  1.3.0
     */
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__alfa_document_types', 'id', $db);

        // List-view publish/unpublish writes through the generic
        // 'published' alias; our visibility column is 'state'.
        $this->setColumnAlias('published', 'state');
    }
}
