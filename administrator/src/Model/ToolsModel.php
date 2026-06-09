<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Model backing the Tools / maintenance view.
 *
 * The view itself is action-driven (its buttons post to SyncController), so this
 * model carries no list state — it exists so the MVC factory resolves a model
 * for the view rather than falling back to none.
 *
 * @since  1.0.0
 */
class ToolsModel extends BaseDatabaseModel
{
}
