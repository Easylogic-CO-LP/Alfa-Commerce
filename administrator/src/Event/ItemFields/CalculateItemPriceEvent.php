<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Calculate Item Price Event (onCalculateItemPrice): the Stage-B pricing hook.
 *
 * Booted DIRECTLY per pricing field (bootPlugin($field->type)->onCalculateItemPrice($event)),
 * never globally dispatched, so a handler only ever receives its OWN typed event
 * for its OWN field (the instanceof guard is therefore defensive). The plugin
 * reads the item, its field, the buyer's opaque payload and its own per-item
 * config, then either APPENDS declarative price lines or OVERRIDES the final:
 *
 *   public function onCalculateItemPrice($event): void
 *   {
 *       if (!$event instanceof CalculateItemPriceEvent) { return; }
 *       $payload = $event->getPayload();                 // {finish: 'gold', images: [...]}
 *       $perImg  = (float) $event->getConfig()->get('price_per_image', 0);
 *       if ($payload && !empty($payload->images) && $perImg > 0) {
 *           $event->contribution('configurator3d.images')
 *               ->label('3 images')->add(5.00)->onGross();
 *       }
 *       // or pin the final outright: $event->setOverrideFinal(99.0);
 *   }
 *
 * Contributions are DECLARATIVE (operation + amount + net/gross target), so the
 * engine folds each through the SAME primitive option deltas use
 * (PriceComputationEngine::applyVariantAdjust) in sortOrder, and each becomes a
 * labeled step in the breakdown. The SAME event runs for the catalog price, the
 * live item-page preview and the cart line, so a configurator prices identically
 * everywhere.
 *
 * Outputs live in PLAIN properties (+ addContribution()/setOverrideFinal());
 * NEVER a setX matching an argument key (the Joomla AbstractEvent magic-set trap
 * would null it at construction; see Dev-Notes -> "AbstractEvent set{Ucfirst} magic
 * trap").
 *
 * @since  1.3.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSelections;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContribution;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContributionBuilder;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class CalculateItemPriceEvent extends AbstractImmutableEvent
{
    use HasSelections;

    /**
     * @var array<PriceContribution|PriceContributionBuilder> The lines this field's plugin appended.
     *
     * @since  1.3.0
     */
    protected array $contributions = [];

    /**
     * @var float|null The pinned final price, or null when no override.
     *
     * @since  1.3.0
     */
    protected ?float $overrideFinal = null;

    /**
     * The item being priced (the event subject).
     *
     *
     * @since  1.3.0
     */
    public function getItem(): object
    {
        return $this->arguments['subject'];
    }

    /**
     * The field driving this dispatch (type, decoded params, value pool).
     *
     *
     * @since  1.3.0
     */
    public function getField(): object
    {
        return $this->arguments['method'];
    }

    /**
     * The buyer's opaque configuration payload for this field (e.g. the
     * configurator state), null when pricing the catalog (no selection yet).
     * Only the field's own plugin interprets it; the engine never inspects it.
     *
     *
     * @since  1.3.0
     */
    public function getPayload(): mixed
    {
        return $this->arguments['payload'] ?? null;
    }

    /**
     * The per-item plugin config of this field (the admin "rule").
     *
     *
     * @since  1.3.0
     */
    public function getConfig(): Registry
    {
        return $this->arguments['config'] ?? new Registry();
    }

    /**
     * The pricing context (currency/place/usergroup/quantity), when provided.
     *
     *
     * @since  1.3.0
     */
    public function getContext(): ?object
    {
        return $this->arguments['context'] ?? null;
    }

    /**
     * Author one declarative price line fluently; typed operations, no
     * artifact imports:
     *
     *   $event->contribution('configurator3d.area.0')
     *       ->label('3 images')->add(5.00)->onGross()->sortOrder(1);
     *
     * Multiple lines compose; the engine folds them in sortOrder.
     *
     * @param string $code Stable id for the line.
     *
     *
     * @since  1.3.0
     */
    public function contribution(string $code): PriceContributionBuilder
    {
        return $this->contributions[] = new PriceContributionBuilder(code: $code);
    }

    /**
     * Append a ready-made price line (programmatic callers). Multiple are
     * allowed and compose; the engine folds them in sortOrder. Returns $this
     * for chaining.
     *
     * @param PriceContribution $contribution The line to add.
     *
     *
     * @since  1.3.0
     */
    public function addContribution(PriceContribution $contribution): static
    {
        $this->contributions[] = $contribution;

        return $this;
    }

    /**
     * The price lines this field's plugin produced, as immutable artifacts.
     * Materializing a half-written builder throws (LogicException naming the
     * contribution); the collector surfaces it loudly in development.
     *
     * @return PriceContribution[]
     *
     * @since  1.3.0
     */
    public function getContributions(): array
    {
        return array_map(
            static fn (PriceContribution|PriceContributionBuilder $entry): PriceContribution => $entry instanceof PriceContributionBuilder
                ? $entry->build()
                : $entry,
            $this->contributions,
        );
    }

    /**
     * Pin the FINAL price outright: wins over every contribution. The engine
     * carves taxes out at runtime so invoices stay truthful (same semantics as a
     * pinned-final custom discount).
     *
     * @param float $final The customer-facing final price.
     *
     *
     * @since  1.3.0
     */
    public function setOverrideFinal(float $final): static
    {
        $this->overrideFinal = $final;

        return $this;
    }

    /**
     * The pinned final, or null when no override.
     *
     *
     * @since  1.3.0
     */
    public function getOverrideFinal(): ?float
    {
        return $this->overrideFinal;
    }
}
