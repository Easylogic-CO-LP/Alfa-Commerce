<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;

/**
 * What `backorder` may hold, and what each value means: the ONE vocabulary.
 *
 * The three actions were three loose constants on {@see InventoryService} with no set
 * behind them, so every consumer re-stated the allow-list (or, more often, did not
 * state it at all and let an unknown string through). This class owns the set, the
 * inheritance chain and the two DISTINCT empties the column carries.
 *
 * ## The values
 *
 *     deny          refuse a line the unit cannot cover
 *     allow         accept it quietly
 *     allow_notify  accept it and raise a back-office notice
 *
 * ## The inheritance chain
 *
 *     combination value -> item value -> shop-wide setting
 *
 * The column is nullable on both the item and the combination, and the two levels
 * use the empties differently on purpose:
 *
 *     combination NULL  no override: follow the item
 *     combination ''    SKIP the item and follow the shop
 *     item        NULL  nothing set: follow the shop
 *     item        ''    same; the item form's "Use shop-wide setting" posts ''
 *
 * That is what buys three levels out of two nullable columns: without the empty
 * string a variant could never opt back out of an item-level "deny".
 *
 * ## Why the merchant does not post ''
 *
 * The combination editor prepends its own blank option meaning "inherit the item",
 * while the item form's own first option is `value=""` meaning "follow the shop":
 * two blanks, one select, indistinguishable to the merchant AND to the persist layer.
 * The inherit option therefore posts {@see self::INHERIT_TOKEN}, a reserved value
 * that is never stored: {@see self::normaliseStoredValue()} maps it back to NULL.
 *
 * @since  2.0.0
 */
final class BackorderPolicy
{
    /**
     * The value a combination's "inherit the item" option POSTS. Never stored, never
     * compared against a column; it exists only to keep the empty string free for
     * its own meaning ("follow the shop").
     *
     * @var string
     * @since  2.0.0
     */
    public const INHERIT_TOKEN = '__inherit';

    /**
     * The stored value meaning "follow the shop-wide setting". Legal on both the item
     * and a combination; on a combination it additionally SKIPS the item.
     *
     * @var string
     * @since  2.0.0
     */
    public const FOLLOW_GLOBAL = '';

    /**
     * Every action a merchant may store, shop-wide or as an override.
     *
     * @var string[]
     * @since  2.0.0
     */
    public const VALUES = [
        InventoryService::BACKORDER_DENY,
        InventoryService::BACKORDER_ALLOW,
        InventoryService::BACKORDER_ALLOW_NOTIFY,
    ];

    /**
     * The shop-wide fallback, already normalised to a real action.
     *
     * @since  2.0.0
     */
    private readonly string $global;

    /**
     * @param string|null $global Explicit shop-wide action; null reads com_alfa's
     *                            parameters. Pass it explicitly from CLI and from
     *                            tests, where there is no application to ask.
     *
     * @since  2.0.0
     */
    public function __construct(?string $global = null)
    {
        // The global param is spelled like the per-item FIELD it mirrors, which is
        // what lets Joomla's native useglobal resolve it and print "Use Global (Deny)".
        $resolved = $global ?? (string) ComponentHelper::getParams('com_alfa')
            ->get('backorder', InventoryService::BACKORDER_DENY);

        // Anything unknown degrades to "deny", the shipped default, the same
        // stance other shop systems ship with: never sell what the shop
        // does not have unless the merchant explicitly opted in.
        $this->global = \in_array($resolved, self::VALUES, true) ? $resolved : InventoryService::BACKORDER_DENY;
    }

    /**
     * The shop-wide action every inheriting unit follows.
     *
     * @return string One of VALUES.
     *
     * @since  2.0.0
     */
    public function globalAction(): string
    {
        return $this->global;
    }

    /**
     * Decode a RESOLVED stored value into the action that actually applies.
     *
     * Takes what the inheritance chain settled on, not a raw combination column: NULL
     * here means "nothing was set anywhere", which is the same answer as the explicit
     * "follow the shop". Resolving the chain itself is
     * {@see InventoryService::unitPolicy()}'s job: it is the only caller that can see
     * both levels at once.
     *
     * @param string|null $stored The settled value; '' and null both follow the shop.
     *
     * @return string One of VALUES; never the empty string, never INHERIT_TOKEN.
     *
     * @since  2.0.0
     */
    public function effective(?string $stored): string
    {
        if ($stored === null || $stored === self::FOLLOW_GLOBAL) {
            return $this->global;
        }

        return \in_array($stored, self::VALUES, true) ? $stored : $this->global;
    }

    /**
     * Normalise a merchant-supplied value for STORAGE on a COMBINATION.
     *
     * Distinct from {@see self::effective()} on purpose: reading resolves the empties,
     * writing must preserve the difference between them: '' is an override ("ignore
     * the item, follow the shop") and NULL is the absence of one.
     *
     * @param mixed $value The submitted value.
     *
     * @return string|null A member of VALUES, '' for "follow the shop", or null for
     *                     "no override".
     *
     * @since  2.0.0
     */
    public static function normaliseStoredValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        // The reserved token and any value that is not an action mean "no override".
        // '' is deliberately NOT in that group; it is a stored decision.
        if ($value === self::INHERIT_TOKEN) {
            return null;
        }

        if ($value === self::FOLLOW_GLOBAL) {
            return self::FOLLOW_GLOBAL;
        }

        return \in_array($value, self::VALUES, true) ? $value : null;
    }
}
