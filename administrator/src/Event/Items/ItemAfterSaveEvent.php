<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Item After Save Event
 *
 * Dispatched at the END of the admin item save, after every core surface has
 * persisted: `alfa`-group feature plugins store their own injected-field
 * data here (subscription settings, bundle contents, ...) into their OWN
 * tables (`#__plg_<group>_<element>_*`).
 *
 * Read-only payload; a listener failure never blocks the item save (the
 * dispatch site logs and continues; same non-blocking contract as
 * onOrderStatusChange).
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Items;

use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class ItemAfterSaveEvent extends AbstractImmutableEvent
{
    /**
     * The saved item id.
     *
     * @since  2.0.0
     */
    public function getItemId(): int
    {
        return (int) $this->arguments['itemId'];
    }

    /**
     * The validated + merged form data that was saved (includes the
     * plugin-injected keys the form filter recovered).
     *
     * @since  2.0.0
     */
    public function getData(): array
    {
        return (array) $this->arguments['data'];
    }

    /**
     * Whether this save CREATED the item.
     *
     * @since  2.0.0
     */
    public function isNew(): bool
    {
        return (bool) $this->arguments['isNew'];
    }
}
