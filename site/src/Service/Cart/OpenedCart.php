<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

defined('_JEXEC') or die;

/**
 * One cart, opened: the owners that serve it for this request.
 *
 * A record and nothing more: it does nothing, forwards nothing, and is held
 * by exactly three composers: the cart service, the placement cart and the
 * checkout reading. It is never passed on, never handed to a layout, a plugin
 * or a factory; each of those receives the part it needs.
 *
 * @since  2.0.0
 */
final class OpenedCart
{
    /** @since 2.0.0 */
    public function __construct(
        public readonly CartHydrator $hydrator,
        public readonly CartComputation $computation,
        public readonly CartRowWriter $rows,
        public readonly CartLineWriter $lines,
        public readonly CartLineConfiguration $configuration,
        public readonly CartPluginDispatch $plugins,
        public readonly MethodReconciliation $reconciliation,
        public readonly CartMessaging $messaging,
    ) {
    }
}
