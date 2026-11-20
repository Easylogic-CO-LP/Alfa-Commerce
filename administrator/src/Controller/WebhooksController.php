<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

/**
 * The Webhooks list: enable, pause and delete.
 *
 * @since  2.2.2
 */
class WebhooksController extends AdminController
{
    /**
     * Proxy to the edit model.
     *
     * @param string $name Model name.
     * @param string $prefix Model prefix.
     * @param array $config Configuration array.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @since  2.2.2
     */
    public function getModel($name = 'Webhook', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Enable or pause the selected webhooks, in the screen's own words.
     *
     * @return void
     *
     * @since  2.2.2
     */
    public function publish()
    {
        $prefix = $this->text_prefix;
        $this->text_prefix = 'COM_ALFA_WEBHOOKS';

        try {
            parent::publish();
        } finally {
            $this->text_prefix = $prefix;
        }
    }
}
