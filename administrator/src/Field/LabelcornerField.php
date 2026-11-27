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
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The corner of the image a label sits in: the four corners (LabelCorner), the same list wherever a label or a
 * discount's sale tag is set up. With none="LANGUAGE_KEY" an empty choice leads, for a product's choice over a
 * label's own corner.
 *
 *   <field name="corner" type="labelcorner" default="top-left" label="..." />
 *   <field name="corner" type="labelcorner" none="COM_ALFA_LABEL_CORNER_AS_LABEL" label="..." />
 *
 * @since  2.3.0
 */
class LabelcornerField extends ListField
{
    /**
     * The field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'Labelcorner';

    /**
     * The options: the empty choice when asked for, then the four corners.
     *
     * @return object[]
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();
        $none = trim((string) ($this->element['none'] ?? ''));

        if ($none !== '') {
            $options[] = HTMLHelper::_('select.option', '', Text::_($none));
        }

        foreach (LabelCorner::cases() as $corner) {
            $options[] = HTMLHelper::_('select.option', $corner->value, Text::_($corner->text()));
        }

        return $options;
    }
}
