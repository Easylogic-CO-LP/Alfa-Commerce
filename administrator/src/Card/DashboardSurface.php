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

/**
 * The card slots the DASHBOARD exposes: each case is a real place on the screen,
 * rendered in this order, so a contributor lands exactly where the template puts
 * it. Typed like {@see \Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface}:
 * a wrong slot is an IDE/compile error, never a string typo that silently puts a
 * card nowhere.
 *
 * Five is deliberate. Fewer and everything competes for one row; more and nobody
 * can say what belongs where.
 *
 * @since  1.10.0
 */
enum DashboardSurface: string
{
    /** Full width, above everything: what the merchant must read before working. */
    case Top = 'top';

    /** The row of figures under it: small cards, several to a row. */
    case Metrics = 'metrics';

    /** The wide column: lists, tables, charts. */
    case Main = 'main';

    /** The narrow column beside Main: short lists, shortcuts, feeds. */
    case Side = 'side';

    /** Full width at the end: housekeeping, diagnostics, anything that can wait. */
    case Bottom = 'bottom';

    /**
     * The CSS class the surface's row carries, so a template can style one slot
     * without knowing every card in it.
     *
     *
     * @since  1.10.0
     */
    public function cssClass(): string
    {
        return 'alfa-cards--' . $this->value;
    }
}
