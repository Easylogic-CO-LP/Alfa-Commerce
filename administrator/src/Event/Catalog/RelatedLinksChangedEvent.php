<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * Products' related links changed: `onAlfaRelatedLinksChanged`, raised by
 * {@see \Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedItems} once the change has committed (a rolled-back
 * change raises nothing), for a cache, a feed or a search engine keeping a copy of them:
 *
 *     public static function getSubscribedEvents(): array
 *     {
 *         return ['onAlfaRelatedLinksChanged' => 'onAlfaRelatedLinksChanged'];
 *     }
 *
 *     public function onAlfaRelatedLinksChanged(RelatedLinksChangedEvent $event): void
 *     {
 *         foreach ($event->itemIds() as $itemId) {
 *             // read the product's links again: RelatedItems::links($db, $itemId)
 *         }
 *     }
 *
 * itemIds() are the products whose own links changed and cause() says why: {@see self::CAUSE_SAVE} the product's save
 * changed them (its Related tab, the REST API, a write built in code), {@see self::CAUSE_COMBINATION} a linked
 * product's combination they named was deleted or archived, {@see self::CAUSE_VALUE} a linked product's option or a
 * value they named was removed, {@see self::CAUSE_DELETE} a linked product was deleted. The item save's own events
 * carry `related` in the item's data besides. Heard by the plugins of the alfa and alfa-search groups, dispatched
 * isolated: a listener that fails is logged and the next one still hears it. A signal, not a job: no retry.
 *
 * @since  2.3.0
 */
final class RelatedLinksChangedEvent extends AbstractImmutableEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaRelatedLinksChanged';

    /**
     * The products' save changed their links.
     *
     * @since  2.3.0
     */
    public const CAUSE_SAVE = 'save';

    /**
     * A combination a link named was deleted or archived.
     *
     * @since  2.3.0
     */
    public const CAUSE_COMBINATION = 'combination';

    /**
     * An option or a value a link named was removed.
     *
     * @since  2.3.0
     */
    public const CAUSE_VALUE = 'value';

    /**
     * A linked product was deleted.
     *
     * @since  2.3.0
     */
    public const CAUSE_DELETE = 'delete';

    /**
     * @param list<int> $itemIds The products whose links changed.
     * @param string $cause Why ({@see self::CAUSE_SAVE} and the others).
     *
     * @since  2.3.0
     */
    public static function of(array $itemIds, string $cause): self
    {
        return new self(self::NAME, ['itemIds' => array_values(array_unique(array_map('intval', $itemIds))), 'cause' => $cause]);
    }

    /**
     * The products whose own links changed.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public function itemIds(): array
    {
        return $this->arguments['itemIds'];
    }

    /**
     * Why: save, combination, value or delete.
     *
     * @since  2.3.0
     */
    public function cause(): string
    {
        return (string) $this->arguments['cause'];
    }
}
