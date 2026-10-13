<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartPurge;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRetention;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * The Carts list's own tasks.
 *
 * The list is read-only by design (a cart is the shopper's working document),
 * so this carries no edit, publish or delete of a chosen row. What it does
 * carry is the one thing the merchant does TO the list: retention.
 *
 * @since  2.0.0
 */
class CartsController extends BaseController
{
    /**
     * Carts examined by one purge.
     *
     * A bound on DELETES, not a page of a walk: the run says whether more
     * expired carts remain and the merchant asks again, so a table full of dead
     * carts is cleared in bites rather than in one statement holding locks over
     * all of them.
     *
     * @var int
     *
     * @since  2.0.0
     */
    public const PURGE_LIMIT = 200;

    /**
     * Delete the carts nobody has touched for longer than the retention period.
     *
     * THE END OF THE CART LIFECYCLE, asked for by hand and only by hand. The
     * package ships no scheduler, and a shop that quietly deleted carts during
     * a storefront page cycle would be destroying data on a shopper's request.
     * So retention has exactly one door: the Carts screen's toolbar, behind the
     * dialog that says what will go, submitted as a POST with the form token by
     * somebody allowed to delete carts.
     *
     *
     * @since  2.0.0
     */
    public function purge(): void
    {
        $this->checkToken();

        if (!AlfaHelper::can(section: 'carts', action: 'delete', user: $this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $retention = new CartRetention();

            $result = (new CartPurge(
                new CartRepository(Factory::getContainer()->get('DatabaseDriver')),
                $retention,
            ))->run(limit: self::PURGE_LIMIT);

            $this->setMessage(Text::sprintf(
                $result['more'] ? 'COM_ALFA_CARTS_PURGE_MORE' : 'COM_ALFA_CARTS_PURGE_DONE',
                $result['purged'],
                $result['skipped'],
            ));
        } catch (Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=carts', false));
    }
}
