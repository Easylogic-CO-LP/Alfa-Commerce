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

class FormfieldgroupTable extends Table
{
    protected $_supportNullValue = true;

    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.formfieldgroup';
        parent::__construct('#__alfa_form_field_groups', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }
}
