<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemLabels;
use Alfa\Component\Alfa\Site\Service\Catalog\LabelCorner;
use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Alfa\Component\Alfa\Site\Service\Catalog\NewProducts;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * The product's Labels tab's first field: a row per label the product may show, the automatic New label first and
 * then the shop's predefined labels (Options > Labels) in their order, each with a tick (shown on this product) and a
 * corner of the image for this product ('' = the label's own). The value is by key: shown and corner
 * (ItemLabels::formValue(), ItemLabels::posted()).
 *
 *   <field name="labels" type="itemlabels" filter="array" label="..." />
 *
 * @since  2.3.0
 */
class ItemlabelsField extends FormField
{
    /**
     * The field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'Itemlabels';

    /**
     * The layout: a table of the labels, their ticks and their corners.
     *
     * @var string
     * @since  2.3.0
     */
    protected $layout = 'form.field.itemlabels';

    /**
     * Where the layout is found: the component's layouts first, then the default paths.
     *
     * @return string[]
     *
     * @since  2.3.0
     */
    protected function getLayoutPaths()
    {
        return array_merge([JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'], parent::getLayoutPaths());
    }

    /**
     * The input: the layout.
     *
     * @return string
     *
     * @since  2.3.0
     */
    protected function getInput()
    {
        return $this->getRenderer($this->layout)->render($this->getLayoutData());
    }

    /**
     * The layout's data: the rows (key, name, kind, colours, the label's own corner, whether it is shown by default),
     * the corners to choose from, the shop's days, and the value by key.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    protected function getLayoutData()
    {
        $rows = [];
        $new = Labels::newLabel();
        $rows[] = [
            'key' => ItemLabels::KEY_NEW,
            'name' => $new?->name ?? Labels::NEW_NAME,
            'kind' => 'new',
            'bg' => $new?->bg ?? '',
            'color' => $new?->color ?? '',
            'corner' => ($new?->corner ?? LabelCorner::TopLeft)->text(),
            'defaultShown' => 1,
        ];

        foreach (Labels::predefined() as $key => $label) {
            $rows[] = [
                'key' => $key,
                'name' => $label->name,
                'kind' => 'predefined',
                'bg' => $label->bg,
                'color' => $label->color,
                'corner' => $label->corner->text(),
                'defaultShown' => 0,
            ];
        }

        $corners = [];

        foreach (LabelCorner::cases() as $corner) {
            $corners[$corner->value] = Text::_($corner->text());
        }

        $value = [];

        foreach ((array) $this->value as $key => $choice) {
            $choice = (array) $choice;
            $value[(string) $key] = ['shown' => (int) !empty($choice['shown']), 'corner' => (string) ($choice['corner'] ?? '')];
        }

        return array_merge(parent::getLayoutData(), [
            'rows' => $rows,
            'corners' => $corners,
            'days' => NewProducts::days(),
            'value' => $value,
        ]);
    }
}
