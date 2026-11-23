<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use DomainException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

/**
 * A webhook's edit screen, and its test: one change of topic ping posted to its address, signed as every request, the
 * answer shown.
 *
 * @since  2.3.0
 */
class WebhookController extends FormController
{
    use HasFormPermissions;

    /**
     * View used by the cancel/save toolbar buttons.
     *
     * @var string
     *
     * @since  2.3.0
     */
    protected $view_list = 'webhooks';

    /**
     * Send the webhook a test: POST with the form token, the Webhooks edit permission.
     *
     * @since  2.3.0
     */
    public function test(): void
    {
        $id = RequestScalar::int($this->input->post, 'id');
        $page = Route::_('index.php?option=com_alfa&view=webhooks', false);

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($page, Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        if (!AlfaHelper::can(section: 'webhooks', action: 'edit', user: $this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $sent = OutboundConsumers::sendTest(id: $id);
        } catch (DomainException) {
            $this->setRedirect($page, Text::_('COM_ALFA_WEBHOOK_ERROR_NOT_WEBHOOK'), 'error');

            return;
        }

        $result = str_starts_with($sent['result'], 'COM_ALFA_') ? Text::_($sent['result']) : $sent['result'];
        $this->setRedirect(
            $page,
            Text::sprintf($sent['ok'] ? 'COM_ALFA_WEBHOOK_TEST_OK' : 'COM_ALFA_WEBHOOK_TEST_FAILED', $result),
            $sent['ok'] ? 'success' : 'warning',
        );
    }
}
