<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The repair of the layout choices that the layout picker of 2.0 saved as "html:<name>", taken by an install or
 * an update.
 *
 * Up to 2.1.0 the layout picker (ViewLayoutField) named a template's layout after the folder above the template's
 * overrides, so templates/<template>/html/com_alfa/<view>/<name>.php was saved as "html:<name>". Joomla reads
 * "html" as a template that does not exist: the page shows the component's own layout and none of the template's
 * overrides. The picker now saves "<template>:<name>"; this gives the choices already saved the same value:
 *  - a value "html:<name>" becomes "<template>:<name>" when exactly one template folder has
 *    templates/<template>/html/com_alfa/<view>/<name>.php, for the view the value is the layout of;
 *  - with no such template, or with several, the value is left as it is and named in the answer, so the shop can
 *    pick that layout again;
 *  - when the site has a template folder named "html", "html:<name>" may be a choice of that template: nothing is
 *    changed and every such value is named.
 * Where the choices live: the products' and the categories' layouts, the com_alfa menu items and the Options (the
 * keys of every layout picker of a site view). Every change is written in one transaction, a row only while it
 * still holds the value read; a failure changes nothing. A second run finds nothing to change.
 *
 * @since  2.1.0
 */
final class TemplateLayoutMigration
{
    /**
     * The template name the picker of 2.0 put before every template's layout.
     *
     * @since  2.1.0
     */
    public const WRONG_TEMPLATE = 'html';

    /**
     * The keys of a product's layouts that hold a view's layout, and that view.
     *
     * @var array<string, string>
     *
     * @since  2.1.0
     */
    public const ITEM_KEYS = ['layout' => 'item'];

    /**
     * The keys of a category's layouts that hold a view's layout, and that view.
     *
     * @var array<string, string>
     *
     * @since  2.1.0
     */
    public const CATEGORY_KEYS = ['layout' => 'items', 'item' => 'item'];

    /**
     * The keys of the Options and of a com_alfa menu item's parameters that hold a view's layout, and that view.
     *
     * @var array<string, string>
     *
     * @since  2.1.0
     */
    public const PARAM_KEYS = [
        'items_layout' => 'items',
        'item_layout' => 'item',
        'manufacturers_layout' => 'manufacturers',
        'manufacturer_layout' => 'manufacturer',
        'registration_layout' => 'registration',
        'userinfo_layout' => 'userinfo',
    ];

    /**
     * Why a value was left: no template has the layout.
     *
     * @since  2.1.0
     */
    public const LEFT_NONE = 'none';

    /**
     * Why a value was left: several templates have the layout.
     *
     * @since  2.1.0
     */
    public const LEFT_SEVERAL = 'several';

    /**
     * Why a value was left: the site has a template folder named "html".
     *
     * @since  2.1.0
     */
    public const LEFT_HTML_FOLDER = 'html-folder';

    /**
     * Template folders by view and layout name, read once per run.
     *
     * @var array<string, string[]>
     */
    private array $holders = [];

    /**
     * @param DatabaseDriver $db The connection the install runs on.
     * @param string $siteRoot The site's root folder, the one holding templates/.
     *
     * @since  2.1.0
     */
    public function __construct(private readonly DatabaseDriver $db, private readonly string $siteRoot = JPATH_SITE)
    {
    }

    /**
     * Give every layout choice saved as "html:<name>" its template, in one transaction.
     *
     * Each entry of the answer names where the value is: kind (item, category, menu or options), id (0 for the
     * Options), key and view. A mapped entry carries the value before and after; a left entry carries the value,
     * why it was left (one of the LEFT_* constants) and, for several, the templates that have the layout.
     *
     * @return array{mapped: list<array{kind: string, id: int, key: string, view: string, from: string, to: string}>,
     *               left: list<array{kind: string, id: int, key: string, view: string, value: string, reason: string, templates: string[]}>}
     *
     * @throws RuntimeException When the change failed: nothing was changed.
     *
     * @since  2.1.0
     */
    public function run(): array
    {
        $this->holders = [];
        $result = ['mapped' => [], 'left' => []];
        $htmlFolder = is_dir($this->siteRoot . '/templates/' . self::WRONG_TEMPLATE);
        $writes = [];

        $sources = [
            ['kind' => 'item', 'table' => '#__alfa_items', 'pk' => 'id', 'column' => 'layouts', 'keys' => self::ITEM_KEYS],
            ['kind' => 'category', 'table' => '#__alfa_categories', 'pk' => 'id', 'column' => 'layouts', 'keys' => self::CATEGORY_KEYS],
            ['kind' => 'menu', 'table' => '#__menu', 'pk' => 'id', 'column' => 'params', 'keys' => self::PARAM_KEYS],
        ];

        foreach ($sources as $source) {
            foreach ($this->rows(source: $source) as $id => $raw) {
                $decoded = json_decode($raw);

                if (!$decoded instanceof stdClass) {
                    continue;
                }

                if ($this->settle(kind: $source['kind'], id: $id, values: $decoded, keys: $source['keys'], htmlFolder: $htmlFolder, result: $result)) {
                    $writes[] = ['source' => $source, 'id' => $id, 'from' => $raw, 'to' => json_encode($decoded)];
                }
            }
        }

        $optionValues = (object) ComponentOptions::stored(db: $this->db);
        $optionsChanged = $this->settle(kind: 'options', id: 0, values: $optionValues, keys: self::PARAM_KEYS, htmlFolder: $htmlFolder, result: $result);

        if ($writes === [] && !$optionsChanged) {
            return $result;
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            foreach ($writes as $write) {
                $this->writeRow(source: $write['source'], id: $write['id'], from: $write['from'], to: (string) $write['to']);
            }

            if ($optionsChanged) {
                ComponentOptions::write(params: (array) $optionValues, db: $db);
            }

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            ComponentOptions::forgetCachedRecords();

            throw new RuntimeException(rtrim($e->getMessage(), '. '), 0, $e);
        }

        return $result;
    }

    /**
     * The rows of one source that hold a value starting with "html:" anywhere in the column, by id.
     *
     * @param array{kind: string, table: string, pk: string, column: string, keys: array<string, string>} $source
     *
     * @return array<int, string> The column as stored, by row id.
     *
     * @since  2.1.0
     */
    private function rows(array $source): array
    {
        $db = $this->db;
        $query = $db->createQuery()
            ->select($db->quoteName([$source['pk'], $source['column']]))
            ->from($db->quoteName($source['table']))
            ->where($db->quoteName($source['column']) . ' LIKE ' . $db->quote('%' . self::WRONG_TEMPLATE . ':%'))
            ->order($db->quoteName($source['pk']) . ' ASC');

        if ($source['kind'] === 'menu') {
            $query->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('link') . ' LIKE ' . $db->quote($db->escape('index.php?option=com_alfa&', true) . '%'));
        }

        $rows = [];

        foreach ($db->setQuery($query)->loadRowList() ?: [] as [$id, $raw]) {
            $rows[(int) $id] = (string) $raw;
        }

        return $rows;
    }

    /**
     * Settle every "html:<name>" value of one record: change it in $values when exactly one template has the
     * layout, else name it in the answer.
     *
     * @param string $kind Where the record is: item, category, menu or options.
     * @param int $id The record's id; 0 for the Options.
     * @param stdClass $values The record's decoded values, changed in place.
     * @param array<string, string> $keys The keys that hold a view's layout, and that view.
     * @param bool $htmlFolder Whether the site has a template folder named "html".
     * @param array $result The answer, added to.
     *
     * @return bool True when a value of $values was changed.
     *
     * @since  2.1.0
     */
    private function settle(string $kind, int $id, stdClass $values, array $keys, bool $htmlFolder, array &$result): bool
    {
        $changed = false;

        foreach ($keys as $key => $view) {
            $value = $values->{$key} ?? null;

            if (!\is_string($value) || !str_starts_with($value, self::WRONG_TEMPLATE . ':')) {
                continue;
            }

            $name = substr($value, \strlen(self::WRONG_TEMPLATE) + 1);
            $where = ['kind' => $kind, 'id' => $id, 'key' => $key, 'view' => $view];

            if ($htmlFolder) {
                $result['left'][] = $where + ['value' => $value, 'reason' => self::LEFT_HTML_FOLDER, 'templates' => []];

                continue;
            }

            $templates = $this->templatesHaving(view: $view, name: $name);

            if (\count($templates) !== 1) {
                $result['left'][] = $where + [
                    'value' => $value,
                    'reason' => $templates === [] ? self::LEFT_NONE : self::LEFT_SEVERAL,
                    'templates' => $templates,
                ];

                continue;
            }

            $values->{$key} = $templates[0] . ':' . $name;
            $result['mapped'][] = $where + ['from' => $value, 'to' => $values->{$key}];
            $changed = true;
        }

        return $changed;
    }

    /**
     * The template folders that have the layout <name> of <view>: templates/<template>/html/com_alfa/<view>/<name>.php.
     *
     * A name the picker could never have offered (anything but lowercase letters, digits and hyphens) has none.
     *
     * @param string $view The site view.
     * @param string $name The layout's file name without .php.
     *
     * @return string[] The template folders, sorted.
     *
     * @since  2.1.0
     */
    private function templatesHaving(string $view, string $name): array
    {
        $memo = $view . '/' . $name;

        if (isset($this->holders[$memo])) {
            return $this->holders[$memo];
        }

        $templates = [];

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            foreach (glob($this->siteRoot . '/templates/*/html/com_alfa/' . $view . '/' . $name . '.php') ?: [] as $file) {
                if (is_file($file)) {
                    $templates[] = basename(\dirname($file, 4));
                }
            }
        }

        sort($templates);

        return $this->holders[$memo] = $templates;
    }

    /**
     * Write one record's changed column, only while it still holds the value read.
     *
     * @param array{kind: string, table: string, pk: string, column: string, keys: array<string, string>} $source
     * @param int $id The record's id.
     * @param string $from The column as read.
     * @param string $to The column with the values changed.
     *
     * @throws RuntimeException When the record was changed or removed since it was read.
     *
     * @since  2.1.0
     */
    private function writeRow(array $source, int $id, string $from, string $to): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()
                ->update($db->quoteName($source['table']))
                ->set($db->quoteName($source['column']) . ' = :to')
                ->where($db->quoteName($source['pk']) . ' = :id')
                ->where($db->quoteName($source['column']) . ' = :from')
                ->bind(':to', $to, ParameterType::STRING)
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':from', $from, ParameterType::STRING),
        )->execute();

        if ((int) $db->getAffectedRows() !== 1) {
            throw new RuntimeException(
                'the ' . $source['kind'] . ' #' . $id . ' was changed while its layout choices were being repaired',
            );
        }
    }
}
