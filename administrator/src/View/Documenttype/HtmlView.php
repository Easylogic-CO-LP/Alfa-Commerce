<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Documenttype;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Document type edit view: presentation fields editable, semantics
 * rendered read-only by the template.
 *
 * @since  1.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity this form serves.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'documenttype';

    protected $form;

    protected $item;

    protected $state;

    /**
     * This view is the document type EDIT screen: it sits on the shared alfa-app
     * wrapper base (not FormView), so the screen identity is declared here.
     *
     *
     * @since  1.8.0
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
     * @since  1.3.0
     */
    public function display($tpl = null)
    {
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
     * @since  1.3.0
     */
    protected function addToolbar()
    {
        $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_DOCUMENTTYPE_TITLE_EDIT'), 'generic');

        // Types are never created here, so saving is always an edit.
        if (AlfaHelper::can(section: 'documents', action: 'edit', user: $this->getCurrentUser())) {
            ToolbarHelper::apply('documenttype.apply');
            ToolbarHelper::save('documenttype.save');
        }

        ToolbarHelper::cancel('documenttype.cancel', 'JTOOLBAR_CLOSE');
    }
}
