<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Helper;

use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Joomla\CMS\Factory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The names of what tabs are about: categories, manufacturers and labels, as this visitor may see them.
 *
 * A tab is named after its categories, and an automatic tab after its one category, manufacturer or label. The names
 * of every tab of a module are read together: one read per type for the whole module, through the component's own
 * lists (published categories this visitor may see, the manufacturers the shop shows, the labels a product may show
 * now), in the page's language. What a list does not return has no name here, so no tab is made for it.
 *
 * @since  2.3.0
 */
final class Subjects
{
    public const CATEGORY = 'category';
    public const MANUFACTURER = 'manufacturer';
    public const LABEL = 'label';

    /**
     * @var string[] What an automatic tab may be about.
     * @since  2.3.0
     */
    public const TYPES = [self::CATEGORY, self::MANUFACTURER, self::LABEL];

    /**
     * @var array<string, array<int, string|null>> The names read this request, by type then id; null = not shown to this visitor.
     * @since  2.3.0
     */
    private static array $names = [self::CATEGORY => [], self::MANUFACTURER => [], self::LABEL => []];

    /**
     * @var int How many reads were made this request, one per type and call that had something still unread.
     * @since  2.3.0
     */
    private static int $reads = 0;

    /**
     * Read the names of these ids, all in one read; ids read before are not read again.
     *
     * @param string $type One of TYPES.
     * @param int[] $ids The ids.
     *
     * @since  2.3.0
     */
    public static function prime(string $type, array $ids): void
    {
        $unread = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys(self::$names[$type])));

        if ($unread === []) {
            return;
        }

        self::$reads++;
        $found = self::read(type: $type, ids: $unread);

        foreach ($unread as $id) {
            self::$names[$type][$id] = $found[$id] ?? null;
        }
    }

    /**
     * The names of these ids, in the order given, without the ones this visitor is not shown.
     *
     * @param string $type One of TYPES.
     * @param int[] $ids The ids.
     *
     * @return array<int, string> Id => name.
     *
     * @since  2.3.0
     */
    public static function names(string $type, array $ids): array
    {
        self::prime(type: $type, ids: $ids);
        $names = [];

        foreach ($ids as $id) {
            if ((self::$names[$type][(int) $id] ?? null) !== null) {
                $names[(int) $id] = self::$names[$type][(int) $id];
            }
        }

        return $names;
    }

    /**
     * How many reads were made so far this request.
     *
     * @since  2.3.0
     */
    public static function reads(): int
    {
        return self::$reads;
    }

    /**
     * Forget every name read: a long-running process that changes the catalogue reads again.
     *
     * @since  2.3.0
     */
    public static function forget(): void
    {
        self::$names = [self::CATEGORY => [], self::MANUFACTURER => [], self::LABEL => []];
        self::$reads = 0;
    }

    /**
     * One read of the component's list of a type, narrowed to the ids.
     *
     * @param string $type One of TYPES.
     * @param int[] $ids The ids, none read before.
     *
     * @return array<int, string> Id => name, only what the list returns.
     *
     * @since  2.3.0
     */
    private static function read(string $type, array $ids): array
    {
        $found = [];

        if ($type === self::LABEL) {
            foreach (array_intersect_key(Labels::predefined(), array_flip($ids)) as $id => $label) {
                $found[(int) $id] = (string) $label->name;
            }

            return $found;
        }

        $category = $type === self::CATEGORY;
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel($category ? 'Categories' : 'Manufacturers', 'Site', ['ignore_request' => true]);

        $model->setState($category ? 'filter.categories' : 'filter.manufacturers', $ids);
        $model->setState('list.ordering', 'name');
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);

        foreach ($model->getItems() ?: [] as $row) {
            $found[(int) $row->id] = (string) ($row->name ?? '');
        }

        return $found;
    }
}
