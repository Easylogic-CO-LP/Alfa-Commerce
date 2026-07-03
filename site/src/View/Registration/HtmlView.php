<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Registration;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Registration view for com_alfa.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var \Joomla\CMS\Form\Form
     */
    protected $form;

    /**
     * @var \Joomla\Registry\Registry
     */
    protected $params;

    /**
     * @inheritDoc
     *
     * @param   string|null  $tpl  Template name.
     *
     * @return  void
     *
     * @throws  Exception
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        $this->params = $app->getParams('com_alfa');

        $model = $this->getModel();

        $savedData = $app->getUserState('com_alfa.registration.data', []);
        $this->form = $model->getForm($savedData, !empty($savedData));

        if (!$this->form) {
            throw new Exception($model->getError(), 500);
        }

        // Check for errors.
        if (count($errors = $model->getErrors())) {
            throw new Exception(implode("\n", $errors));
        }

        $this->setDocumentTitle(Text::_('COM_ALFA_REGISTRATION_PAGE_TITLE'));

        parent::display($tpl);
    }
}