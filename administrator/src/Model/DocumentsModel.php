<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

/**
 * List model for issued sales documents (all types, one list).
 *
 * Documents are IMMUTABLE records written by DocumentIssuer; this
 * model is strictly read-side: no publish/trash states, no editing. The
 * only mutation reachable from the list is void (DocumentsController).
 *
 * @since  1.3.0
 */
class DocumentsModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'document';

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
                'status', 'a.status',
                'number', 'a.number',
                'number_formatted', 'a.number_formatted',
                'issue_date_local', 'a.issue_date_local',
                'id_order', 'a.id_order',
                'buyer_name', 'a.buyer_name',
                'total_tax_incl', 'a.total_tax_incl',
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
     * Populate the model state.
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default ordering direction.
     *
     * @return void
     *
     * @since  1.10.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);

        // Date window against issue_date_local: a bare DATE holding the FROZEN
        // legal date in shop time, and a date-only picker. Hence dateOnly, and
        // hence no UTC conversion in getListQuery().
        $this->resolveDateRangeFilter(dateOnly: true);
    }

    /**
     * Build the list query: documents + series code.
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
            ->from($db->quoteName('#__alfa_documents', 'a'));

        $query->select($db->quoteName('s.code', 'series_code'))
            ->join('LEFT', $db->quoteName('#__alfa_document_series', 's') . ' ON s.id = a.id_series');

        // Date window over the LEGAL date. issue_date_local is a bare DATE, frozen in
        // the shop's timezone at issue: it is already the thing the operator means,
        // so convertToUtc is FALSE. Converting would shift every document by the
        // offset and land invoices in the wrong month, which on a fiscal list is a
        // reporting error, not a cosmetic one. (a.issue_date is the UTC instant; it
        // is not what a period filter should ask about.)
        AlfaHelper::applyDateRange(
            query:        $query,
            column:       'a.issue_date_local',
            localStart:   (string) $this->getState('filter.date_start', ''),
            localEnd:     (string) $this->getState('filter.date_end', ''),
            convertToUtc: false,
        );

        $docType = (string) $this->getState('filter.doc_type', '');

        if ($docType !== '') {
            $query->where($db->quoteName('a.doc_type') . ' = ' . $db->quote($docType));
        }

        $status = $this->getState('filter.status');

        if (is_numeric($status)) {
            $query->where('a.status = ' . (int) $status);
        }

        // Every document belonging to one order. Reachable through the search
        // box as "order:123" too, but an explicit filter is what an
        // integration wants when listing an order's paperwork.
        $orderId = (int) $this->getState('filter.id_order', 0);

        if ($orderId > 0) {
            $query->where('a.id_order = ' . $orderId);
        }

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } elseif (stripos($search, 'order:') === 0) {
                $query->where('a.id_order = ' . (int) substr($search, 6));
            } else {
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where(
                    '(a.number_formatted LIKE ' . $like
                    . ' OR a.buyer_name LIKE ' . $like
                    . ' OR a.buyer_vat_number LIKE ' . $like . ')',
                );
            }
        }

        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'DESC');
        $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));

        return $query;
    }

    /**
     * Build the VOID form (document_void.xml), the body of the void modal.
     *
     * The control prefix is deliberately EMPTY. documents.void binds nothing:
     * it reads an id and a reason and hands them to DocumentIssuer::void(),
     * which owns the act. Flat names are that task's contract, and the API
     * controller answers to the same two names.
     *
     * @param int $documentId The document to void.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since   1.3.0
     */
    public function getVoidForm(int $documentId)
    {
        $form = $this->loadForm('com_alfa.document.void', 'document_void', [
            'control' => '',
            'load_data' => false,
        ]);

        if (empty($form)) {
            return false;
        }

        $form->bind(['id' => $documentId]);

        return $form;
    }
}
