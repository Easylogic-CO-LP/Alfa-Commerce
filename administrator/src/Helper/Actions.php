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
 * Template-side one-liners for rendering plugin-declared list actions: the {@see Columns}
 * counterpart for onAlfaAdminListRowActions and onAlfaAdminListBulkActions. A list template
 * hosts them with three flush-mounted calls:
 *
 *   ...last core <th>...<?php echo Actions::head($this->pluginRowActions ?? []); ?>
 *   ...last core <td>...<?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>
 *   ...above the table: <?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>
 *
 * Everything returns '' when no plugin contributed, so a site with no contributors renders
 * byte-identically. The footer colspan adds {@see self::span()}.
 *
 * A TASK action submits the screen's own adminForm: Joomla.listItemTask checks the row's
 * box and posts, so the write arrives with the CSRF token the screen already carries; a bulk
 * task refuses to submit with nothing checked, exactly like a native listCheck toolbar
 * button. A URL action navigates and must never change state. An action with a layout
 * renders through its owner plugin's layout with its per-row resolver data (null = nothing
 * for that row).
 *
 * @since  2.0.0
 */
final class Actions
{
    /**
     * The header cell of the row-actions column ('' when no actions).
     *
     * @param array[] $actions The row-action descriptors (view harvest).
     *
     *
     * @since  2.0.0
     */
    public static function head(array $actions): string
    {
        if ($actions === []) {
            return '';
        }

        return '<th scope="col" class="w-5 text-end">'
            . htmlspecialchars(Text::_('COM_ALFA_LIST_ROW_ACTIONS'), ENT_QUOTES, 'UTF-8')
            . '</th>';
    }

    /**
     * How many columns the actions add to the table, for the footer colspan.
     *
     * @param array[] $actions The row-action descriptors.
     *
     * @return int 0 or 1.
     *
     * @since  2.0.0
     */
    public static function span(array $actions): int
    {
        return $actions === [] ? 0 : 1;
    }

    /**
     * One row's action cell ('' when no actions were contributed at all; the table stays
     * rectangular because every row then omits the cell together with the header).
     *
     * @param array[] $actions The row-action descriptors.
     * @param int $i The row index (the grid checkbox is "cb$i").
     * @param object $row The list row.
     *
     *
     * @since  2.0.0
     */
    public static function cell(array $actions, int $i, object $row): string
    {
        if ($actions === []) {
            return '';
        }

        $html = '<td class="text-end"><div class="btn-group btn-group-sm" role="group">';

        foreach ($actions as $action) {
            // A layout-rendered action: its resolver decides per row; null renders nothing.
            if ($action['layout'] !== '') {
                $data = $action['data'] instanceof Closure ? ($action['data'])($row) : null;

                if ($data !== null) {
                    $html .= Layout::render(
                        $action['layout'],
                        $data,
                        $action['layoutPluginType'],
                        $action['layoutPluginName'],
                    );
                }

                continue;
            }

            $title = htmlspecialchars(Text::_($action['title']), ENT_QUOTES, 'UTF-8');
            $icon = $action['icon'] !== ''
                ? '<span class="' . htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></span>'
                : '';
            $css = htmlspecialchars(trim('btn btn-outline-secondary ' . $action['cssClass']), ENT_QUOTES, 'UTF-8');

            if ($action['task'] !== '') {
                $js = "return Joomla.listItemTask('cb" . (int) $i . "', '" . addslashes($action['task']) . "')";

                if ($action['confirm'] !== '') {
                    $js = "if (!confirm('" . addslashes(Text::_($action['confirm'])) . "')) return false; " . $js;
                }

                $html .= '<button type="button" class="' . $css . '" title="' . $title . '"'
                    . ' onclick="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '">'
                    . $icon . '<span class="visually-hidden">' . $title . '</span></button>';

                continue;
            }

            // URL action: navigation only.
            $html .= '<a class="' . $css . '" title="' . $title . '"'
                . ' href="' . htmlspecialchars($action['url'], ENT_QUOTES, 'UTF-8') . '">'
                . $icon . '<span class="visually-hidden">' . $title . '</span></a>';
        }

        return $html . '</div></td>';
    }

    /**
     * The bulk-actions bar rendered above the grid ('' when none): labelled buttons that
     * act on the CHECKED rows through the screen's own adminForm, refusing to submit with
     * nothing selected, exactly like a native listCheck toolbar button.
     *
     * @param array[] $actions The bulk-action descriptors (view harvest).
     *
     *
     * @since  2.0.0
     */
    public static function bulkBar(array $actions): string
    {
        if ($actions === []) {
            return '';
        }

        $select = addslashes(Text::_('JLIB_HTML_PLEASE_MAKE_A_SELECTION_FROM_THE_LIST'));
        $html = '<div class="btn-toolbar gap-2 mb-2" role="toolbar" aria-label="'
            . htmlspecialchars(Text::_('COM_ALFA_LIST_BULK_ACTIONS'), ENT_QUOTES, 'UTF-8') . '">';

        foreach ($actions as $action) {
            // A layout-rendered bulk action draws its own control. There is no row here, so
            // the per-row data resolver does not apply; the layout receives the descriptor
            // itself and submits through the adminForm like any other bulk control.
            if ($action['layout'] !== '') {
                $html .= Layout::render(
                    $action['layout'],
                    $action,
                    $action['layoutPluginType'],
                    $action['layoutPluginName'],
                );

                continue;
            }

            $title = htmlspecialchars(Text::_($action['title']), ENT_QUOTES, 'UTF-8');
            $icon = $action['icon'] !== ''
                ? '<span class="' . htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></span> '
                : '';
            $css = htmlspecialchars(trim('btn btn-sm btn-outline-primary ' . $action['cssClass']), ENT_QUOTES, 'UTF-8');

            if ($action['task'] !== '') {
                $js = "if (document.adminForm.boxchecked.value == 0) { alert('" . $select . "'); return false; } ";

                if ($action['confirm'] !== '') {
                    $js .= "if (!confirm('" . addslashes(Text::_($action['confirm'])) . "')) return false; ";
                }

                $js .= "Joomla.submitbutton('" . addslashes($action['task']) . "'); return false;";

                $html .= '<button type="button" class="' . $css . '"'
                    . ' onclick="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '">'
                    . $icon . $title . '</button>';

                continue;
            }

            $html .= '<a class="' . $css . '" href="' . htmlspecialchars($action['url'], ENT_QUOTES, 'UTF-8') . '">'
                . $icon . $title . '</a>';
        }

        return $html . '</div>';
    }
}
