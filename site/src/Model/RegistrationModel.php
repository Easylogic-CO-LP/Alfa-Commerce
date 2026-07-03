<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\FormModel;

class RegistrationModel extends FormModel
{
    private $coreModel = null;

    private function getCoreModel()
    {
        if ($this->coreModel === null) {
            $this->coreModel = Factory::getApplication()
                ->bootComponent('com_users')
                ->getMVCFactory()
                ->createModel('Registration', 'Site', ['ignore_request' => true]);
        }

        return $this->coreModel;
    }

    public function getForm($data = [], $loadData = true)
    {
        // Force Joomla to load the com_users language strings for the site frontend
        Factory::getApplication()->getLanguage()->load('com_users', JPATH_SITE);

        // Explicitly append the native com_users form folder to the lookup path array
        Form::addFormPath(JPATH_SITE . '/components/com_users/forms');

        $form = $this->getCoreModel()->getForm($data, $loadData);

        if (!$form) {
            $this->setError($this->getCoreModel()->getError());
            return false;
        }

        FieldsHelper::prepareForm(context: 'user.register', form: $form, data: $data, targetFieldset: 'registration');

        if ($loadData && !empty($data)) {
            $form->bind($data);
        }

        return $form;
    }

    public function validate($form, $data, $group = null)
    {
        $result = $this->getCoreModel()->validate($form, $data, $group);

        if ($result === false) {
            foreach ($this->getCoreModel()->getErrors() as $error) {
                $this->setError($error);
            }
        }

        return $result;
    }

    public function register(array $data)
    {
        $result = $this->getCoreModel()->register($data);

        if ($result === false) {
            foreach ($this->getCoreModel()->getErrors() as $error) {
                $this->setError($error);
            }
        }

        return $result;
    }

    public function registrationAllowed(): bool
    {
        return (bool) ComponentHelper::getParams('com_users')->get('allowUserRegistration', 1);
    }
}
