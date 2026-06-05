<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;

/**
 * Single-notification model — the canonical write path for the notification store.
 * Creating/updating a notification goes through {@see self::save()} (used by the
 * static {@see \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::push()},
 * the form controller, and the webservices API alike), so there is one professional
 * write everywhere. Reads use {@see NotificationsModel}.
 *
 * @since  1.0.5
 */
class NotificationModel extends AdminModel
{
    /**
     * The prefix to use with controller messages.
     *
     * @var string
     * @since  1.0.5
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * Alias for history/UCM.
     *
     * @var string
     * @since  1.0.5
     */
    public $typeAlias = 'com_alfa.notification';

    /**
     * Get the record form.
     *
     * @param array $data     Optional data for the form to interrogate.
     * @param bool  $loadData True if the form should load its own data.
     *
     * @return Form|false
     *
     * @since   1.0.5
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.notification',
            'notification',
            [
                'control'   => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    /**
     * Data injected into the form.
     *
     * @return mixed
     *
     * @since   1.0.5
     */
    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_alfa.edit.notification.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}
