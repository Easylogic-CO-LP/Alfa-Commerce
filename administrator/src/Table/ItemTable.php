<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;
use Joomla\Event\EventInterface;

/**
 * Item table
 *
 * @since  1.0.0
 */
class ItemTable extends Table
{
    /**
     * Stock projection columns. The inventory service derives them from the stock
     * records and is their only writer, so a row write never carries them.
     *
     * @var string[]
     * @since  2.0.0
     */
    private const PROJECTION_COLUMNS = ['stock', 'available_quantity'];

    /**
     * The stock tracking setting. The inventory service writes every change of an
     * existing item's setting, together with the opening stock of a switch to
     * tracked, so an update never carries it; an insert stores the new item's
     * setting.
     *
     * @var string
     * @since  2.0.0
     */
    private const TRACKING_COLUMN = 'manage_stock';

    /**
     * The catalogue state. Only the inventory service moves an existing item into
     * or out of the archive, so an update never carries it in the row write: it is
     * written afterwards by a statement of its own that never crosses the archive
     * (see writeCurrentState()). An insert stores the new item's state.
     *
     * @var string
     * @since  2.0.0
     */
    private const STATE_COLUMN = 'state';

    /**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var bool
     * @since  1.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param JDatabase &$db A database connector object
     * @since  1.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.item';

        // Core auto-encodes these array values to JSON on bind().
        $this->_jsonEncode = ['layouts'];

        parent::__construct('#__alfa_items', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * Method to store a row in the database from the Table instance properties.
     *
     * If a primary key value is set the row with that primary key value will be updated with the instance property values.
     * If no primary key value is set a new row will be inserted into the database with the properties from the Table instance.
     *
     * The stock projection columns never take part in the write, and neither does
     * the tracking setting of an existing item: the inventory service is their
     * only writer. The values loaded, bound or set by a listener are held back,
     * once before the store's before-event and again as its last listener, and
     * restored on the instance afterwards. An update leaves the stored values as
     * they are; an insert stores the new item's tracking setting and leaves the
     * projections NULL until the inventory service records stock for the item.
     *
     * The state of an existing item is held out of the row write the same way and
     * written right after it by writeCurrentState(), which never moves the item
     * into or out of the archive.
     *
     * @param bool $updateNulls True to update fields even if they are null.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function store($updateNulls = true)
    {
        $held = [];

        $hold = function () use (&$held): void {
            $columns = self::PROJECTION_COLUMNS;

            if ($this->hasPrimaryKey()) {
                $columns[] = self::TRACKING_COLUMN;
                $columns[] = self::STATE_COLUMN;
            }

            foreach ($columns as $column) {
                if (property_exists($this, $column)) {
                    if (!\array_key_exists($column, $held)) {
                        $held[$column] = $this->$column;
                    }

                    unset($this->$column);
                }
            }
        };

        $holdAfterListeners = function (EventInterface $event) use ($hold): void {
            if ($event->getArgument('subject') === $this) {
                $hold();
            }
        };

        $hold();

        $dispatcher = $this->getDispatcher();
        $dispatcher->addListener(eventName: 'onTableBeforeStore', callback: $holdAfterListeners, priority: PHP_INT_MIN);

        try {
            $stored = parent::store(updateNulls: $updateNulls);

            if ($stored && isset($held[self::STATE_COLUMN]) && is_numeric($held[self::STATE_COLUMN])) {
                $this->writeCurrentState(state: (int) $held[self::STATE_COLUMN]);
            }

            return $stored;
        } finally {
            $dispatcher->removeListener(eventName: 'onTableBeforeStore', listener: $holdAfterListeners);

            foreach ($held as $column => $value) {
                $this->$column = $value;
            }
        }
    }

    /**
     * Write an existing item's current state without crossing the archive.
     *
     * One statement writes the state only while the stored state is current, and
     * judges that under the row lock it takes, so an archive committed by another
     * request after this row was loaded is never undone. An archived state is
     * never written here: moving an item into the archive is the inventory
     * service's transition, and writing it over an archived row changes nothing,
     * so a restore committed meanwhile stands too.
     *
     * @param int $state The state the row write carried.
     *
     *
     * @since  2.0.0
     */
    private function writeCurrentState(int $state): void
    {
        if (!CombinationState::isCurrent(state: $state)) {
            return;
        }

        $db = $this->getDatabase();
        $key = $this->getKeyName();
        $column = $db->quoteName(name: self::STATE_COLUMN);

        $db->setQuery(
            query: $db->getQuery(new: true)
                ->update(table: $db->quoteName(name: '#__alfa_items'))
                ->set(conditions: $column . ' = ' . $state)
                ->where(conditions: $db->quoteName(name: $key) . ' = ' . (int) $this->$key)
                ->where(conditions: CombinationState::currentSql(column: $column)),
        )->execute();
    }
}
