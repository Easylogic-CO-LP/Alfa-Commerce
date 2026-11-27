<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFailedCopyRedirect;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Pricing\DiscountReach;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Shipment controller class.
 *
 * @since  1.0.0
 */
class DiscountController extends FormController
{
    use HasFormPermissions;
    use HasFailedCopyRedirect;

    protected $view_list = 'discounts';

    /**
     * The Reach card's counts of a saved rule, asked for by the form on demand: what the rule reaches, the products its
     * categories leave out, the rules it stacks with and its exclusions (DiscountReach::of()), rendered by the card's
     * own layout, as JSON {html}. A POST with the form token, by a user who may edit discounts.
     *
     * @since  2.3.0
     */
    public function reach(): void
    {
        $this->app->setHeader('Content-Type', 'application/json', true);

        if (strtoupper($this->input->getMethod()) !== 'POST' || !Session::checkToken('post')) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN_NOTICE'), true);
            $this->app->close();
        }

        if (!AlfaHelper::can(section: 'discounts', action: 'edit')) {
            echo new JsonResponse(null, Text::_('JERROR_ALERTNOAUTHOR'), true);
            $this->app->close();
        }

        $id = RequestScalar::int($this->input->post, 'id');

        try {
            $reach = $id > 0 ? (new DiscountReach(db: Factory::getContainer()->get(DatabaseInterface::class)))->of(discountId: $id) : null;
        } catch (Throwable $e) {
            Log::add('Discount #' . $id . ': the Reach card could not be counted: ' . $e::class . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa');
            $reach = null;
        }

        if ($reach === null) {
            echo new JsonResponse(null, Text::_('COM_ALFA_DISCOUNT_REACH_FAILED'), true);
            $this->app->close();
        }

        echo new JsonResponse(['html' => LayoutHelper::render('discount.reach_counts', ['reach' => $reach], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts')]);
        $this->app->close();
    }
}
