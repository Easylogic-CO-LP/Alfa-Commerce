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

use Alfa\Component\Alfa\Administrator\Event\Admin\AdminActions;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminListBulkActionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminListRowActionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminRenderEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminToolbarEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminAware;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminDispatcher;
use Throwable;

/**
 * The presentation half of com_alfa's admin extension contract: the toolbar, row and bulk actions,
 * the plugin columns a grid template prints, and the named render slots.
 *
 * A TRAIT, NOT A BASE CLASS, because com_alfa's admin views sit on three different parents: the
 * shared {@see HtmlView} (which emits the alfa-app wrapper), Joomla's plain HtmlView (which does
 * not), and Joomla's FormView (which builds the toolbar itself). PHP allows one parent; folding
 * them into a single base would silently add or remove the wrapper div on a third of the screens.
 * So the plumbing travels as a trait, and {@see AlfaHtmlView} / {@see AlfaFormView} are the two
 * ready-made bases for the common shapes. A view on any other parent uses the trait directly and
 * keeps the parent it has.
 *
 * WHY loadTemplate() IS THE SEAM. The toolbar has to be finished before a plugin can add to it,
 * and the page must not have rendered yet. Those two conditions meet at exactly one point that ALL
 * three shapes pass through: a list view builds its toolbar and then calls parent::display(); a
 * FormView has its toolbar built for it inside FormView::display(); both end in
 * HtmlView::display(), whose first act is loadTemplate(). Hooking addToolbar() instead would reach
 * nothing: all 47 com_alfa views override it and not one calls its parent.
 *
 * The preamble runs ONCE per view: templates call loadTemplate() again for their own partials, and
 * a toolbar built twice would show every contributed button twice.
 *
 * @since  2.0.0
 */
trait HasAdminEvents
{
    use AlfaAdminAware;

    /**
     * Which screen this view is: {@see AlfaAdminContext::SCREEN_LIST} or SCREEN_EDIT. It is what
     * tells a plugin the grid from the form, since both share the entity name.
     *
     * A method rather than a property because {@see AlfaFormView} has to answer differently, and
     * PHP refuses to let the class that USES a trait redeclare one of its properties with another
     * default. Overriding a method is free.
     *
     * @return string A SCREEN_* constant.
     *
     * @since  2.0.0
     */
    protected function alfaScreen(): string
    {
        return AlfaAdminContext::SCREEN_LIST;
    }

    /**
     * Plugin-declared list columns for this render: the descriptors
     * {@see \Alfa\Component\Alfa\Administrator\Helper\Columns} prints. Public because the template
     * reads it, and named exactly as the orders grid already names it so one template idiom serves
     * both the old wiring and the contract.
     *
     * @var array[]
     * @since  2.0.0
     */
    public array $pluginColumns = [];

    /**
     * Plugin-declared per-row actions for this render.
     *
     * @var array[]
     * @since  2.0.0
     */
    public array $pluginRowActions = [];

    /**
     * Plugin-declared bulk actions for this render.
     *
     * @var array[]
     * @since  2.0.0
     */
    public array $pluginBulkActions = [];

    /**
     * @var bool Whether the once-per-view preamble has run.
     * @since  2.0.0
     */
    private bool $alfaPreambleDone = false;

    /**
     * Render a template, running the contract's pre-render phases first.
     *
     * @param string|null $tpl The name of the template file to parse.
     *
     * @return string The rendered template.
     *
     * @since  2.0.0
     */
    public function loadTemplate($tpl = null)
    {
        $this->alfaPreamble();

        return parent::loadTemplate($tpl);
    }

    /**
     * The markup plugins contributed to a named position: the one line a template adds to open a
     * screen up:
     *
     *     <?php echo $this->renderSlot('after-toolbar'); ?>
     *
     * A slot with no contributor returns an empty string, so a template can declare as many
     * positions as it likes without paying for the ones nobody fills. Name them for WHERE they are
     * on the screen, not for what a particular plugin might put there; the name is the contract.
     *
     * @param string $slot The position name.
     *
     * @return string The contributed markup, already assembled in plugin order.
     *
     * @since  2.0.0
     */
    public function renderSlot(string $slot): string
    {
        if (!$this->alfaDispatches(AdminRenderEvent::NAME)) {
            return '';
        }

        $context = new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: $this->alfaScreen(),
            phase: AlfaAdminContext::PHASE_RENDER,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            state: $this->state ?? null,
            layout: $this->getLayout(),
            slot: $slot,
            item: \is_object($this->item ?? null) ? $this->item : null,
            items: \is_array($this->items ?? null) ? $this->items : null,
        );

        AlfaAdminDispatcher::dispatch(new AdminRenderEvent($context));

        return $context->html;
    }

    /**
     * Everything the contract does before a byte is rendered: finish the toolbar, and (on a grid)
     * collect the contributed columns and actions.
     *
     *
     * @since  2.0.0
     */
    private function alfaPreamble(): void
    {
        if ($this->alfaPreambleDone || $this->alfaEntity === '') {
            return;
        }

        $this->alfaPreambleDone = true;

        $this->alfaDispatchToolbar();

        if ($this->alfaScreen() === AlfaAdminContext::SCREEN_LIST) {
            $this->alfaCollectColumns();
            $this->alfaDispatchActions();
        }
    }

    /**
     * Hand plugins the finished toolbar.
     *
     *
     * @since  2.0.0
     */
    private function alfaDispatchToolbar(): void
    {
        if (!$this->alfaDispatches(AdminToolbarEvent::NAME)) {
            return;
        }

        try {
            $toolbar = $this->getDocument()->getToolbar();
        } catch (Throwable) {
            // No document with a toolbar (a raw or JSON render): there is nothing to contribute to.
            return;
        }

        AlfaAdminDispatcher::dispatch(new AdminToolbarEvent(new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: $this->alfaScreen(),
            phase: AlfaAdminContext::PHASE_TOOLBAR,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            state: $this->state ?? null,
            layout: $this->getLayout(),
            toolbar: $toolbar,
            item: \is_object($this->item ?? null) ? $this->item : null,
        )));
    }

    /**
     * Take the contributed columns off the model. The model is what ran the row-data and column
     * phases: it owns the rows, and it is the only place that can guarantee they are final.
     *
     *
     * @since  2.0.0
     */
    private function alfaCollectColumns(): void
    {
        $model = $this->getModel();

        if ($model !== null && method_exists($model, 'getPluginColumns')) {
            $this->pluginColumns = $model->getPluginColumns();
        }
    }

    /**
     * The plugin row data the model collected for this page, so a contributed action can resolve
     * its per-row state from the same one-query map the columns use.
     *
     * @return array<int, array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function alfaRowData(): array
    {
        $model = $this->getModel();

        return $model !== null && method_exists($model, 'getPluginRowData') ? $model->getPluginRowData() : [];
    }

    /**
     * Collect the contributed row and bulk actions: presentation, so the view asks, not the model.
     *
     *
     * @since  2.0.0
     */
    private function alfaDispatchActions(): void
    {
        $items = \is_array($this->items ?? null) ? $this->items : [];
        $ids = [];

        foreach ($items as $item) {
            if (isset($item->id)) {
                $ids[] = (int) $item->id;
            }
        }

        if ($this->alfaDispatches(AdminListRowActionsEvent::NAME)) {
            $context = new AlfaAdminContext(
                entity: $this->alfaEntity,
                view: $this->alfaView(),
                screen: AlfaAdminContext::SCREEN_LIST,
                phase: AlfaAdminContext::PHASE_LIST_ROW_ACTIONS,
                user: $this->alfaUser(),
                token: $this->alfaToken(),
                state: $this->state ?? null,
                items: $items,
                ids: $ids,
                actions: new AdminActions(),
                rowData: $this->alfaRowData(),
            );

            AlfaAdminDispatcher::dispatch(new AdminListRowActionsEvent($context));

            $this->pluginRowActions = $context->actions->getActions(user: $context->user);
        }

        if (!$this->alfaDispatches(AdminListBulkActionsEvent::NAME)) {
            return;
        }

        $context = new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_LIST,
            phase: AlfaAdminContext::PHASE_LIST_BULK_ACTIONS,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            state: $this->state ?? null,
            items: $items,
            ids: $ids,
            bulkActions: new AdminActions(),
        );

        AlfaAdminDispatcher::dispatch(new AdminListBulkActionsEvent($context));

        $this->pluginBulkActions = $context->bulkActions->getActions(user: $context->user);
    }
}
