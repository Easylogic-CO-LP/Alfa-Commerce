<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Get Field Actions Event
 *
 * Asks a field's plugin for the action buttons to show on a backend surface
 * where that field participates: the SAME fluent contract as the payments
 * GetPaymentActionsEvent. The SCOPE says which surface is asking, and the
 * event arrives PRE-BOUND to this row+field (ScopedFieldEvent), so the plugin
 * reads this row's config via $this->getConfig($event), no table knowledge:
 *
 *   'combination'  a variation row in the Studio
 *   'option'       an option row on the item edit
 *   'item'         the item-level field setup
 *   'order'        an order line on the admin order edit (the customer's saved
 *                  snapshot, order stage, task #7)
 *
 *   public function onGetFieldActions($event) {
 *       if ($event->getScope() === 'combination') {
 *           $cfg = $this->getConfig($event);       // this row's config
 *           $event->add('configure', 'Configure')->icon('pencil')->layout('configure');
 *       }
 *   }
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Helper\FieldParams;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasPluginActionsTrait;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class GetFieldActionsEvent extends AbstractImmutableEvent implements ScopedFieldEvent
{
    use HasPluginActionsTrait;

    /**
     * The surface asking: 'item' | 'combination' | 'option' | 'order'.
     *
     * @since  2.0.0
     */
    public function getScope(): string
    {
        return $this->arguments['scope'] ?? FieldParams::SCOPE_ITEM;
    }

    /**
     * The scope row id (item / combination / option-value / order-item id).
     *
     * @since  2.0.0
     */
    public function getScopeId(): int
    {
        return (int) ($this->arguments['scopeId'] ?? 0);
    }

    /**
     * The owning item id.
     *
     * @since  2.0.0
     */
    public function getItemId(): int
    {
        return (int) ($this->arguments['itemId'] ?? 0);
    }

    /**
     * The field definition row.
     *
     * @since  2.0.0
     */
    public function getField(): object
    {
        return $this->arguments['field'];
    }
}
