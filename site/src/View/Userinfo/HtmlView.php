<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Userinfo;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;

/**
 * User-info view: list + add/edit form for the current customer's rows.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    /** @var object[] */
    protected $rows = [];

    /** @var \Joomla\CMS\Form\Form */
    protected $form;

    /** @var int Row currently being edited (0 = new). */
    protected $editId = 0;

    /**
     * @inheritDoc
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            $app->enqueueMessage(Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST'), 'error');
            $app->redirect(Route::_('index.php?option=com_users&view=login', false));

            return;
        }

        $model = $this->getModel();
        $this->rows = $model->getRows();
        $this->form = $model->getForm();
        $this->editId = $app->input->getInt('id', 0);

        $this->setDocumentTitle(Text::_('COM_ALFA_USERINFO_PAGE_TITLE'));

        parent::display($tpl);
    }
}
