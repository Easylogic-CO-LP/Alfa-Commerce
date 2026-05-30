<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Toolsmedia;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools → Media maintenance view: review and bulk-delete orphan / missing-file
 * media rows.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * Active listing mode: 'rows' (database records) or 'files' (untracked
     * upload files). Drives both the model method used and the template layout.
     *
     * @var  string
     */
    public string $mode = 'rows';

    /**
     * @param   string|null  $tpl
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function display($tpl = null): void
    {
        if (!Factory::getApplication()->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $model = $this->getModel();

        $this->state = $model->getState();
        $this->mode  = $this->state->get('filter.source') === 'files' ? 'files' : 'rows';

        // The two modes read different sources; the view picks the model method.
        // Pagination is the inherited list pagination (getTotal() is mode-aware).
        $this->items         = $this->mode === 'files'
            ? $model->getUntrackedMediaItems()
            : $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        // Hidden control fields rendered at the bottom of the form.
        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * @return  void
     *
     * @since   1.0.1
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_TOOLS_MEDIA'), 'images');

        $toolbar = $this->getDocument()->getToolbar();

        // Back to Tools — first.
        $toolbar->link(Text::_('COM_ALFA_TOOLS_BACK'), 'index.php?option=com_alfa&view=tools')
            ->icon('icon-arrow-left');

        // Bulk shortcuts apply to database rows only (every matching row across
        // all pages); they make no sense for the filesystem listing.
        if ($this->mode === 'rows') {
            $toolbar->popupButton('delete-orphans', 'COM_ALFA_TOOLSMEDIA_DELETE_ALL_ORPHANS')
                ->icon('icon-unlink')
                ->buttonClass('btn btn-warning')
                ->textHeader(Text::_('COM_ALFA_TOOLSMEDIA_DELETE_ORPHANS_HEADER'))
                ->popupType('inline')
                ->url('#joomla-dialog-delete-orphans')
                ->modalWidth('600px')
                ->modalHeight('fit-content');

            $toolbar->popupButton('delete-missing', 'COM_ALFA_TOOLSMEDIA_DELETE_ALL_MISSING')
                ->icon('icon-warning')
                ->buttonClass('btn btn-warning')
                ->textHeader(Text::_('COM_ALFA_TOOLSMEDIA_DELETE_MISSING_HEADER'))
                ->popupType('inline')
                ->url('#joomla-dialog-delete-missing')
                ->modalWidth('600px')
                ->modalHeight('fit-content');
        }

        // Selected delete — styled popup (the dialog body adapts its wording to
        // the current mode). Always permanently removes the file(s) from disk. Last.
        $toolbar->popupButton('delete-confirm', 'JTOOLBAR_DELETE')
            ->icon('icon-trash')
            ->buttonClass('btn btn-danger')
            ->textHeader(Text::_('COM_ALFA_TOOLSMEDIA_DELETE_HEADER'))
            ->popupType('inline')
            ->url('#joomla-dialog-delete')
            ->modalWidth('600px')
            ->modalHeight('fit-content')
            ->listCheck(true);
    }
}
