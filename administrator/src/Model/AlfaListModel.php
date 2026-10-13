<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AdminColumns;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminListColumnsEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminListFiltersEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminListQueryEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminListRowDataEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminAware;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminDispatcher;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasFormDerivedFiltersTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\DatabaseQuery;

/**
 * The list half of com_alfa's admin extension contract: an entity's grid model gains the query,
 * filter, row-data and column hooks by changing ONE word (the class it extends) and declaring
 * its entity name.
 *
 *     final class CurrenciesModel extends AlfaListModel
 *     {
 *         protected string $alfaEntity = 'currency';
 *         // ...everything else unchanged...
 *     }
 *
 * WHY THESE SEAMS. Every com_alfa grid model overrides getListQuery() and many override
 * getItems(), so wrapping either would put the hook where a subclass has already replaced it.
 * The seams chosen here are the ones NO model overrides, which is what lets ~25 entities gain the
 * hooks without a single line moving inside them:
 *
 *   _getListQuery()   -> onAlfaAdminListQuery    Joomla's own memoiser, downstream of whatever the
 *                                               subclass built, so a plugin always sees the
 *                                               FINISHED query, once per build, never per call.
 *   preprocessForm()  -> onAlfaAdminListFilters  the single funnel every form load passes through,
 *                                               here reached only for the grid's filter form.
 *   getPluginColumns()-> onAlfaAdminListRowData  fired lazily when the VIEW asks, which is after
 *                       onAlfaAdminListColumns  the subclass has finished enriching its rows:
 *                                               the same discipline the orders grid established.
 *
 * ROW DATA BEFORE COLUMNS, ALWAYS. The page's ids go out first so every contributor resolves its
 * values in one query; the column phase that follows is then pure lookup. That ordering is the
 * whole reason a grid with five plugin columns costs five queries and not five-times-twenty.
 *
 * @since  2.0.0
 */
abstract class AlfaListModel extends ListModel
{
    use HasFormDerivedFiltersTrait;

    use AlfaAdminAware;

    /**
     * Whether this request is a grid rendered inside a modal.
     *
     *
     * @since  2.0.0
     */
    public static function isModalRequest(): bool
    {
        return (bool) Factory::getApplication()->getInput()->getInt('modal', 0);
    }

    /**
     * Give a modal grid its own state.
     *
     * A grid shown in a modal and the same grid on its own screen are ONE model
     * context, so without this the modal's narrow filter, ordering and page
     * number are written into the bucket the full list reads from: the merchant
     * closes the popup and the main grid has silently become the popup's view of
     * the world. One suffix, applied before ListModel reads the request, keeps
     * the two apart; nothing else in the stack has to know.
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default ordering direction.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function populateState($ordering = null, $direction = null)
    {
        if (self::isModalRequest() && !str_ends_with($this->context, '.modal')) {
            $this->context .= '.modal';
        }

        parent::populateState($ordering, $direction);
    }

    /**
     * @var array[]|null Harvested plugin column descriptors ({@see AdminColumns}); null until the
     *                   list phases have run for the current state.
     * @since  2.0.0
     */
    private ?array $alfaColumns = null;

    /**
     * @var array<int, array<string, mixed>> Plugin row data for the current page, [id => [key => value]].
     * @since  2.0.0
     */
    private array $alfaRowData = [];

    /**
     * @var string|null The store id the list phases last ran for, so a second getPluginColumns()
     *                  call in the same render does not re-dispatch.
     * @since  2.0.0
     */
    private ?string $alfaListStoreId = null;

    /**
     * @var object|null The query object onAlfaAdminListQuery last saw. Joomla rebuilds the query
     *                  whenever the state changes and hands back the same instance otherwise, so
     *                  comparing instances fires the event exactly once per build without
     *                  duplicating (or depending on) the parent's caching rule.
     * @since  2.0.0
     */
    private ?object $alfaDispatchedQuery = null;

    /**
     * The finished list query, with every contributed filter applied.
     *
     * @return \Joomla\Database\QueryInterface|string
     *
     * @since  2.0.0
     */
    protected function _getListQuery()
    {
        $query = parent::_getListQuery();

        // A fresh instance means the parent rebuilt: dispatch. The same instance means we are
        // being handed the memoised query again (getTotal() and getItems() both ask for it), and
        // a second dispatch would let every contributor add its clause twice.
        if (!$query instanceof DatabaseQuery || $query === $this->alfaDispatchedQuery) {
            return $query;
        }

        $this->alfaDispatchedQuery = $query;

        if (!$this->alfaDispatches(AdminListQueryEvent::NAME)) {
            return $query;
        }

        AlfaAdminDispatcher::dispatch(new AdminListQueryEvent(new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_LIST,
            phase: AlfaAdminContext::PHASE_LIST_QUERY,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            state: $this->getState(),
            query: $query,
        )));

        return $query;
    }

    /**
     * The plugin-declared columns for the current page: plain descriptors the list template
     * renders through {@see \Alfa\Component\Alfa\Administrator\Helper\Columns}.
     *
     * Calling this is what runs the row-data and column phases, so it must be called only once the
     * rows are final. The base views do exactly that, from the render seam; a template that asks
     * earlier would hand contributors half-built rows.
     *
     * @return array[] Column descriptors, insertion order.
     *
     * @since  2.0.0
     */
    public function getPluginColumns(): array
    {
        $this->alfaRunListPhases();

        return $this->alfaColumns ?? [];
    }

    /**
     * The plugin-contributed values for the current page, [id => [key => value]]: the same map
     * the column resolvers read, exposed for a template that wants a value without a column.
     *
     * @return array<int, array<string, mixed>>
     *
     * @since  2.0.0
     */
    public function getPluginRowData(): array
    {
        $this->alfaRunListPhases();

        return $this->alfaRowData;
    }

    /**
     * Let plugins add fields to the grid's filter form.
     *
     * This is also the funnel Joomla uses for every other form a list model loads, so the event is
     * raised only for the filter form itself, the one this class is about.
     *
     * @param Form $form The form to preprocess.
     * @param mixed $data The data expected for the form.
     * @param string $group The name of the plugin group to import.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function preprocessForm(Form $form, $data, $group = 'content')
    {
        parent::preprocessForm($form, $data, $group);

        if ($form->getName() !== $this->context . '.filter' || !$this->alfaDispatches(AdminListFiltersEvent::NAME)) {
            return;
        }

        AlfaAdminDispatcher::dispatch(new AdminListFiltersEvent(new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_LIST,
            phase: AlfaAdminContext::PHASE_LIST_FILTERS,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            state: $this->getState(),
            form: $form,
        )));
    }

    /**
     * Run the row-data and column phases once for the current state.
     *
     * Rows come from $this->getItems(), i.e. from the SUBCLASS: whatever it enriches, re-orders
     * or drops after Joomla's own load is what contributors see, because a plugin's column has to
     * line up with the rows the template will actually print.
     *
     *
     * @since  2.0.0
     */
    private function alfaRunListPhases(): void
    {
        $storeId = $this->getStoreId();

        if ($this->alfaListStoreId === $storeId) {
            return;
        }

        $this->alfaListStoreId = $storeId;
        $this->alfaColumns = [];
        $this->alfaRowData = [];

        $wantsRowData = $this->alfaDispatches(AdminListRowDataEvent::NAME);
        $wantsColumns = $this->alfaDispatches(AdminListColumnsEvent::NAME);

        if (!$wantsRowData && !$wantsColumns) {
            return;
        }

        $items = $this->getItems();

        if (!\is_array($items)) {
            return;
        }

        $ids = [];

        foreach ($items as $item) {
            if (isset($item->id)) {
                $ids[] = (int) $item->id;
            }
        }

        // The whole page's ids in one hand: this is what keeps a contributed column at one query
        // however many rows and however many plugins are involved.
        if ($wantsRowData) {
            $rowDataContext = new AlfaAdminContext(
                entity: $this->alfaEntity,
                view: $this->alfaView(),
                screen: AlfaAdminContext::SCREEN_LIST,
                phase: AlfaAdminContext::PHASE_LIST_ROW_DATA,
                user: $this->alfaUser(),
                token: $this->alfaToken(),
                state: $this->getState(),
                items: $items,
                ids: $ids,
            );

            AlfaAdminDispatcher::dispatch(new AdminListRowDataEvent($rowDataContext));

            $this->alfaRowData = $rowDataContext->rowData;
        }

        if (!$wantsColumns) {
            return;
        }

        $columnsContext = new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_LIST,
            phase: AlfaAdminContext::PHASE_LIST_COLUMNS,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            state: $this->getState(),
            items: $items,
            ids: $ids,
            columns: new AdminColumns(),
            rowData: $this->alfaRowData,
        );

        AlfaAdminDispatcher::dispatch(new AdminListColumnsEvent($columnsContext));

        $this->alfaColumns = $columnsContext->columns->getColumns();
    }
}
