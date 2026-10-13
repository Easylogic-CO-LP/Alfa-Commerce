<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

/**
 * List model for the document numbering series manager.
 *
 * Not multilingual by design: series metadata is internal admin data;
 * only the code/prefix appear on documents, frozen at issue.
 *
 * @since  1.3.0
 */
class DocumentseriesModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'series';

    /**
     * Constructor.
     *
     * @param array $config Configuration settings.
     * @param MVCFactoryInterface|null $factory The MVC factory.
     *
     * @since  1.3.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'doc_type', 'a.doc_type',
                'code', 'a.code',
                'title', 'a.title',
                'next_number', 'a.next_number',
                'is_default', 'a.is_default',
                'state', 'a.state',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Cache identity includes every filter this list supports.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string
     *
     * @since  1.3.0
     */
    protected function getStoreId($id = '')
    {
        // Serialise EVERY filter into the cache identity, including filters a plugin
        // declared through onAlfaAdminListFilters, which a hand-enumerated list would
        // silently omit (stale cache + the contract's list phases skipping re-runs).
        // Same discipline as the orders grid.
        $filters = [];

        // toArray(), not (array): the model State keeps LITERAL dotted keys, so an
        // object cast returns its protected properties and matches nothing.
        foreach ($this->getState()->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'filter.')) {
                $filters[$key] = \is_array($value) ? implode(',', $value) : (string) $value;
            }
        }

        ksort($filters);

        foreach ($filters as $key => $value) {
            $id .= ':' . $key . '=' . $value;
        }

        return parent::getStoreId($id);
    }

    /**
     * Build the list query: series rows + issued-document count per series
     * (the count drives the "counter locked" hint in the list).
     *
     * @return \Joomla\Database\DatabaseQuery
     *
     * @since  1.3.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_document_series', 'a'));

        // Issued-documents count as a correlated subquery (no GROUP BY, so
        // pagination totals stay correct).
        $query->select(
            '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_documents', 'd')
            . ' WHERE d.id_series = a.id AND d.number IS NOT NULL) AS documents_count',
        );

        // What actually locks the counter is ANY document pointing at the series:
        // a draft holds id_series too and would fail to issue against a moved
        // counter (SeriesModel::hasIssuedDocuments). Counting only issued rows here
        // let the list show an unlocked 0 next to a form that refused to budge.
        $query->select(
            '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_documents', 'd2')
            . ' WHERE d2.id_series = a.id) AS references_count',
        );

        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } else {
            $query->where('a.state IN (0, 1)');
        }

        $docType = (string) $this->getState('filter.doc_type', '');

        if ($docType !== '') {
            $query->where($db->quoteName('a.doc_type') . ' = ' . $db->quote($docType));
        }

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where('(a.code LIKE ' . $like . ' OR a.title LIKE ' . $like . ' OR a.prefix LIKE ' . $like . ')');
            }
        }

        $orderCol = $this->state->get('list.ordering', 'a.doc_type');
        $orderDirn = $this->state->get('list.direction', 'ASC');
        $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));

        return $query;
    }
}
