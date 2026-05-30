<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Uri\Uri;
use RuntimeException;
use Throwable;

/**
 * OrderEmailHelper
 *
 * Sends status-change notification emails using the per-status,
 * per-language templates configured on `#__alfa_orders_statuses` +
 * `#__alfa_orders_statuses_<langtag>`.
 *
 * Public entry point:
 *
 *   OrderEmailHelper::sendForStatusChange(int $orderId, int $newStatusId): void
 *
 * Hooked from OrderModel::save() right after the stock-transition
 * branch on `$oldStatusId !== $newStatusId`. Wrapped in its own
 * try/catch so a mail failure never blocks the order save.
 *
 * Token catalogue (see availableTokens() for the live list shown in the
 * admin picker):
 *
 *   Order   — {order_id}, {order_number}, {order_date}, {status},
 *             {status_customer}, {order_total} (scalar formatted total),
 *             {payment_method}, {shipment_method} (method-name snapshots).
 *   User    — {user_id}, {user_name}, {user_username}, {user_email},
 *             {user_registered}. Empty strings for guest orders.
 *   Fields  — dynamic {field_<machine_key>} for every active row in
 *             `#__alfa_form_fields`, rendered through FieldsHelper so
 *             each plugin's tmpl decides presentation.
 *   Site    — {site_name}, {site_url}.
 *
 * Tokens are SCALAR only. The items table and totals breakdown are
 * STRUCTURE: the layout renders them via $render('emails.partials.*')
 * with the data exposed in $displayData (items, totals) — no
 * presentation layout id lives in this helper. Partials under
 * `layouts/emails/partials/` are overridable by templates.
 *
 * @since   1.0.4
 */
class OrderEmailHelper
{
    /**
     * Default layout id for the order-email wrapper. Order-email layouts
     * live under layouts/emails/order/<name>.php (id emails.order.<name>);
     * 'default' is the shipped one. Override via
     *   templates/<template>/html/layouts/com_alfa/emails/order/default.php
     * when a site needs custom branding.
     */
    private const LAYOUT_ID = 'emails.order.default';

    /**
     * Logical position included in the JSON value alongside the
     * layout-discovered body positions. `subject` lives in the same
     * payload but is read into `Mail::setSubject()` rather than being
     * substituted into the body. Kept out of discoverPositions() so
     * callers know to render it differently (single-line text input
     * vs. WYSIWYG editor).
     */
    public const SUBJECT_POSITION = 'subject';

    /**
     * Language-key prefix for a position's seedable default content.
     * The full key is PREFIX . strtoupper($position), e.g.
     * COM_ALFA_ORDEREMAIL_DEFAULT_INTRO. Resolved per content-language
     * (see defaultContent) with an untranslated-key guard so a raw
     * constant is never seeded.
     */
    private const DEFAULT_KEY_PREFIX = 'COM_ALFA_ORDEREMAIL_DEFAULT_';

    /**
     * Request-scoped cache of body positions per layout id.
     * Keyed by layout id (e.g. 'emails.order.default') →
     * ordered list of position names found in the rendered layout.
     *
     * @var array<string, string[]>
     */
    private static array $positionsCache = [];

    /**
     * Discover the body positions an email layout asks for.
     *
     * Renders the layout via LayoutHelper (so template overrides are
     * picked up) with COLLECTOR closures in place of the real helper API:
     * every `$position($name)` / `$hasPosition($name)` call records the
     * name. `$hasPosition` returns true during discovery so positions
     * guarded by `if ($hasPosition(...))` are still reached; `$render` is
     * a no-op (we only want the position names, not partial output).
     *
     * The collected names, in first-call order, are the editors
     * EmailPositionsField renders. The logical `subject` position is NOT
     * among them — it's authored separately and never requested by a
     * layout.
     *
     * Memoised per request.
     *
     * @param string $layoutId Joomla layout id, e.g. 'emails.order.default'.
     *
     * @return string[] Ordered body position names.
     *
     * @since   1.0.4
     */
    public static function discoverPositions(string $layoutId): array
    {
        $names = [];

        foreach (self::discoverSequence(layoutId: $layoutId) as $entry) {
            if ($entry['type'] === 'position') {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    /**
     * Discover the ORDERED sequence of slots an email layout asks for —
     * both admin-fillable positions and the layout-owned structural
     * blocks (items/totals via $render) — so the composer can place each
     * editor and the items block exactly where the layout puts them.
     *
     * Renders the layout once with collector closures:
     *   • $position / $hasPosition → records ['type'=>'position','name'=>x]
     *     (deduped; the logical `subject` is skipped — authored separately).
     *   • $render → records ['type'=>'struct'] (consecutive renders collapse
     *     into one block, e.g. order_items + order_totals).
     *
     * Memoised per request.
     *
     * @param string $layoutId Joomla layout id (e.g. 'emails.order.default').
     *
     * @return array<int, array{type:string, name?:string}> Ordered entries.
     *
     * @since   1.0.4
     */
    public static function discoverSequence(string $layoutId): array
    {
        if (isset(self::$positionsCache[$layoutId])) {
            return self::$positionsCache[$layoutId];
        }

        $seq = [];
        $seen = [];

        $record = static function (string $type, string $name = '') use (&$seq, &$seen): void {
            if ($type === 'position') {
                if ($name === '' || $name === self::SUBJECT_POSITION || isset($seen[$name])) {
                    return;
                }
                $seen[$name] = true;
                $seq[] = ['type' => 'position', 'name' => $name];

                return;
            }

            // struct — $name is the rendered partial's id (e.g.
            // 'emails.partials.order_items'). The composer derives the block's
            // label from the id itself (last segment, humanised) — NO hardcoded
            // family list, so any partial a layout $renders shows a sensible
            // placeholder. Deduped per-id and consecutive-collapsed: the same
            // partial twice in a row is one block, distinct partials each their
            // own. (Consecutive items+totals stay separate blocks now — they're
            // different ids — which is fine and more accurate.)
            if ($name === '' || isset($seen['struct:' . $name])) {
                $last = end($seq);
                if ($last !== false && $last['type'] === 'struct' && ($last['name'] ?? '') === $name) {
                    return;
                }
            }
            $seen['struct:' . $name] = true;
            $seq[] = ['type' => 'struct', 'name' => $name];
        };

        try {
            LayoutHelper::render(
                $layoutId,
                array_merge(self::displayDataSkeleton(), [
                    // One stub row per list so the layout's `if (!empty(...))`
                    // branches (items/totals, payments, shipments) are walked
                    // during discovery. Never inspected: $render is a no-op
                    // collector here.
                    'items' => [(object) []],
                    'payments' => [(object) []],
                    'shipments' => [(object) []],
                    'position' => static function (string $name) use ($record): string {
                        $record('position', $name);
                        return '';
                    },
                    'hasPosition' => static function (string $name) use ($record): bool {
                        $record('position', $name);
                        return true;
                    },
                    'render' => static function (string $id = '', ?array $data = null) use ($record): string {
                        $record('struct', $id);
                        return '';
                    },
                ]),
                null,
                ['component' => 'com_alfa', 'client' => 1],
            );
        } catch (Throwable $e) {
            Log::add(
                entry:    'OrderEmailHelper::discoverSequence failed for "' . $layoutId . '": ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orderemail',
            );

            return self::$positionsCache[$layoutId] = [];
        }

        return self::$positionsCache[$layoutId] = $seq;
    }

    /**
     * Return the full catalogue of tokens admins can drop into an email
     * subject / body, grouped for the token picker UI.
     *
     * Static categories (Order, Customer, Site) are hardcoded. The
     * "Customer details" category is built from the active rows in
     * `#__alfa_form_fields` — same source loadFormFieldNames() uses,
     * but enriched with the field's translated label so the picker
     * shows "Company name" next to `{field_company}` instead of a
     * bare machine key.
     *
     * @return array<string, array<string, string>> Map of group key
     *                                              (`order`, `user`, `fields`, `site`) → token → label.
     *
     * @since   1.0.4
     */
    public static function availableTokens(): array
    {
        return [
            'order' => [
                '{order_id}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_ORDER_ID'),
                '{order_number}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_ORDER_NUMBER'),
                '{order_date}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_ORDER_DATE'),
                '{status}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_STATUS'),
                '{status_customer}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_STATUS_CUSTOMER'),
                '{order_total}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_ORDER_TOTAL'),
                '{payment_method}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PAYMENT_METHOD'),
                '{shipment_method}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_SHIPMENT_METHOD'),
            ],
            'user' => [
                '{user_id}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_USER_ID'),
                '{user_name}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_USER_NAME'),
                '{user_username}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_USER_USERNAME'),
                '{user_email}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_USER_EMAIL'),
                '{user_registered}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_USER_REGISTERED'),
            ],
            'fields' => self::loadFormFieldTokenCatalogue(),
            'site' => [
                '{site_name}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_SITE_NAME'),
                '{site_url}' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_SITE_URL'),
            ],
        ];
    }

    /**
     * Seedable default content for ONE position in ONE content language.
     *
     * Resolves COM_ALFA_ORDEREMAIL_DEFAULT_<POSITION> through a language
     * fallback chain: the requested language → en-GB → the first installed
     * content language → ''. Each candidate is run through the
     * untranslated-key guard (a Language that returns the key unchanged has
     * no translation), so a raw COM_ALFA_… constant is never seeded or shown.
     *
     * Defaults are plain HTML carrying tokens (e.g. {order_number},
     * {status_customer}); the tokens resolve at send/preview time.
     *
     * @param string $position Position name (incl. SUBJECT_POSITION).
     * @param string $langTag Requested content language (e.g. 'el-GR').
     *
     * @return string Default HTML, or '' when no language in the chain
     *                translates the key.
     *
     * @since   1.0.4
     */
    public static function defaultContent(string $position, string $langTag): string
    {
        $key = self::DEFAULT_KEY_PREFIX . strtoupper($position);

        foreach (self::defaultLangChain(langTag: $langTag) as $tag) {
            $value = self::translateInLanguage(key: $key, langTag: $tag);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Every seedable default for a language: the subject plus each position
     * the layout declares (preheader included, since the layout requests it
     * via $position()). Keyed by position name; empty defaults are omitted so
     * a virgin-seed only writes meaningful slots.
     *
     * @param string $layoutId Layout whose positions to seed.
     * @param string $langTag Content language to resolve defaults in.
     *
     * @return array<string, string> position => default HTML.
     *
     * @since   1.0.4
     */
    public static function defaultsForLanguage(string $layoutId, string $langTag): array
    {
        $defaults = [];

        $subject = self::defaultContent(position: self::SUBJECT_POSITION, langTag: $langTag);

        if ($subject !== '') {
            $defaults[self::SUBJECT_POSITION] = $subject;
        }

        foreach (self::discoverSequence(layoutId: $layoutId) as $entry) {
            if (($entry['type'] ?? '') !== 'position') {
                continue;
            }

            $position = (string) ($entry['name'] ?? '');
            $value = self::defaultContent(position: $position, langTag: $langTag);

            if ($value !== '') {
                $defaults[$position] = $value;
            }
        }

        return $defaults;
    }

    /**
     * Language fallback chain for default resolution: requested → en-GB →
     * first installed content language. Deduped, empties dropped.
     *
     * @param string $langTag Requested language tag.
     *
     * @return string[]
     *
     * @since   1.0.4
     */
    private static function defaultLangChain(string $langTag): array
    {
        $chain = [$langTag, 'en-GB'];

        $installed = array_keys(LanguageHelper::getLanguages('lang_code') ?: []);

        if (!empty($installed)) {
            $chain[] = (string) $installed[0];
        }

        return array_values(array_unique(array_filter($chain)));
    }

    /**
     * Translate one key in a specific language, with the untranslated-key
     * guard: a Language that echoes the key back (no .ini entry) yields ''.
     *
     * The Language instance is loaded once per tag and cached for the
     * request — seeding touches the same few tags repeatedly.
     *
     * @param string $key Language constant.
     * @param string $langTag Language to resolve in.
     *
     * @return string Translated text, or '' when untranslated.
     *
     * @since   1.0.4
     */
    private static function translateInLanguage(string $key, string $langTag): string
    {
        static $cache = [];

        if (!isset($cache[$langTag])) {
            // LanguageFactory (not the deprecated Language::getInstance) —
            // a standalone instance for $langTag, independent of the admin's
            // current language, so seeding resolves each content language's
            // own .ini.
            $language = Factory::getContainer()
                ->get(LanguageFactoryInterface::class)
                ->createLanguage($langTag);
            $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            $cache[$langTag] = $language;
        }

        $value = $cache[$langTag]->_($key);

        return $value === $key ? '' : $value;
    }

    /**
     * Enumerate every published form field as a token-label pair,
     * keyed by token string.
     *
     * Token format: `{field_<machine_key>}`. Label resolution falls
     * back through field_label → name → machine key so the picker is
     * always readable even when admin hasn't filled translations.
     *
     * Memoised per request like loadFormFieldNames().
     *
     * @return array<string, string> Map of token → human label.
     *
     * @since   1.0.4
     */
    private static function loadFormFieldTokenCatalogue(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            $factory = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory();
            $model = $factory->createModel('Formfields', 'Administrator');

            if (!$model) {
                return $cache = [];
            }

            // Force populateState() first (see loadFormFieldRows notes).
            $model->getState('list.ordering');

            // Clear any inherited admin user-state filters so we get
            // the FULL set of published fields, not whatever subset the
            // admin last narrowed the Form Fields list to.
            $model->setState('filter.state', 1);
            $model->setState('filter.group_id', '');
            $model->setState('filter.search', '');
            $model->setState('filter.context', '');
            $model->setState('list.limit', 0);
            $model->setState('list.start', 0);

            $items = $model->getItems() ?: [];
        } catch (Throwable $e) {
            return $cache = [];
        }

        $catalogue = [];

        foreach ($items as $item) {
            $machineKey = (string) ($item->field_name ?? '');

            // Reject only what would corrupt {token} substitution —
            // empty, whitespace, or curly braces in the key. Hyphens,
            // dots, digits-at-start are all fine: strtr() matches the
            // literal string and PHP property access via {$var} handles
            // any non-empty key.
            if ($machineKey === '' || !preg_match('/^[^\s{}]+$/', $machineKey)) {
                continue;
            }

            $label = (string) ($item->field_label ?? '');

            if ($label === '') {
                $label = (string) ($item->name ?? '');
            }
            if ($label === '') {
                $label = $machineKey;
            }

            $catalogue['{field_' . $machineKey . '}'] = $label;
        }

        return $cache = $catalogue;
    }

    /**
     * Build the rendered email HTML for a single status + language +
     * recipient, used by the admin preview modal.
     *
     * Resolves a sample order to use for token substitution — the
     * most recent row in `#__alfa_orders` when one exists, falling
     * back to a synthetic stub so the preview is still meaningful on
     * a fresh install. Returns the same HTML payload the live
     * dispatch path would build.
     *
     * @param int $statusId Orderstatus PK.
     * @param string $langTag Language to render in (e.g. 'en-GB').
     * @param string $recipient 'customer' or 'admin'.
     *
     * @return string Final email HTML.
     *
     * @since   1.0.4
     */
    public static function previewForStatus(int $statusId, string $langTag, string $recipient): string
    {
        $recipient = $recipient === 'admin' ? 'admin' : 'customer';

        $status = self::loadStatusForLanguage(statusId: $statusId, langTag: $langTag);

        if ($status === null) {
            return '';
        }

        $positions = self::decodePositions(
            json: $recipient === 'customer'
                ? (string) ($status->email_positions_customer ?? '')
                : (string) ($status->email_positions_admin ?? ''),
        );

        $mostRecent = self::loadMostRecentOrder();
        $order = $mostRecent !== null
            ? self::loadOrder(orderId: (int) $mostRecent->id)
            : null;

        if ($order === null) {
            $order = self::buildSyntheticOrder();
        }

        $tokens = self::buildTokens(order: $order, status: $status, langTag: $langTag);

        $layoutId = $recipient === 'customer'
            ? (string) ($status->email_layout_customer ?? self::LAYOUT_ID)
            : (string) ($status->email_layout_admin ?? self::LAYOUT_ID);

        return self::renderEmailHtml(
            positions: $positions,
            tokens:    $tokens,
            layoutId:  $layoutId,
            context:   self::buildLayoutContext(order: $order, status: $status, recipient: $recipient, langTag: $langTag),
        );
    }

    /**
     * Send a real test email for the picked combination.
     *
     * Used by the admin "Send test" button on the orderstatus edit
     * screen. Unlike sendForStatusChange() this:
     *   • Takes the destination address directly (not derived from
     *     `notify_customer` / `admin_recipient_ids`).
     *   • Builds tokens against the specific order admin picked (not
     *     the most-recent fallback).
     *   • Ignores the notify gates — admin asked to send, we send.
     *
     * The position content (subject + body) still comes from whichever
     * recipient variant admin chose; the body is rendered through the
     * same layout pipeline the live dispatch uses, so what arrives in
     * the inbox matches what the live email would.
     *
     * @param int $orderId Order to resolve tokens against.
     * @param int $statusId Status whose positions to use.
     * @param string $langTag Language to render in (e.g. 'en-GB').
     * @param string $recipient 'customer' or 'admin' — picks which
     *                          positions JSON to read.
     * @param string $destinationEmail Single recipient address.
     *
     *
     * @throws RuntimeException When the order/status can't be loaded
     *                          or the destination is missing — caller
     *                          surfaces the message to the admin.
     *
     * @since   1.0.4
     */
    public static function sendTestForStatus(
        int $orderId,
        int $statusId,
        string $langTag,
        string $recipient,
        string $destinationEmail,
    ): void {
        $recipient = $recipient === 'admin' ? 'admin' : 'customer';

        if ($destinationEmail === '' || !filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                Text::_('COM_ALFA_ORDEREMAIL_TEST_INVALID_EMAIL'),
            );
        }

        $order = self::loadOrder(orderId: $orderId);

        if ($order === null) {
            throw new RuntimeException(
                Text::sprintf('COM_ALFA_ORDEREMAIL_TEST_ORDER_NOT_FOUND', $orderId),
            );
        }

        $status = self::loadStatusForLanguage(statusId: $statusId, langTag: $langTag);

        if ($status === null) {
            throw new RuntimeException(
                Text::sprintf('COM_ALFA_ORDEREMAIL_TEST_STATUS_NOT_FOUND', $statusId),
            );
        }

        $positions = self::decodePositions(
            json: $recipient === 'customer'
                ? (string) ($status->email_positions_customer ?? '')
                : (string) ($status->email_positions_admin ?? ''),
        );

        if (empty($positions)) {
            throw new RuntimeException(
                Text::_('COM_ALFA_ORDEREMAIL_TEST_NO_CONTENT'),
            );
        }

        $tokens = self::buildTokens(order: $order, status: $status, langTag: $langTag);

        $subjectTpl = (string) ($positions[self::SUBJECT_POSITION] ?? '');
        $subject = self::applyTokens(template: $subjectTpl, tokens: $tokens);

        if ($subject === '') {
            $subject = Text::sprintf(
                'COM_ALFA_ORDEREMAIL_SUBJECT_FALLBACK',
                (int) ($order->id ?? 0),
                (string) ($tokens['{status_customer}'] ?? ''),
            );
        }

        // Append a [TEST] tag so admins recognise it in their inbox.
        $subject = '[TEST] ' . $subject;

        $layoutId = $recipient === 'customer'
            ? (string) ($status->email_layout_customer ?? self::LAYOUT_ID)
            : (string) ($status->email_layout_admin ?? self::LAYOUT_ID);

        $html = self::renderEmailHtml(
            positions: $positions,
            tokens:    $tokens,
            layoutId:  $layoutId,
            context:   self::buildLayoutContext(order: $order, status: $status, recipient: $recipient, langTag: $langTag),
        );

        $mailer = Factory::getContainer()
            ->get(MailerFactoryInterface::class)
            ->createMailer();

        $mailer->isHtml(true);
        $mailer->addRecipient($destinationEmail);
        $mailer->setSubject($subject);
        $mailer->setBody($html);
        $mailer->Send();
    }

    /**
     * Load the most recently created order from `#__alfa_orders`.
     *
     * Used by the preview path so tokens resolve against real data
     * whenever possible. Returns null when the table is empty.
     *
     *
     * @since   1.0.4
     */
    private static function loadMostRecentOrder(): ?object
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // First try: most recent order that actually has line items.
            // The preview is meaningless against a draft / empty order, so
            // skip those when richer data exists.
            $query = $db->getQuery(true)
                ->select('o.*')
                ->from($db->quoteName('#__alfa_orders', 'o'))
                ->join(
                    'INNER',
                    $db->quoteName('#__alfa_order_items', 'oi')
                        . ' ON ' . $db->quoteName('oi.id_order') . ' = ' . $db->quoteName('o.id'),
                )
                ->group($db->quoteName('o.id'))
                ->order($db->quoteName('o.created') . ' DESC')
                ->setLimit(1);

            $db->setQuery(query: $query);
            $row = $db->loadObject();

            if ($row) {
                return $row;
            }

            // Fallback: any most-recent row (lets the partials show the
            // "no items" placeholder instead of the synthetic stub).
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders'))
                ->order($db->quoteName('created') . ' DESC')
                ->setLimit(1);

            $db->setQuery(query: $query);

            return $db->loadObject() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Build a synthetic order object for preview when no real orders
     * exist yet.
     *
     * Matches the column shape OrderEmailHelper reads — minimum
     * viable: id, invoice_number, created, id_user, id_address_delivery,
     * id_language. Token values resolve to placeholder strings.
     *
     *
     * @since   1.0.4
     */
    private static function buildSyntheticOrder(): object
    {
        return (object) [
            'id' => 999,
            'invoice_number' => 0,
            'created' => Factory::getDate()->toSql(),
            'id_user' => 0,
            'id_address_delivery' => 0,
            'id_language' => 0,
            'payment_method_name' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_PAYMENT_METHOD'),
            'shipment_method_name' => Text::_('COM_ALFA_ORDEREMAIL_TOKEN_SHIPMENT_METHOD'),
        ];
    }

    /**
     * Reset the position discovery cache.
     *
     * Useful when a template override is dropped in mid-request (rare;
     * mainly for tests) or after a layout file is rewritten in the
     * same PHP process.
     *
     *
     * @since   1.0.4
     */
    public static function clearPositionsCache(): void
    {
        self::$positionsCache = [];
    }

    /**
     * Send customer + admin status-change notifications for an order
     * transitioning INTO $newStatusId.
     *
     * Customer email: gated by `notify_customer` on the status row,
     * sent to the order's customer (#__users.email via id_user).
     *
     * Admin emails: one mail per user listed in `admin_recipient_ids`
     * (JSON array of #__users.id values). Each recipient gets its own
     * Mailer instance so a failure on one doesn't poison the rest;
     * blocked / missing users are filtered out by resolveAdminRecipientEmails().
     *
     * @param int $orderId Order whose status just changed.
     * @param int $newStatusId Status the order is transitioning into.
     *
     *
     * @since   1.0.5
     */
    public static function sendForStatusChange(int $orderId, int $newStatusId): void
    {
        if ($orderId <= 0 || $newStatusId <= 0) {
            return;
        }

        try {
            $order = self::loadOrder(orderId: $orderId);

            if ($order === null) {
                return;
            }

            $langTag = self::resolveLangTag(idLanguage: (int) ($order->id_language ?? 0));
            $status = self::loadStatusForLanguage(statusId: $newStatusId, langTag: $langTag);

            if ($status === null) {
                return;
            }

            $notifyCustomer = (int) ($status->notify_customer ?? 0) === 1;
            $adminRecipients = self::resolveAdminRecipientEmails(statusId: $newStatusId);

            if (!$notifyCustomer && empty($adminRecipients)) {
                return;
            }

            $tokens = self::buildTokens(order: $order, status: $status, langTag: $langTag);

            if ($notifyCustomer) {
                self::dispatch(
                    recipient: self::resolveCustomerEmail(order: $order),
                    positions: self::decodePositions(json: $status->email_positions_customer ?? ''),
                    tokens:    $tokens,
                    orderId:   $orderId,
                    statusId:  $newStatusId,
                    layoutId:  (string) ($status->email_layout_customer ?? self::LAYOUT_ID),
                    context:   self::buildLayoutContext(order: $order, status: $status, recipient: 'customer', langTag: $langTag),
                );
            }

            if (!empty($adminRecipients)) {
                $adminPositions = self::decodePositions(json: $status->email_positions_admin ?? '');

                $adminLayout = (string) ($status->email_layout_admin ?? self::LAYOUT_ID);
                $adminContext = self::buildLayoutContext(order: $order, status: $status, recipient: 'admin', langTag: $langTag);

                foreach ($adminRecipients as $adminEmail) {
                    self::dispatch(
                        recipient: $adminEmail,
                        positions: $adminPositions,
                        tokens:    $tokens,
                        orderId:   $orderId,
                        statusId:  $newStatusId,
                        layoutId:  $adminLayout,
                        context:   $adminContext,
                    );
                }
            }
        } catch (Throwable $e) {
            // Mail dispatch must never block the order save — log and move on.
            Log::add(
                entry:    Text::sprintf(
                    'COM_ALFA_ORDEREMAIL_LOG_SEND_FAILED',
                    $orderId,
                    $newStatusId,
                    $e->getMessage(),
                ),
                priority: Log::ERROR,
                category: 'com_alfa.orderemail',
            );
        }
    }

    /**
     * Build a single email and send it via Joomla's MailerFactory.
     *
     * `$positions` is the decoded per-language JSON for one recipient
     * (customer or admin), keyed by position name. self::SUBJECT_POSITION
     * provides the mail subject (with token substitution); every other
     * position's value is emitted by the layout via `$position($name)`
     * and resolved in renderEmailHtml's central variable pass.
     *
     * Empty recipient OR an empty positions array short-circuits so
     * admins who configured only one channel don't get noise about the
     * other.
     *
     * @param string $recipient Destination email address.
     * @param array<string, string> $positions Decoded positions for this
     *                                         recipient (subject + body).
     * @param array<string, string> $tokens Map of {token} → value.
     * @param int $orderId Used for fallback subject.
     * @param int $statusId Used for log context.
     * @param string $layoutId Wrapper layout id for this
     *                         recipient's email.
     * @param array<string, mixed> $context Display data forwarded to
     *                                      the layout (order, items,
     *                                      status, recipient, langTag).
     *
     *
     * @since   1.0.4
     */
    private static function dispatch(
        string $recipient,
        array $positions,
        array $tokens,
        int $orderId,
        int $statusId,
        string $layoutId = self::LAYOUT_ID,
        array $context = [],
    ): void {
        if ($recipient === '' || empty($positions)) {
            return;
        }

        $subjectTpl = (string) ($positions[self::SUBJECT_POSITION] ?? '');
        $subject = self::applyTokens(template: $subjectTpl, tokens: $tokens);

        if ($subject === '') {
            // Headerless emails are a deliverability red flag — fall back
            // to a generic subject when admin filled in body positions
            // but left subject blank.
            $subject = Text::sprintf(
                'COM_ALFA_ORDEREMAIL_SUBJECT_FALLBACK',
                $orderId,
                $tokens['{status_customer}'] ?? '',
            );
        }

        $html = self::renderEmailHtml(positions: $positions, tokens: $tokens, layoutId: $layoutId, context: $context);

        try {
            $mailer = Factory::getContainer()
                ->get(MailerFactoryInterface::class)
                ->createMailer();

            $mailer->isHtml(true);
            $mailer->addRecipient($recipient);
            $mailer->setSubject($subject);
            $mailer->setBody($html);
            $mailer->Send();
        } catch (Throwable $e) {
            Log::add(
                entry:    Text::sprintf(
                    'COM_ALFA_ORDEREMAIL_LOG_SEND_FAILED',
                    $orderId,
                    $statusId,
                    $e->getMessage(),
                ),
                priority: Log::ERROR,
                category: 'com_alfa.orderemail',
            );
        }
    }

    /**
     * Empty-but-well-formed $displayData skeleton.
     *
     * Guarantees every key a layout might read/call exists (null / empty /
     * no-op closure), so a layout never warns when rendered with partial
     * context. renderEmailHtml + discoverPositions override the closures
     * with their real / collector implementations.
     *
     * @return array<string, mixed>
     *
     * @since   1.0.4
     */
    private static function displayDataSkeleton(): array
    {
        return [
            'order' => null,
            // One stub row each so a layout's `if(!empty($payments|$shipments))`
            // branch is walked during discovery. Never inspected (struct render
            // is a no-op collector during discoverSequence).
            'items' => [(object) []],
            'totals' => [],
            'payments' => [(object) []],
            'shipments' => [(object) []],
            'status' => null,
            'tokens' => [],
            'positions' => [],
            'recipient' => '',
            'langTag' => '',
            'position' => static fn (string $name = ''): string => '',
            'hasPosition' => static fn (string $name = ''): bool => false,
            'render' => static fn (string $layoutId = '', ?array $data = null): string => '',
        ];
    }

    /**
     * Build the per-recipient display-data context handed to the wrapper
     * layout (merged with tokens + positions inside renderEmailHtml).
     *
     * Items come straight off the hydrated order (OrderModel::getItem
     * attaches ->items on every render path; the synthetic preview stub
     * simply has none).
     *
     * @param object $order Resolved order row.
     * @param object $status Status row (+ per-language fields).
     * @param string $recipient 'customer' or 'admin'.
     * @param string $langTag Active language tag.
     *
     * @return array<string, mixed>
     *
     * @since   1.0.4
     */
    private static function buildLayoutContext(object $order, object $status, string $recipient, string $langTag): array
    {
        $items = is_array($order->items ?? null) ? $order->items : [];
        $orderId = (int) ($order->id ?? 0);
        $currency = self::resolveCurrency(items: $items);

        // Payments/shipments are LISTS (their own tables) — prefer the rows
        // already hydrated on the order, else a defensive minimal DB load.
        $payments = is_array($order->payments ?? null) && !empty($order->payments)
            ? $order->payments
            : self::safeLoadPayments(orderId: $orderId, currency: $currency);
        $shipments = is_array($order->shipments ?? null) && !empty($order->shipments)
            ? $order->shipments
            : self::safeLoadShipments(orderId: $orderId);

        return [
            'order' => $order,
            'items' => $items,
            'totals' => self::buildTotals(orderId: $orderId, items: $items),
            'payments' => $payments,
            'shipments' => $shipments,
            'status' => $status,
            'recipient' => $recipient,
            'langTag' => $langTag,
        ];
    }

    /**
     * Render the complete email HTML for a recipient.
     *
     * The wrapper layout is rendered as a plain Joomla layout (template
     * overrides picked up automatically) with a helper API extracted at
     * the top — the layout author composes the email in PHP:
     *
     *   $position($name)            — the admin's content for a slot (raw)
     *   $hasPosition($name)         — does that slot have content (for
     *                                 conditional wrappers, like countModules)
     *   $render($layoutId, $data?)  — render any sub-layout (e.g.
     *                                 emails.partials.order_items) with the
     *                                 com_alfa/client=1 context pre-wired;
     *                                 pass $data to override what it receives
     *
     * Plus the raw data: $order, $items, $totals, $status, $tokens,
     * $positions, $recipient, $langTag.
     *
     * Variable replacement happens ONCE, centrally: after the layout
     * composes the email, this method runs a single pass over the whole
     * output replacing every scalar `{token}` (incl. {order_total},
     * {field_*}) and the site-level `{{SITE_NAME}}` / `{{SITE_URL}}`. So
     * the layout/positions emit raw text with variables in it, and the
     * helper resolves them everywhere in one place.
     *
     * @param array<string, string> $positions Decoded positions.
     * @param array<string, string> $tokens Map of {token} → value.
     * @param string $layoutId Wrapper layout id to render
     *                         (e.g. 'emails.order.default').
     *                         Empty falls back to LAYOUT_ID.
     * @param array<string, mixed> $context Extra display data (order,
     *                                      items, status, recipient,
     *                                      langTag) for the layout.
     *
     * @return string Final HTML payload for Mail::setBody().
     *
     * @since   1.0.4
     */
    private static function renderEmailHtml(
        array $positions,
        array $tokens,
        string $layoutId = self::LAYOUT_ID,
        array $context = [],
    ): string {
        $dataContext = array_merge(self::displayDataSkeleton(), $context, [
            'tokens' => $tokens,
            'positions' => $positions,
        ]);

        // Real helper API overrides the skeleton's no-op closures.
        $displayData = array_merge(
            $dataContext,
            self::layoutHelperApi(positions: $positions, dataContext: $dataContext),
        );

        $rendered = LayoutHelper::render(
            $layoutId !== '' ? $layoutId : self::LAYOUT_ID,
            $displayData,
            null,
            ['component' => 'com_alfa', 'client' => 1],
        );

        // Single, central variable pass over the WHOLE composed email —
        // covers admin content, rendered partials, and the layout's own
        // text alike. Site markers first, then every {token}.
        $rendered = str_replace(
            ['{{SITE_NAME}}', '{{SITE_URL}}'],
            [(string) ($tokens['{site_name}'] ?? ''), (string) ($tokens['{site_url}'] ?? '')],
            $rendered,
        );

        return self::applyTokens(template: $rendered, tokens: $tokens);
    }

    /**
     * Build the helper API (closures) exposed to an email layout.
     *
     * Returned under the keys `position`, `hasPosition`, `render` — the
     * layout extracts $displayData and calls them directly. They return
     * RAW output (no token substitution): renderEmailHtml does the single
     * variable pass afterward, so a partial that emits `{order_total}`
     * resolves just like admin content does.
     *
     * @param array<string, string> $positions Decoded position content.
     * @param array<string, mixed> $dataContext Default data handed to
     *                                          sub-layouts via $render.
     *
     * @return array<string, callable>
     *
     * @since   1.0.4
     */
    private static function layoutHelperApi(array $positions, array $dataContext): array
    {
        return [
            'position' => static fn (string $name): string => (string) ($positions[$name] ?? ''),

            'hasPosition' => static fn (string $name): bool
                => trim(strip_tags((string) ($positions[$name] ?? ''), '<img><a>')) !== ''
                    || (bool) preg_match('/<(img|iframe|video|source|audio|embed)\b/i', (string) ($positions[$name] ?? '')),

            // Honours the per-recipient "Sections" toggle: a struct block
            // whose flag is '_show_<slug>' = '0' renders nothing, no matter
            // the layout. Default ON (absent flag → shown). Slug transform
            // mirrors EmailPositionsField::structSlug so flags round-trip.
            'render' => static function (string $layoutId, ?array $data = null) use ($positions, $dataContext): string {
                $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $layoutId), '_'));

                if (($positions['_show_' . $slug] ?? '1') === '0') {
                    return '';
                }

                return (string) LayoutHelper::render(
                    $layoutId,
                    $data ?? $dataContext,
                    null,
                    ['component' => 'com_alfa', 'client' => 1],
                );
            },
        ];
    }

    /**
     * Decode a per-language positions JSON string into an associative
     * array of position → content.
     *
     * Returns `[]` for empty input / invalid JSON so callers can use
     * `empty()` as the "no content for this language" check.
     *
     * @param string $json Raw JSON read from the per-language column.
     *
     * @return array<string, string>
     *
     * @since   1.0.4
     */
    private static function decodePositions(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Replace every `{token}` key in $template with its value.
     *
     * Plain str_replace over the map — no recursion, no escaping (admin
     * is authoring the body in a WYSIWYG so HTML is already trusted).
     *
     * @param string $template Subject or body template.
     * @param array<string, string> $tokens Map of token → replacement.
     *
     * @return string Token-substituted output.
     *
     * @since   1.0.5
     */
    private static function applyTokens(string $template, array $tokens): string
    {
        if ($template === '' || empty($tokens)) {
            return $template;
        }

        return str_replace(array_keys($tokens), array_values($tokens), $template);
    }

    /**
     * Build the token replacement map for an order + status pair.
     *
     * Tokens are keyed with curly braces so substitution runs in a
     * single str_replace pass. Three categories live in the map:
     *
     *   • Order — {order_id}, {order_number}, {order_date},
     *     {status}, {status_customer}, {order_total} (scalar formatted
     *     total), {payment_method}, {shipment_method}. {status} is the
     *     internal name, {status_customer} the required customer-facing
     *     one. The
     *     items table + totals breakdown are NOT tokens — they're
     *     structure rendered by the layout (see buildLayoutContext).
     *
     *   • Customer (Joomla user) — {user_id}, {user_name},
     *     {user_username}, {user_email}, {user_registered}. Resolve
     *     to '' for guest orders so admin templates can include them
     *     unconditionally without producing literal placeholder noise.
     *
     *   • Customer details (FieldsHelper) — one token per active
     *     `#__alfa_form_fields` row: {field_<field_name>}. Values pulled
     *     from `#__alfa_user_info` via the order's id_address_delivery.
     *
     *   • Site — {site_name}, {site_url}.
     *
     * @param object $order `#__alfa_orders` row.
     * @param object $status Status row joined to its per-language fields.
     * @param string $langTag Resolved language tag (e.g. 'en-GB').
     *
     * @return array<string, string>
     *
     * @since   1.0.4
     */
    private static function buildTokens(object $order, object $status, string $langTag): array
    {
        $orderId = (int) ($order->id ?? 0);
        $invoiceNo = (int) ($order->invoice_number ?? 0);
        $orderNumber = $invoiceNo > 0 ? (string) $invoiceNo : (string) $orderId;
        $config = Factory::getApplication()->getConfig();

        // Prefer the items already attached by OrderModel::getItem (the
        // canonical loader the admin view uses). Falls back to a fresh
        // OrderHelper::getOrderItems() call when $order came from a
        // raw query that didn't hydrate the relation.
        $items = is_array($order->items ?? null) && !empty($order->items)
            ? $order->items
            : self::safeLoadItems(orderId: $orderId);

        // Scalar tokens only. The items table + totals breakdown are
        // STRUCTURE, not tokens — the layout renders them via
        // $render('emails.partials.order_items'|'order_totals', …), so no
        // presentation layout id is named here. See buildLayoutContext().
        // Status names — internal (admin) vs customer-facing. name_customer is
        // a required field, so {status_customer} renders it directly with no
        // fallback.
        $tokens = [
            // Order
            '{order_id}' => (string) $orderId,
            '{order_number}' => $orderNumber,
            '{order_date}' => self::formatDate(sqlDate: $order->created ?? null),
            '{status}' => (string) ($status->name ?? ''),
            '{status_customer}' => (string) ($status->name_customer ?? ''),
            '{order_total}' => self::formatOrderTotalFromItems(items: $items),

            // Site
            '{site_name}' => (string) $config->get('sitename', ''),
            '{site_url}' => Uri::root(),
        ];

        // Customer (Joomla user) — always-present empty-string fallbacks
        // for guest orders, so admin templates can reference these
        // tokens unconditionally without leaking literal placeholders.
        $tokens += self::resolveJoomlaUserTokens(idUser: (int) ($order->id_user ?? 0));

        // Customer details (FieldsHelper). One {field_<name>} token per
        // active row in `#__alfa_form_fields`. Values keyed by
        // id_address_delivery (the address the customer filled out for
        // this order).
        $tokens += self::resolveFormFieldTokens(
            addressId: (int) ($order->id_address_delivery ?? 0),
            langTag:   $langTag,
        );

        return $tokens;
    }

    /**
     * Build the five-token map for the Joomla user row backing an order.
     *
     * Always returns the same five keys so admin templates can use them
     * unconditionally; guest orders (id_user = 0) get empty strings.
     *
     * @param int $idUser Joomla user id from the order.
     *
     * @return array<string, string>
     *
     * @since   1.0.4
     */
    private static function resolveJoomlaUserTokens(int $idUser): array
    {
        $empty = [
            '{user_id}' => '',
            '{user_name}' => '',
            '{user_username}' => '',
            '{user_email}' => '',
            '{user_registered}' => '',
        ];

        if ($idUser <= 0) {
            return $empty;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('name'),
                    $db->quoteName('username'),
                    $db->quoteName('email'),
                    $db->quoteName('registerDate'),
                ])
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = ' . (int) $idUser);

            $db->setQuery(query: $query);
            $row = $db->loadObject();
        } catch (Exception $e) {
            return $empty;
        }

        if ($row === null) {
            return $empty;
        }

        return [
            '{user_id}' => (string) ($row->id ?? ''),
            '{user_name}' => (string) ($row->name ?? ''),
            '{user_username}' => (string) ($row->username ?? ''),
            '{user_email}' => (string) ($row->email ?? ''),
            '{user_registered}' => self::formatDate(sqlDate: $row->registerDate ?? null),
        ];
    }

    /**
     * Build the dynamic `{field_<name>}` token map for the order's
     * delivery-address row.
     *
     * Enumerates every published `#__alfa_form_fields` row via the
     * admin Formfields ListModel — the canonical source of "what
     * fields does this shop have?". For each field, looks up the value
     * on the order's `#__alfa_user_info` row (delivery address) and
     * exposes it as `{field_<field_name>}`.
     *
     * Returned tokens are ALL declared even when the address row
     * doesn't have a column for them (legacy fields, sparse data) —
     * value is '' in that case. Keeps admin templates safe against
     * "did this field exist when I wrote my body?" branching.
     *
     * @param int $addressId The order's `id_address_delivery`.
     * @param string $langTag Active language tag (reserved for
     *                        future per-language enum value
     *                        resolution; unused right now).
     *
     * @return array<string, string>
     *
     * @since   1.0.4
     */
    private static function resolveFormFieldTokens(int $addressId, string $langTag): array
    {
        $fields = self::loadFormFieldRows();

        if (empty($fields)) {
            return [];
        }

        $userInfo = $addressId > 0 ? self::loadUserInfoRow(addressId: $addressId) : null;
        $tokens = [];

        foreach ($fields as $machineKey => $field) {
            $raw = $userInfo !== null && isset($userInfo->{$machineKey})
                ? $userInfo->{$machineKey}
                : '';

            // Plugin tmpls (plg_alfa-fields/<type>/tmpl/default.php) read
            // the value off `$field->value` — that's the cart/JForm
            // contract. We hold a cached row from FormfieldsModel that
            // has no `value` populated, so clone it and set the value
            // on the clone before handing it to render. Mutating the
            // cached object would poison the next iteration.
            $fieldForRender = clone $field;
            $fieldForRender->value = $raw;

            $rendered = FieldsHelper::render(
                'com_alfa.orderemail',
                $fieldForRender,
                [
                    'value' => $raw,
                    'item' => $userInfo,
                    'raw' => $raw,
                ],
            );

            $tokens['{field_' . $machineKey . '}'] = trim((string) $rendered);
        }

        return $tokens;
    }

    /**
     * Enumerate every published form field as full row objects, keyed
     * by machine_key.
     *
     * Returns the COMPLETE row (id, type, layout, params, field_name,
     * etc.) — needed by FieldsHelper::render to dispatch the field to
     * its plugin tmpl. Bootstraps the admin Formfields ListModel via
     * the MVC factory, same path com_alfa uses elsewhere.
     *
     * Memoised per request.
     *
     * @return array<string, object> Map of machine_key → field row.
     *
     * @since   1.0.4
     */
    private static function loadFormFieldRows(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            $factory = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory();
            $model = $factory->createModel('Formfields', 'Administrator');

            if (!$model) {
                return $cache = [];
            }

            // Force populateState() to run NOW so our subsequent
            // setState() calls aren't trampled when getItems() lazily
            // invokes populateState later. Same pattern as
            // OrderHelper::bootItemsModel().
            $model->getState('list.ordering');

            // populateState pulls filter.group_id / filter.search /
            // filter.context from user-state. If the admin previously
            // filtered the Form Fields list (e.g. Group = Billing) that
            // value would silently scope our token catalogue. Clear
            // everything but the published filter.
            $model->setState('filter.state', 1);
            $model->setState('filter.group_id', '');
            $model->setState('filter.search', '');
            $model->setState('filter.context', '');
            $model->setState('list.limit', 0);
            $model->setState('list.start', 0);

            $items = $model->getItems() ?: [];
        } catch (Throwable $e) {
            Log::add(
                entry:    'OrderEmailHelper::loadFormFieldRows failed: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.orderemail',
            );

            return $cache = [];
        }

        $rows = [];

        foreach ($items as $item) {
            $name = (string) ($item->field_name ?? '');

            // Match loadFormFieldTokenCatalogue — accept any non-empty
            // key that has no whitespace or curly braces (which would
            // corrupt {token} substitution).
            if ($name !== '' && preg_match('/^[^\s{}]+$/', $name)) {
                $rows[$name] = $item;
            }
        }

        return $cache = $rows;
    }

    /**
     * Load a single `#__alfa_user_info` row by id.
     *
     * Returns the row as a generic object so dynamic column access
     * (`$row->{$fieldName}`) works for any FieldsHelper-managed column,
     * known or unknown at compile time.
     *
     * @param int $addressId Row PK.
     *
     *
     * @since   1.0.4
     */
    private static function loadUserInfoRow(int $addressId): ?object
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = ' . (int) $addressId);

            $db->setQuery(query: $query);

            return $db->loadObject() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Load an order through OrderModel::getItem — the canonical admin
     * loading path. Returns the full hydrated object with
     * `$order->items` (Money objects), `$order->payments`,
     * `$order->shipments`, `$order->user_info` and `$order->currency`
     * already attached. Same data the admin order view sees.
     *
     * Returns null when the model can't load the id; callers treat
     * that as "order doesn't exist" and fall back to whatever stub is
     * appropriate (preview synthetic, throw, etc.). No raw-SQL backup
     * path — if OrderModel can't surface it, we don't surface it.
     *
     * @param int $orderId Order PK.
     *
     *
     * @since   1.0.4
     */
    private static function loadOrder(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        try {
            $factory = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory();
            $model = $factory->createModel('Order', 'Administrator');

            if (!$model) {
                return null;
            }

            $item = $model->getItem($orderId);

            return $item ?: null;
        } catch (Throwable $e) {
            Log::add(
                entry:    'OrderEmailHelper::loadOrder failed for #' . $orderId . ': ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.orderemail',
            );

            return null;
        }
    }

    /**
     * Load the status row for $statusId joined to its per-language
     * subject / body / name in $langTag.
     *
     * Falls back to Joomla's default language if no row exists in
     * $langTag — keeps the email from going out with blank subject
     * and body when admin only filled in one language.
     *
     * @param int $statusId Status PK.
     * @param string $langTag Language code (e.g. 'en-GB').
     *
     * @return object|null Combined row or null when status doesn't exist.
     *
     * @since   1.0.5
     */
    private static function loadStatusForLanguage(int $statusId, string $langTag): ?object
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    's.id',
                    's.notify_customer',
                    's.email_layout_customer',
                    's.email_layout_admin',
                ])
                ->from($db->quoteName('#__alfa_orders_statuses', 's'))
                ->where($db->quoteName('s.id') . ' = ' . (int) $statusId);

            $db->setQuery(query: $query);
            $status = $db->loadObject();

            if ($status === null) {
                return null;
            }

            // Default empty/missing layout selections to the canonical layout
            // so callers never have to branch on a blank id.
            $status->email_layout_customer = ((string) ($status->email_layout_customer ?? '')) ?: self::LAYOUT_ID;
            $status->email_layout_admin = ((string) ($status->email_layout_admin ?? '')) ?: self::LAYOUT_ID;

            $langRow = self::loadLangRow(statusId: $statusId, langTag: $langTag);

            if ($langRow === null) {
                // Fall back to site default language.
                $defaultTag = Factory::getApplication()->get('language', 'en-GB');

                if ($defaultTag !== $langTag) {
                    $langRow = self::loadLangRow(statusId: $statusId, langTag: $defaultTag);
                }
            }

            $status->name = (string) ($langRow->name ?? '');
            $status->name_customer = (string) ($langRow->name_customer ?? '');
            $status->email_positions_customer = (string) ($langRow->email_positions_customer ?? '');
            $status->email_positions_admin = (string) ($langRow->email_positions_admin ?? '');

            return $status;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Load the per-language status row from `#__alfa_orders_statuses_<tag>`.
     *
     * The table name is built by snake-casing the language tag
     * ('en-GB' → 'en_gb'), matching MultilingualHelper::normaliseTag()
     * and SyncHelper's table-creation convention.
     *
     * @param int $statusId Status PK.
     * @param string $langTag Language code (e.g. 'en-GB').
     *
     * @return object|null Per-language row or null when missing.
     *
     * @since   1.0.5
     */
    private static function loadLangRow(int $statusId, string $langTag): ?object
    {
        $suffix = strtolower(str_replace('-', '_', $langTag));

        if ($suffix === '') {
            return null;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $table = '#__alfa_orders_statuses_' . $suffix;
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName($table))
                ->where($db->quoteName('id_orderstatus') . ' = ' . (int) $statusId);

            $db->setQuery(query: $query);

            return $db->loadObject() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Resolve the language tag for an order.
     *
     * `#__alfa_orders.id_language` is the PK from `#__languages`. When
     * unset (legacy / guest orders) or unresolvable, falls back to the
     * application's current language.
     *
     * @param int $idLanguage Joomla language id stored on the order.
     *
     * @return string Language tag like 'en-GB'.
     *
     * @since   1.0.5
     */
    private static function resolveLangTag(int $idLanguage): string
    {
        if ($idLanguage > 0) {
            try {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $query = $db->getQuery(true)
                    ->select($db->quoteName('lang_code'))
                    ->from($db->quoteName('#__languages'))
                    ->where($db->quoteName('lang_id') . ' = ' . (int) $idLanguage);

                $db->setQuery(query: $query);
                $tag = (string) ($db->loadResult() ?? '');

                if ($tag !== '') {
                    return $tag;
                }
            } catch (Exception $e) {
                // Fall through to the application default.
            }
        }

        return (string) Factory::getApplication()->getLanguage()->getTag();
    }

    /**
     * Resolve a customer's name + email for an order.
     *
     * Only handles registered users for now — pulls from `#__users` via
     * `id_user`. Guest orders (id_user = 0/NULL) get empty strings;
     * follow-up: integrate FieldsHelper to pull dynamic name/email
     * fields from `#__alfa_user_info`.
     *
     * @param object $order `#__alfa_orders` row.
     *
     * @return array{0:string,1:string} [name, email]
     *
     * @since   1.0.5
     */
    private static function resolveCustomer(object $order): array
    {
        $idUser = (int) ($order->id_user ?? 0);

        if ($idUser <= 0) {
            return ['', ''];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('name'),
                    $db->quoteName('email'),
                ])
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = ' . (int) $idUser);

            $db->setQuery(query: $query);
            $row = $db->loadObject();

            return [
                (string) ($row->name ?? ''),
                (string) ($row->email ?? ''),
            ];
        } catch (Exception $e) {
            return ['', ''];
        }
    }

    /**
     * Resolve the customer's email — wrapper around resolveCustomer().
     *
     * Kept separate so dispatch() reads cleanly. Returns '' for guest
     * orders, which short-circuits the dispatch.
     *
     * @param object $order `#__alfa_orders` row.
     *
     * @return string Customer email or '' when unknown.
     *
     * @since   1.0.5
     */
    private static function resolveCustomerEmail(object $order): string
    {
        [, $email] = self::resolveCustomer(order: $order);

        return $email;
    }

    /**
     * Pull every admin recipient email address registered for the status.
     *
     * Joins `#__alfa_orderstatus_recipients` to `#__users` so blocked /
     * deleted users are filtered out automatically (the JOIN drops
     * orphan rows; blocked users excluded by the WHERE). Results are
     * de-duplicated as belt-and-suspenders against the same address
     * being reachable through two user accounts.
     *
     * @param int $statusId Status PK whose admin recipients to load.
     *
     * @return string[] Distinct, non-empty admin email addresses.
     *
     * @since   1.0.5
     */
    private static function resolveAdminRecipientEmails(int $statusId): array
    {
        if ($statusId <= 0) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('u.email'))
                ->from($db->quoteName('#__alfa_orderstatus_recipients', 'r'))
                ->join(
                    'INNER',
                    $db->quoteName('#__users', 'u')
                        . ' ON ' . $db->quoteName('u.id')
                        . ' = ' . $db->quoteName('r.id_user'),
                )
                ->where($db->quoteName('r.id_orderstatus') . ' = ' . (int) $statusId)
                ->where($db->quoteName('u.block') . ' = 0');

            $db->setQuery(query: $query);
            $emails = $db->loadColumn() ?: [];
        } catch (Exception $e) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $emails),
            static fn (string $email): bool => $email !== '',
        )));
    }

    /**
     * Format a SQL datetime for inclusion in an email.
     *
     * Uses the application's current locale formatting via Factory::getDate.
     *
     * @param string|null $sqlDate Datetime from the orders row.
     *
     * @return string Formatted date, or '' when input was null/empty.
     *
     * @since   1.0.5
     */
    private static function formatDate(?string $sqlDate): string
    {
        if ($sqlDate === null || $sqlDate === '' || $sqlDate === '0000-00-00 00:00:00') {
            return '';
        }

        try {
            return Factory::getDate($sqlDate)->format(
                Text::_('DATE_FORMAT_LC2'),
                true,
            );
        } catch (Exception $e) {
            return $sqlDate;
        }
    }

    /**
     * Format the order total in the order's currency.
     *
     * Sums `total_price_tax_incl` from `#__alfa_order_items` for the
     * order. Currency formatting reuses OrderHelper's Money objects so
     * the displayed currency code matches what admin sees in the order
     * detail view.
     *
     *
     * @return string Formatted total (e.g. '€42.50') or ''.
     *
     * @since   1.0.5
     */
    private static function formatOrderTotalFromItems(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $total = null;

        foreach ($items as $item) {
            if (!isset($item->total_price_tax_incl) || !is_object($item->total_price_tax_incl)) {
                continue;
            }

            $total = $total === null
                ? $item->total_price_tax_incl
                : $total->add($item->total_price_tax_incl);
        }

        return $total === null ? '' : (string) $total->format();
    }

    /**
     * Defensive wrapper around OrderHelper::getOrderItems — returns []
     * on any error so the layouts always get an array even when the
     * loader throws on partially-saved test orders.
     *
     * @param int $orderId Order PK.
     *
     * @return object[]
     *
     * @since   1.0.4
     */
    private static function safeLoadItems(int $orderId): array
    {
        try {
            return OrderHelper::getOrderItems($orderId);
        } catch (Throwable $e) {
            Log::add(
                entry:    'OrderEmailHelper::safeLoadItems fell back for #' . $orderId . ': ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.orderemail',
            );

            return [];
        }
    }

    /**
     * Compute the totals breakdown as DATA (not HTML).
     *
     * Returns the exact argument array `emails.partials.order_totals`
     * expects — `items` + Money objects for subtotal / shipping /
     * discount / tax / total (null when not computable, so the partial
     * decides how to show a missing line). The layout renders it with
     * `$render('emails.partials.order_totals', $totals)` — this helper
     * never names a presentation layout.
     *
     * Numbers come from OrderTotalHelper::getBreakdown (the canonical
     * formula the admin order view + invoices use).
     *
     * @param int $orderId Order PK.
     * @param object[] $items Hydrated line items (carry ->currency).
     *
     * @return array<string, mixed> Keys: items, subtotal, shipping,
     *                              discount, tax, total.
     *
     * @since   1.0.4
     */
    private static function buildTotals(int $orderId, array $items): array
    {
        try {
            $breakdown = OrderTotalHelper::getBreakdown($orderId);
        } catch (Throwable $e) {
            $breakdown = null;
        }

        // Currency from the first item's attached object (OrderHelper
        // sets ->currency on every row); fall back to the shop default.
        $currency = null;
        foreach ($items as $item) {
            if (isset($item->currency) && is_object($item->currency)) {
                $currency = $item->currency;
                break;
            }
        }
        if ($currency === null) {
            try {
                $currency = \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault();
            } catch (Throwable $e) {
                $currency = null;
            }
        }

        $money = static function ($value) use ($currency) {
            if ($currency === null || $value === null) {
                return null;
            }
            try {
                return \Alfa\Component\Alfa\Site\Service\Pricing\Money::of((float) $value, $currency);
            } catch (Throwable $e) {
                return null;
            }
        };

        return [
            'items' => $items,
            'subtotal' => $breakdown !== null ? $money($breakdown->items_tax_excl) : null,
            'shipping' => $breakdown !== null ? $money($breakdown->shipping_tax_incl) : null,
            'discount' => $breakdown !== null ? $money($breakdown->discount_tax_incl) : null,
            'tax' => $breakdown !== null
                ? $money($breakdown->grand_total_tax_incl - $breakdown->grand_total_tax_excl)
                : null,
            'total' => $breakdown !== null ? $money($breakdown->grand_total_tax_incl) : null,
        ];
    }

    /**
     * Resolve the currency to format email money with: the currency object
     * attached to a loaded item, else the shop default, else null.
     *
     * @param object[] $items Hydrated items (may carry ->currency).
     *
     * @return object|null Currency object or null when unresolvable.
     *
     * @since   1.0.4
     */
    private static function resolveCurrency(array $items = [])
    {
        foreach ($items as $item) {
            if (isset($item->currency) && is_object($item->currency)) {
                return $item->currency;
            }
        }

        try {
            return \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Format a numeric value as a money string in the given currency,
     * '' when not formattable. Used by the payments/shipments partials.
     *
     * @param mixed $value Numeric amount (or null).
     * @param object|null $currency Currency object from resolveCurrency.
     *
     *
     * @since   1.0.4
     */
    private static function formatMoney($value, $currency): string
    {
        if ($currency === null || $value === null) {
            return '';
        }

        try {
            return (string) \Alfa\Component\Alfa\Site\Service\Pricing\Money::of((float) $value, $currency)->format();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Human label for a payment/shipment status: translate
     * COM_ALFA_STATUS_<UPPER> when present, else title-case the raw value.
     *
     * @param string $status Raw status string.
     *
     *
     * @since   1.0.4
     */
    private static function statusLabel(string $status): string
    {
        if ($status === '') {
            return '';
        }

        $key = 'COM_ALFA_STATUS_' . strtoupper($status);
        $val = Text::_($key);

        return $val === $key ? ucfirst($status) : $val;
    }

    /**
     * Customer-facing payment rows for the email partial: one object per
     * order payment with method name, formatted amount (refunds prefixed
     * with a minus), and a human status. Defensive — '' / [] on any error.
     *
     * @param object|null $currency
     *
     * @return object[] Each: {method, amount, status}.
     *
     * @since   1.0.4
     */
    private static function safeLoadPayments(int $orderId, $currency): array
    {
        if ($orderId <= 0) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('payment_method'),
                    $db->quoteName('amount'),
                    $db->quoteName('payment_type'),
                    $db->quoteName('transaction_status'),
                ])
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
                ->order($db->quoteName('added') . ' ASC');

            $db->setQuery(query: $query);
            $rows = $db->loadObjectList() ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $isRefund = ($row->payment_type ?? '') === 'refund';
            $amount = self::formatMoney(value: abs((float) ($row->amount ?? 0)), currency: $currency);

            $out[] = (object) [
                'method' => (string) ($row->payment_method ?? ''),
                'amount' => ($isRefund && $amount !== '' ? '−' : '') . $amount,
                'status' => self::statusLabel(status: (string) ($row->transaction_status ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Customer-facing shipment rows for the email partial: one object per
     * order shipment with method name, tracking number, and human status.
     * Defensive — '' / [] on any error.
     *
     *
     * @return object[] Each: {method, tracking, status}.
     *
     * @since   1.0.4
     */
    private static function safeLoadShipments(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('shipment_method_name'),
                    $db->quoteName('tracking_number'),
                    $db->quoteName('status'),
                ])
                ->from($db->quoteName('#__alfa_order_shipments'))
                ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
                ->order($db->quoteName('added') . ' ASC');

            $db->setQuery(query: $query);
            $rows = $db->loadObjectList() ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = (object) [
                'method' => (string) ($row->shipment_method_name ?? ''),
                'tracking' => (string) ($row->tracking_number ?? ''),
                'status' => self::statusLabel(status: (string) ($row->status ?? '')),
            ];
        }

        return $out;
    }
}
