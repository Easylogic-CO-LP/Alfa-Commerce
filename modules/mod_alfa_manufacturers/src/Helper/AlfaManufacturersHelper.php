<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaManufacturers\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What the manufacturers module shows, read through the shop's own list of manufacturers.
 *
 * The module drives the component's site Manufacturers model, so what a visitor sees here is what the shop's
 * Manufacturers page shows them: published manufacturers (also the unpublished ones for whoever may edit them), names in
 * the page's language, the logos and the links the shop builds. Nothing here queries a table.
 *
 * @since  2.3.0
 */
final class AlfaManufacturersHelper
{
    /**
     * What an entry shows: its logo and its name, its logo only, or its name only.
     *
     * @var string[]
     * @since 2.3.0
     */
    public const SHOW = ['logo_name', 'logo', 'name'];

    /**
     * Where an entry leads: the shop's product listing filtered by the manufacturer, or the manufacturer's own page.
     *
     * @var string[]
     * @since 2.3.0
     */
    public const LINK = ['products', 'page'];

    /**
     * The order of the entries, as the setting names it, and the list ordering the model is handed for it.
     *
     * @var array<string, string>
     * @since 2.3.0
     */
    public const ORDERING = ['name' => 'name', 'ordering' => 'a.ordering'];

    /**
     * The entries the module draws, in its order and within its limit.
     *
     * @param Registry $params The module's settings.
     *
     * @return list<object{id: int, name: string, link: string, logo: string}> One entry per manufacturer; empty when
     *                                                                           the shop shows none of the chosen ones.
     *
     * @since  2.3.0
     */
    public static function entries(Registry $params): array
    {
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Manufacturers', 'Site', ['ignore_request' => true]);

        $chosen = array_values(array_filter(
            array_map('intval', (array) $params->get('manufacturers', [])),
            static fn (int $id): bool => $id > 0,
        ));

        if ($chosen !== []) {
            $model->setState('filter.manufacturers', $chosen);
        }

        $model->setState('list.ordering', self::ORDERING[self::choice(params: $params, name: 'ordering', choices: array_keys(self::ORDERING))]);
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', 0);
        $model->setState('list.limit', max(0, (int) $params->get('limit', 0)));

        $toPage = self::choice(params: $params, name: 'link', choices: self::LINK) === 'page';
        $entries = [];

        foreach ($model->getItems() ?: [] as $row) {
            $entries[] = (object) [
                'id' => (int) $row->id,
                'name' => (string) ($row->name ?? ''),
                'link' => (string) ($toPage ? $row->details_link : $row->link),
                'logo' => (string) ($row->medias[0]->url ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * One setting's value among its choices; a value the form never offers reads as the first choice, the default.
     *
     * @param Registry $params The module's settings.
     * @param string $name The setting.
     * @param string[] $choices Its choices, the default first.
     *
     * @since  2.3.0
     */
    public static function choice(Registry $params, string $name, array $choices): string
    {
        $value = (string) $params->get($name, $choices[0]);

        return \in_array($value, $choices, true) ? $value : $choices[0];
    }
}
