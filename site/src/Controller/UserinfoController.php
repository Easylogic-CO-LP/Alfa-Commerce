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
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\Language\Text;
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
     * @return  \Joomla\CMS\User\User|null
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
     * Adds or edits a row. New/changed details insert a fresh row (dedup); when
     * editing a row that is not used in any order, the source row is removed so
     * no duplicate accumulates.
     *
     * @return  void
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
        $values = $jform[FieldsHelper::FIELDS_KEY] ?? [];
        $sourceId = $this->input->getInt('source_id', 0);

        if (!empty($values)) {
            // Editing a free row updates it in place; a frozen row forks a new one.
            UserInfoHelper::insertData(userId: (int) $user->id, data: $values, existingId: $sourceId ?: null);
            $this->app->enqueueMessage(Text::_('COM_ALFA_USERINFO_SAVED'), 'message');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=userinfo', false));
    }

    /**
     * Deletes a row when it is owned by the user and not used in any order.
     *
     * @return  void
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