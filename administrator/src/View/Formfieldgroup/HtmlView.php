<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Formfieldgroup;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\FormView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends FormView
{
    protected $canDo;

    public function display($tpl = null)
    {
        parent::display($tpl);
    }

    protected function initializeView()
    {
        parent::initializeView();

        $this->canDo = ContentHelper::getActions('com_alfa');

        $this->form
            ->addControlField('task', '')
            ->addControlField('return', Factory::getApplication()->getInput()->getBase64('return', ''));
    }

    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $checkedOut = !(is_null($this->item->checked_out) || $this->item->checked_out == $userId);
        $canDo = $this->canDo;

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_FORM_FIELD_GROUP'), 'generic');

        if (!$checkedOut && ($canDo->get('core.edit') || $canDo->get('core.create'))) {
            ToolbarHelper::apply('formfieldgroup.apply', 'JTOOLBAR_APPLY');
            ToolbarHelper::save('formfieldgroup.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canDo->get('core.create')) {
            ToolbarHelper::custom('formfieldgroup.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        ToolbarHelper::cancel('formfieldgroup.cancel', 'JTOOLBAR_CANCEL');
    }
}
