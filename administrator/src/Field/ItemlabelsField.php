<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Catalog\LabelCorner;
use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * The product's Labels tab's tick table: a row per label of the Labels screen, in their order, each with a tick (the
 * product shows it) and a corner of the image for this product ('' = the label's own). The value is by label id:
 * {on, corner} (ItemLabels::formValue(), ItemLabels::posted()).
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
     * The layout's data: the rows (id, name, colours, the label's own corner, its state), the corners to choose from,
     * and the value by label id.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    protected function getLayoutData()
    {
        $rows = [];

        foreach (Labels::all() as $id => $record) {
            $rows[] = [
                'id' => (int) $id,
                'name' => $record->label->name,
                'bg' => $record->label->bg,
                'color' => $record->label->color,
                'corner' => $record->label->corner->text(),
                'published' => $record->state === 1,
            ];
        }

        $corners = [];

        foreach (LabelCorner::cases() as $corner) {
            $corners[$corner->value] = Text::_($corner->text());
        }

        $value = [];

        foreach ((array) $this->value as $labelId => $choice) {
            $choice = (array) $choice;
            $value[(int) $labelId] = ['on' => (int) !empty($choice['on']), 'corner' => (string) ($choice['corner'] ?? '')];
        }

        return array_merge(parent::getLayoutData(), [
            'rows' => $rows,
            'corners' => $corners,
            'value' => $value,
        ]);
    }
}
