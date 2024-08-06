<?php

/**
 * @version     CVS: 1.0.1
 * @package     com_alfa
 * @subpackage  mod_alfa
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2024 Easylogic CO LP
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Alfa\Module\Alfa\Site\Helper\AlfaHelper;

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wr = $wa->getRegistry();
$wr->addRegistryFile('media/mod_alfa/joomla.asset.json');
$wa->useStyle('mod_alfa.style')
    ->useScript('mod_alfa.script');

require ModuleHelper::getLayoutPath('mod_alfa', $params->get('content_type', 'blank'));
