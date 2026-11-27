<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;

/**
 * The period of a price row, as the editor shows it and as the table stores it.
 *
 * A row's "Active from" and "Active until" are two Joomla calendar fields, declared here once. Rendered, they show
 * the stored UTC moment in the user's time zone; posted, the typed text comes back through the same fields'
 * filtering, read in the user's time zone and stored as UTC. The item form posts its rows inside one field
 * (jform[prices]) that Joomla does not filter row by row, and the variant window posts its rows outside any form, so
 * the two controllers hand each posted row to stored() before the model reads it. The REST API never passes here:
 * it sends UTC text, which the stores take as it is.
 *
 * @since  2.3.0
 */
final class PriceRowDates
{
    /**
     * The row's two calendar fields, bound to its stored dates, for rendering.
     *
     * @param string $control The row's POST base, e.g. jform[prices][0]; the fields are named under it.
     * @param array $row The row as stored (publish_up and publish_down as UTC text, or empty).
     *
     * @since  2.3.0
     */
    public static function form(string $control, array $row): Form
    {
        $form = new Form('com_alfa.pricerow.' . md5($control), ['control' => $control]);
        $form->load(self::definition());
        $form->bind([
            'publish_up' => self::shown(value: $row['publish_up'] ?? null),
            'publish_down' => self::shown(value: $row['publish_down'] ?? null),
        ]);

        return $form;
    }

    /**
     * The posted row with its two dates as the table stores them: the typed text read in the user's time zone and
     * stored as UTC (Y-m-d H:i:s); an empty date stays null.
     *
     * @param array $row The posted row.
     *
     * @return array The row, its publish_up and publish_down replaced.
     *
     * @since  2.3.0
     */
    public static function stored(array $row): array
    {
        $form = new Form('com_alfa.pricerow.posted');
        $form->load(self::definition());
        $filtered = $form->filter([
            'publish_up' => self::typed(value: $row['publish_up'] ?? null),
            'publish_down' => self::typed(value: $row['publish_down'] ?? null),
        ]);

        foreach (['publish_up', 'publish_down'] as $column) {
            $value = $filtered[$column] ?? '';
            $row[$column] = \is_string($value) && $value !== '' ? $value : null;
        }

        return $row;
    }

    /**
     * The two fields: a date and time in the user's time zone, labelled for the row's Active cell.
     *
     * @since  2.3.0
     */
    private static function definition(): string
    {
        return '<form>
    <field name="publish_up" type="calendar" showtime="true" format="%Y-%m-%d %H:%M" filter="USER_UTC" hiddenLabel="true" label="COM_ALFA_PRICES_FROM"/>
    <field name="publish_down" type="calendar" showtime="true" format="%Y-%m-%d %H:%M" filter="USER_UTC" hiddenLabel="true" label="COM_ALFA_PRICES_TO"/>
</form>';
    }

    /**
     * A stored date for the field: the null date of an old row shows as no date.
     *
     * @since  2.3.0
     */
    private static function shown(mixed $value): string
    {
        $value = \is_scalar($value) ? (string) $value : '';

        return str_starts_with($value, '0000') ? '' : $value;
    }

    /**
     * A posted date for the filter: text as typed, anything else as no date.
     *
     * @since  2.3.0
     */
    private static function typed(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
