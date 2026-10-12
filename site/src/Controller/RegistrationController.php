<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FormErrors;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Exception;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

/**
 * Registration controller for com_alfa.
 *
 * Wraps the core com_users registration flow (token check, form validation,
 * user creation, activation emails) while rendering alfa-fields through the
 * alfa pipeline (showon, custom layouts).
 *
 * @since  1.0.1
 */
class RegistrationController extends FormController
{
    /**
     * Registration runs through register() alone, never through FormController's tasks.
     *
     * The inherited add, save and reload tasks would be answered with Joomla's core.create;
     * refusing here keeps the storefront independent of admin rights.
     *
     * @param array $data The data for the new record.
     *
     * @return bool
     *
     * @since  1.10.0
     */
    protected function allowAdd($data = [])
    {
        return false;
    }

    /**
     * Closes the inherited edit, save and reload tasks, which Joomla would grant on core.edit.
     *
     * @param array $data The record data.
     * @param string $key The record key name.
     *
     * @return bool
     *
     * @since  1.10.0
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        return false;
    }

    /**
     * Joomla's generic batch, reachable as a task only because it is public.
     *
     * @param mixed $model Unused.
     *
     * @return bool
     *
     * @throws NotAllowed Always.
     *
     * @since  1.10.0
     */
    public function batch($model = null)
    {
        throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
    }

    /**
     * Method to get a model object, loading it if required.
     *
     * @param string $name The model name. Optional.
     * @param string $prefix The class prefix. Optional.
     * @param array $config Configuration array for model. Optional.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @since   1.0.1
     */
    public function getModel($name = 'Registration', $prefix = '', $config = [])
    {
        return parent::getModel($name, $prefix, ['ignore_request' => true]);
    }

    /**
     * Handles the registration form submission.
     *
     * Flow:
     *   1. CSRF token check
     *   2. Guard: registration disabled -> redirect to login
     *   3. Validate merged form (core fields + captcha + alfa fields)
     *   4. On failure: save form state to session, enqueue errors, redirect back
     *   5. Create the Joomla user via the core model
     *   6. Redirect based on activation type
     *
     * @return void
     *
     * @since   1.0.1
     */
    public function register()
    {
        $this->checkToken();

        $app = $this->app;
        $model = $this->getModel();

        // Guard: respect com_users allowUserRegistration param.
        if (!$model->registrationAllowed()) {
            $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
            return;
        }

        // Get the merged form (core fieldsets + alfa registration fieldsets).
        $form = $model->getForm();

        if (!$form) {
            throw new Exception($model->getError(), 500);
        }

        // Validate: core rules + captcha + alfa field rules all fire here.
        $data = $this->input->post->get('jform', [], 'array');

        $validData = $model->validate($form, $data);

        if ($validData === false) {
            $this->flashErrors($model->getErrors());

            // Save submitted data to session so the form can be re-filled.
            // com_users.registration.data -> re-fills core fields (name, username, email, password)
            // com_alfa.registration.data -> re-fills alfa fields
            $app->setUserState('com_users.registration.data', $data);
            $app->setUserState('com_alfa.registration.data', $data);

            $this->setRedirect(Route::_('index.php?option=com_alfa&view=registration', false));
            return;
        }

        // Create the Joomla user. Activation emails are handled by the core model.
        $result = $model->register($validData);

        if ($result === false) {
            $this->flashErrors($model->getErrors());

            $this->setRedirect(Route::_('index.php?option=com_alfa&view=registration', false));
            return;
        }

        // Clear saved form state on success.
        $app->setUserState('com_alfa.registration.data', null);

        // Redirect based on activation type.
        if ($result === 'adminactivate' || $result === 'useractivate') {
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=registration&layout=complete', false));
            return;
        }

        // No activation required: user is live, send to login.
        $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
    }

    /**
     * Enqueues model errors as user messages.
     *
     * @param array $errors Error strings or Exceptions.
     *
     *
     * @since   1.0.1
     */
    private function flashErrors(array $errors): void
    {
        // A rejected field is the visitor's to fix and must be shown. Anything
        // else in this bag is the machinery failing, and its words name tables
        // and (depending on where the connection died) the account the site
        // connects as.
        $split = FormErrors::partition($errors);

        foreach ($split['messages'] as $message) {
            $this->app->enqueueMessage($message, 'error');
        }

        if ($split['failures'] === []) {
            return;
        }

        AlfaLog::register();
        Log::add('Registration failed: ' . FormErrors::detail($split['failures']), Log::ERROR, 'com_alfa.admin');

        $this->app->enqueueMessage(Text::_('COM_ALFA_REGISTRATION_FAILED'), 'error');
    }
}
