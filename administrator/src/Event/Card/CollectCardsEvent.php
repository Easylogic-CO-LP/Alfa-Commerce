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
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
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
 * A card is its owner plus its id (2.2.0): every way in takes the contributing
 * plugin as group.element first (core's own cards as core.com_alfa), checked for
 * its shape only, attribution and never an authorisation. Nothing is stamped
 * by the collector, so a broken contributor's half-made cards stay its own.
 *
 * @since  2.0.0
 */
class CollectCardsEvent extends AbstractEvent
{
    /**
     * @var string The event a plugin implements as a method of this name.
     * @since  2.0.0
     */
    public const NAME = 'onAlfaCollectCards';

    /**
     * @var AdminCard[]
     * @since  2.0.0
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
     * @since  2.0.0
     */
    public function screen(): string
    {
        return (string) ($this->getArgument('screen') ?? '');
    }

    /**
     * Full width, above everything.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  2.0.0
     */
    public function top(string $contributor, string $id, string $title): AdminCard
    {
        return $this->addCard($contributor, DashboardSurface::Top, $id, $title);
    }

    /**
     * The row of figures.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  2.0.0
     */
    public function metrics(string $contributor, string $id, string $title): AdminCard
    {
        return $this->addCard($contributor, DashboardSurface::Metrics, $id, $title);
    }

    /**
     * The wide column.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  2.0.0
     */
    public function main(string $contributor, string $id, string $title): AdminCard
    {
        return $this->addCard($contributor, DashboardSurface::Main, $id, $title);
    }

    /**
     * The narrow column beside it.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  2.0.0
     */
    public function side(string $contributor, string $id, string $title): AdminCard
    {
        return $this->addCard($contributor, DashboardSurface::Side, $id, $title);
    }

    /**
     * Full width at the end.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @since  2.0.0
     */
    public function bottom(string $contributor, string $id, string $title): AdminCard
    {
        return $this->addCard($contributor, DashboardSurface::Bottom, $id, $title);
    }

    /**
     * Place a card on a named surface, owned by the plugin that names itself. The typed helpers above are the
     * readable way in; this is the same door when the surface is held in a variable.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param DashboardSurface $surface Where it goes.
     * @param string $id Stable id, unique within the contributor.
     * @param string $title Language key for the heading.
     *
     * @return AdminCard The card, for chaining.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    public function addCard(string $contributor, DashboardSurface $surface, string $id, string $title): AdminCard
    {
        $owner = PluginId::of($contributor);
        $card = (new AdminCard($id, $title))
            ->screen($this->screen())
            ->surface($surface);
        $card->metadata['owner_group'] = $owner->group;
        $card->metadata['owner_name'] = $owner->element;
        $this->cards[] = $card;

        return $card;
    }

    /**
     * Every card complete enough to be placed.
     *
     * @return AdminCard[]
     *
     * @since  2.0.0
     */
    public function getCards(): array
    {
        return array_values(array_filter(
            $this->cards,
            static fn (AdminCard $card): bool => $card->isValid(),
        ));
    }
}
