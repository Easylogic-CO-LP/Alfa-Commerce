<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Table;

// No direct access
defined('_JEXEC') or die;

use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table as Table;
use Joomla\CMS\Versioning\VersionableTableInterface;
use Joomla\CMS\Tag\TaggableTableInterface;
use Joomla\CMS\Tag\TaggableTableTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Filesystem\File;
use Joomla\Registry\Registry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Helper\ContentHelper;

/**
 * Place table
 *
 * @since 1.0.1
 */
class PlaceTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var    boolean
     * @since  4.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param   JDatabase  &$db  A database connector object
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.place';
        parent::__construct('#__alfa_places', 'id', $db);
        $this->setColumnAlias('published', 'state');

    }

    /**
     * Method to store a row in the database from the Table instance properties.
     *
     * If a primary key value is set the row with that primary key value will be updated with the instance property values.
     * If no primary key value is set a new row will be inserted into the database with the properties from the Table instance.
     *
     * @param   boolean  $updateNulls  True to update fields even if they are null.
     *
     * @return  boolean  True on success.
     *
     * @since   1.0.1
     */
    public function store($updateNulls = true)
    {
        return parent::store($updateNulls);
    }



}
