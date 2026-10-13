<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Location;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Inventory location edit view.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    use HasAdminEvents;

    protected $form;

    protected $item;

    protected $state;

    /**
     * This view is the location EDIT screen: the contract must not run the
     * list phases (columns/actions) for it.
     *
     *
     * @since  2.0.0
     */
    protected function alfaScreen(): string
    {
        return AlfaAdminContext::SCREEN_EDIT;
    }

    /**
     * Display the view.
     *
     * @param string $tpl Template name.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function display($tpl = null)
    {
        // The trait owns the $alfaEntity property; redeclaring it here is a composition
        // fatal, so the entity is assigned, not declared.
        $this->alfaEntity = 'location';

        $model = $this->getModel();

        $this->form = $model->getForm();
        $this->item = $model->getItem();
        $this->state = $model->getState();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function addToolbar()
    {
        $this->getDocument()->getToolbar();

        $isNew = (int) ($this->item->id ?? 0) === 0;

        ToolbarHelper::title(Text::_($isNew ? 'COM_ALFA_LOCATION_TITLE_NEW' : 'COM_ALFA_LOCATION_TITLE_EDIT'), 'generic');

        $user = $this->getCurrentUser();
        $canEdit = AlfaHelper::can(section: 'locations', action: 'edit', user: $user);
        $canSave = $isNew ? AlfaHelper::can(section: 'locations', action: 'create', user: $user) : $canEdit;

        // Apply reopens the saved location, which needs edit even when the location is new.
        if ($canSave && $canEdit) {
            ToolbarHelper::apply('location.apply');
        }

        // A new location needs create, an existing one edit. Cancel stays: it only closes the form.
        if ($canSave) {
            ToolbarHelper::save('location.save');
        }

        ToolbarHelper::cancel('location.cancel', $isNew ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
    }
}
