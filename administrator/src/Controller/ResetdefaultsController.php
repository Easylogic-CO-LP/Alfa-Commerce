<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\ResetToDefault;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * Tools > Reset to default: the confirmed request of the page, held to a POST with the form's
 * token, to the Tools and Options permissions and to the confirmation box. The work is the domain
 * service's ({@see ResetToDefault}).
 *
 * @since  2.1.0
 */
class ResetdefaultsController extends BaseController
{
    /**
     * Reset the settings shown on the page.
     *
     * @since  2.1.0
     */
    public function apply(): void
    {
        $page = Route::_('index.php?option=com_alfa&view=resetdefaults', false);

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($page, Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        if (!ResetToDefault::permitted($this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($this->input->post->getInt('confirm', 0) !== 1) {
            $this->setRedirect($page, Text::_('COM_ALFA_RESET_ERROR_CONFIRM'), 'error');

            return;
        }

        try {
            $done = (new ResetToDefault())->apply(user: $this->app->getIdentity());
        } catch (Throwable $e) {
            $this->setRedirect($page, Text::sprintf('COM_ALFA_RESET_FAILED', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')), 'error');

            return;
        }

        $this->setRedirect($page, Text::sprintf('COM_ALFA_RESET_DONE', $done['options'], $done['groups']), 'success');

        if ($done['audit'] !== null) {
            $this->app->enqueueMessage(Text::sprintf('COM_ALFA_RESET_AUDIT_FAILED', htmlspecialchars($done['audit'], ENT_QUOTES, 'UTF-8')), 'warning');
        }

        if ($done['rebuild']) {
            $this->app->enqueueMessage(Text::_('COM_ALFA_RESET_REBUILD_PROJECTIONS'), 'warning');
        }
    }
}
