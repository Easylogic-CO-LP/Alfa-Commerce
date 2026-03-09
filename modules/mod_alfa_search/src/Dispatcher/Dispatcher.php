<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_alfasearch
 *
 * @copyright   (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Module\AlfaSearch\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

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

	    $basePath = Uri::base(true);
	    $data['formAction'] = $basePath . Route::_('index.php?option=com_alfa&view=items&category_id=0');
	    $data['ajaxAction'] = $basePath . '/index.php?option=com_ajax&module=alfa_search&method=get&format=json';

	    $filters = $this->input->get('filter', array(), 'array');
	    $data['currentSearch'] = isset($filters['search']) ? $filters['search'] : '';

        return $data;
    }
}
