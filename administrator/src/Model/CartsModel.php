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
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartActivity;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * Carts list: what shoppers are assembling right now, and what has gone quiet.
 *
 * READ-ONLY by design in this slice. A cart is the shopper's own working
 * document; the merchant's job here is to see it, not to reach into it. Every
 * mutating surface (edit lines, apply a concession, check out on behalf) is a
 * later, deliberate step with its own ACL and audit trail.
 *
 * ONLY OPERATIONAL CARTS EXIST HERE. A cart that becomes an order is deleted
 * inside the placement transaction: the order is a complete, self-contained
 * record and the cart has no further business value. So this screen is never a
 * historical archive: every row in it is a cart someone could still finish.
 *
 * Two states, neither of them a column:
 *   ABANDONED  has lines and untouched for longer than the window. A QUERY,
 *              never a stored status: a cart the shopper opens again stops
 *              being abandoned by itself.
 *   ACTIVE     everything else (an empty cart is shown as such).
 *
 * @since  1.10.0
 */
class CartsModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * @var string The entity a plugin targets with $context->targets('cart').
     * @since  1.10.0
     */
    protected string $alfaEntity = 'cart';

    /**
     * Hours of silence after which a cart with lines reads as abandoned.
     *
     * Kept as this class's own constant because the view and the language
     * strings read it from here; the value itself belongs to CartActivity,
     * which is what actually decides the buckets.
     */
    public const ABANDONED_AFTER_HOURS = CartActivity::ABANDONED_AFTER_HOURS;

    /**
     * @var string|null The instant THIS request measures abandonment from.
     * @since  1.10.0
     */
    private ?string $cutoff = null;

    /**
     * Constructor: the sortable columns.
     *
     * `filter_fields` is the ORDERING whitelist: a column missing here is
     * silently ignored when an operator clicks its header.
     *
     * @param array $config Configuration array.
     *
     * @since  1.10.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'added', 'a.added',
                'updated', 'a.updated',
                'id_customer', 'a.id_customer',
                'line_count',
            ];
        }

        parent::__construct($config);
    }

    /**
     * Populate the list state (most recently active first: the useful order
     * for a screen about live shopping).
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default direction.
     *
     * @since  1.10.0
     */
    protected function populateState($ordering = 'a.updated', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);

        // Must run AFTER the parent: the trait reads the registry it fills.
        $this->resolveDateRangeFilter();
    }

    /**
     * The cache key for a result set.
     *
     * Joomla's default key knows only the filters IT declared, so this list's
     * own ones (status, owner, has_lines) would all share a store id and the
     * second query of a request would be served the first one's rows. Same
     * shape as OrdersModel: every `filter.*` in state, sorted so the key is
     * stable regardless of the order they were set in.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string
     *
     * @since  1.10.0
     */
    protected function getStoreId($id = '')
    {
        $filters = [];

        // toArray(), not getProperties(): the API app injects a plain Registry
        // as model state and only toArray() exists on both.
        foreach ($this->getState()->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'filter.')) {
                $filters[$key] = $value;
            }
        }

        ksort($filters);

        $id .= ':' . serialize($filters);

        return parent::getStoreId($id);
    }

    /**
     * The instant this request measures abandonment from.
     *
     *
     * @since  1.10.0
     */
    public function getAbandonedCutoff(): string
    {
        // ONE INSTANT PER REQUEST. The list, the tiles and each row's badge all
        // measure from this, so a cart updated between two of them can no longer
        // be counted active, filtered as abandoned and badged as neither.
        return $this->cutoff ??= CartActivity::cutoff();
    }

    /**
     * Build the list query.
     *
     * @return \Joomla\Database\QueryInterface
     *
     * @since  1.10.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $nameColumn = RoleRegistry::column(RoleRegistry::NAME);
        $emailColumn = RoleRegistry::column(RoleRegistry::EMAIL);

        $query = $db->getQuery(true)
            ->select('a.*')
            // The member behind a cart. A guest has no account by construction,
            // so the identity comes from the details record the cart points at
            // - which is exactly why that pointer had to stop being a literal 0.
            ->select($db->quoteName('u.name', 'account_name'))
            ->select($db->quoteName('u.email', 'account_email'))
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_cart_items', 'cl')
                . ' WHERE ' . $db->quoteName('cl.id_cart') . ' = ' . $db->quoteName('a.id') . ') AS ' . $db->quoteName('line_count'))
            ->select('(SELECT COALESCE(SUM(cq.quantity), 0) FROM ' . $db->quoteName('#__alfa_cart_items', 'cq')
                . ' WHERE ' . $db->quoteName('cq.id_cart') . ' = ' . $db->quoteName('a.id') . ') AS ' . $db->quoteName('unit_count'))
            ->from($db->quoteName('#__alfa_cart', 'a'))
            ->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = a.id_customer')
            ->join('LEFT', $db->quoteName('#__alfa_user_info', 'ui') . ' ON ui.id = a.id_user_info_delivery');

        // The guest's own name/email, read through the role registry rather than
        // hardcoded column names: a merchant may have adopted their own fields.
        $query->select(
            $nameColumn !== null
                ? $db->quoteName('ui.' . $nameColumn, 'guest_name')
                : "'' AS " . $db->quoteName('guest_name'),
        );
        $query->select(
            $emailColumn !== null
                ? $db->quoteName('ui.' . $emailColumn, 'guest_email')
                : "'' AS " . $db->quoteName('guest_email'),
        );

        // Date window on last activity: the column this slice made truthful.
        AlfaHelper::applyDateRange(
            query:      $query,
            column:     'a.updated',
            localStart: (string) $this->getState('filter.date_start', ''),
            localEnd:   (string) $this->getState('filter.date_end', ''),
        );

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            if (str_starts_with($search, 'id:')) {
                $query->where($db->quoteName('a.id') . ' = ' . (int) substr($search, 3));
            } else {
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                $terms = [
                    $db->quoteName('u.name') . ' LIKE ' . $like,
                    $db->quoteName('u.email') . ' LIKE ' . $like,
                ];

                if ($nameColumn !== null) {
                    $terms[] = $db->quoteName('ui.' . $nameColumn) . ' LIKE ' . $like;
                }

                if ($emailColumn !== null) {
                    $terms[] = $db->quoteName('ui.' . $emailColumn) . ' LIKE ' . $like;
                }

                $query->extendWhere('AND', $terms, 'OR');
            }
        }

        // Status: a real WHERE over the same expressions the list renders, so
        // the filter and the badge can never disagree. Never a HAVING over a
        // translated label: that breaks the moment the site changes language.
        $status = (string) $this->getState('filter.status', '');

        if (\in_array($status, ['active', 'abandoned', 'empty'], true)) {
            $query->where(CartActivity::predicate($db, $status, $this->getAbandonedCutoff()));
        }

        $owner = (string) $this->getState('filter.owner', '');

        if ($owner === 'member') {
            $query->where($db->quoteName('a.id_customer') . ' > 0');
        } elseif ($owner === 'guest') {
            $query->where($db->quoteName('a.id_customer') . ' = 0');
        }

        if ((string) $this->getState('filter.has_lines', '') === '1') {
            $query->where(CartActivity::hasLines($db));
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.updated')) . ' '
            . $db->escape($this->getState('list.direction', 'DESC')),
        );

        return $query;
    }

    /**
     * The counts above the list: how much shopping is live and how much has
     * gone quiet. There is no "converted" count: a converted cart no longer
     * exists, and the Orders screen is where that history lives.
     *
     * @return array{active: int, abandoned: int, empty: int}
     *
     * @since  1.10.0
     */
    public function getSummary(): array
    {
        $db = $this->getDatabase();

        $cutoff = $this->getAbandonedCutoff();

        try {
            // The SAME three predicates the filter uses, against the SAME
            // instant. They partition the table, so the three counts add up to
            // the row count, which is how a breakdown should behave and did
            // not, when each was measured from its own reading of the clock.
            $row = $db->setQuery(
                'SELECT'
                . ' SUM(CASE WHEN ' . CartActivity::predicate($db, 'active', $cutoff) . ' THEN 1 ELSE 0 END) AS active_carts,'
                . ' SUM(CASE WHEN ' . CartActivity::predicate($db, 'abandoned', $cutoff) . ' THEN 1 ELSE 0 END) AS abandoned_carts,'
                . ' SUM(CASE WHEN ' . CartActivity::predicate($db, 'empty', $cutoff) . ' THEN 1 ELSE 0 END) AS empty_carts'
                . ' FROM ' . $db->quoteName('#__alfa_cart', 'a'),
            )->loadAssoc() ?: [];
        } catch (Throwable $e) {
            // THREE ZEROS LOOK EXACTLY LIKE AN EMPTY SHOP. Without this line a
            // broken summary query is indistinguishable from a store nobody is
            // shopping in, and the operator has nothing to chase.
            AlfaLog::register();
            Log::add('Cart summary failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.admin');

            return ['active' => 0, 'abandoned' => 0, 'empty' => 0];
        }

        return [
            'active' => (int) ($row['active_carts'] ?? 0),
            'abandoned' => (int) ($row['abandoned_carts'] ?? 0),
            'empty' => (int) ($row['empty_carts'] ?? 0),
        ];
    }
}
