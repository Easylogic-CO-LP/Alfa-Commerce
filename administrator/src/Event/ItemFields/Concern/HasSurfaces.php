<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;

defined('_JEXEC') or die;

/**
 * Shared surface store for the per-field render events (ItemViewEvent,
 * ItemListPrepareEvent, CartItemViewEvent). Each event exposes its OWN typed
 * surface(): surface(ItemSurface::X) / surface(CartSurface::X), so the valid
 * slots are scoped per context (a wrong slot is a type error). This trait holds
 * the storage + harvest; the event just maps its enum case to a region key.
 *
 * The orchestrator (model / cart hydrator) reads getSurfaces() onto $field->surfaces
 * (plain descriptors, which the dumb template renders by slot).
 *
 * @since  2.0.0
 */
trait HasSurfaces
{
    /**
     * @var SurfaceBuilder[] region value => builder.
     * @since  2.0.0
     */
    protected array $surfaceBuilders = [];

    /**
     * Get (or start) the builder for a region key. Events call this from their
     * typed surface() wrapper with $enumCase->value.
     *
     * @param string $region The region key (a *Surface enum case value).
     *
     *
     * @since  2.0.0
     */
    protected function surfaceFor(string $region): SurfaceBuilder
    {
        return $this->surfaceBuilders[$region] ??= new SurfaceBuilder();
    }

    /**
     * The set slots as plain render descriptors, keyed by region: only those a
     * plugin actually gave a layout. The orchestrator harvests this onto
     * $field->surfaces.
     *
     * @return array<string, array> region => descriptor.
     *
     * @since  2.0.0
     */
    public function getSurfaces(): array
    {
        $out = [];

        foreach ($this->surfaceBuilders as $region => $builder) {
            if ($builder->hasLayout()) {
                $out[$region] = $builder->toDescriptor();
            }
        }

        return $out;
    }
}
