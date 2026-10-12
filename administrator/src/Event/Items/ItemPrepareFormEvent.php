<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Item Prepare Form Event
 *
 * Dispatched while the admin ITEM edit form is being built, so `alfa`-group
 * feature plugins can inject their own fieldsets/fields (subscription
 * settings, bundle contents panel, vendor assignment, ...). A new fieldset
 * automatically becomes its own tab in the item editor (the edit template
 * renders every non-core fieldset as a tab).
 *
 * The FORM object is intentionally mutable (that's the point: plugins call
 * $event->getForm()->load()/setField()); everything else is read-only.
 * With no listeners the form renders exactly as today (core stands alone).
 *
 * @since  1.3.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Items;

use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Form\Form;

defined('_JEXEC') or die;

class ItemPrepareFormEvent extends AbstractImmutableEvent
{
    /**
     * The item form under construction: mutate through the Form API.
     *
     * @since  1.3.0
     */
    public function getForm(): Form
    {
        return $this->arguments['form'];
    }

    /**
     * The data the form is being bound with (array or object as given).
     *
     * @return array|object
     *
     * @since  1.3.0
     */
    public function getData()
    {
        return $this->arguments['data'];
    }

    /**
     * The item id being edited (0 = new item).
     *
     * @since  1.3.0
     */
    public function getItemId(): int
    {
        return (int) $this->arguments['itemId'];
    }
}
