<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\Traits\HasHeldDeletedOptionsTrait;
use Joomla\CMS\Form\Field\SqlField;

/**
 * The accounts a discount, a tax, a payment method or a shipping method is for: the accounts its query lists, with the
 * ones the record holds that the query no longer finds. An account deleted in Joomla since is listed as "#45
 * (deleted)" and stays selected, so saving the form unchanged keeps the part as it is stored and never turns it into
 * every customer ({@see HasHeldDeletedOptionsTrait}).
 *
 * @since  2.3.0
 */
class ScopeusersField extends SqlField
{
    use HasHeldDeletedOptionsTrait;

    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     * @since  2.3.0
     */
    public $type = 'Scopeusers';

    /**
     * The accounts of the query, then the held ones it does not find.
     *
     * @return array
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        return $this->withHeldDeleted(options: parent::getOptions());
    }
}
