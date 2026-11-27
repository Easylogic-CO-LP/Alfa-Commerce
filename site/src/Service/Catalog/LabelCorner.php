<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Catalog;

\defined('_JEXEC') or die;

/**
 * The corner of the image a label sits in when its place is the image, on a catalogue card and on the product page.
 * Every label has one, a discount's sale tag too, and the labels of one corner stack one under the other there
 * (the item.labels layout). Top left when none was chosen.
 *
 * @since  2.3.0
 */
enum LabelCorner: string
{
    case TopLeft = 'top-left';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomRight = 'bottom-right';

    /**
     * The corner a stored value names; top left for an empty value, or one that names no corner.
     *
     * @param string|null $value The stored value.
     *
     * @since  2.3.0
     */
    public static function of(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::TopLeft;
    }

    /**
     * The language key of the corner's name, for the forms.
     *
     * @since  2.3.0
     */
    public function text(): string
    {
        return 'COM_ALFA_LABEL_CORNER_' . strtoupper(str_replace('-', '_', $this->value));
    }
}
