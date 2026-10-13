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
 * Document numbering series table (`#__alfa_document_series`).
 *
 * The counter columns (next_number, current_year, last_issued_at) are
 * advanced EXCLUSIVELY by DocumentIssuer inside the issue transaction;
 * the form layer only seeds next_number while a series has no issued
 * documents yet (SeriesModel enforces).
 *
 * @since  2.0.0
 */
class SeriesTable extends Table
{
    /**
     * Columns fully support NULL values in the database.
     *
     * @var bool
     * @since  2.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor.
     *
     * @param DatabaseDriver $db A database connector object.
     *
     * @since  2.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.series';
        parent::__construct('#__alfa_document_series', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }
}
