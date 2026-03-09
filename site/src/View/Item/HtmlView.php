<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Item;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\ItemViewEvent as PaymentsItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ItemViewEvent as ShipmentsItemViewEvent;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use stdClass;

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

	protected $category_id;

    protected $category_id;

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     *
     * @throws Exception
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();
        $model = $this->getModel();

        $this->params = $app->getParams('com_alfa');

        $this->category_id = $input->getInt('catid', 0);

        $this->state = $model->getState();
        $this->item = $model->getItem();

        // Resolve price settings once for all items
        $this->priceSettings = PriceSettings::get();

        /*
         *  Setting up alfa-payments onProductView event call to be used on tmpl.
         */
        $onProductViewEventName = 'onItemView';

        foreach ($this->item->payment_methods as &$payment_method) {
            if (!$payment_method->show_on_product) {
                unset($this->item->payment_methods[$payment_method->id]);
                continue;
            }

            $paymentEvent = new PaymentsItemViewEvent($onProductViewEventName, [
                'subject' => $this->item,
                'method' => $payment_method,
            ]);

            $app->bootPlugin($payment_method->type, 'alfa-payments')->{$onProductViewEventName}($paymentEvent);

            if (empty($paymentEvent->getLayoutPluginName())) {
                $paymentEvent->setLayoutPluginName($payment_method->type);
            }
            if (empty($paymentEvent->getLayoutPluginType())) {
                $paymentEvent->setLayoutPluginType('alfa-payments');
            }
            if ($paymentEvent->hasRedirect()) {
                $app->redirect(
                    $paymentEvent->getRedirectUrl(),
                    $paymentEvent->getRedirectCode() ?? 303,
                );

                return;
            }

            $payment_method->events = new stdClass();
            $payment_method->events->{$onProductViewEventName} = $paymentEvent;
        }

        foreach ($this->item->shipment_methods as &$shipment_method) {
            if (!$shipment_method->show_on_product) {
                unset($this->item->payment_methods[$shipment_method->id]);
                continue;
            }

            $shipment_event = new ShipmentsItemViewEvent($onProductViewEventName, [
                'subject' => $this->item,
                'method' => $shipment_method,
            ]);

            $app->bootPlugin($shipment_method->type, 'alfa-shipments')->{$onProductViewEventName}($shipment_event);

            if (empty($shipment_event->getLayoutPluginName())) {
                $shipment_event->setLayoutPluginName($shipment_method->type);
            }
            if (empty($shipment_event->getLayoutPluginType())) {
                $shipment_event->setLayoutPluginType('alfa-shipments');
            }
            if ($shipment_event->hasRedirect()) {
                $app->redirect(
                    $shipment_event->getRedirectUrl(),
                    $shipment_event->getRedirectCode() ?? 303,
                );

                return;
            }

            $shipment_method->events = new stdClass();
            $shipment_method->events->{$onProductViewEventName} = $shipment_event;
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
        $app = Factory::getApplication();

        // Add Breadcrumbs
        $pathway = $app->getPathway();

        // Use your helper to get the category path
        $categoryPath = CategoryHelper::getCategoryPath($this->category_id ?: $this->item->id_category_default);

        foreach ($categoryPath as $category) {
            $link = Route::_('index.php?option=com_alfa&view=items&category_id=' . $category['id']);
            if (!in_array($category['name'], $pathway->getPathwayNames())) {
                $pathway->addItem($category['name'], $link);
            }
        }

        $breadcrumbTitle = $this->item->name;

        if (!in_array($breadcrumbTitle, $pathway->getPathwayNames())) {
            $pathway->addItem($breadcrumbTitle);
        }

        // META TAGS
        // Meta Title
        $metaTitle = $this->params->get('page_title', '');

        if (!empty($this->item->meta_title)) {
            $metaTitle = $this->item->meta_title;
        } elseif (!empty($this->item->name)) {
            $metaTitle = $this->item->name;
        }

        $siteNameOnpageTitle = $app->get('sitename_pagetitles', 0);
        $siteName = $app->get('sitename');
        if (!empty($siteNameOnpageTitle)) {
            if ($siteNameOnpageTitle == 1) { // Before
                $metaTitle = $siteName . ' - ' . $metaTitle;
            } else { // After
                $metaTitle = $metaTitle . ' - ' . $siteName;
            }
        }

        // Meta Description
        $metaDescription = $this->params->get('menu-meta_description', '');

        if (!empty($this->item->meta_desc)) {
            $metaDescription = $this->item->meta_desc;
        } elseif (!empty($this->item->short_desc)) {
            $metaDescription = AlfaHelper::cleanContent(
                html:$this->item->short_desc,
                removeTags: true,
                removeScripts: true,
                removeIsolatedPunctuation: false,
            );
        }

        $otherMetaData = [];

        if (!empty($this->item->meta_data)) {
            $otherMetaData = json_decode($this->item->meta_data, true) ?: [];
        }

        if ($this->params->get('robots')) {
            $metaRobots = $this->params->get('robots');
        }

        if (!empty($otherMetaData['robots'])) {
            $metaRobots = $otherMetaData['robots'];
        }

        // Set all tags
        $this->getDocument()->setTitle($metaTitle);
        $this->getDocument()->setDescription($metaDescription);

        if (!empty($metaRobots)) {
            $this->getDocument()->setMetadata('robots', $metaRobots);
        }
    }
}
