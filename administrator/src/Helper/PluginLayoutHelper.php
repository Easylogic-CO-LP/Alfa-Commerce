<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\PluginHelper;

defined('_JEXEC') or die;

/**
 * Class AlfaFrontendHelper
 *
 * @since  1.0.1
 */
class PluginLayoutHelper
{
    public static function pluginLayout($pluginType, $pluginName, $fileName): FileLayout
    {
        if (empty($pluginType) || empty($pluginName) || empty($fileName)) {
            return self::getEmptyLayout();
        }

        $path = dirname(PluginHelper::getLayoutPath($pluginType, $pluginName, $fileName));

        if (file_exists($path . '/' . $fileName . '.php')) {
            return new FileLayout($fileName, $path);
        } else {
            if (JDEBUG) {
                Factory::getApplication()->enqueueMessage(
                    "Plugin layout not found: $pluginType / $pluginName / $fileName.",
                    'warning'
                );
            }

            return self::getDefaultPluginLayout($pluginType, $fileName);
        }

    }

    public static function getDefaultPluginLayout($type, $fileName): FileLayout
    {
        $layoutType = explode('-', $type)[1] ?? '';
        $layoutFile = $fileName;
        return new FileLayout($layoutType.'.'.$layoutFile, JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
    }

    public static function getEmptyLayout(): FileLayout
    {
        $empty_layout = new FileLayout('non.existing.layout'); // Or any layout ID that doesn’t exist
        $empty_layout->clearIncludePaths(); // Optional: ensures no valid paths are searched
        return $empty_layout; // dummy empty layout which with render will output ''
    }


}
