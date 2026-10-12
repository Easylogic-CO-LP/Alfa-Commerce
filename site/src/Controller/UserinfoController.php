<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\FormErrors;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * User-info controller: add / edit / delete the current customer's saved rows.
 *
 * @since  1.0.1
 */
class UserinfoController extends BaseController
{
    /**
     * Ensures a logged-in user; redirects guests to login.
     *
     * @return \Joomla\CMS\User\User|null
     *
     * @since   1.0.1
     */
    private function requireUser()
    {
        $user = $this->app->getIdentity();

        if (!$user || $user->guest) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));

            return null;
        }

        return $user;
    }

    /**
     * Adds or edits a row. Identical data reuses the matching row (dedup);
     * editing an own unfrozen row updates it in place; a row referenced by
     * an order is never touched; the edit becomes a new row.
     *
     * @return void
     *
     * @since   1.0.1
     */
    public function save()
    {
        $this->checkToken();

        $user = $this->requireUser();
        if (!$user) {
            return;
        }

        $jform = $this->input->post->get('jform', [], 'array');
        $sourceId = $this->input->getInt('source_id', 0);

        // Server-side validation (the client class is a convenience, not a
        // gate): required/format/showon rules are enforced here just as
        // registration and checkout do, so a crafted POST cannot bypass them.
        $model = $this->getModel('Userinfo', 'Site');
        $form = $model->getForm($jform, false);
        $validData = $form ? $model->validate($form, $jform) : false;

        if ($validData === false) {
            $split = FormErrors::partition((array) $model->getErrors());

            foreach ($split['messages'] as $message) {
                $this->app->enqueueMessage($message, 'error');
            }

            if ($split['failures'] !== []) {
                AlfaLog::register();
                Log::add('Saving customer details failed: ' . FormErrors::detail($split['failures']), Log::ERROR, 'com_alfa.admin');

                $this->app->enqueueMessage(Text::_('COM_ALFA_USERINFO_SAVE_FAILED'), 'error');
            }

            $this->app->setUserState('com_alfa.userinfo.data', $jform);
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=userinfo' . ($sourceId ? '&id=' . $sourceId : ''), false));

            return;
        }

        $values = $validData[FieldsHelper::FIELDS_KEY] ?? [];

        if (!empty($values)) {
            UserInfoHelper::insertData(userId: (int) $user->id, data: $values, existingId: $sourceId ?: null);
            $this->app->enqueueMessage(Text::_('COM_ALFA_USERINFO_SAVED'), 'message');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=userinfo', false));
    }

    /**
     * Deletes a row when it is owned by the user and not used in any order.
     *
     * @return void
     *
     * @since   1.0.1
     */
    public function delete()
    {
        $this->checkToken();

        $user = $this->requireUser();
        if (!$user) {
            return;
        }

        $id = $this->input->getInt('id', 0);

        if (UserInfoHelper::deleteRow(userId: (int) $user->id, id: $id)) {
            $this->app->enqueueMessage(Text::_('COM_ALFA_USERINFO_DELETED'), 'message');
        } else {
            $this->app->enqueueMessage(Text::_('COM_ALFA_USERINFO_DELETE_DENIED'), 'warning');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=userinfo', false));
    }
}
