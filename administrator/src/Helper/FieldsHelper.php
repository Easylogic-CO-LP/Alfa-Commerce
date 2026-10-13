<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FormFieldsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use DOMDocument;
use DOMElement;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use Throwable;

defined('_JEXEC') or die;

/**
 * FieldsHelper
 *
 * Entry points for rendering alfa-form-fields in Joomla forms.
 *   - prepareForm()  injects custom fields into a Form for the given context
 *   - getFields()    loads fields visible to the given user in the given context
 * @since  1.0.0
 */
class FieldsHelper
{
    /**
     * Prefix used for every fieldset injected by prepareForm(). Views detect
     * alfa-injected fieldsets via str_starts_with($name, self::FIELDSET_PREFIX).
     */
    public const FIELDSET_PREFIX = 'formfield_group_';

    /**
     * Wrapper name applied to the <fields> node injected by prepareForm().
     * On submit, all alfa form-field values land under $data[self::FIELDS_KEY].
     * Mirrors the table name #__alfa_form_fields for predictability.
     */
    public const FIELDS_KEY = 'alfa_form_fields';

    /**
     * Bucket for the billing (invoice) copy of the checkout fields. Same
     * field names as FIELDS_KEY: the bucket carries the ROLE, the field
     * name carries the #__alfa_user_info column (rows stay type-neutral).
     */
    public const BILLING_FIELDS_KEY = 'alfa_form_fields_billing';

    /**
     * Field type whose value is a #__alfa_places id. In the delivery section
     * it is the delivery place that shipping zones and method scopes are
     * evaluated against.
     */
    public const PLACE_FIELD_TYPE = 'country';

    /**
     * The `#__alfa_user_info` column a saved checkout-field ID points at.
     *
     * Params written by {@see \Alfa\Component\Alfa\Administrator\Field\CheckoutfieldField}
     * hold the field's ID rather than its name, because renaming a field
     * rewrites the name and ALTERs the column while the id stays put. This
     * turns one back into the other, memoised per request.
     *
     * @param int $fieldId The saved `#__alfa_form_fields.id`.
     *
     * @return string|null The column name, or null when the field is gone or unpublished.
     *
     * @since  2.0.0
     */
    public static function columnForField(int $fieldId): ?string
    {
        static $resolved = [];

        if ($fieldId <= 0) {
            return null;
        }

        if (\array_key_exists($fieldId, $resolved)) {
            return $resolved[$fieldId];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('field_name'))
            ->from($db->quoteName('#__alfa_form_fields'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':id', $fieldId, ParameterType::INTEGER);

        try {
            $name = (string) $db->setQuery($query)->loadResult();
        } catch (Throwable) {
            return $resolved[$fieldId] = null;
        }

        return $resolved[$fieldId] = $name !== '' ? $name : null;
    }

    /** @var array<string, array> */
    private static array $fieldsCache = [];

    /**
     * Context -> section columns on #__alfa_form_fields. Each column is a
     * visibility level (0 hidden, 1 optional, 2 required); a context shows the
     * fields where ANY of its columns is >= 1, and a field is required in a
     * context when the level is 2 (see visibilityLevel()).
     *
     * `user.edit` is the storefront "My info" screen (a multi-row editor of
     * saved delivery/invoice records), so it shows the checkout fields, like
     * the backend saved-info popup (`cart.form`).
     */
    private const CONTEXT_FLAGS = [
        'user.register' => ['registration'],
        'user.edit' => ['billing', 'shipping'],
        'cart.form' => ['billing', 'shipping'],
        'cart.general' => ['billing', 'shipping'],
        'cart.billing' => ['billing'],
        'cart.shipping' => ['shipping'],
    ];

    /** A field is hidden in a section. */
    public const LEVEL_HIDDEN = 0;

    /** A field shows in a section and may be left empty. */
    public const LEVEL_OPTIONAL = 1;

    /** A field shows in a section and must be filled. */
    public const LEVEL_REQUIRED = 2;

    /**
     * Inject custom alfa-form-fields for $context into $form as one <fieldset> per
     * group. Each generated fieldset is tagged with target="$targetFieldset"
     * so views can pull only the groups meant for a specific UI slot via
     * getTargetFieldsets() / renderTargetFieldsets().
     *
     * Joomla's native $form->renderFieldset('user_details') returns ONLY the
     * static fields declared in the consumer's order/cart XML; the dynamic
     * groups produced here are separate fieldsets and are rendered through
     * FieldsHelper::renderTargetFieldsets() instead.
     * @since  1.0.0
     */
    /**
     * Seed one bucket of a prepared form from a saved details row.
     *
     * The row's columns ARE the field names (that is the whole point of the
     * dynamic schema), so a saved address drops straight into the bucket that
     * carries it. `id` and `id_user` are the record's own identity, never a
     * merchant field, and are not the form's to hold.
     *
     * Both the Order screen and the cart builder seed their address buckets this
     * way; there is one implementation so a screen cannot start seeding a
     * column the other refuses.
     *
     * @param Form $form The prepared form.
     * @param string $bucket The group to write into (FIELDS_KEY / BILLING_FIELDS_KEY).
     * @param object|null $row A #__alfa_user_info row, or null to leave the bucket empty.
     *
     *
     * @since  2.0.0
     */
    public static function prefillBucket(Form $form, string $bucket, ?object $row): void
    {
        if ($row === null) {
            return;
        }

        $values = (array) $row;
        unset($values['id'], $values['id_user']);

        foreach ($values as $key => $value) {
            $form->setValue($key, $bucket, $value);
        }
    }

    public static function prepareForm(
        string $context,
        Form $form,
        $data,
        string $targetFieldset,
        ?string $group = null,
        ?int $userId = null,
        ?int $customerId = null,
    ): bool {
        $bucket = $group ?? self::FIELDS_KEY;

        // TWO IDENTITIES, ONE FORM. $userId answers "whose fields may be seen"
        // (the per-user/usergroup restrictions): the current identity on the
        // storefront, the record's owner or the operator in the backend.
        // $customerId answers "whose identity is this" (RoleRegistry: a member's
        // email is the account's) and defaults to the same person; the backend's
        // CREATE screen is the case where they differ: the operator's groups,
        // no customer yet.
        $userId ??= (int) (Factory::getApplication()->getIdentity()->id ?? 0);
        $customerId ??= $userId;

        $fields = self::getFields($context, $data, $userId);
        if (!$fields) {
            return true;
        }

        $groupIds = array_filter(array_unique(array_map(
            static fn ($field) => (int) ($field->group_id ?? 0),
            $fields,
        )));
        $groups = $groupIds ? self::getGroups($groupIds) : [];

        // Bucket fields by group id. Ungrouped (0) first. A SYSTEM field whose
        // group is disabled is not hidden with the group (the identity it
        // carries is what the order needs), so it renders ungrouped, at the top.
        $buckets = [0 => []];
        foreach ($fields as $field) {
            $gid = (int) ($field->group_id ?? 0);

            if ($gid !== 0 && isset($groups[$gid]) && (int) $groups[$gid]->state === 0 && (int) ($field->is_system ?? 0) === 1) {
                $gid = 0;
            }

            $buckets[$gid][] = $field;
        }

        $xml = new DOMDocument('1.0', 'UTF-8');
        $fieldsNode = $xml->appendChild(new DOMElement('form'))->appendChild(new DOMElement('fields'));
        $fieldsNode->setAttribute('name', $bucket);

        foreach ($buckets as $gid => $groupFields) {
            if (!$groupFields) {
                continue;
            }

            // Skip fields whose group exists but is disabled.
            if ($gid !== 0 && isset($groups[$gid]) && (int) $groups[$gid]->state === 0) {
                continue;
            }

            $fieldset = $fieldsNode->appendChild(new DOMElement('fieldset'));
            $fieldset->setAttribute(
                'name',
                self::FIELDSET_PREFIX . $gid . ($group !== null ? '_' . $group : ''),
            );
            $fieldset->setAttribute('target', $targetFieldset);

            if ($gid === 0 || !isset($groups[$gid])) {
                // No legend for the ungrouped fieldset, kept unlabelled on purpose.
                $fieldset->setAttribute('label', '');
            } else {
                // Distinct variable: $group is the fieldset-name suffix parameter used
                // above, and must not be overwritten with the group row (doing so
                // fataled on the next grouped bucket: stdClass into a string).
                $groupRow = $groups[$gid];
                $fieldset->setAttribute('label', Text::_($groupRow->title));
                // Description is editor HTML in the active language; emit it raw
                // (the storefront renders the group description as HTML).
                $fieldset->setAttribute('description', $groupRow->description ?? '');
            }

            foreach ($groupFields as $field) {
                // Reset per field: a plugin that fails to start must not be logged under the previous field's plugin.
                $plugin = null;

                try {
                    $plugin = self::boot($field->type ?? '');

                    if ($plugin === null) {
                        // A system field with nothing to render it is a hole in
                        // the identity capture (a guest order with no email),
                        // never a silent no-op.
                        if ((int) ($field->is_system ?? 0) === 1) {
                            $message = 'The "' . $field->type . '" form-field plugin is missing or disabled; the system field "'
                                . $field->field_name . '" cannot be rendered.';
                            Log::add($message, Log::ERROR, 'com_alfa');

                            // Extension and column names are for the operator, not the visitor.
                            if (Factory::getApplication()->isClient('administrator')) {
                                Factory::getApplication()->enqueueMessage($message, 'error');
                            }
                        }

                        continue;
                    }

                    $plugin->prepareDom(new PrepareDomEvent('onAlfaFormFieldsPrepareDom', [
                        'subject' => $field,
                        'fieldset' => $fieldset,
                        'form' => $form,
                        'context' => $context,
                        // The CUSTOMER: the identity rules are about whose
                        // details these are, not about who is looking.
                        'userId' => $customerId,
                    ]));
                } catch (Throwable $e) {
                    // LOG ONLY. This builds the CHECKOUT form as well as the
                    // admin ones, so a field plugin that throws here used to put
                    // its stack's worth of internals in front of a buyer. The
                    // fieldset simply renders without that plugin's DOM work,
                    // which is what the catch already meant to allow.
                    AlfaLog::register();
                    Log::add(
                        (\is_object($plugin)
                            ? 'onAlfaFormFieldsPrepareDom threw in plugin "' . ($plugin->name ?? \get_class($plugin))
                            : 'The form-field plugin could not start: "' . ($field->type ?? ''))
                        . '" for field #' . (int) ($field->id ?? 0) . ' in context "' . $context . '": '
                        . $e->getMessage(),
                        Log::ERROR,
                        'com_alfa.cart',
                    );
                }
            }

            if (!$fieldset->hasChildNodes()) {
                $fieldsNode->removeChild($fieldset);
            }
        }

        $form->load($xml->saveXML());

        return true;
    }

    /**
     * Return the fieldset stdClass objects produced by prepareForm() that are
     * routed to a given UI slot. Pass $target = null to get every alfa-injected
     * fieldset regardless of routing.
     *
     * @return object[]
     * @since  1.0.0
     */
    public static function getTargetFieldsets(Form $form, ?string $target = null): array
    {
        $matched = [];

        foreach ($form->getFieldsets() as $fieldset) {
            if (!str_starts_with($fieldset->name, self::FIELDSET_PREFIX)) {
                continue;
            }

            if ($target !== null && ($fieldset->target ?? null) !== $target) {
                continue;
            }

            $matched[] = $fieldset;
        }

        return $matched;
    }

    /**
     * Render the full UI slot for $target: the static fields declared in the
     * consumer's XML <fieldset name="$target"> first (loose at the top), then
     * each alfa-injected group routed to $target as its own sub-fieldset with
     * the group title/description as legend.
     *
     * Pass $target = null to render every alfa-injected group regardless of
     * routing (useful for forms like the frontend cart where there are no
     * static fields to render alongside).
     * @since  1.0.0
     */
    public static function renderFieldset(Form $form, ?string $target = null): string
    {
        // Static fields: declared in the consumer's XML under <fieldset name="$target">
        // (e.g. forms/order.xml has <fieldset name="user_details"> with user_email,
        // user_name, id_user_group). Rendered loose at the top of the slot, before any
        // dynamic groups. With $target = null there is no static fieldset to pull from.
        $static = $target !== null ? $form->getFieldset($target) : [];

        // Grouped fields: alfa-injected fieldsets created by prepareForm(), one per
        // #__alfa_form_field_groups row, tagged with target="$target" so we pull only
        // the groups routed to this UI slot. Each becomes its own sub-fieldset with the
        // group title/description as legend. $target = null pulls every alfa group.
        $groups = [];

        foreach (self::getTargetFieldsets($form, $target) as $fs) {
            $fields = $form->getFieldset($fs->name);
            if (!count($fields)) {
                continue;
            }

            $groups[] = [
                'name' => $fs->name,
                'label' => $fs->label ?? '',
                'description' => $fs->description ?? '',
                'fields' => $fields,
            ];
        }

        if (!$static && !$groups) {
            return '';
        }

        $layout = new FileLayout(
            'fieldshelper.grouped_fields',
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );

        return (string) $layout->render([
            'target' => $target,
            'static' => $static,
            'groups' => $groups,
        ]);
    }

    /**
     * Load fields visible to $userId in $context. Ordered by group.ordering, field.ordering.
     * @since  1.0.0
     */
    public static function getFields(string $context, $item = null, ?int $userId = null): array
    {
        $itemId = is_object($item) && isset($item->id) ? (int) $item->id : 0;
        $userId ??= (int) (Factory::getApplication()->getIdentity()->id ?? 0);

        $cacheKey = $context . '|' . $itemId . '|' . $userId;
        if (isset(self::$fieldsCache[$cacheKey])) {
            return self::$fieldsCache[$cacheKey];
        }

        $flagColumns = self::CONTEXT_FLAGS[$context] ?? null;
        if ($flagColumns === null) {
            // Unknown context: no fields.
            return self::$fieldsCache[$cacheKey] = [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('a.*, g.ordering AS group_ordering')
            ->from($db->quoteName('#__alfa_form_fields', 'a'))
            ->leftJoin($db->quoteName('#__alfa_form_field_groups', 'g') . ' ON g.id = a.group_id')
            ->where('a.state = 1')
            // A disabled group hides its fields, except a system field, which
            // prepareForm() then renders ungrouped (the identity is always asked).
            ->where('(a.group_id = 0 OR g.state IS NULL OR g.state = 1 OR a.is_system = 1)');

        // MULTILINGUAL: resolve the field's translatable text in the active
        // language: field_label / field_description are shown to the customer.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_form_fields',
            langPrimaryColumn: 'id_formfield',
            fields:            ['name', 'field_label', 'field_description'],
        );

        // Context match: shown (optional OR required) in ANY of the context's sections.
        $flagExpr = array_map(
            fn ($col) => $db->quoteName('a.' . $col) . ' >= ' . self::LEVEL_OPTIONAL,
            $flagColumns,
        );
        $query->where('(' . implode(' OR ', $flagExpr) . ')');

        // Per-user visibility, two dimensions AND-combined:
        //   user dimension:       pass if current user is listed OR no real users are listed
        //   usergroup dimension:  pass if any of user's groups is listed OR no real groups are listed
        //
        // Rows with user_id = 0 / usergroup_id = 0 are sentinels written by
        // AlfaHelper::setAssocsToDb when the admin left the list empty. They mean
        // "no restriction on this dimension", not "allow guest". Only rows with id > 0
        // count as actual restrictions.
        // The groups of the customer the form is built FOR: the current
        // identity on the storefront, the record's owner in the backend (an
        // administrator editing a customer's order must see the customer's fields).
        $identity = Factory::getApplication()->getIdentity();
        $authGroups = $userId !== (int) ($identity->id ?? 0)
            ? Access::getGroupsByUser($userId, true) // includes the guest group for 0
            : (array) ($identity?->getAuthorisedGroups() ?? []);
        $authGroups = array_filter(array_map('intval', (array) $authGroups));
        $authGroupsIn = $authGroups ? implode(',', $authGroups) : '0';

        $usersTable = $db->quoteName('#__alfa_form_fields_users');
        $groupsTable = $db->quoteName('#__alfa_form_fields_usergroups');

        $userDimension = $userId > 0
            ? '(EXISTS (SELECT 1 FROM ' . $usersTable . ' fu WHERE fu.field_id = a.id AND fu.user_id = ' . (int) $userId . ')'
              . ' OR NOT EXISTS (SELECT 1 FROM ' . $usersTable . ' fu WHERE fu.field_id = a.id AND fu.user_id > 0))'
            : 'NOT EXISTS (SELECT 1 FROM ' . $usersTable . ' fu WHERE fu.field_id = a.id AND fu.user_id > 0)';

        $groupDimension =
            '(EXISTS (SELECT 1 FROM ' . $groupsTable . ' fg WHERE fg.field_id = a.id AND fg.usergroup_id IN (' . $authGroupsIn . '))'
            . ' OR NOT EXISTS (SELECT 1 FROM ' . $groupsTable . ' fg WHERE fg.field_id = a.id AND fg.usergroup_id > 0))';

        // A system field is shown to every customer: no restriction can hide
        // the identity capture from the guests it exists for.
        $query->where('(a.is_system = 1 OR (' . $userDimension . ' AND ' . $groupDimension . '))');

        $query->order('COALESCE(g.ordering, 0) ASC, a.ordering ASC');

        $db->setQuery($query);
        $fields = $db->loadObjectList() ?: [];

        return self::$fieldsCache[$cacheKey] = $fields;
    }

    /**
     * Render a field's stored value using its chosen layout.
     *
     * Signature order mirrors Joomla core's FieldsHelper::render($context, ...).
     * Tmpls start with `extract($displayData);` and see $context, $field,
     * $fieldParams, $item, plus anything else the caller passed in.
     *
     * Layout resolution (from $field->layout):
     *   ""              -> active-template override then plugin's default.php
     *   "_:name"        -> plugin's tmpl/name.php only
     *   "tpl:name"      -> templates/tpl/html/plg_alfa-form-fields_<type>/name.php
     *
     * Falls back to the plugin's default.php if the chosen file is missing.
     * @since  1.0.0
     */
    public static function render(string $context, $field, array $displayData = []): string
    {
        $type = (string) ($field->type ?? '');
        if ($type === '') {
            return '';
        }

        $path = self::resolveLayoutPath($field, $type);
        if ($path === null) {
            return '';
        }

        // Resolve inline {lang: value} maps (value/JSON-mode MultilingualText,
        // e.g. choice option labels) to the current language for display.
        $resolvedParams = MultilingualHelper::collapseToCurrent(
            is_string($field->params ?? null)
                ? (json_decode((string) $field->params, true) ?: [])
                : (array) ($field->params ?? []),
        );

        $fieldParams = new \Joomla\Registry\Registry();
        $fieldParams->loadArray(is_array($resolvedParams) ? $resolvedParams : []);

        // Guarantee keys tmpls can rely on after extract($displayData).
        $displayData['context'] = $context;
        $displayData['field'] = $field;
        $displayData['fieldParams'] = $fieldParams;
        $displayData['item'] ??= null;

        $layout = new \Joomla\CMS\Layout\FileLayout(basename($path, '.php'), dirname($path));

        return (string) $layout->render($displayData);
    }

    /**
     * Resolve the filesystem path for a field's chosen layout.
     * Returns null if neither the chosen file nor the plugin default exists.
     * @since  1.0.0
     */
    private static function resolveLayoutPath($field, string $type): ?string
    {
        $layoutValue = (string) ($field->layout ?? '');
        $template = null;
        $name = 'default';

        if ($layoutValue !== '') {
            if (str_contains($layoutValue, ':')) {
                [$prefix, $rawName] = explode(':', $layoutValue, 2);
                $template = ($prefix !== '' && $prefix !== '_') ? $prefix : null;
                if ($rawName !== '') {
                    $name = $rawName;
                }
            } else {
                // Back-compat: bare layout name without prefix.
                $name = $layoutValue;
            }
        }

        $candidates = [];

        if ($template !== null) {
            // Explicit template override chosen by admin.
            $candidates[] = JPATH_SITE . '/templates/' . $template
                          . '/html/plg_alfa-form-fields_' . $type . '/' . $name . '.php';
        } else {
            // No explicit template: let PluginHelper resolve active-template
            // override + plugin default.
            $candidates[] = PluginHelper::getLayoutPath('alfa-form-fields', $type, $name);
        }

        // Last-resort fallback: plugin's default.php for this type.
        $candidates[] = JPATH_PLUGINS . '/alfa-form-fields/' . $type . '/tmpl/default.php';

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * How the shop's delivery destination is mapped. Kept apart from "is there
     * a value" on purpose: a merchant who has not chosen a field and a shopper
     * who has not typed one are different problems with different fixes.
     *
     * @since  2.0.0
     */
    public const DESTINATION_CONFIGURED = 'configured';
    public const DESTINATION_INFERRED = 'inferred';
    public const DESTINATION_MISSING = 'missing';
    public const DESTINATION_AMBIGUOUS = 'ambiguous';
    public const DESTINATION_UNMAPPED = 'unmapped';

    /**
     * The method setting by which ONE payment or shipping method reads the
     * destination from a field of its own instead of the shop's.
     *
     * A carrier that delivers to parcel lockers asks for the locker's country,
     * not the recipient's; a gateway licensed in one market cares where the
     * CARD is registered. Those are that method's questions, and a shop cannot
     * answer them with the single field that says where the goods are going.
     *
     * DELIBERATELY THE SAME NAME AS THE SHOP-WIDE SETTING. Joomla's
     * `useglobal="true"` resolves its "Use Global (...)" option by the field's
     * BARE NAME against the component's own params, so a method-level override
     * called `delivery_place_field` inherits the shop's mapping automatically
     * and shows the merchant which field that is, rather than a static sentence
     * that could be wrong. Renaming either half silently breaks the inheritance
     * (the option would resolve to nothing), which is why they are one name.
     *
     * EMPTY IS THE ANSWER ALMOST ALWAYS, and empty is what "Use Global" saves:
     * the method is offered, quoted and placed against the destination every
     * other method sees. An override changes that ONE method, never its
     * neighbours, and never tax, which keeps its own address policy and is not
     * routed through here.
     *
     * @since  2.0.0
     */
    public const METHOD_DESTINATION_PARAM = 'delivery_place_field';

    /**
     * The shop's own default country, as an ISO 3166-1 alpha-2 code.
     *
     * WHERE A DEFAULT COUNTRY LEGITIMATELY LIVES. The shop does not hold one as
     * a setting and must not: no destination is assumed on a shopper's behalf,
     * which is the whole point of the destination resolver. But the merchant
     * DOES say which country their checkout starts on: the place field carries
     * a `default_place`, and that is a real, configured answer to "where are
     * this shop's customers, absent other information".
     *
     * It is the right region to READ AN AMBIGUOUS PHONE NUMBER against (a
     * national number is uninterpretable without one) and it is emphatically
     * NOT a destination. Nothing about shipping or tax reads this.
     *
     * @return string The alpha-2 code, or '' when the shop has not said.
     *
     * @since  2.0.0
     */
    public static function shopRegionCode(): string
    {
        $field = self::deliveryPlaceField();

        if ($field === null) {
            return '';
        }

        $params = $field->params ?? '';
        $params = \is_string($params) ? (json_decode($params, true) ?: []) : (array) $params;
        $placeId = (int) ($params['default_place'] ?? 0);

        if ($placeId <= 0) {
            return '';
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $code = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('code2'))
                ->from($db->quoteName('#__alfa_places'))
                ->where($db->quoteName('id') . ' = ' . $placeId),
        )->loadResult();

        return strtoupper(trim($code));
    }

    /**
     * The checkout field a method overrides its destination with.
     *
     * TWO SHAPES OF ONE METHOD, ONE ANSWER. The core holds the loaded row, on
     * which the loader has already decoded `params` to an array; a plugin holds
     * the cart's Method reader, which keeps the same configuration private
     * behind `param()`. Which of the two a caller happens to be holding must
     * not change where the parcel is judged to be going, so both are read here
     * rather than at each call site.
     *
     * @param object|null $method A loaded method row, or the cart's Method reader.
     *
     * @return int The field id, or 0 when the method follows the shop.
     *
     * @since  2.0.0
     */
    public static function methodDestinationFieldId(?object $method): int
    {
        if ($method === null) {
            return 0;
        }

        return (int) (
            method_exists($method, 'param')
                ? $method->param(self::METHOD_DESTINATION_PARAM, 0)
                : (($method->params ?? [])[self::METHOD_DESTINATION_PARAM] ?? 0)
        );
    }

    /**
     * Every checkout field that could carry the delivery destination.
     *
     * @param int|null $userId Whose visibility applies.
     *
     * @return object[] The eligible field rows, in form order.
     *
     * @since  2.0.0
     */
    public static function deliveryPlaceCandidates(?int $userId = null): array
    {
        $candidates = [];

        foreach (self::getFields('cart.shipping', null, $userId) as $field) {
            if ((string) ($field->type ?? '') === self::PLACE_FIELD_TYPE) {
                $candidates[] = $field;
            }
        }

        return $candidates;
    }

    /**
     * THE ONE PLACE THE DELIVERY DESTINATION FIELD IS DECIDED.
     *
     * Everything that asks where an order is going (which methods are offered,
     * what a carrier is quoted against, what the cart's own delivery API
     * reports) resolves through here, so those answers cannot diverge. They
     * did: the core resolved by field TYPE while the plugin-facing address API
     * resolved by ROLE, and a shop with a hand-made country field, or two of
     * them, could be filtered against one field and priced against another.
     *
     * A SHOP MAY HAVE NO SUCH FIELD, and that stays legitimate: a
     * single-country shop drops it and sells fine. What is not legitimate is
     * GUESSING when there is more than one: taking whichever the merchant
     * happened to drag higher in the form made a checkout's field order
     * silently decide shipping zones and tax. Several candidates and no
     * explicit mapping is a configuration question, answered by the merchant,
     * not by ordering.
     *
     * @param int|null $userId Whose visibility applies.
     *
     * @return object|null The field, or null when none is mapped.
     *
     * @since  2.0.0
     */
    public static function deliveryPlaceField(?int $userId = null): ?object
    {
        return self::resolveDeliveryPlaceField($userId)[0];
    }

    /**
     * Why {@see self::deliveryPlaceField()} answered as it did: for the
     * merchant-facing message, and for diagnosing a shop that is not offering
     * what its owner expects.
     *
     * @param int|null $userId Whose visibility applies.
     *
     * @return string One of the DESTINATION_* constants.
     *
     * @since  2.0.0
     */
    public static function deliveryPlaceFieldState(?int $userId = null): string
    {
        return self::resolveDeliveryPlaceField($userId)[1];
    }

    /**
     * @param int|null $userId Whose visibility applies.
     *
     * @return array{0: object|null, 1: string} The field and the reason.
     *
     * @since  2.0.0
     */
    private static function resolveDeliveryPlaceField(?int $userId): array
    {
        $candidates = self::deliveryPlaceCandidates($userId);
        $configured = (int) ComponentHelper::getParams('com_alfa')->get('delivery_place_field', 0);

        if ($configured > 0) {
            foreach ($candidates as $field) {
                if ((int) ($field->id ?? 0) === $configured) {
                    return [$field, self::DESTINATION_CONFIGURED];
                }
            }

            // Mapped to a field that has since been deleted, unpublished or
            // hidden from this shopper. Falling back to another field would
            // quietly price against an address the merchant did not choose.
            return [null, self::DESTINATION_UNMAPPED];
        }

        return match (\count($candidates)) {
            0 => [null, self::DESTINATION_MISSING],
            1 => [$candidates[0], self::DESTINATION_INFERRED],
            default => [null, self::DESTINATION_AMBIGUOUS],
        };
    }

    /**
     * The first field of a type among those a context shows to a user.
     *
     * @param string $context The field context, e.g. 'cart.shipping'.
     * @param string $type The field type (plugin name).
     * @param int|null $userId The user the fields are resolved for; null = current.
     *
     * @return object|null The field row, or null when the context has none of that type.
     *
     * @since  1.0.0
     */
    public static function findFieldByType(string $context, string $type, ?int $userId = null): ?object
    {
        foreach (self::getFields($context, null, $userId) as $field) {
            if ((string) ($field->type ?? '') === $type) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Clear the per-request field cache.
     * @since  1.0.0
     */
    public static function clearFieldsCache(): void
    {
        self::$fieldsCache = [];
    }

    /**
     * The visibility level of a field in a context: the section column the
     * context maps to, or the HIGHEST of them for a context spanning both
     * checkout sections (a record edited role-neutrally must satisfy either).
     *
     * @param object $field The `#__alfa_form_fields` row.
     * @param string $context The FieldsHelper context.
     *
     * @return int LEVEL_HIDDEN | LEVEL_OPTIONAL | LEVEL_REQUIRED.
     *
     * @since  2.0.0
     */
    public static function visibilityLevel(object $field, string $context): int
    {
        // The sections that show the field. A context spanning both checkout
        // sections (a record edited role-neutrally) shows it when either does,
        // but demands it only when EVERY section showing it demands it: a
        // field required on invoices alone must not block saving an address.
        $shown = [];

        foreach (self::CONTEXT_FLAGS[$context] ?? [] as $column) {
            $level = (int) ($field->$column ?? 0);

            if ($level >= self::LEVEL_OPTIONAL) {
                $shown[] = $level;
            }
        }

        if ($shown === []) {
            return self::LEVEL_HIDDEN;
        }

        return min($shown) === self::LEVEL_REQUIRED ? self::LEVEL_REQUIRED : self::LEVEL_OPTIONAL;
    }

    /**
     * Whether a field must be filled in a context.
     *
     * @param object $field The `#__alfa_form_fields` row.
     * @param string $context The FieldsHelper context.
     *
     *
     * @since  2.0.0
     */
    public static function fieldRequired(object $field, string $context): bool
    {
        return self::visibilityLevel($field, $context) === self::LEVEL_REQUIRED;
    }

    /**
     * Whether the field is rendered on a form built for this customer in this
     * context. Merchant fields always are; a field carrying an identity role
     * follows RoleRegistry::hiddenIn() (a member's email is the account's).
     *
     * @param object $field The `#__alfa_form_fields` row.
     * @param string $context The FieldsHelper context ('' = unknown, shown).
     * @param int $userId The customer the form is built for (0 = guest).
     *
     *
     * @since  1.0.0
     */
    public static function displayFieldOnForm($field, string $context = '', int $userId = 0): bool
    {
        $role = (string) ($field->role ?? '');

        if ($role === '' || $context === '' || !RoleRegistry::isRole($role)) {
            return true;
        }

        return !RoleRegistry::hiddenIn(role: $role, context: $context, userId: $userId);
    }

    /**
     * Whether the current user may edit the field value. Hook for future ACL; returns true.
     * @since  1.0.0
     */
    public static function canEditFieldValue($field): bool
    {
        return true;
    }

    /**
     * Load groups by id, keyed by id. Used by prepareForm to render fieldsets.
     * @since  1.0.0
     */
    private static function getGroups(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('a.id, a.state, a.ordering')
            ->from($db->quoteName('#__alfa_form_field_groups', 'a'))
            ->whereIn($db->quoteName('a.id'), array_map('intval', $ids))
            ->order('a.ordering ASC');

        // MULTILINGUAL: title + description in the active language (the translatable
        // columns now live in the per-language tables).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_form_field_groups',
            langPrimaryColumn: 'id_form_field_group',
            fields:            ['title', 'description'],
        );

        $db->setQuery($query);

        return $db->loadObjectList('id') ?: [];
    }

    /**
     * Boot the alfa-form-fields plugin for this field type. Returns null if the plugin is
     * missing, disabled, or not a FormFieldsPlugin instance.
     * @since  1.0.0
     */
    private static function boot(string $type): ?FormFieldsPlugin
    {
        if ($type === '' || !PluginHelper::getPlugin('alfa-form-fields', $type)) {
            return null;
        }

        $app = Factory::getApplication();
        $plugin = $app->bootPlugin($type, 'alfa-form-fields');

        if ($plugin instanceof FormFieldsPlugin) {
            $app->getDispatcher()->addSubscriber($plugin);

            return $plugin;
        }

        return null;
    }
}
