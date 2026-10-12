<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

\defined('_JEXEC') or die;

/**
 * What an engine says ABOUT rebuilding, not how rebuilding is done.
 *
 * THE COMPONENT OWNS THE OPERATION. Rebuilding the product index reads the catalogue, drives
 * {@see RebuildRunner} and {@see RebuildStore}, and swaps a generation: all component work, over
 * component data, on the component's own Tools screen. It used to be offered by whichever plugin
 * the merchant had selected, which made the engine look like the owner; it never was. The engine
 * only supplies the two things the component cannot know:
 *
 *   - what to CALL the button for this engine, and
 *   - whether a rebuild is OWED, which only the engine's own ledger can answer.
 *
 * OPTIONAL BY DESIGN. This is a separate interface rather than more methods on
 * {@see SearchEngineInterface} so that implementing it is a choice: an engine that maintains itself
 * continuously, or one backed by a service that rebuilds on its own schedule, simply does not
 * implement it and no rebuild button appears for it. Adding these to the main contract would break
 * every third-party engine that already satisfies it.
 *
 * @since  1.8.0
 */
interface RebuildableEngine
{
    /**
     * The language key for this engine's rebuild button.
     *
     * Engine-specific because two installed engines must be distinguishable at a glance: the
     * merchant is being told which index is about to be rebuilt.
     *
     * @return string A language key.
     *
     * @since  1.8.0
     */
    public function rebuildLabel(): string;

    /**
     * Whether the stored index no longer matches the settings it was built under.
     *
     * Only the engine can answer: it is the one that recorded the fingerprint of the generation it
     * built. A false answer is always safe: the button still works, it just does not warn.
     * Implementations must never throw; a missing ledger reads as "nothing owed", never as a broken
     * panel.
     *
     *
     * @since  1.8.0
     */
    public function isRebuildOwed(): bool;
}
