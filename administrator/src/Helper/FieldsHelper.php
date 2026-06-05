<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FieldsPlugin;
use DOMDocument;
use DOMElement;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;
use Throwable;

defined('_JEXEC') or die;

/**
 * FieldsHelper
 *
 * Entry points for rendering alfa-fields in Joomla forms.
 *   - prepareForm()  injects custom fields into a Form for the given context
 *   - getFields()    loads fields visible to the given user in the given context
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

    /** @var array<string, array> */
    private static array $fieldsCache = [];

    /**
     * Path A context → boolean-flag columns on #__alfa_form_fields.
     * A context matches fields where ANY of the listed columns is 1.
     */
    private const CONTEXT_FLAGS = [
        'user.register' => ['registration'],
        'user.edit' => ['registration'],
        'cart.form' => ['billing', 'shipping'],
        'cart.general' => ['billing', 'shipping'],
        'cart.billing' => ['billing'],
        'cart.shipping' => ['shipping'],
    ];

    /**
     * Inject custom alfa-fields for $context into $form as one <fieldset> per
     * group. Each generated fieldset is tagged with target="$targetFieldset"
     * so views can pull only the groups meant for a specific UI slot via
     * getTargetFieldsets() / renderTargetFieldsets().
     *
     * Joomla's native $form->renderFieldset('user_details') returns ONLY the
     * static fields declared in the consumer's order/cart XML — the dynamic
     * groups produced here are separate fieldsets and are rendered through
     * FieldsHelper::renderTargetFieldsets() instead.
     */
    public static function prepareForm(string $context, Form $form, $data, string $targetFieldset): bool
    {
        $fields = self::getFields($context, $data);
        if (!$fields) {
            return true;
        }

        // Bucket fields by group id. Ungrouped (0) first.
        $buckets = [0 => []];
        foreach ($fields as $field) {
            $gid = (int) ($field->group_id ?? 0);
            $buckets[$gid][] = $field;
        }

        $groupIds = array_filter(array_keys($buckets));
        $groups = $groupIds ? self::getGroups($groupIds) : [];

        $xml = new DOMDocument('1.0', 'UTF-8');
        $fieldsNode = $xml->appendChild(new DOMElement('form'))->appendChild(new DOMElement('fields'));
        $fieldsNode->setAttribute('name', self::FIELDS_KEY);

        foreach ($buckets as $gid => $groupFields) {
            if (!$groupFields) {
                continue;
            }

            // Skip fields whose group exists but is disabled.
            if ($gid !== 0 && isset($groups[$gid]) && (int) $groups[$gid]->state === 0) {
                continue;
            }

            $fieldset = $fieldsNode->appendChild(new DOMElement('fieldset'));
            $fieldset->setAttribute('name', self::FIELDSET_PREFIX . $gid);
            $fieldset->setAttribute('target', $targetFieldset);

            if ($gid === 0 || !isset($groups[$gid])) {
                // No legend for the ungrouped fieldset — kept unlabelled on purpose.
                $fieldset->setAttribute('label', '');
            } else {
                $group = $groups[$gid];
                $fieldset->setAttribute('label', Text::_($group->title));
                $fieldset->setAttribute('description', Text::_($group->description ?? ''));
            }

            foreach ($groupFields as $field) {
                try {
                    $plugin = self::boot($field->type ?? '');
                    $plugin?->prepareDom(new PrepareDomEvent('onAlfaFieldsPrepareDom', [
                        'subject' => $field,
                        'fieldset' => $fieldset,
                        'form' => $form,
                    ]));
                } catch (Throwable $e) {
                    Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
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
            // Unknown context — no fields.
            return self::$fieldsCache[$cacheKey] = [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('a.*, g.ordering AS group_ordering')
            ->from($db->quoteName('#__alfa_form_fields', 'a'))
            ->leftJoin($db->quoteName('#__alfa_form_field_groups', 'g') . ' ON g.id = a.group_id')
            ->where('a.state = 1')
            ->where('(a.group_id = 0 OR g.state IS NULL OR g.state = 1)');

        // MULTILINGUAL: resolve the field's translatable text in the active
        // language — field_label / field_description are shown to the customer.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_form_fields',
            langPrimaryColumn: 'id_formfield',
            fields:            ['name', 'field_label', 'field_description'],
        );

        // Context match: ANY of the mapped flag columns = 1.
        $flagExpr = array_map(
            fn ($col) => $db->quoteName('a.' . $col) . ' = 1',
            $flagColumns,
        );
        $query->where('(' . implode(' OR ', $flagExpr) . ')');

        // Per-user visibility — two dimensions AND-combined:
        //   user dimension:       pass if current user is listed OR no real users are listed
        //   usergroup dimension:  pass if any of user's groups is listed OR no real groups are listed
        //
        // Rows with user_id = 0 / usergroup_id = 0 are sentinels written by
        // AlfaHelper::setAssocsToDb when the admin left the list empty. They mean
        // "no restriction on this dimension", not "allow guest". Only rows with id > 0
        // count as actual restrictions.
        $authGroups = array_filter(array_map(
            'intval',
            (array) (Factory::getApplication()->getIdentity()?->getAuthorisedGroups() ?? []),
        ));
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

        $query->where('(' . $userDimension . ' AND ' . $groupDimension . ')');

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
     *   ""              → active-template override then plugin's default.php
     *   "_:name"        → plugin's tmpl/name.php only
     *   "tpl:name"      → templates/tpl/html/plg_alfa-fields_<type>/name.php
     *
     * Falls back to the plugin's default.php if the chosen file is missing.
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

        // Resolve inline {lang: value} maps (value/JSON-mode MultilingualText —
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
                          . '/html/plg_alfa-fields_' . $type . '/' . $name . '.php';
        } else {
            // No explicit template — let PluginHelper resolve active-template
            // override + plugin default.
            $candidates[] = PluginHelper::getLayoutPath('alfa-fields', $type, $name);
        }

        // Last-resort fallback: plugin's default.php for this type.
        $candidates[] = JPATH_PLUGINS . '/alfa-fields/' . $type . '/tmpl/default.php';

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Clear the per-request field cache.
     */
    public static function clearFieldsCache(): void
    {
        self::$fieldsCache = [];
    }

    /**
     * Whether the field should be displayed on a form. Kept as a hook for future show_on /
     * display_readonly logic; returns true by default.
     */
    public static function displayFieldOnForm($field): bool
    {
        return true;
    }

    /**
     * Whether the current user may edit the field value. Hook for future ACL; returns true.
     */
    public static function canEditFieldValue($field): bool
    {
        return true;
    }

    /**
     * Load groups by id, keyed by id. Used by prepareForm to render fieldsets.
     */
    private static function getGroups(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('id, title, description, state, ordering')
            ->from($db->quoteName('#__alfa_form_field_groups'))
            ->whereIn($db->quoteName('id'), array_map('intval', $ids))
            ->order('ordering ASC');

        $db->setQuery($query);

        return $db->loadObjectList('id') ?: [];
    }

    /**
     * Boot the alfa-fields plugin for this field type. Returns null if the plugin is
     * missing, disabled, or not a FieldsPlugin instance.
     */
    private static function boot(string $type): ?FieldsPlugin
    {
        if ($type === '' || !PluginHelper::getPlugin('alfa-fields', $type)) {
            return null;
        }

        $app = Factory::getApplication();
        $plugin = $app->bootPlugin($type, 'alfa-fields');

        if ($plugin instanceof FieldsPlugin) {
            $app->getDispatcher()->addSubscriber($plugin);

            return $plugin;
        }

        return null;
    }
}
