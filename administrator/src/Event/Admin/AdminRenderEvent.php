<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Admin;

\defined('_JEXEC') or die;

/**
 * onAlfaAdminRender: markup for a named position on the screen.
 *
 * READ    entity, view, screen, phase (render), slot, layout, user, state, item, items, token
 * WRITE   html: appended to the slot
 *
 * The screen names its positions; a handler claims the ones it wants with {@see self::at()},
 * which is the guard for this phase because one handler usually serves several:
 *
 *     public function onAlfaAdminRender(AdminRenderEvent $event): void
 *     {
 *         if (!$event->isEditScreen() || !$event->targets('order') || !$event->at('sidebar')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $context->append(LayoutHelper::render('plugins.alfa.fraud.panel', ['order' => $context->item]));
 *     }
 *
 * Fires once per slot, and every contribution is APPENDED in plugin order; nobody replaces
 * anybody. Return markup only: no headers, no redirect, no exit. The screen is mid-render, so
 * anything that ends the response takes the rest of the page with it.
 *
 * A handler that finds nothing to say simply leaves html untouched, and the slot renders
 * nothing at all rather than an empty wrapper.
 *
 * @since  1.8.0
 */
final class AdminRenderEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminRender';
}
