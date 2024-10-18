<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Cart;
// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    protected $state;

    protected $cart;

    protected $items;

    protected $form;

    protected $params;

    /**
     * Display the view
     *
     * @param   string  $tpl  Template name
     *
     * @return void
     *
     * @throws Exception
     */
    public function display($tpl = null)
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();
        $input = $this->app->input;

        $this->state  = $this->get('State');
        $this->cart   = $this->get('Item');
        // $this->items   = $this->get('Items');
        $this->params = $app->getParams('com_alfa');

        // Check for errors.
        if (count($errors = $this->get('Errors')))
        {
            throw new \Exception(implode("\n", $errors));
        }

        // print_r($this->_layout);
        // exit;
        if ((!$this->cart->getData() || !is_array($this->cart->getData()->items) || !count($this->cart->getData()->items)) 
            && $this->_layout=='default') {
            $this->_layout = 'default_cart_empty';
            // $tpl = 'clear_cart';
        }

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Prepares the document
     *
     * @return void
     *
     * @throws Exception
     */
    protected function _prepareDocument()
    {
        $app   = Factory::getApplication();
        $menus = $app->getMenu();
        $title = null;

        // Because the application sets a default page title,
        // We need to get it from the menu manufacturer itself
        $menu = $menus->getActive();

        if ($menu)
        {
            $this->params->def('page_heading', $this->params->get('page_title', $menu->title));
        }
        else
        {
            $this->params->def('page_heading', Text::_('COM_ALFA_DEFAULT_PAGE_TITLE'));
        }

        // $title = $this->item->name;

        // if (empty($title))
        // {
        //     $title = $app->get('sitename');
        // }
        // elseif ($app->get('sitename_pagetitles', 0) == 1)
        // {
        //     $title = Text::sprintf('JPAGETITLE', $app->get('sitename'), $title);
        // }
        // elseif ($app->get('sitename_pagetitles', 0) == 2)
        // {
        //     $title = Text::sprintf('JPAGETITLE', $title, $app->get('sitename'));
        // }

        // $this->document->setTitle($title);

        // if ($this->params->get('menu-meta_description'))
        // {
        //     $this->document->setDescription($this->params->get('menu-meta_description'));
        // }

        // if ($this->params->get('menu-meta_keywords'))
        // {
        //     $this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
        // }

        // if ($this->params->get('robots'))
        // {
        //     $this->document->setMetadata('robots', $this->params->get('robots'));
        // }

        // // Add Breadcrumbs
        // $pathway = $app->getPathway();
        // $breadcrumbList = Text::_('COM_ALFA_TITLE_ITEMS');

        // if(!in_array($breadcrumbList, $pathway->getPathwayNames())) {
        //     $pathway->addItem($breadcrumbList, "index.php?option=com_alfa&view=items");
        // }
        // $breadcrumbTitle = $this->item->name;

        // if(!in_array($breadcrumbTitle, $pathway->getPathwayNames())) {
        //     $pathway->addItem($breadcrumbTitle);
        // }
    }
}
