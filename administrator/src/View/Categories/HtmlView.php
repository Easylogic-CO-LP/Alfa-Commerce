<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Categories;
// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Toolbar\Toolbar;
use \Joomla\CMS\Toolbar\ToolbarHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\Component\Content\Administrator\Extension\ContentComponent;
use \Joomla\CMS\Form\Form;
use \Joomla\CMS\HTML\Helpers\Sidebar;

/**
 * View class for a list of Categories.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    protected $items;

    protected $pagination;

    protected $state;

    protected $sortedNames = array();

    protected $nestedNames = array();

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     *
     * @throws Exception
     */
    public function display($tpl = null)
    {
        $this->state = $this->get('State');
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->filterForm = $this->get('FilterForm');
        $this->activeFilters = $this->get('ActiveFilters');

        // Check for errors.
        if (count($errors = $this->get('Errors'))) {
            throw new \Exception(implode("\n", $errors));
        }

        // Modify names for nested categories
        // Create associative array based on names
        $nestedCategoryNames = AlfaHelper::buildNestedArray($this->items);
        AlfaHelper::iterateNestedArray($nestedCategoryNames, function ($node, $fullPath) {
            $this->sortedNames[] = $node->name; // Collect names
            $this->nestedNames[$node->name] = $fullPath; // Map names to full paths
        }, false);

        // Create a mapping from names to objects
        $nameToObjectMap = [];
        foreach ($this->items as $item) {
            $nameToObjectMap[$item->name] = $item;
        }

        // Create a new array based on the sorted names
        $sortedItems = [];
        foreach ($this->sortedNames as $name) {
            if (isset($nameToObjectMap[$name])) {
                $sortedItems[] = $nameToObjectMap[$name];
            }
        }

        // Map sorted items to include full path as names
        $fullPathNames = [];
        foreach ($this->sortedNames as $name) {
            if (isset($this->nestedNames[$name])) {
                $fullPathNames[$name] = $this->nestedNames[$name];
            }
        }

        // Update names in sorted items with full paths
        foreach ($sortedItems as $item) {
            $item->name = $fullPathNames[$item->name];
        }

        // Assign sorted items back
        $this->items = $sortedItems;

        $this->addToolbar();

        $this->sidebar = Sidebar::render();
        parent::display($tpl);
    }

    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    protected function addToolbar()
    {
        $state = $this->get('State');
        $canDo = AlfaHelper::getActions();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_CATEGORIES'), "generic");

        $toolbar = Toolbar::getInstance('toolbar');

        // Check if the form exists before showing the add/edit buttons
        $formPath = JPATH_COMPONENT_ADMINISTRATOR . '/src/View/Categories';

        if (file_exists($formPath)) {
            if ($canDo->get('core.create')) {
                $toolbar->addNew('category.add');
            }
        }

        if ($canDo->get('core.edit.state')) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if (isset($this->items[0]->state)) {
                $childBar->publish('categories.publish')->listCheck(true);
                $childBar->unpublish('categories.unpublish')->listCheck(true);
                $childBar->archive('categories.archive')->listCheck(true);
            } elseif (isset($this->items[0])) {
                // If this component does not use state then show a direct delete button as we can not trash
                $toolbar->delete('categories.delete')
                    ->text('JTOOLBAR_EMPTY_TRASH')
                    ->message('JGLOBAL_CONFIRM_DELETE')
                    ->listCheck(true);
            }

            $childBar->standardButton('duplicate')
                ->text('JTOOLBAR_DUPLICATE')
                ->icon('fas fa-copy')
                ->task('categories.duplicate')
                ->listCheck(true);

            if (isset($this->items[0]->checked_out)) {
                $childBar->checkin('categories.checkin')->listCheck(true);
            }

            if (isset($this->items[0]->state)) {
                $childBar->trash('categories.trash')->listCheck(true);
            }
        }


        // Show trash and delete for components that uses the state field
        if (isset($this->items[0]->state)) {

            if ($this->state->get('filter.state') == ContentComponent::CONDITION_TRASHED && $canDo->get('core.delete')) {
                $toolbar->delete('categories.delete')
                    ->text('JTOOLBAR_EMPTY_TRASH')
                    ->message('JGLOBAL_CONFIRM_DELETE')
                    ->listCheck(true);
            }
        }

        if ($canDo->get('core.admin')) {
            $toolbar->preferences('com_alfa');
        }

        // Set sidebar action
        Sidebar::setAction('index.php?option=com_alfa&view=categories');
    }

    /**
     * Method to order fields
     *
     * @return void
     */
    protected
    function getSortFields()
    {
        return array(
            'a.`ordering`' => Text::_('JGRID_HEADING_ORDERING'),
            'a.`id`' => Text::_('JGRID_HEADING_ID'),
            'a.`name`' => Text::_('COM_ALFA_CATEGORIES_NAME'),
            'a.`state`' => Text::_('JSTATUS'),
        );
    }

    /**
     * Check if state is set
     *
     * @param mixed $state State
     *
     * @return bool
     */
    public
    function getState($state)
    {
        return isset($this->state->{$state}) ? $this->state->{$state} : false;
    }
}
