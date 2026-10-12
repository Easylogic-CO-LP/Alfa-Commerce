<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasFormDerivedFiltersTrait;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * List model for the Tools -> Media maintenance view.
 *
 * Lists every #__alfa_media row, flagging two kinds of "mess" so an admin can
 * review and bulk-delete them:
 *   - ORPHAN     : the row's (origin, item_id) has no matching parent entity
 *                  (detected in SQL via LEFT JOINs -> has_parent).
 *   - FILE MISSING: the stored file no longer exists on disk (checked per page
 *                  with file_exists(), so it stays paginatable).
 *
 * @since  1.0.0
 */
class ToolsmediaModel extends ListModel
{
    // This model extends core's ListModel rather than AlfaListModel, so it does not
    // inherit the shared list behaviour; without this the filter panel stayed SHUT on
    // a filtered page (status=orphan), because core derives "is anything filtered" from
    // filter_fields, which is really the ordering whitelist.
    use HasFormDerivedFiltersTrait;

    /**
     * Cached untracked-file scan for the current request (filtered + sorted).
     *
     * @var object[]|null
     */
    private ?array $untrackedFiles = null;

    /**
     * @param array $config
     *
     * @since  1.0.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'item_id', 'a.item_id',
                'origin', 'a.origin',
                'type', 'a.type',
                'ordering', 'a.ordering',
                // A computed SELECT alias (CASE ... AS has_parent), so it IS a valid
                // ORDER BY target even though no such column exists.
                'has_parent',

                // 'path' is BOTH a real #__alfa_media column and the key the
                // "Untracked files" mode sorts on, so it belongs here.
                'path',

                // NOTE: 'size' and 'mtime' are deliberately ABSENT. They are keys on
                // the scanned-file objects, never columns and never SELECT aliases,
                // and filter_fields is the ORDERING whitelist
                // (`in_array($fullOrdering, $this->filter_fields)` in
                // ListModel::populateState), not a list of sortable display keys.
                // Whitelisting them made `list[fullordering]=size ASC` an accepted
                // sort, and getListQuery() below drops list.ordering straight into
                // ORDER BY: the grid then went permanently empty, since searchtools'
                // Clear does not reset the sort. Nothing was lost by removing them:
                // scanUntrackedFiles() only ever sorts by path (it reads
                // list.direction and ignores list.ordering), and neither the column
                // headers nor filter_toolsmedia.xml offers either one.
            ];
        }

        parent::__construct($config);
    }

    /**
     * Filter and ordering state is read from the searchtools filter form
     * (filter_toolsmedia.xml) by the parent ListModel.
     *
     * @param string $ordering
     * @param string $direction
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Vary the cached store id by the active filters.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string
     *
     * @since  1.0.0
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.source');
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.status');

        return parent::getStoreId($id);
    }

    /**
     * @return \Joomla\Database\DatabaseQuery
     *
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            // Whether the (origin, item_id) still points at a live parent entity.
            ->select(
                '(CASE a.origin'
                . ' WHEN ' . $db->quote('item') . ' THEN (i.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('category') . ' THEN (c.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('manufacturer') . ' THEN (m.id IS NOT NULL)'
                . ' ELSE 1 END) AS has_parent',
            )
            ->from($db->quoteName('#__alfa_media', 'a'))
            ->join('LEFT', $db->quoteName('#__alfa_items', 'i')
                . ' ON a.origin = ' . $db->quote('item') . ' AND i.id = a.item_id')
            ->join('LEFT', $db->quoteName('#__alfa_categories', 'c')
                . ' ON a.origin = ' . $db->quote('category') . ' AND c.id = a.item_id')
            ->join('LEFT', $db->quoteName('#__alfa_manufacturers', 'm')
                . ' ON a.origin = ' . $db->quote('manufacturer') . ' AND m.id = a.item_id');

        // Status filter. "orphan" is pure SQL (has_parent). "missing" depends on
        // a disk check that SQL can't express, so it resolves to an explicit id
        // set scanned once here, keeping the result fully paginatable.
        switch ((string) $this->getState('filter.status')) {
            case 'orphan':
                $query->having($db->quoteName('has_parent') . ' = 0');
                break;

            case 'missing':
                $missingIds = MediaHelper::findMissingFileMediaIds();

                if (empty($missingIds)) {
                    $query->where('0 = 1');
                } else {
                    $query->whereIn($db->quoteName('a.id'), $missingIds);
                }
                break;
        }

        // Search by path or numeric item id.
        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            if (is_numeric($search)) {
                $query->where($db->quoteName('a.item_id') . ' = ' . (int) $search);
            } else {
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where('(' . $db->quoteName('a.path') . ' LIKE ' . $like
                    . ' OR ' . $db->quoteName('a.origin') . ' LIKE ' . $like . ')');
            }
        }

        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDir = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($orderCol . ' ' . $orderDir));

        return $query;
    }

    /**
     * Decorate each row with display URLs and the disk/orphan status badges.
     *
     * @return object[]|false
     *
     * @since  1.0.0
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        foreach ($items as $row) {
            $row->url = MediaHelper::toUrl($row->path);
            $row->thumbnail_url = MediaHelper::toUrl($row->thumbnail);
            $row->is_orphan = !(int) ($row->has_parent ?? 1);

            // External (type=url) media has no local file; never "missing".
            $row->file_missing = $row->type !== 'url'
                && $row->path !== ''
                && !is_file(JPATH_ROOT . '/' . ltrim((string) $row->path, '/'));
        }

        return $items;
    }

    /**
     * Full, filtered + sorted list of untracked upload files (cached per request).
     * Backs the "Untracked files" mode: files on disk with no #__alfa_media row.
     *
     * @return object[] Each: { path, size, mtime }.
     *
     * @since  1.0.0
     */
    private function scanUntrackedFiles(): array
    {
        if ($this->untrackedFiles !== null) {
            return $this->untrackedFiles;
        }

        $files = MediaHelper::findUntrackedFiles();

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $files = array_values(array_filter(
                $files,
                static fn (object $file): bool => str_contains(mb_strtolower($file->path), $needle),
            ));
        }

        $direction = strtoupper((string) $this->getState('list.direction')) === 'DESC' ? -1 : 1;

        usort($files, static fn (object $a, object $b): int => strcasecmp($a->path, $b->path) * $direction);

        return $this->untrackedFiles = $files;
    }

    /**
     * Paginated slice of untracked upload files, decorated with a display URL.
     * Called by the view (instead of getItems()) when filter.source = "files".
     *
     * @return object[]
     *
     * @since  1.0.0
     */
    public function getUntrackedMediaItems(): array
    {
        $all = $this->scanUntrackedFiles();
        $limit = (int) $this->getState('list.limit');
        $start = (int) $this->getState('list.start');

        $slice = $limit > 0 ? array_slice($all, $start, $limit) : $all;

        foreach ($slice as $file) {
            $file->url = MediaHelper::toUrl($file->path);
        }

        return $slice;
    }

    /**
     * Total item count. In "files" mode this is the untracked-file count so the
     * inherited getPagination() works unchanged; otherwise the SQL row count.
     *
     * @return int
     *
     * @since  1.0.0
     */
    public function getTotal()
    {
        if ($this->getState('filter.source') === 'files') {
            return count($this->scanUntrackedFiles());
        }

        return parent::getTotal();
    }
}
