<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;

/**
 * The guard and the reply shared by every Tools-screen AJAX endpoint.
 *
 * Extracted because a second endpoint appeared. While these lived as private methods
 * on one controller, any new maintenance endpoint (including one serving
 * plugin-contributed actions) had to restate the permission check and the response
 * envelope, and a restatement is where the two eventually drift: one endpoint
 * validating the token, the next forgetting to.
 *
 * @since  1.5.0
 */
trait HasToolsAjaxTrait
{
    /**
     * Admin permission plus CSRF token, accepted in GET or POST.
     *
     * Emits a JSON error and closes the application when either fails, so a caller
     * may simply invoke it first and continue. What this does NOT decide is whether
     * the request may change state: a mutating action is additionally held to a POST
     * with a one-time key by the endpoint, once it knows which action it is.
     *
     *
     * @since  1.5.0
     */
    private function ajaxGuard(): void
    {
        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            $this->ajaxRespond([], false, Text::_('JERROR_ALERTNOAUTHOR'));

            return;
        }

        if (!Session::checkToken('request')) {
            $this->ajaxRespond([], false, Text::_('JINVALID_TOKEN'));
        }
    }

    /**
     * Emit Joomla's JSON envelope ({success, message, data}) and close the app.
     *
     * @param array<string, mixed> $data Payload, returned under `data` on success.
     * @param bool $success Success flag.
     * @param string $message Message; used as the error text when failing.
     *
     *
     * @since  1.5.0
     */
    private function ajaxRespond(array $data = [], bool $success = true, string $message = ''): void
    {
        $this->app->setHeader('Content-Type', 'application/json', true);
        $this->app->sendHeaders();

        echo $success
            ? new JsonResponse($data, $message !== '' ? $message : null)
            : new JsonResponse(null, $message, true);

        $this->app->close();
    }
}
