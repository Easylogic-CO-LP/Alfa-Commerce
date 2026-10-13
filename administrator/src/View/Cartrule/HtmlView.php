<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Cartrule;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Cart rule edit view.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    use HasAdminEvents;

    protected $state;

    protected $item;

    protected $form;

    /**
     * This view is the cart rule EDIT screen.
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
        // Assigned, not declared: the trait owns the property (see the Order view).
        $this->alfaEntity = 'cartrule';

        $this->state = $this->get('State');
        $this->item = $this->get('Item');
        $this->form = $this->get('Form');

        if (count($errors = $this->get('Errors'))) {
            throw new Exception(implode("\n", $errors));
        }

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
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $isNew = empty($this->item->id);
        $user = $this->getCurrentUser();
        $canCreate = AlfaHelper::can(section: 'cartrules', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'cartrules', action: 'edit', user: $user);

        ToolbarHelper::title(
            $isNew ? Text::_('COM_ALFA_TITLE_CARTRULE_NEW') : Text::_('COM_ALFA_TITLE_CARTRULE_EDIT'),
            'generic',
        );

        // Apply reopens the saved record, which needs edit even when the record is new.
        if ($isNew ? ($canCreate && $canEdit) : $canEdit) {
            ToolbarHelper::apply('cartrule.apply');
        }

        // A new rule needs create, an existing one edit.
        if ($isNew ? $canCreate : $canEdit) {
            ToolbarHelper::save('cartrule.save');
        }

        if ($canCreate) {
            ToolbarHelper::save2new('cartrule.save2new');
        }

        ToolbarHelper::cancel('cartrule.cancel', $isNew ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
    }
}
