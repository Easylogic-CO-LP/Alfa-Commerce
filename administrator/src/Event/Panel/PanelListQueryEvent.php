<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Panel;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\Database\DatabaseQuery;

/**
 * A panel's list query: fired at the owning plugin (onAlfaPanelListQuery) from the shared
 * Panel list model, so the plugin owns its own list entirely.
 *
 * Unlike the orders list (where plugins CONSTRAIN an existing query), a panel's query
 * starts EMPTY: the plugin builds it in full against its own table,
 * `$query->select(...)->from(...)->where(...)->order(...)`, and the shared model runs it
 * through native pagination. Read filter values from the state as `filter.<name>`.
 *
 * @since  2.0.0
 */
class PanelListQueryEvent extends AbstractImmutableEvent
{
    /**
     * The (initially empty) query to build against the plugin's own table.
     *
     *
     * @since  2.0.0
     */
    public function getQuery(): DatabaseQuery
    {
        return $this->arguments['query'];
    }

    /**
     * The model state: read filter values as state 'filter.<name>'.
     *
     *
     * @since  2.0.0
     */
    public function getState(): object
    {
        return $this->arguments['state'];
    }

    /**
     * Who this request is for.
     *
     *
     * @since  2.0.0
     */
    public function getIdentity(): PanelIdentity
    {
        return $this->arguments['identity'];
    }
}
