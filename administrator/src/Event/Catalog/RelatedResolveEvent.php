<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedLink;
use Joomla\CMS\Event\AbstractEvent;

/**
 * A product page is about to judge its related products: `onAlfaRelatedResolve`, raised by
 * {@see \Alfa\Component\Alfa\Site\Service\Catalog\RelatedProducts::visible()} with the product's stored links in staff
 * order, before any is judged. A listener may add links, remove them or put them in another order:
 *
 *     public static function getSubscribedEvents(): array
 *     {
 *         return ['onAlfaRelatedResolve' => 'onAlfaRelatedResolve'];
 *     }
 *
 *     public function onAlfaRelatedResolve(RelatedResolveEvent $event): void
 *     {
 *         $event->remove(42)->add(RelatedLink::to(57)->onCombination(305));
 *     }
 *
 * Nothing a listener adds is trusted: every link is judged as a stored one is, against what the visitor may see and
 * buy, and one that does not stand is skipped. A product is shown once: of two links to one product the first is kept.
 * The page shows at most {@see self::limit()} of them. (No method is named set<Argument>: Joomla's event reads such a
 * method as the argument's setter.) Dispatched isolated: a listener that fails is logged, the next
 * one runs and the related products are shown from the links as the failing listener left them.
 *
 * @since  2.3.0
 */
final class RelatedResolveEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaRelatedResolve';

    /**
     * @param int $itemId The product whose page shows the links.
     * @param list<RelatedLink> $links Its links, in staff order.
     * @param int $count How many the page shows at most.
     *
     * @since  2.3.0
     */
    public static function of(int $itemId, array $links, int $count): self
    {
        return new self(self::NAME, ['itemId' => $itemId, 'links' => array_values($links), 'limit' => $count]);
    }

    /**
     * The product whose page shows the links.
     *
     * @since  2.3.0
     */
    public function itemId(): int
    {
        return (int) $this->arguments['itemId'];
    }

    /**
     * How many related products the page shows at most.
     *
     * @since  2.3.0
     */
    public function limit(): int
    {
        return (int) $this->arguments['limit'];
    }

    /**
     * The links, in the order they are judged.
     *
     * @return list<RelatedLink>
     *
     * @since  2.3.0
     */
    public function links(): array
    {
        return $this->arguments['links'];
    }

    /**
     * Add a link, after the others or at a place (0 for the first).
     *
     * @param RelatedLink $link The link.
     * @param int|null $at Its place; null for the end.
     *
     * @since  2.3.0
     */
    public function add(RelatedLink $link, ?int $at = null): self
    {
        $links = $this->links();
        array_splice($links, $at === null ? \count($links) : max(0, $at), 0, [$link]);
        $this->arguments['links'] = $links;

        return $this;
    }

    /**
     * Remove the links to a product.
     *
     * @param int $itemId The linked product.
     *
     * @since  2.3.0
     */
    public function remove(int $itemId): self
    {
        $this->arguments['links'] = array_values(array_filter($this->links(), static fn (RelatedLink $link): bool => $link->itemId !== $itemId));

        return $this;
    }

    /**
     * Put these links in place of the others, in this order.
     *
     * @param list<RelatedLink> $links The links.
     *
     * @since  2.3.0
     */
    public function replaceWith(array $links): self
    {
        $this->arguments['links'] = array_values(array_filter($links, static fn (mixed $link): bool => $link instanceof RelatedLink));

        return $this;
    }
}
