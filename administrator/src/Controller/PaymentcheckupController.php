<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentCheckupEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\PaymentCheckupHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The "Check connection" button of a payment method's checkup: asks the
 * gateway plugin to contact its provider with the method's saved settings,
 * then answers with the redrawn checkup card for the page to swap in.
 *
 * A BaseController with one task: nothing here writes the method, and the
 * provider calls the plugin makes are read-only by contract. It still demands
 * a POST with the form token and the payments edit permission, because it
 * spends the merchant's API credentials on an outbound call.
 *
 * @since  2.0.0
 */
class PaymentcheckupController extends BaseController
{
    /**
     * Run the checks and answer with the card's HTML.
     *
     *
     * @throws NotAllowed Without the token, a POST or the permission.
     *
     * @since  2.0.0
     */
    public function check(): void
    {
        if (strtoupper($this->input->getMethod()) !== 'POST') {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 405);
        }

        $this->checkToken();

        if (!AlfaHelper::can(section: 'payments', action: 'edit')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $methodId = $this->input->post->getInt('method', 0);
        $variant = $this->input->post->getCmd('variant', '');
        $variant = \in_array($variant, PaymentCheckupEvent::VARIANTS, true) ? $variant : 'general';
        $domId = $this->input->post->getCmd('card', '');
        $title = Text::_('COM_ALFA_CHECKUP_TITLE_' . strtoupper($variant));

        $html = PaymentCheckupHelper::render(methodId: $methodId, variant: $variant, check: true, domId: $domId, title: $title);

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->sendHeaders();

        echo $html;

        $this->app->close();
    }
}
