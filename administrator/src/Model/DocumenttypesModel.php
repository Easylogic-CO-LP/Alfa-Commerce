<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;

/**
 * Document types list model: registry rows with the translated name
 * resolved in the active administrator language.
 *
 * @since  2.0.0
 */
class DocumenttypesModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'documenttype';

    /**
     * Constructor: sortable columns.
     *
     * @param array $config Configuration array.
     *
     * @since  2.0.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'alias', 'a.alias',
                'ordering', 'a.ordering',
                'state', 'a.state',
            ];
        }

        parent::__construct($config);
    }

    /**
     * Populate list state (default ordering: registry order).
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default direction.
     *
     * @since  2.0.0
     */
    protected function populateState($ordering = 'a.ordering', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Build the list query.
     *
     * @return \Joomla\Database\QueryInterface
     *
     * @since  2.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('a.*')
            ->from($db->quoteName('#__alfa_document_types', 'a'));

        // Translated name in the active admin language (default-language
        // fallback), aliased `name` for display and search.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_document_types',
            langPrimaryColumn: 'id_document_type',
            fields:            ['name'],
        );

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $like = '%' . $db->escape($search, true) . '%';

                // Both conditions in one HAVING: `name` is a SELECT alias
                // (only valid in HAVING) and this query has no GROUP BY, so
                // the real column `a.alias` is equally valid there: a
                // WHERE-stage OR'd with a HAVING-stage clause would combine
                // as AND at the top level instead of the intended OR.
                $query->having(
                    '(' . $db->quoteName('a.alias') . ' LIKE ' . $db->quote($like)
                    . ' OR ' . $db->quoteName('name') . ' LIKE ' . $db->quote($like) . ')',
                );
            }
        }

        $state = $this->getState('filter.state', '');

        if ($state !== '' && $state !== null) {
            $query->where($db->quoteName('a.state') . ' = ' . (int) $state);
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.ordering')) . ' '
            . $db->escape($this->getState('list.direction', 'ASC')),
        );

        return $query;
    }
}
