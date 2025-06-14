<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_alfasearch
 *
 * @copyright   (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Module\AlfaSearch\Site\Dispatcher;

// use Joomla\CMS\Component\ComponentHelper;
// use Joomla\CMS\Helper\HelperFactoryAwareInterface;
// use Joomla\CMS\Helper\HelperFactoryAwareTrait;
// use Joomla\CMS\Language\Text;
// use Joomla\CMS\HTML\HTMLHelper;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
// use Alfa\Module\AlfaSearch\Site\Helper\AlfasearchHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher class for mod_alfasearch
 *
 * @since  4.4.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * Returns the layout data.
     *
     * @return  array
     *
     * @since   4.4.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();

        $wa = $this->app->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_search');
        $wa->useScript('mod_alfa_search.searchbar')
            ->useStyle('mod_alfa_search.searchbar');
        // $items = AlfaSearchHelper::getItemsFromDatabase();

//        echo '<pre>';
//        print_r($items);
//        echo '</pre>';

        // $data['alfa_items'] = $items;
        // ($data['params'])->get('text_field', '');  //to deutero pedio orizei thn default timh

        // $data['alfasearch'] = 'yo'; //auto tha epistrepsei sto view thn metavlhth $alfasearch
        // ara ola ta $data['params'] epistrefoun sto tmpl/default.php ws th metavlhth $params kai mporoume na kanoume access $params->text_field


        // if (($data['params'])->get('prepare_content', 1)) {
        //     ($data['module'])->content = HTMLHelper::_('content.prepare', ($data['module'])->content, '', 'mod_alfasearch.content');
        // }


        // $data['ariclesCount'] = $this->getHelperFactory()->getHelper('AlfaSearchHelper', $data)->getNumberOfArticles();
        // tha episrepsei thn timh $articlesCount sto tmpl/default.php

        return $data;
    }
}
