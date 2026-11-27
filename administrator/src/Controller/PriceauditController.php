<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceAuditJob;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * The price check page's one action: ask for the check to run again. The check itself is the job's, and the page
 * follows it.
 *
 * @since  2.3.0
 */
class PriceauditController extends BaseController
{
    private const PAGE = 'index.php?option=com_alfa&view=priceaudit';

    /**
     * Queue the price check's job, due now, and go back to the page, which follows the job while it is open. A second
     * ask while one waits or runs joins it: there is one check at a time. A POST with the form token, by a Super User.
     *
     * @throws NotAllowed When the user is not a Super User.
     *
     * @since  2.3.0
     */
    public function recheck(): void
    {
        $this->setRedirect(url: Route::_(url: self::PAGE, xhtml: false));

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken(method: 'post', redirect: false)) {
            $this->setMessage(text: Text::_('JINVALID_TOKEN_NOTICE'), type: 'error');

            return;
        }

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_alfa')) {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 403);
        }

        try {
            $queued = PriceAuditJob::enqueue();
            TaskStaff::dueNow(id: $queued->id);
        } catch (Throwable $e) {
            Log::add('Price check: the check could not be queued: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, 'com_alfa');
            $this->setMessage(text: Text::_('COM_ALFA_PRICE_AUDIT_RECHECK_FAILED'), type: 'error');

            return;
        }

        $this->setMessage(text: Text::_($queued->joined ? 'COM_ALFA_PRICE_AUDIT_RECHECK_JOINED' : 'COM_ALFA_PRICE_AUDIT_RECHECK_QUEUED'), type: 'info');
    }
}
