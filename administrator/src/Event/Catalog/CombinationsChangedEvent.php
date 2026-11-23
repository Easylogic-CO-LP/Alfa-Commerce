<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * onAlfaCombinationsChanged: a product's combinations were created, changed, archived or removed
 * ({@see \Alfa\Component\Alfa\Administrator\Service\Catalog\CombinationService}). Raised once a write, after its
 * commit, to each listener on its own: one listener's failure is logged and the next still hears it. Read only.
 *
 * @since  2.3.0
 */
class CombinationsChangedEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaCombinationsChanged';

    /**
     * The product.
     *
     * @since  2.3.0
     */
    public function itemId(): int
    {
        return (int) ($this->arguments['itemId'] ?? 0);
    }

    /**
     * The combinations created.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public function created(): array
    {
        return array_values(array_map('intval', (array) ($this->arguments['created'] ?? [])));
    }

    /**
     * The combinations changed.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public function changed(): array
    {
        return array_values(array_map('intval', (array) ($this->arguments['changed'] ?? [])));
    }

    /**
     * The combinations archived (removed, kept because order history names them).
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public function archived(): array
    {
        return array_values(array_map('intval', (array) ($this->arguments['archived'] ?? [])));
    }

    /**
     * The combinations deleted.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public function removed(): array
    {
        return array_values(array_map('intval', (array) ($this->arguments['removed'] ?? [])));
    }

    /**
     * Where the write came from: admin, api or sync.
     *
     * @since  2.3.0
     */
    public function origin(): string
    {
        return (string) ($this->arguments['origin'] ?? 'admin');
    }
}
