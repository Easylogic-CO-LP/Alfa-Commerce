<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Empties;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    protected $params;

    /**
     * Display the view.
     *
     * @param string|null $tpl The name of the template file to parse.
     *
     * @return void
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        // This is a content-less landing view (e.g. "Search Results"); it has no
        // model. Pull the merged component/menu params straight from the application.
        $this->params = Factory::getApplication()->getParams('com_alfa');

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Set the document title, metadata and breadcrumb for this content-less landing
     * view (e.g. "Search Results"). It has no model/item, so everything derives from
     * the active menu item and the merged component params.
     *
     * @return void
     * @since  1.0.0
     */
    protected function _prepareDocument()
    {
        $app = Factory::getApplication();
        $menu = $app->getMenu()->getActive();

        // Page title: the menu's page_title param, else the menu title, else the site name.
        $title = $this->params->get('page_title', $menu ? $menu->title : '');
        $siteName = $app->get('sitename');
        $siteNameInTitle = (int) $app->get('sitename_pagetitles', 0);

        if (empty($title)) {
            $title = $siteName;
        } elseif ($siteNameInTitle === 1) {
            $title = Text::sprintf('JPAGETITLE', $siteName, $title);
        } elseif ($siteNameInTitle === 2) {
            $title = Text::sprintf('JPAGETITLE', $title, $siteName);
        }

        $document = $this->getDocument();
        $document->setTitle($title);

        if ($this->params->get('menu-meta_description')) {
            $document->setDescription($this->params->get('menu-meta_description'));
        }

        if ($this->params->get('menu-meta_keywords')) {
            $document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
        }

        if ($this->params->get('robots')) {
            $document->setMetadata('robots', $this->params->get('robots'));
        }

        // Breadcrumb.
        $pathway = $app->getPathway();
        $breadcrumbTitle = Text::_('COM_ALFA_TITLE_EMPTIES');

        if (!in_array($breadcrumbTitle, $pathway->getPathwayNames(), true)) {
            $pathway->addItem($breadcrumbTitle);
        }
    }
}
