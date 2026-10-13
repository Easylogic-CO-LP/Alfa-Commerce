<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Closure;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;

defined('_JEXEC') or die;

/**
 * Class AlfaFrontendHelper
 *
 * @since  1.0.0
 */
class AlfaHelper
{
    /**
     * The published, plugin-enabled methods of one kind, read once per request.
     *
     * @var array<string, array>
     * @since  2.0.0
     */
    private static array $loaded = [];

    /**
     * Return the com_alfa component global parameters/settings.
     *
     * @return \Joomla\Registry\Registry The component parameters.
     *
     * @since  1.0.0
     */
    public static function getGeneralSettings()
    {
        $settings = ComponentHelper::getParams('com_alfa');

        return $settings;
    }

    /**
     * The published methods of one kind that apply to a context.
     *
     * Every scope pivot (categories, manufacturers, user groups, users,
     * places and, for payment methods, shipping methods) is an allow-list
     * in which a 0 row means "no restriction". A method is offered when each
     * dimension either holds the wildcard or meets the context. A dimension
     * passed as null is not evaluated: the product page knows no delivery
     * address or chosen shipping method, the checkout evaluates all of them.
     *
     * The full published set is read once per request and kept, decoded, per
     * kind; every call filters its own copy.
     *
     * Category and manufacturer restrictions are whole-order constraints:
     * in a multi-line context ($lines) a restricted method is offered only
     * when EVERY line is admitted; one line outside the restriction removes
     * the method. A single product passes its own scope through
     * $categories / $manufacturers, which is the one-line case of the same
     * rule. A context with no line at all is admitted by unrestricted
     * methods only.
     *
     * @param array $categories Category ids of the single product in scope (ignored when $lines is given).
     * @param array $manufacturers Manufacturer ids of the single product in scope (ignored when $lines is given).
     * @param array $usergroups The visitor's user group ids.
     * @param int $userId The visitor's user id, 0 for a guest.
     * @param string $baseTable 'payment' or 'shipment'.
     * @param array|Closure|null $placeIds The delivery place and its ancestors; null = not
     *                                     evaluated. A Closure is called with each method row and
     *                                     answers for THAT method, so a method reading the
     *                                     destination from a field of its own is filtered against
     *                                     the same chain it will later be quoted and placed against.
     * @param int|null $shipmentId The chosen shipping method, 0 when none is chosen; null = not evaluated (payment methods only).
     * @param array|null $lines Per-line scopes of a cart, [['categories' => int[], 'manufacturers' => int[]], ...]; null = the single product scope.
     *
     * @return array The offered methods keyed by id, in administration order.
     *
     * @since  1.0.0
     */
    public static function getFilteredMethods($categories, $manufacturers, $usergroups, $userId, $baseTable = 'payment', array|Closure|null $placeIds = null, ?int $shipmentId = null, ?array $lines = null)
    {
        $scopes = $lines ?? [['categories' => (array) $categories, 'manufacturers' => (array) $manufacturers]];

        // No line to admit: only unrestricted methods qualify.
        if ($scopes === []) {
            $scopes = [['categories' => [], 'manufacturers' => []]];
        }

        $offered = [];

        foreach (self::loadedMethods($baseTable) as $id => $method) {
            if (
                !self::everyLineAllowed($method, $scopes)
                || !self::scopeAllows($method->usergroups, (array) $usergroups)
                || !self::scopeAllows($method->users, [(int) $userId])
            ) {
                continue;
            }

            // PER METHOD WHEN THE CALLER CAN ANSWER PER METHOD. A shop has one
            // destination, but a single carrier may read its own (a locker
            // network asks where the LOCKER is) and the offer filter has to
            // use that method's chain, not the shop's, or it offers a carrier
            // it will refuse at the quote (or hides one it would have carried).
            $places = $placeIds instanceof Closure ? $placeIds($method) : $placeIds;

            if ($places !== null && !self::scopeAllows($method->places, $places)) {
                continue;
            }

            // A payment method restricted to shipping methods needs one of
            // them chosen: with none chosen, or a cart that does not ship,
            // only its wildcard row can match.
            if (
                $baseTable === 'payment'
                && $shipmentId !== null
                && !self::scopeAllows($method->shipments, $shipmentId > 0 ? [$shipmentId] : [])
            ) {
                continue;
            }

            $offered[$id] = $method;
        }

        return $offered;
    }

    /**
     * Whether a method's category and manufacturer restrictions admit
     * EVERY line of the context. A wildcard row admits every line at once;
     * a restricted dimension must be met by each line on its own.
     *
     * @param object $method The loaded method (categories / manufacturers comma lists).
     * @param array $lines Per-line scopes: [['categories' => int[], 'manufacturers' => int[]], ...].
     *
     * @return bool True when every line is admitted on both dimensions.
     *
     * @since  1.0.0
     */
    private static function everyLineAllowed(object $method, array $lines): bool
    {
        foreach ($lines as $line) {
            if (
                !self::scopeAllows($method->categories, (array) ($line['categories'] ?? []))
                || !self::scopeAllows($method->manufacturers, (array) ($line['manufacturers'] ?? []))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every published method of one kind whose plugin is enabled: the set a
     * cart could ever offer, before any narrowing by lines, audience,
     * destination or carrier.
     *
     * FOR WHAT MUST NOT DEPEND ON THE CART. The cart page declares each
     * method plugin's assets from this list rather than from the offer: the
     * offer narrows as the shopper types an address or removes a line, and a
     * script cannot be added to a page after its head is compiled. This list
     * changes only when the merchant publishes something.
     *
     * @param string $baseTable 'payment' or 'shipment'.
     *
     * @return array The methods keyed by id, in administration order.
     *
     * @since  2.0.0
     */
    public static function getPublishedMethods(string $baseTable): array
    {
        return self::loadedMethods($baseTable);
    }

    /**
     * One read per kind per request, shared by every filter over it.
     *
     * @param string $baseTable 'payment' or 'shipment'.
     *
     *
     * @since  2.0.0
     */
    private static function loadedMethods(string $baseTable): array
    {
        return self::$loaded[$baseTable] ??= self::loadMethods($baseTable);
    }

    /**
     * Whether an allow-list admits a context: it holds the wildcard row (0)
     * or shares at least one id with the context. A method with no row at
     * all on a dimension admits nothing.
     *
     * @param string|null $allowed The pivot ids as a comma list, as read by loadMethods().
     * @param array $given The context ids.
     *
     * @return bool True when the context is admitted.
     *
     * @since  1.0.0
     */
    private static function scopeAllows(?string $allowed, array $given): bool
    {
        if ($allowed === null || $allowed === '') {
            return false;
        }

        $allowedIds = array_map('intval', explode(',', $allowed));

        if (in_array(0, $allowedIds, true)) {
            return true;
        }

        return array_intersect($allowedIds, array_map('intval', $given)) !== [];
    }

    /**
     * Read every published method of one kind with each scope pivot as a
     * comma list (one correlated subquery per pivot, so a method with many
     * rows on several pivots never multiplies) and its settings decoded.
     *
     * @param string $baseTable 'payment' or 'shipment'.
     *
     * @return array The methods keyed by id, in administration order.
     *
     * @since  1.0.0
     */
    private static function loadMethods(string $baseTable): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('m.*')
            ->from($db->quoteName('#__alfa_' . $baseTable . 's', 'm'))
            ->where('m.state = 1')
            ->order('m.ordering ASC, m.id ASC');

        $pivots = [
            'categories' => ['#__alfa_' . $baseTable . '_categories', 'category_id'],
            'manufacturers' => ['#__alfa_' . $baseTable . '_manufacturers', 'manufacturer_id'],
            'users' => ['#__alfa_' . $baseTable . '_users', 'user_id'],
            'usergroups' => ['#__alfa_' . $baseTable . '_usergroups', 'usergroup_id'],
            'places' => ['#__alfa_' . $baseTable . '_places', 'place_id'],
        ];

        if ($baseTable === 'payment') {
            $pivots['shipments'] = ['#__alfa_payment_shipments', 'shipment_id'];
        }

        foreach ($pivots as $alias => [$table, $column]) {
            MultilingualHelper::addRelatedIdsToQuery(
                query:         $query,
                mainAlias:     'm',
                mainPk:        'id',
                junctionTable: $table,
                junctionFk:    $baseTable . '_id',
                junctionValue: $column,
                selectAlias:   $alias,
            );
        }

        // Name and description in the active language (1:1 join).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'm',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_' . $baseTable . 's',
            langPrimaryColumn: 'id_' . $baseTable,
            fields:            ['name', 'description'],
        );

        $methods = $db->setQuery($query)->loadObjectList('id') ?: [];

        // ONE shape for a method's settings everywhere: the administration
        // model hands `params` back decoded, so this path decodes too, and a
        // plugin reading $event->getMethod()->params never has to ask which
        // shape it holds. json_decode(), never Registry: Registry throws on
        // malformed JSON (one corrupt row would take every product and
        // checkout page down) and INI-parses text that is not JSON at all.
        foreach ($methods as $method) {
            $method->params = json_decode((string) $method->params, true) ?: [];
            $method->shipments ??= null;
        }

        // A METHOD WHOSE PLUGIN IS SWITCHED OFF IS NOT A METHOD.
        //
        // Two separate switches decide whether a method exists commercially and
        // this query only ever read one of them: `m.state` is the merchant
        // publishing the METHOD, while #__extensions.enabled is the merchant
        // enabling the PLUGIN that implements it. Disabling a gateway plugin
        // removed it from the admin type dropdown and reddened the setup
        // checklist, but left its published method row offered on the cart,
        // selectable, priced, and booted at placement, while the return and
        // webhook endpoints were already correctly closed against exactly this
        // (see Controller/PaymentController's isEnabled guard). The outbound
        // half stayed open, so a buyer could be sent to a withdrawn gateway,
        // pay, and come back to a dead endpoint.
        //
        // PluginHelper::isEnabled() is the same authority those endpoints use:
        // core, cached for the request, and it answers false for a plugin that
        // is disabled, uninstalled or in the wrong group alike, so this fails
        // closed on all three.
        $group = 'alfa-' . $baseTable . 's';

        return array_filter(
            $methods,
            static fn ($method): bool => PluginHelper::isEnabled($group, (string) ($method->type ?? '')),
        );
    }

    /**
     * Normalise an HTML string to plain, search-friendly text.
     * Optionally strips scripts/styles/comments and remaining tags (block elements become spaces),
     * replaces disallowed characters with spaces, optionally drops isolated punctuation, then collapses
     * whitespace.
     *
     * @param string $html The HTML/text to clean.
     * @param bool $removeTags Strip remaining HTML tags when true.
     * @param bool $removeScripts Strip <script>/<style> blocks and HTML comments when true.
     * @param bool $removeIsolatedPunctuation Remove standalone punctuation characters when true.
     *
     * @return string The cleaned, whitespace-collapsed text.
     *
     * @since  1.0.0
     */
    public static function cleanContent(
        string $html,
        bool $removeTags = false,
        bool $removeScripts = false,
        bool $removeIsolatedPunctuation = false,
    ): string {
        mb_internal_encoding('UTF-8');

        // Optionally remove <script>, <style>, and comments
        if ($removeScripts) {
            $search = [
                '@<script[^>]*?>.*?</script>@si', // Remove scripts
                '@<style[^>]*?>.*?</style>@siU',  // Remove styles
                '@<![\s\S]*?--[ \t\n\r]*>@',       // Remove HTML comments
            ];
            $html = preg_replace($search, '', $html);
        }

        // Optionally remove remaining HTML tags
        if ($removeTags) {
            // Convert block-level elements and <br> to spaces BEFORE stripping tags
            $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
            $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|td|th|blockquote|pre)>/i', ' ', $html);

            $html = strip_tags($html);
        }

        // Replace non-letter/number characters (except punctuation) with spaces
        $pattern = '/[^\p{L}\p{N}\'\"\.\!\?\;\,\/\-\:\&@]+/u';
        $html = preg_replace($pattern, ' ', $html);

        // Optionally remove isolated punctuation "words"
        if ($removeIsolatedPunctuation) {
            $html = preg_replace('/\s*[\.\!\?\;\,\/\-\:\&@]\s*/u', ' ', $html);
        }

        // Collapse multiple spaces and trim
        $html = preg_replace('/\s\s+/u', ' ', $html);
        $html = trim($html);

        return $html ?? '';
    }
}
