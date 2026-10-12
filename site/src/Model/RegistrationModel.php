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
use Alfa\Component\Alfa\Site\Service\ShowOn\SubmitAuthority;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\FormModel;

/**
 * Registration model: wraps the core com_users registration model
 * (validation, user creation, activation) and extends its form with the
 * alfa fields flagged for registration.
 *
 * @since  1.0.1
 */
class RegistrationModel extends FormModel
{
    /**
     * @var \Joomla\CMS\MVC\Model\BaseDatabaseModel|null Memoised core model.
     * @since  1.0.1
     */
    private $coreModel = null;

    /**
     * The core com_users registration model.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @since  1.0.1
     */
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

    /**
     * Build the merged form: the core registration fieldsets plus the
     * alfa registration fields via the pipeline.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to bind the given data.
     *
     * @return \Joomla\CMS\Form\Form|false
     *
     * @since  1.0.1
     */
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

    /**
     * Validate through the core model (core rules + captcha + alfa rules).
     *
     * @param \Joomla\CMS\Form\Form $form The form.
     * @param array $data The posted data.
     * @param string|null $group The form group.
     *
     * @return array|false
     *
     * @since  1.0.1
     */
    public function validate($form, $data, $group = null)
    {
        // Take off the form every alfa field a ShowOn rule hides before the core
        // model runs: the browser disables a hidden field so it never posts, and
        // demanding it would block registration outright. Dropping the field also
        // drops it from the filter pass, so a crafted POST cannot smuggle a value
        // for a field the visitor never saw.
        $data = (array) $data;

        $data[FieldsHelper::FIELDS_KEY] = SubmitAuthority::prune(
            form: $form,
            fields: FieldsHelper::getFields('user.register'),
            values: (array) ($data[FieldsHelper::FIELDS_KEY] ?? []),
            group: FieldsHelper::FIELDS_KEY,
        );

        $result = $this->getCoreModel()->validate($form, $data, $group);

        if ($result === false) {
            foreach ($this->getCoreModel()->getErrors() as $error) {
                $this->setError($error);
            }
        }

        return $result;
    }

    /**
     * Create the user through the core model (activation emails included).
     *
     * @param array $data Validated form data.
     *
     * @return string|false Activation mode on success, false on failure.
     *
     * @since  1.0.1
     */
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

    /**
     * Whether com_users allows public registration.
     *
     *
     * @since  1.0.1
     */
    public function registrationAllowed(): bool
    {
        return (bool) ComponentHelper::getParams('com_users')->get('allowUserRegistration', 1);
    }
}
