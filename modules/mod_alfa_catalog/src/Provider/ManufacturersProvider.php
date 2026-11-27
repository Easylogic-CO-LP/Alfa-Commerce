<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Provider;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The manufacturers of a tab: chosen ones, or every manufacturer the shop shows.
 *
 * Read through the component's Manufacturers model, so what a visitor sees here is what the shop's Manufacturers page
 * shows them: published manufacturers, names in the page's language, the logos and the links the shop builds. One
 * read for the manufacturers and one for their logos, whatever their number. The optional product counts are one
 * more read for all of them together: the catalogue's own listing query (the products this visitor may see and the
 * stock policy shows), counted per manufacturer.
 *
 * @since  2.3.0
 */
final class ManufacturersProvider implements ProviderInterface
{
    /**
     * @var string[] What an entry shows: its logo and its name, its logo only, or its name only.
     * @since  2.3.0
     */
    public const SHOW = ['logo_name', 'logo', 'name'];

    /**
     * @var string[] Where an entry leads: the catalogue filtered by the manufacturer, or the manufacturer's own page.
     * @since  2.3.0
     */
    public const LINK = ['products', 'page'];

    /**
     * @var array<string, string> The order, as the setting names it, and the list ordering the model is handed for it.
     * @since  2.3.0
     */
    public const ORDERING = ['name' => 'name', 'ordering' => 'a.ordering'];

    /**
     * @var int The most manufacturers a tab shows; a stored 0 means this many.
     * @since  2.3.0
     */
    public const LIMIT_MAX = 200;

    /**
     * A manufacturers tab's query.
     *
     * @param array $row The stored tab row.
     * @param string $display The tab's presentation.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public function query(array $row, string $display): array
    {
        return [
            'ids' => Providers::ids($row['man_ids'] ?? []),
            'ordering' => Providers::choice($row['man_ordering'] ?? '', array_keys(self::ORDERING)),
            'limit' => Providers::bounded($row['man_limit'] ?? null, 0, self::LIMIT_MAX, 0),
            'show' => Providers::choice($row['man_show'] ?? '', self::SHOW),
            'link' => Providers::choice($row['man_link'] ?? '', self::LINK),
            'counts' => (int) ($row['man_show_count'] ?? 0) === 1,
        ];
    }

    /**
     * The manufacturers of a tab: one object per manufacturer with its id, name, link, logo ('' when it has none),
     * the logo's description and its product count (null when counts are off).
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function records(array $query, Registry $params): array
    {
        $limit = (int) ($query['limit'] ?? 0);
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Manufacturers', 'Site', ['ignore_request' => true]);

        $chosen = Providers::ids($query['ids'] ?? []);

        if ($chosen !== []) {
            $model->setState('filter.manufacturers', $chosen);
        }

        $model->setState('list.ordering', self::ORDERING[Providers::choice($query['ordering'] ?? '', array_keys(self::ORDERING))]);
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', 0);
        $model->setState('list.limit', $limit > 0 ? min($limit, self::LIMIT_MAX) : self::LIMIT_MAX);

        $rows = $model->getItems() ?: [];
        $toPage = ($query['link'] ?? '') === 'page';
        $productCounts = !empty($query['counts']) && $rows !== []
            ? self::productCounts(array_map(static fn (object $row): int => (int) $row->id, $rows))
            : null;
        $records = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $name = (string) ($row->name ?? '');
            $media = $row->medias[0] ?? null;

            $records[] = (object) [
                'id' => $id,
                'name' => $name,
                'link' => (string) ($toPage ? $row->details_link : $row->link),
                'image' => (string) ($media->url ?? ''),
                // A logo always says whose it is: its own description, else the manufacturer's name.
                'alt' => trim((string) ($media->alt ?? '')) ?: $name,
                'count' => $productCounts === null ? null : (int) ($productCounts[$id] ?? 0),
            ];
        }

        return $records;
    }

    /**
     * A stored result is one page's (a link follows the page's menu item).
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings.
     *
     * @return array<int, mixed>
     *
     * @since  2.3.0
     */
    public function varies(array $query, Registry $params): ?array
    {
        return [Factory::getApplication()->getInput()->getInt('Itemid', 0)];
    }

    /**
     * How many products of the catalogue each of these manufacturers has, for this visitor, in one read: the
     * listing model's own query, with its visibility and stock rules, grouped by manufacturer.
     *
     * @param int[] $ids The manufacturers.
     *
     * @return array<int, int> Manufacturer id => products; a manufacturer with none is absent.
     *
     * @since  2.3.0
     */
    private static function productCounts(array $ids): array
    {
        /** @var \Alfa\Component\Alfa\Site\Model\ItemsModel $model */
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Items', 'Site', ['ignore_request' => true]);

        $model->getState('list.ordering');
        $model->setState('filter.manufacturer', Providers::ids($ids));
        $model->setState('list.limit', 0);

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $model->getListQuery();
        $query->clear('select')
            ->clear('order')
            ->clear('group')
            ->select($db->quoteName('man.manufacturer_id') . ', COUNT(DISTINCT ' . $db->quoteName('a.id') . ') AS ' . $db->quoteName('cnt'))
            ->group($db->quoteName('man.manufacturer_id'));

        $counts = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $counts[(int) $row['manufacturer_id']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
