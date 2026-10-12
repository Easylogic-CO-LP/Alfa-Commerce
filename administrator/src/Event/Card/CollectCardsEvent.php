<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Card;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Card\AdminCard;
use Alfa\Component\Alfa\Administrator\Card\DashboardSurface;
use Joomla\CMS\Event\AbstractEvent;

/**
 * The broadcast that fills an admin surface with cards.
 *
 * It carries the surface being built, so a contributor answers only for the
 * screen actually being rendered instead of declaring everything it owns on
 * every page load. Core declares its cards through this same event; there is no
 * second path and no hard-coded card, so the route a plugin's card takes is
 * exercised on every dashboard load.
 *
 * Ownership is stamped by the collector after each contributor runs
 * ({@see self::attributeTo()}), which makes it observed rather than claimed,
 * and lets a broken contributor's half-registered cards be pinned to the
 * contributor that actually added them.
 *
 * @since  1.10.0
 */
class CollectCardsEvent extends AbstractEvent
{
    /**
     * @var string The event a plugin implements as a method of this name.
     * @since  1.10.0
     */
    public const NAME = 'onAlfaCollectCards';

    /**
     * @var AdminCard[]
     * @since  1.10.0
     */
    private array $cards = [];

    /**
     * The screen being built: `dashboard` today, any admin view that hosts cards
     * tomorrow. A contributor should return early for screens it knows nothing
     * about rather than paying for queries nobody will render.
     *
     * NOT named getScreen(): `AbstractEvent::getArgument()` treats a `get{Ucfirst}`
     * method as a value PRE-PROCESSOR and calls it with the raw value, so an
     * accessor of that name which reads the argument calls itself until the stack
     * dies: a segfault with no message (sibling of the `set{Ucfirst}` trap).
     *
     *
     * @since  1.10.0
     */
    public function screen(): string
    {
        return (string) ($this->getArgument('screen') ?? '');
    }

    /**
     * Full width, above everything.
     *
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  1.10.0
     */
    public function top(string $id, string $title): AdminCard
    {
        return $this->on(DashboardSurface::Top, $id, $title);
    }

    /**
     * The row of figures.
     *
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  1.10.0
     */
    public function metrics(string $id, string $title): AdminCard
    {
        return $this->on(DashboardSurface::Metrics, $id, $title);
    }

    /**
     * The wide column.
     *
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  1.10.0
     */
    public function main(string $id, string $title): AdminCard
    {
        return $this->on(DashboardSurface::Main, $id, $title);
    }

    /**
     * The narrow column beside it.
     *
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  1.10.0
     */
    public function side(string $id, string $title): AdminCard
    {
        return $this->on(DashboardSurface::Side, $id, $title);
    }

    /**
     * Full width at the end.
     *
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  1.10.0
     */
    public function bottom(string $id, string $title): AdminCard
    {
        return $this->on(DashboardSurface::Bottom, $id, $title);
    }

    /**
     * Place a card on a named surface. The typed helpers above are the readable
     * way in; this is the same door when the surface is held in a variable.
     *
     * @param DashboardSurface $surface Where it goes.
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  1.10.0
     */
    public function on(DashboardSurface $surface, string $id, string $title): AdminCard
    {
        $card = (new AdminCard($id, $title))
            ->screen($this->screen())
            ->surface($surface);

        $this->cards[] = $card;

        return $card;
    }

    /**
     * Every card complete enough to be placed.
     *
     * @return AdminCard[]
     *
     * @since  1.10.0
     */
    public function getCards(): array
    {
        return array_values(array_filter(
            $this->cards,
            static fn (AdminCard $card): bool => $card->isValid(),
        ));
    }

    /**
     * Stamp whatever a contributor just added with who added it. Only untagged
     * cards are touched, so calling this after a contributor throws pins its
     * half-registered cards to the thrower instead of letting the next
     * contributor inherit them.
     *
     * @param string $group Extension group (`alfa`, or `core` for the component).
     * @param string $name Contributor name.
     *
     *
     * @since  1.10.0
     */
    public function attributeTo(string $group, string $name): void
    {
        foreach ($this->cards as $card) {
            if (($card->metadata['owner_group'] ?? '') !== '') {
                continue;
            }

            $card->metadata['owner_group'] = $group;
            $card->metadata['owner_name'] = $name;
        }
    }
}
