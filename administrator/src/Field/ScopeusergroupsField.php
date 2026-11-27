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
use Joomla\CMS\Form\Field\UsergrouplistField;
use Joomla\CMS\Helper\UserGroupsHelper;

/**
 * The customer groups a discount, a tax, a payment method or a shipping method is for: Joomla's group list, with the
 * groups the record holds that the list does not have. A group deleted in Joomla since is listed as "#12 (deleted)"
 * and stays selected, so saving the form unchanged keeps the part as it is stored and never turns it into every group
 * ({@see HasHeldDeletedOptionsTrait}). A held group the list leaves out for another reason keeps its title.
 *
 * @since  2.3.0
 */
class ScopeusergroupsField extends UsergrouplistField
{
    use HasHeldDeletedOptionsTrait;

    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'Scopeusergroups';

    /**
     * Joomla's groups, then the held ones it does not list.
     *
     * @return array
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        $groups = UserGroupsHelper::getInstance()->getAll();

        return $this->withHeldDeleted(
            options: parent::getOptions(),
            nameOf: static fn (int $id): ?string => isset($groups[$id]) ? (string) $groups[$id]->title : null,
        );
    }
}
