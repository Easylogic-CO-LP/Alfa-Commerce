<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

defined('_JEXEC') or die;

/**
 * What a method asked to have rendered next to itself.
 *
 * JOINED BY METHOD ID, NEVER BY OBJECT IDENTITY. The previous arrangement had
 * the component hand each plugin the very object it had memoised for that
 * method and let the plugin hang an event on it, so the selector rendered
 * whatever happened to still be attached to that exact instance. Replacing an
 * equivalent object anywhere in between silently emptied the page.
 *
 * A contribution says which method it belongs to. The selector looks it up.
 *
 * @since  1.10.0
 */
final class MethodViewContribution
{
    /**
     * @param int $methodId The method this belongs to.
     * @param string $layout The layout to render.
     * @param array $layoutData What to render it with.
     * @param string $pluginType The layout's plugin group.
     * @param string $pluginName The layout's plugin.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly int $methodId,
        public readonly string $layout,
        public readonly array $layoutData,
        public readonly string $pluginType,
        public readonly string $pluginName,
    ) {
    }
}
