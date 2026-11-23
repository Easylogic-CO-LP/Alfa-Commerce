<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Orders;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PageCache;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * My orders: the orders of the customer logged in, newest first, each opening its own page. A visitor who is not
 * logged in is sent to log in and back here. The page is the customer's own: never stored by a browser, a proxy or
 * Joomla's page cache, never indexed.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The page's orders (OrdersModel::getItems()).
     *
     * @var object[]
     * @since  2.3.0
     */
    protected array $items = [];

    /**
     * The list's pages.
     *
     * @since  2.3.0
     */
    protected ?Pagination $pagination = null;

    /**
     * The menu item's and the component's parameters.
     *
     * @var \Joomla\Registry\Registry
     * @since  2.3.0
     */
    protected $params;

    /**
     * Display the customer's orders.
     *
     * @param string|null $tpl The template.
     *
     * @since  2.3.0
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        // The customer's own page at an address every visitor shares: never stored, never indexed.
        PageCache::excludeResponse();
        $app->allowCache(false);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        if ($app->getIdentity()->guest) {
            $app->enqueueMessage(Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST'), 'notice');
            $app->redirect(Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()), false));

            return;
        }

        $model = $this->getModel();
        $this->params = $app->getParams('com_alfa');
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();

        $this->getDocument()->setMetaData('robots', 'noindex, nofollow');
        $this->params->def('page_heading', $this->params->get('page_title', Text::_('COM_ALFA_ORDERS_HEADING')));
        $this->setDocumentTitle($this->params->get('page_title', Text::_('COM_ALFA_ORDERS_HEADING')));

        parent::display($tpl);
    }
}
