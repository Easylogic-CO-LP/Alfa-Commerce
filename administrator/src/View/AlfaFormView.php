<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Joomla\CMS\MVC\View\FormView;

/**
 * The base for a com_alfa admin EDIT view on the extension contract: Joomla's {@see FormView}
 * (which the 15 record screens already use, and which emits no alfa-app wrapper) plus the toolbar
 * and render-slot plumbing.
 *
 *     final class HtmlView extends AlfaFormView
 *     {
 *         protected string $alfaEntity = 'currency';
 *         // ...everything else unchanged...
 *     }
 *
 * The screen is fixed to EDIT: a plugin guards with isEditScreen() + targets('currency') and gets
 * the record form, never the grid. The list-only phases (columns, row and bulk actions) never fire
 * here.
 *
 * FormView builds the toolbar inside its own display(), before rendering, which is why the
 * contract hooks loadTemplate() rather than addToolbar(): by then the toolbar is complete either
 * way, and nothing has been printed yet.
 *
 * Adds nothing to the output on its own: with no plugin subscribed, every phase this base
 * introduces short-circuits before it builds anything.
 *
 * @since  2.0.0
 */
abstract class AlfaFormView extends FormView
{
    use HasAdminEvents;

    /**
     * This base is only ever an edit screen.
     *
     *
     * @since  2.0.0
     */
    protected function alfaScreen(): string
    {
        return AlfaAdminContext::SCREEN_EDIT;
    }
}
