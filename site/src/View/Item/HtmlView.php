<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Item;
// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
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

	protected $item;

	protected $form;

	protected $params;

    protected $payment_methods;

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

		$this->state  = $this->get('State');
		$this->item   = $this->get('Item');
		$this->params = $app->getParams('com_alfa');

		if (!empty($this->item))
		{
			
		}

		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new \Exception(implode("\n", $errors));
		}
        
		if ($this->_layout == 'edit')
		{
			$authorised = $user->authorise('core.create', 'com_alfa');

			if ($authorised !== true)
			{
				throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'));
			}
		}

        /*
         *  Setting up alfa-payments onProductView event call to be used on tmpl.
         */
        $onProductViewPaymentEventName = "onProductView";
        
        foreach($this->item->payment_methods as &$payment_method) {
            $payment_method->events = new \stdClass();
            $payment_method->events->{$onProductViewPaymentEventName} = $app->bootPlugin($payment_method->type, "alfa-payments")->{$onProductViewPaymentEventName}($this->item, $payment_method);
            
            if(!$payment_method->show_on_product){
                unset($this->item->payment_methods[$payment_method->id]);
            }
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

        $title = $this->item->name;

        if (empty($title))
        {
            $title = $app->get('sitename');
        }
        elseif ($app->get('sitename_pagetitles', 0) == 1)
        {
            $title = Text::sprintf('JPAGETITLE', $app->get('sitename'), $title);
        }
        elseif ($app->get('sitename_pagetitles', 0) == 2)
        {
            $title = Text::sprintf('JPAGETITLE', $title, $app->get('sitename'));
        }

        $this->document->setTitle($title);

        if ($this->params->get('menu-meta_description'))
        {
            $this->document->setDescription($this->params->get('menu-meta_description'));
        }

        if ($this->params->get('menu-meta_keywords'))
        {
            $this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
        }

        if ($this->params->get('robots'))
        {
            $this->document->setMetadata('robots', $this->params->get('robots'));
        }

        // Add Breadcrumbs
        $pathway = $app->getPathway();
        $breadcrumbList = Text::_('COM_ALFA_TITLE_ITEMS');

        if(!in_array($breadcrumbList, $pathway->getPathwayNames())) {
            $pathway->addItem($breadcrumbList, "index.php?option=com_alfa&view=items");
        }
        $breadcrumbTitle = $this->item->name;

        if(!in_array($breadcrumbTitle, $pathway->getPathwayNames())) {
            $pathway->addItem($breadcrumbTitle);
        }
    }


}
