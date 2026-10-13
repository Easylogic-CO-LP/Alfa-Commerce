<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Card;

\defined('_JEXEC') or die;

use Throwable;

/**
 * A panel placed somewhere in the admin, declared by whoever owns what it shows.
 *
 * A card names three things and the host obeys them: a **surface** (which admin
 * screen), a **position** inside that surface (which slot on it, the same idea
 * as a module position), and an **ordering** within the position. That is the
 * whole placement contract: nobody edits a template to add, move or remove a
 * card, exactly as nobody edits code to reorder the admin menu.
 *
 * There is no built-in card shape. Every card (core's included) names its own
 * layout through {@see self::setLayout()}, and a contributor shipping a layout
 * from its own extension passes its own base path with it. Core's shared layouts
 * (`card.metric`, `card.orders`) are offered to be reused, never imposed: a card
 * that wants a chart, a table or a form draws one.
 *
 * Ordering is ASCENDING, like the admin menu (100 sits above 200), because a
 * position is layout, not run order. (The Tools registry sorts DESC because its
 * numbers encode the order jobs must be run in; the difference is deliberate.)
 *
 * The data callable runs INSIDE the card: it is allowed to fail, and a card whose
 * data cannot be produced simply does not render. One broken contributor must
 * never cost the merchant the rest of the screen.
 *
 * @since  2.0.0
 */
final class AdminCard
{
    /**
     * @var int Narrowest a card may ask to be, as a percentage of the row.
     * @since  2.0.0
     */
    public const WIDTH_MIN = 5;

    /**
     * @var int A quarter row: what a figure tile wants.
     * @since  2.0.0
     */
    public const WIDTH_DEFAULT = 25;

    /**
     * @var int Where a card lands when it does not say: after everything that did.
     * @since  2.0.0
     */
    public const ORDERING_DEFAULT = 500;

    /**
     * @var string Stable id, dot-notation. Unique PER OWNER, not globally.
     * @since  2.0.0
     */
    public string $id;

    /**
     * @var string Language key for the heading.
     * @since  2.0.0
     */
    public string $title;

    /**
     * @var string Which admin screen hosts it.
     * @since  2.0.0
     */
    public string $screen = '';

    /**
     * @var string Which surface (slot) of that screen: a {@see DashboardSurface} value.
     * @since  2.0.0
     */
    public string $surface = '';

    /**
     * @var int Ascending within the position.
     * @since  2.0.0
     */
    public int $ordering = self::ORDERING_DEFAULT;

    /**
     * @var string Layout that draws this card.
     * @since  2.0.0
     */
    public string $layout = '';

    /**
     * @var string Language key for the line under the heading.
     * @since  2.0.0
     */
    public string $subtitle = '';

    /**
     * @var string Icon name the shared layouts know how to draw.
     * @since  2.0.0
     */
    public string $icon = '';

    /**
     * @var string Where the card links, if it links anywhere.
     * @since  2.0.0
     */
    public string $url = '';

    /**
     * @var int Percentage of the row this card asks for.
     * @since  2.0.0
     */
    public int $width = self::WIDTH_DEFAULT;

    /**
     * @var array Free-form, including the owner_group/owner_name the event stamps.
     * @since  2.0.0
     */
    public array $metadata = [];

    /**
     * @var callable|null Returns the array the layout renders from.
     * @since  2.0.0
     */
    private $dataProvider = null;

    /**
     * @var callable|null Decides whether this viewer sees the card at all.
     * @since  2.0.0
     */
    private $visibility = null;

    /**
     * @param string $id Stable id, unique within the owner.
     * @param string $title Language key for the heading.
     *
     * @since  2.0.0
     */
    public function __construct(string $id, string $title)
    {
        $this->id = trim($id);
        $this->title = trim($title);
    }

    /**
     * Which admin screen this card belongs to. Set by the event that collected it;
     * a contributor never types this.
     *
     * @param string $screen Screen id (`dashboard`).
     *
     *
     * @since  2.0.0
     */
    public function screen(string $screen): static
    {
        $this->screen = trim($screen);

        return $this;
    }

    /**
     * Which surface of that screen. Set by the typed helper the contributor called
     * ({@see \Alfa\Component\Alfa\Administrator\Event\Card\CollectCardsEvent::metrics()}
     * and friends), so the slot is chosen from an enum rather than typed as a string.
     *
     * @param DashboardSurface|string $surface The surface.
     *
     *
     * @since  2.0.0
     */
    public function surface(DashboardSurface|string $surface): static
    {
        $this->surface = $surface instanceof DashboardSurface ? $surface->value : trim($surface);

        return $this;
    }

    /**
     * Ascending within the surface, spaced like the menu (100, 200, 300...) so a
     * contributor can always land between two existing cards.
     *
     * @param int $ordering Lower renders first.
     *
     *
     * @since  2.0.0
     */
    public function ordering(int $ordering): static
    {
        $this->ordering = $ordering;

        return $this;
    }

    /**
     * The layout that draws this card: a NAME, never a path.
     *
     * The host knows where a contributor's layouts live, exactly as it does for
     * panels: a plugin ships `layouts/<name>.php` in its own folder and names it
     * here (`cards.notes` -> `layouts/cards/notes.php`). Core's shared layouts
     * (`card.metric`, `card.orders`) are found the same way and may be named by
     * anyone who wants that shape.
     *
     * @param string $layout Dotted layout name.
     *
     *
     * @since  2.0.0
     */
    public function setLayout(string $layout): static
    {
        $this->layout = trim($layout);

        return $this;
    }

    /**
     * @param string $key Language key for the line under the heading.
     *
     *
     * @since  2.0.0
     */
    public function subtitle(string $key): static
    {
        $this->subtitle = $key;

        return $this;
    }

    /**
     * @param string $icon Icon name the shared layouts know.
     *
     *
     * @since  2.0.0
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @param string $url Where the card links.
     *
     *
     * @since  2.0.0
     */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * How much of the row the card asks for, as a percentage: `width(25)` for a
     * figure, `width(50)` for half, `width(100)` for the full row, and anything
     * between when a layout wants it. A number says what three constants could
     * not, and the grid does the arithmetic; the request is clamped rather than
     * rejected, because a card is decoration and decoration never breaks a page.
     *
     * @param int $percent 5-100.
     *
     *
     * @since  2.0.0
     */
    public function width(int $percent): static
    {
        $this->width = max(self::WIDTH_MIN, min(100, $percent));

        return $this;
    }

    /**
     * What the layout renders from: the same hand-off the panel and payment
     * events use, with one addition: a CALLABLE is accepted as well as an array,
     * because a card's figures cost queries and a card the viewer cannot see, or a
     * position nobody renders, must not pay for them.
     *
     * @param array|callable $data Array, or a callable returning one.
     *
     *
     * @since  2.0.0
     */
    public function setLayoutData(array|callable $data): static
    {
        $this->dataProvider = \is_callable($data) ? $data : static fn (): array => $data;

        return $this;
    }

    /**
     * @param callable $visibility Returns false to hide the card from this viewer.
     *
     *
     * @since  2.0.0
     */
    public function when(callable $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * @param string $key Metadata key.
     * @param mixed $value Metadata value.
     *
     *
     * @since  2.0.0
     */
    public function meta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * A card needs an identity, a place to be, and something that draws it.
     *
     *
     * @since  2.0.0
     */
    public function isValid(): bool
    {
        return $this->id !== ''
            && $this->title !== ''
            && $this->screen !== ''
            && $this->surface !== ''
            && $this->layout !== '';
    }

    /**
     * May this viewer see it? A predicate that throws hides the card; a card is
     * decoration, and decoration never argues with the page.
     *
     *
     * @since  2.0.0
     */
    public function isVisible(): bool
    {
        if ($this->visibility === null) {
            return true;
        }

        try {
            return (bool) ($this->visibility)();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve what the layout will render from, or null when it cannot be produced.
     *
     *
     * @since  2.0.0
     */
    public function resolve(): ?array
    {
        if ($this->dataProvider === null) {
            return [];
        }

        try {
            return (array) ($this->dataProvider)();
        } catch (Throwable) {
            return null;
        }
    }
}
