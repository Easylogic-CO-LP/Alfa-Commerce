<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Closure;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Template-side one-liners for rendering plugin-declared list columns: the
 * admin list counterpart of the storefront Surface helper. Any list template
 * hosts plugin columns with:
 *
 *   $byAnchor = Columns::prepare($this->pluginColumns, ['id', 'customer', ...]);
 *   ...
 *   <th>...core...</th><?php echo Columns::heads($byAnchor, 'payment'); ?>
 *   <td>...core...</td><?php echo Columns::cells($byAnchor, 'payment', $row); ?>
 *   ...after the last core column: heads/cells with Columns::END.
 *
 * prepare() groups descriptors by their anchor and folds unknown or unset
 * anchors into END, so a mistyped anchor still renders (at the end) and the
 * table stays rectangular. A cell whose data resolver returns null renders
 * empty: every row always emits every cell.
 *
 * @since  1.3.0
 */
final class Columns
{
    /**
     * The trailing anchor: columns rendered after the last core column.
     *
     * @var string
     *
     * @since  1.3.0
     */
    public const END = '';

    /**
     * Group column descriptors by anchor, folding unset/unknown anchors into
     * END. Call once per template render.
     *
     * @param array[] $columns The plugin column descriptors (model harvest).
     * @param string[] $anchors The screen's core column keys, in render order.
     *
     * @return array<string, array[]> anchor => descriptors.
     *
     * @since  1.3.0
     */
    public static function prepare(array $columns, array $anchors): array
    {
        $byAnchor = [];

        foreach ($columns as $column) {
            $anchor = \in_array($column['after'], $anchors, true) ? $column['after'] : self::END;
            $byAnchor[$anchor][] = $column;
        }

        return $byAnchor;
    }

    /**
     * The <th> cells of every column anchored at $anchor.
     *
     * @param array<string, array[]> $byAnchor The prepare() map.
     * @param string $anchor A core column key, or Columns::END.
     *
     * @return string The rendered header cells ('' when none).
     *
     * @since  1.3.0
     */
    public static function heads(array $byAnchor, string $anchor): string
    {
        $html = '';

        foreach ($byAnchor[$anchor] ?? [] as $column) {
            $html .= '<th class="text-center">' . Text::_($column['title']) . '</th>';
        }

        return $html;
    }

    /**
     * The <td> cells of every column anchored at $anchor, for one row. The
     * cell body renders the column's layout via Layout::render with the data
     * its resolver returns for the row; null renders an empty cell.
     *
     * @param array<string, array[]> $byAnchor The prepare() map.
     * @param string $anchor A core column key, or Columns::END.
     * @param object $row The list row.
     *
     * @return string The rendered cells ('' when none).
     *
     * @since  1.3.0
     */
    public static function cells(array $byAnchor, string $anchor, object $row): string
    {
        $html = '';

        foreach ($byAnchor[$anchor] ?? [] as $column) {
            $data = $column['data'] instanceof Closure ? ($column['data'])($row) : null;

            $html .= '<td class="text-center">';

            if ($data !== null) {
                $html .= Layout::render(
                    $column['layout'],
                    $data,
                    $column['layoutPluginType'],
                    $column['layoutPluginName'],
                );
            }

            $html .= '</td>';
        }

        return $html;
    }
}
