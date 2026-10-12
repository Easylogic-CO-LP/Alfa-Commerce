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

use Alfa\Component\Alfa\Administrator\Event\Panel\PanelListQueryEvent;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasFormDerivedFiltersTrait;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Alfa\Component\Alfa\Administrator\Panel\PanelResolver;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\DatabaseQuery;

/**
 * The shared list model behind every panel: a native {@see ListModel}, so search,
 * ordering, pagination and totals are Joomla's, not ours.
 *
 * It owns none of the data. Which plugin and screen it serves comes from the URL
 * ({@see PanelIdentity}); the SQL comes from that plugin, on {@see PanelListQueryEvent}
 * (an empty query it builds in full against its own table). The plugin selects exactly the
 * columns its list layout draws, so the rows come back render-ready: the model is the
 * native frame; the plugin is the data.
 *
 * @since  1.7.0
 */
class PanelModel extends ListModel
{
    use HasDateRangeFilterTrait;
    use HasFormDerivedFiltersTrait;

    /**
     * @since  1.7.0
     */
    private ?PanelIdentity $panelIdentity = null;

    /**
     * @param array<string, mixed> $config Model config.
     * @param MVCFactoryInterface|null $factory MVC factory.
     *
     * @since  1.7.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        // A permissive ordering whitelist: the owning plugin's list query is what actually
        // applies ordering (validating its own columns), so this only lets the native
        // ListModel accept the common state keys a panel filter form posts.
        // filter_fields is NOT set here: it is the ORDERING whitelist, and a panel's
        // table belongs to the owning plugin, so this class cannot know a valid column
        // at construction time. populateState() derives it from the panel's own form;
        // see self::declaredSortColumns(). It stays EMPTY for a panel that declares no
        // fullordering field, which is safe by construction: in_array() against []
        // never matches, so list.ordering falls back to the default and a crafted URL
        // cannot reach ORDER BY.

        parent::__construct($config, $factory);
    }

    /**
     * Read the filter/list state, scoped per panel so two panels never share a search box,
     * a status filter or a page limit.
     *
     * The scoping must land on $this->context ITSELF, not on a local copy: the native
     * populateState below reads the whole submitted filter form from
     * "$this->context.filter" (and the list options from "$this->context.list"), and with
     * the shared 'com_alfa.panel' left in place every panel would read every other panel's
     * filters back out of user state: a synonyms status filter leaking into a notes list.
     *
     * @param string|null $ordering Default ordering column.
     * @param string|null $direction Default ordering direction.
     *
     *
     * @since  1.7.0
     */
    protected function populateState($ordering = null, $direction = null): void
    {
        $identity = $this->getIdentity();

        if ($identity !== null && !str_contains($this->context, $identity->key())) {
            $this->context .= '.' . $identity->key();
        }

        // Derive the ordering whitelist BEFORE the parent runs: that is where core
        // validates list[fullordering] against it.
        $this->filter_fields = $this->declaredSortColumns();

        $this->setState('filter.search', $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string'));
        $this->setState('filter.state', $this->getUserStateFromRequest($this->context . '.filter.state', 'filter_state', '', 'string'));

        parent::populateState($ordering, $direction);

        // The shared date window, but ONLY for a panel whose form asks for it;
        // otherwise every panel would carry phantom filter.date_* state (and an
        // always-open filter bar, since the form drives getActiveFilters()). The
        // owning plugin opts in purely by declaring the fields; it applies them with
        // AlfaHelper::applyDateRange() on its own listQuery event.
        if ($this->declaresDateRangeFilter()) {
            $this->resolveDateRangeFilter();
        }
    }

    /**
     * The columns this panel allows sorting by: declared by the owning PLUGIN.
     *
     * A panel's table belongs to its plugin, so only the plugin can say which of its
     * columns are sortable. It says so the ordinary Joomla way: the `fullordering`
     * field in its own forms/filter_<panel>.xml, exactly as the component's own lists
     * do. Each option value is "<column> <DIRECTION>" (e.g. "r.created DESC"); the
     * direction is stripped and the COLUMN is what core matches against, since
     * ListModel::populateState pops the direction off before its `in_array()` check.
     *
     * Read from the form FILE, never via getFilterForm(): we are called from inside
     * populateState(), and building a Form there re-enters the state machinery and
     * 404s the panel. Cached per path: getFilterForm() parses the same file later.
     *
     * A panel that declares no fullordering field gets [], which is what keeps a
     * crafted list[fullordering] from ever reaching ORDER BY.
     *
     * @return string[] Sortable column names, no duplicates.
     *
     * @since   1.10.0
     */
    private function declaredSortColumns(): array
    {
        static $cache = [];

        $path = $this->filterFormPath();

        if ($path === null) {
            return [];
        }

        if (!isset($cache[$path])) {
            $columns = [];
            $xml = is_file($path) ? @simplexml_load_file($path) : false;

            if ($xml !== false) {
                foreach ($xml->xpath('//fields[@name="list"]/field[@name="fullordering"]/option') ?: [] as $option) {
                    $value = trim((string) $option['value']);

                    if ($value === '') {
                        continue;
                    }

                    // "r.created DESC" -> "r.created"; a value with no direction is
                    // taken as-is.
                    $parts = preg_split('/\s+/', $value);

                    if (\count($parts) > 1 && \in_array(strtoupper(end($parts)), ['ASC', 'DESC'], true)) {
                        array_pop($parts);
                    }

                    $columns[] = implode(' ', $parts);
                }
            }

            $cache[$path] = array_values(array_unique($columns));
        }

        return $cache[$path];
    }

    /**
     * Absolute path to the panel's filter form, or null when it ships none.
     *
     *
     * @since   1.10.0
     */
    private function filterFormPath(): ?string
    {
        $identity = $this->getIdentity();

        return $identity === null
            ? null
            : $identity->pluginPath() . '/forms/filter_' . $identity->panel . '.xml';
    }

    /**
     * Whether the panel's filter form declares the date window.
     *
     * Reads the form FILE rather than calling getFilterForm(). We are inside
     * populateState() here, and loading a Form re-enters the state machinery: the
     * panel 404s, the same class of failure as calling getState() from here. The XML
     * is the declaration either way, so ask it directly; the result is cached per
     * path because getFilterForm() will parse the same file again later.
     *
     *
     * @since   1.10.0
     */
    private function declaresDateRangeFilter(): bool
    {
        static $cache = [];

        $path = $this->filterFormPath();

        if ($path === null) {
            return false;
        }

        if (!isset($cache[$path])) {
            $xml = is_file($path) ? @simplexml_load_file($path) : false;

            $cache[$path] = $xml !== false
                && $xml->xpath('//fields[@name="filter"]/field[@name="date_range"]') !== [];
        }

        return $cache[$path];
    }

    /**
     * The list's filter form: the owning plugin's forms/filter_<panel>.xml, loaded with
     * Alfa's field types available, or null when it ships none (no search chrome then).
     *
     * @param array<string, mixed> $data Data for the form.
     * @param bool $loadData Whether to load the data.
     *
     * @return Form|null
     *
     * @since  1.7.0
     */
    public function getFilterForm($data = [], $loadData = true)
    {
        $identity = $this->getIdentity();

        if ($identity === null || !is_file($identity->pluginPath() . '/forms/filter_' . $identity->panel . '.xml')) {
            return null;
        }

        Form::addFormPath($identity->pluginPath() . '/forms');
        FormHelper::addFieldPrefix('Alfa\\Component\\Alfa\\Administrator\\Field');

        return $this->loadForm(
            'com_alfa.panel.filter.' . preg_replace('/[^a-z0-9]+/i', '.', $identity->key()),
            'filter_' . $identity->panel,
            ['control' => '', 'load_data' => $loadData],
        ) ?: null;
    }

    /**
     * The panel being served, resolved once from the request.
     *
     *
     * @since  1.7.0
     */
    public function getIdentity(): ?PanelIdentity
    {
        if ($this->panelIdentity === null) {
            $this->panelIdentity = PanelResolver::fromInput(Factory::getApplication()->getInput());
        }

        return $this->panelIdentity;
    }

    /**
     * Keep the list cache distinct per panel: two panels must never share a store id.
     *
     * @param string $id A prefix.
     *
     *
     * @since  1.7.0
     */
    protected function getStoreId($id = ''): string
    {
        return parent::getStoreId($id . ':' . ($this->getIdentity()?->key() ?? ''));
    }

    /**
     * The list query: built entirely by the owning plugin against its own table.
     *
     *
     * @since  1.7.0
     */
    protected function getListQuery(): DatabaseQuery
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $identity = $this->getIdentity();

        if ($identity === null) {
            // Defensive: with no valid panel the view has already refused the request;
            // return a query that yields zero rows rather than an invalid one.
            return $query->select('1')
                ->from($db->quoteName('#__extensions'))
                ->where('0 = 1');
        }

        // The operator's choice goes on FIRST, so it beats whatever the plugin orders
        // by in its own listQuery: DatabaseQuery::order() APPENDS, so the plugin's
        // hardcoded ordering survives as the tie-breaker rather than being lost. A
        // panel that declares no sortable columns gets nothing added here, and keeps
        // full control of its ordering.
        $orderCol = (string) $this->getState('list.ordering', '');

        if ($orderCol !== '' && \in_array($orderCol, $this->declaredSortColumns(), true)) {
            $orderDir = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC'
                ? 'DESC'
                : 'ASC';

            $query->order($db->quoteName($orderCol) . ' ' . $orderDir);
        }

        $event = new PanelListQueryEvent('onAlfaPanelListQuery', [
            'query' => $query,
            'state' => $this->getState(),
            'identity' => $identity,
        ]);

        PanelDispatcher::toOwner($identity, $event, 'onAlfaPanelListQuery');

        return $query;
    }

    /**
     * The current page of rows. Cast so a query error (native {@see ListModel::getItems}
     * can return false) reaches the layout as an empty list, never a non-iterable.
     *
     * @return mixed[]
     *
     * @since  1.7.0
     */
    public function getItems(): array
    {
        return (array) parent::getItems();
    }
}
