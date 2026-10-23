<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Joomla\CMS\Form\Form;

/**
 * Single-notification record model of the admin contract. Notifications are raised by
 * code, never typed in: {@see \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::push()}
 * writes them itself and never saves through this model, so no admin save event fires
 * for a notification core raises. There is no edit form and no webservices route. Reads
 * use {@see NotificationsModel}.
 *
 * @since  1.0.0
 */
class NotificationModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity this model serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'notification';

    /**
     * The prefix to use with controller messages.
     *
     * @var string
     * @since  1.0.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * Alias for history/UCM.
     *
     * @var string
     * @since  1.0.0
     */
    public $typeAlias = 'com_alfa.notification';

    /**
     * Get the record form.
     *
     * @param array $data Optional data for the form to interrogate.
     * @param bool $loadData True if the form should load its own data.
     *
     * @return Form|false
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.notification',
            'notification',
            [
                'control' => 'jform',
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
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.notification.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}
