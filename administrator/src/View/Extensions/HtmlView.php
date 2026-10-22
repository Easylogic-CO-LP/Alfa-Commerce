<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Extensions;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataExtensions;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataStatus;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools > Extensions: the Alfa extensions installed apart from Alfa Commerce, each with its type, version, state and
 * what Clear Data can do with it, and the three actions as toolbar dialogs on the selected rows: Clear Data, Remove
 * extension only, Remove extension and its data. A plugin without the hook shows "Automatic Clear Data not supported";
 * a module shows nothing of Clear Data. Opening the page boots each plugin to ask whether it has the hook, which
 * changes nothing.
 *
 * @since  2.1.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The rows: each listing entry with its name, its support, its description and its last Clear Data; a package's
     * extensions in `children`, the same way.
     *
     * @var list<array<string, mixed>>
     *
     * @since  2.1.0
     */
    public array $rows = [];

    /**
     * Whether the user may clear an extension's data.
     *
     * @since  2.1.0
     */
    public bool $mayClear = false;

    /**
     * Whether the user may remove an extension.
     *
     * @since  2.1.0
     */
    public bool $mayRemove = false;

    /**
     * Render the page.
     *
     * @param string|null $tpl The template name.
     *
     * @since  2.1.0
     */
    public function display($tpl = null): void
    {
        $user = Factory::getApplication()->getIdentity();

        if (!$user->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $lister = new ClearDataExtensions();

        // A mark left by a request that died inside an extension's hook becomes cleanup failed, with the reason.
        $lister->recoverStale();

        // Each plugin booted under the lock, marked while it loads: one whose code cannot be loaded is shown so.
        $rows = $lister->inspect(entries: $lister->rows());
        $statuses = ClearDataStatus::all();
        $last = static fn (array $entry): ?array => \is_array($statuses[$entry['key']] ?? null) && isset($statuses[$entry['key']]['status']) ? $statuses[$entry['key']] : null;

        foreach ($rows as $row) {
            $row['last'] = $last($row);
            $row['children'] = array_map(static fn (array $child): array => $child + ['last' => $last($child)], (array) $row['children']);
            $this->rows[] = $row;
        }

        $this->mayClear = ClearDataExtensions::permitted(user: $user);
        $this->mayRemove = ClearDataExtensions::mayRemove(user: $user);
        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * The toolbar: back to Tools, then the actions this user may take, each a dialog on the selected rows, the
     * strongest last.
     *
     * @since  2.1.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_EXTENSIONS_TITLE'), 'generic');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->link(Text::_('COM_ALFA_TOOLS_BACK'), 'index.php?option=com_alfa&view=tools')
            ->icon('icon-arrow-left');

        $actions = [
            ['clear', 'COM_ALFA_EXTENSIONS_CLEAR_DATA', 'COM_ALFA_EXTENSIONS_CLEAR_HEADER', 'btn btn-danger', $this->mayClear],
            ['remove', 'COM_ALFA_EXTENSIONS_REMOVE', 'COM_ALFA_EXTENSIONS_REMOVE_HEADER', 'btn btn-warning', $this->mayRemove],
            ['removedata', 'COM_ALFA_EXTENSIONS_REMOVE_WITH_DATA', 'COM_ALFA_EXTENSIONS_REMOVE_WITH_DATA_HEADER', 'btn btn-danger', $this->mayClear && $this->mayRemove],
        ];

        foreach ($actions as [$id, $label, $header, $class, $allowed]) {
            if (!$allowed || $this->rows === []) {
                continue;
            }

            $toolbar->popupButton('extensions-' . $id, $label)
                ->icon('icon-trash')
                ->buttonClass($class)
                ->textHeader(Text::_($header))
                ->popupType('inline')
                ->url('#joomla-dialog-extensions-' . $id)
                ->modalWidth('600px')
                ->modalHeight('fit-content')
                ->listCheck(true);
        }

        NotificationHelper::toolbarBadge($toolbar);
    }
}
