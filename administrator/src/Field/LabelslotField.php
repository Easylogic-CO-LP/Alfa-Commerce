<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Where a label sits: the slots a badge may take on a catalogue card (context="list", ItemListSurface) or on the
 * product page (context="item", ItemSurface), the same list wherever a label or a discount's sale tag is set up. The
 * slots of a payment or shipment method's own part are not offered: a badge has no place among the methods.
 *
 *   <field name="list_slot" type="labelslot" context="list" default="image" label="..." />
 *   <field name="item_slot" type="labelslot" context="item" default="image" label="..." />
 *
 * @since  2.3.0
 */
class LabelslotField extends ListField
{
    /**
     * The field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'Labelslot';

    /**
     * The language key of each slot's name, by slot value.
     *
     * @since  2.3.0
     */
    private const TEXTS = [
        'image' => 'COM_ALFA_DISCOUNT_TAG_SLOT_IMAGE',
        'title' => 'COM_ALFA_DISCOUNT_TAG_SLOT_TITLE',
        'buy-form' => 'COM_ALFA_DISCOUNT_TAG_SLOT_BUY_FORM',
        'short-description' => 'COM_ALFA_DISCOUNT_TAG_SLOT_SHORT_DESC',
        'full-description' => 'COM_ALFA_DISCOUNT_TAG_SLOT_FULL_DESC',
        'near-add-to-cart' => 'COM_ALFA_DISCOUNT_TAG_SLOT_NEAR_CART',
        'specifications' => 'COM_ALFA_DISCOUNT_TAG_SLOT_SPECS',
    ];

    /**
     * The options: the context's slots that have a place for a badge, the image first.
     *
     * @return object[]
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();
        $cases = (string) ($this->element['context'] ?? '') === 'item' ? ItemSurface::cases() : ItemListSurface::cases();
        $slots = array_filter(array_map(static fn (ItemSurface|ItemListSurface $case): string => $case->value, $cases), static fn (string $slot): bool => isset(self::TEXTS[$slot]));
        usort($slots, static fn (string $a, string $b): int => array_search($a, array_keys(self::TEXTS), true) <=> array_search($b, array_keys(self::TEXTS), true));

        foreach ($slots as $slot) {
            $options[] = HTMLHelper::_('select.option', $slot, Text::_(self::TEXTS[$slot]));
        }

        return $options;
    }
}
