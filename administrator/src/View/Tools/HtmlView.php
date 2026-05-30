<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Tools;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\PackageHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools / maintenance view — developer & sync utilities (language-table resync,
 * user/group resync, chunked translation backfill, package export).
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Libraries declared in the manifest, for the package-export card's "add these
     * manually for an installable build" note. Each entry: folder, libraryname,
     * installCode, installManifest.
     *
     * @var    array<int, array{folder: string, libraryname: string, installCode: string, installManifest: string}>
     * @since  1.0.3
     */
    public array $libraries = [];

    /**
     * Render the Tools page.
     *
     * @param   string|null  $tpl  The template name.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function display($tpl = null): void
    {
        // Gate the whole view — menu hiding is cosmetic, this is the real boundary.
        if (!Factory::getApplication()->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->libraries = PackageHelper::describeLibraries(installRoot: JPATH_ROOT);

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Build the toolbar.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_TOOLS'), 'wrench');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->preferences('com_alfa');
    }
}
