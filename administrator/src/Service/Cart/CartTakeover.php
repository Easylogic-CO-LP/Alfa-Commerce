<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use InvalidArgumentException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;
use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

/**
 * An operator working a cart that a storefront shopper created.
 *
 * Checkout authority creates and completes operator carts. Editing authority is
 * also required to take over a shopper's cart. This class states that rule once.
 * The builder's endpoints, its screen and the placement boundary all ask it, and
 * it judges by the source the cart row persisted, never by a key, a customer
 * id, a cookie, the screen the request arrived at, or the request itself.
 *
 * Every act an operator performs on a shopper's cart goes into Joomla's User
 * Actions Log under the operator's account. The merchant can then answer which
 * cart, who, what and when, including for a cart since placed or purged.
 *
 * @since  1.10.0
 */
final class CartTakeover
{
    /** The permission a shopper's cart also asks; documentation, asked through AlfaHelper::can(). */
    public const PERMISSION = 'alfa.carts.edit';

    /** The User Actions Log context of every record. */
    public const LOG_CONTEXT = 'com_alfa.cart';

    /** The operations recorded, by builder task, with the sentence each is logged in. */
    public const OPERATIONS = [
        'takeOver' => 'COM_ALFA_ACTIONLOG_CART_TAKEOVER',
        'addLine' => 'COM_ALFA_ACTIONLOG_CART_ADDLINE',
        'setQuantity' => 'COM_ALFA_ACTIONLOG_CART_SETQUANTITY',
        'removeLine' => 'COM_ALFA_ACTIONLOG_CART_REMOVELINE',
        'applyCoupon' => 'COM_ALFA_ACTIONLOG_CART_APPLYCOUPON',
        'removeCoupon' => 'COM_ALFA_ACTIONLOG_CART_REMOVECOUPON',
        'pointAddress' => 'COM_ALFA_ACTIONLOG_CART_POINTADDRESS',
        'chooseShipment' => 'COM_ALFA_ACTIONLOG_CART_CHOOSESHIPMENT',
        'choosePayment' => 'COM_ALFA_ACTIONLOG_CART_CHOOSEPAYMENT',
        'checkout' => 'COM_ALFA_ACTIONLOG_CART_PLACED',
        'setLinePrice' => 'COM_ALFA_ACTIONLOG_CART_SETLINEPRICE',
    ];

    /** Session key: the `id:added` tokens this session confirmed taking over (screen state only). */
    private const CONFIRMED = 'com_alfa.cart_takeover';

    /**
     * The persisted source of a cart, or null when there is no such cart.
     *
     * @param int $cartId The cart.
     *
     *
     * @throws CartPersistenceException When the database refuses the read.
     *
     * @since  1.10.0
     */
    public static function sourceOf(int $cartId): ?CartSource
    {
        $row = $cartId > 0 ? (new CartRepository())->loadCart($cartId) : null;

        return $row === null ? null : CartSource::fromStored($row->source ?? null);
    }

    /**
     * May this operator change or place a cart of this source? Check out on
     * behalf always; on a cart a shopper created, Carts: Edit as well, and
     * Carts: View under it, because in the administrator a section's Edit is
     * held only together with its View ("A user may create or edit a record in
     * a section only if they may also view that section").
     *
     * @param User $actor The operator.
     * @param CartSource $source The cart's source.
     *
     *
     * @since  1.10.0
     */
    public static function authorized(User $actor, CartSource $source): bool
    {
        if (!AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor)) {
            return false;
        }

        if (!$source->requiresTakeover()) {
            return true;
        }

        return AlfaHelper::can(section: 'carts', action: 'view', user: $actor)
            && AlfaHelper::can(section: 'carts', action: 'edit', user: $actor);
    }

    /**
     * MAY THIS OPERATOR WORK THIS CART, RIGHT NOW? The one answer three callers
     * share: the screen, which decides whether to draw the builder's controls;
     * the controller, which decides whether a reading may settle; and the model,
     * which takes that reading. Stated once here, because an authority rule
     * written down three times is three rules that can disagree.
     *
     * It is authorisation PLUS the deliberate act: on a shopper's cart the
     * operator must also have confirmed taking it over in this session. That
     * confirmation is a UX safeguard and nothing more; every endpoint asks
     * {@see self::authorized()} on its own account, whatever this answers.
     *
     * @param User $actor The operator.
     * @param int $cartId The cart.
     * @param CartSource $source The cart's source.
     *
     *
     * @since  1.10.0
     */
    public static function mayOperate(User $actor, int $cartId, CartSource $source): bool
    {
        if (!AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor)) {
            return false;
        }

        if (!$source->requiresTakeover()) {
            return true;
        }

        return self::authorized(actor: $actor, source: $source) && self::confirmed($cartId);
    }

    /**
     * What the builder screen may draw for this operator and cart. Presentation
     * only: every endpoint asks authorized() on its own.
     *
     * @param User $actor The operator.
     * @param int $cartId The cart.
     * @param CartSource $source The cart's source.
     *
     * @return object{isShopperCart: bool, canTakeOver: bool, takenOver: bool, canOperate: bool}
     *
     * @since  1.10.0
     */
    public static function screen(User $actor, int $cartId, CartSource $source): object
    {
        $shoppers = $source->requiresTakeover();
        $mayTake = $shoppers && self::authorized(actor: $actor, source: $source);
        $taken = $mayTake && self::confirmed($cartId);

        return (object) [
            'isShopperCart' => $shoppers,
            'canTakeOver' => $mayTake && !$taken,
            'takenOver' => $taken,
            'canOperate' => self::mayOperate(actor: $actor, cartId: $cartId, source: $source),
            // HELD, DELIBERATELY, AND THIS ONE BOOLEAN IS THE WHOLE SWITCH.
            //
            // The pipeline below it is built, proven and audited end to end,
            // but the ORDER LINE cannot yet record that a price was overridden
            // (those columns belong to the next item). A merchant who
            // negotiated on a builder cart today would place an order with the
            // right money and no record of why, recoverable only by comparing
            // the saved line against tomorrow's catalogue: the exact
            // reconstruction the custom-price contract forbids.
            //
            // So the control ships drawn-but-unreachable. Pre-v1 has no users
            // to migrate and no back-compat debt, and the custom-price contract already imposes a
            // staged exposure of its own ("the cart-source and cart-permission
            // work must land before this capability is exposed"), so holding it
            // one step longer is that ruling's own habit rather than a new
            // policy. Server-side authority is unaffected: every endpoint asks
            // mayPriceCustom() on its own account, whatever this says.
            'canPriceCustom' => false,
        ];
    }

    /**
     * THE TOKEN A CONFIRMATION IS HELD UNDER: THE CART'S ID AND THE MOMENT IT
     * WAS CREATED, never the id alone.
     *
     * `#__alfa_cart` is operational state that is deleted at placement (its own
     * install comment says so), and MariaDB recomputes `AUTO_INCREMENT` as
     * MAX(id) + 1 when it restarts. An id can therefore come back attached to a
     * DIFFERENT shopper's cart. A session held in `#__session` outlives that
     * restart, so an id-only confirmation would read the new shopper's cart as
     * already taken over: {@see self::mayOperate()} would answer true and the
     * screen would settle it, which is a write to a cart nobody took over.
     * `added` is stamped once at creation and never updated, so the pair names
     * the cart rather than the slot it happens to occupy.
     *
     * @param int $cartId The cart.
     *
     * @return string The token, or '' when there is no readable cart, which
     *                nothing can have confirmed.
     *
     * @since  1.10.0
     */
    private static function token(int $cartId): string
    {
        try {
            $added = (string) ((new CartRepository())->loadCart($cartId)?->added ?? '');
        } catch (Throwable) {
            return '';
        }

        return $added === '' ? '' : $cartId . ':' . $added;
    }

    /**
     * Remember, for this session, that the operator confirmed taking over the cart.
     *
     * @param int $cartId The cart.
     *
     *
     * @since  1.10.0
     */
    public static function confirm(int $cartId): void
    {
        $token = self::token($cartId);

        if ($token === '') {
            return;
        }

        $session = Factory::getApplication()->getSession();
        $tokens = array_map('strval', (array) $session->get(self::CONFIRMED, []));
        $tokens[] = $token;

        $session->set(self::CONFIRMED, array_values(array_unique($tokens)));
    }

    /**
     * Has this session confirmed taking over THIS cart?
     *
     * @param int $cartId The cart.
     *
     *
     * @since  1.10.0
     */
    public static function confirmed(int $cartId): bool
    {
        $token = self::token($cartId);

        if ($token === '') {
            return false;
        }

        $tokens = (array) Factory::getApplication()->getSession()->get(self::CONFIRMED, []);

        return \in_array($token, array_map('strval', $tokens), true);
    }

    /**
     * Put an operator's act on a shopper's cart in the User Actions Log, and
     * SAY WHETHER IT IS THERE. A builder cart records nothing and answers true.
     *
     * The row is inserted directly rather than through
     * {@see \Joomla\Component\Actionlogs\Administrator\Model\ActionlogModel::addLog()},
     * which swallows a failed insert and so cannot tell a caller that the record
     * the owner requires does not exist. The columns, the JSON shape and the IP
     * rule are core's own, so the Actions Log screen renders these rows exactly
     * like any other.
     *
     * @param CartSource $source The cart's source.
     * @param int $cartId The cart.
     * @param User $actor The operator.
     * @param string $operation A key of self::OPERATIONS.
     * @param array $facts Scalar details for the sentence (line, item, code, method, order).
     *
     * @return bool True when a builder cart needed no record, or the row is written.
     *
     * @since  1.10.0
     */
    public static function record(CartSource $source, int $cartId, User $actor, string $operation, array $facts = []): bool
    {
        if (!$source->requiresTakeover()) {
            return true;
        }

        return self::write($cartId, $actor, $operation, $facts);
    }

    /**
     * MAY THIS OPERATOR SET A CUSTOM PRICE ON THIS CART'S LINES?
     *
     * Three conditions, all three required. Written once, here, beside
     * mayOperate(), because an authority rule written down three times is three
     * rules that can disagree: this file's own words, and the reason it holds
     * the others too.
     *
     * @param User $actor The operator.
     * @param int $cartId The cart.
     * @param CartSource $source The cart's source.
     *
     *
     * @since  1.11.0
     */
    public static function mayPriceCustom(User $actor, int $cartId, CartSource $source): bool
    {
        // (1) The ordinary authority to operate THIS cart. Both are
        //     required: the pricing capability never stands alone.
        if (!self::mayOperate(actor: $actor, cartId: $cartId, source: $source)) {
            return false;
        }

        // (2) An ADMIN-CREATED cart only, and this is the condition a
        //     permission check alone would miss. The custom-price contract gives the capability
        //     to the builder's own carts; a shopper's cart never gains it, and
        //     TAKING ONE OVER DOES NOT CONFER IT: mayOperate() answers true
        //     after a takeover, which is exactly why this is a separate test.
        if ($source !== CartSource::Admin) {
            return false;
        }

        // (3) The dedicated pricing authority: a permission of its
        //     own rather than overloading Carts: Edit or Check out on behalf.
        return AlfaHelper::can(section: 'carts', action: 'customprice', user: $actor);
    }

    /**
     * THE RECORD, WHATEVER THE CART'S SOURCE.
     *
     * record() returns TRUE without inserting when the cart is a builder cart,
     * because a cart the operator created needs no takeover trail. But the
     * custom-price capability exists ONLY on builder carts, so the contract's
     * "every custom-price write remains an audited consequential staff action"
     * is not satisfiable by that recorder at all: reusing it would look right,
     * report success, and write nothing.
     *
     * Same insert, same log context, same IP rule, so the Actions Log renders
     * these rows like any other.
     *
     * @param int $cartId The cart.
     * @param User $actor The operator.
     * @param string $operation A key of self::OPERATIONS.
     * @param array $facts Scalar details for the sentence.
     *
     *
     * @since  1.11.0
     */
    public static function recordAlways(int $cartId, User $actor, string $operation, array $facts = []): bool
    {
        return self::write($cartId, $actor, $operation, $facts);
    }

    /**
     * The insert both recorders share.
     *
     * @param int $cartId The cart.
     * @param User $actor The operator.
     * @param string $operation A key of self::OPERATIONS.
     * @param array $facts Scalar details for the sentence.
     *
     *
     * @since  1.11.0
     */
    private static function write(int $cartId, User $actor, string $operation, array $facts): bool
    {
        try {
            if (!isset(self::OPERATIONS[$operation])) {
                throw new InvalidArgumentException('Unknown cart operation "' . $operation . '".');
            }

            // Whose cart, as it stood: the id alone says nothing once the cart
            // has been placed or purged.
            $facts['customer'] ??= (int) ((new CartRepository())->loadCart($cartId)?->id_customer ?? 0);

            $message = [
                'action' => $operation,
                'id' => $cartId,
                'title' => '#' . $cartId,
                'itemlink' => 'index.php?option=com_alfa&view=cart&id=' . $cartId,
                'userid' => (int) $actor->id,
                'username' => (string) $actor->username,
                'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . (int) $actor->id,
            ] + $facts;

            $row = (object) [
                'message_language_key' => self::OPERATIONS[$operation],
                'message' => json_encode($message),
                'log_date' => (string) Factory::getDate(),
                'extension' => self::LOG_CONTEXT,
                'user_id' => (int) $actor->id,
                'ip_address' => self::ip(),
                'item_id' => $cartId,
            ];

            Factory::getContainer()->get(DatabaseInterface::class)->insertObject('#__action_logs', $row, 'id');

            return (int) ($row->id ?? 0) > 0;
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add(
                'Cart #' . $cartId . ': the ' . $operation . ' record was not written: ' . $e::class . ': ' . $e->getMessage(),
                Log::ERROR,
                'com_alfa.orders',
            );

            return false;
        }
    }

    /**
     * The record, or no act. Used where the record can still be written BEFORE
     * the thing it describes: taking over a shopper's cart, which the owner
     * requires to leave a trail.
     *
     * @param CartSource $source The cart's source.
     * @param int $cartId The cart.
     * @param User $actor The operator.
     * @param string $operation A key of self::OPERATIONS.
     * @param array $facts Scalar details for the sentence.
     *
     *
     * @throws RuntimeException When the record cannot be written.
     *
     * @since  1.10.0
     */
    public static function recordOrFail(CartSource $source, int $cartId, User $actor, string $operation, array $facts = []): void
    {
        if (!self::record(source: $source, cartId: $cartId, actor: $actor, operation: $operation, facts: $facts)) {
            throw new RuntimeException(Text::_('COM_ALFA_CART_TAKEOVER_NOT_RECORDED'), 500);
        }
    }

    /**
     * The caller's address, under com_actionlogs' own ip_logging option, so an
     * Alfa row never records more than the merchant asked core to record.
     *
     *
     * @since  1.10.0
     */
    private static function ip(): string
    {
        if (!ComponentHelper::getComponent('com_actionlogs')->getParams()->get('ip_logging', 0)) {
            return 'COM_ACTIONLOGS_DISABLED';
        }

        $ip = IpHelper::getIp();

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'COM_ACTIONLOGS_IP_INVALID';
    }
}
