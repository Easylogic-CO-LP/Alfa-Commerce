<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Plugin\PluginHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * FieldsHelper
 *
 * @since  3.7.0
 */
class ShipmentsHelper
{

    /**
     * Loads the fields plugins and returns an array of field types from the plugins.
     *
     * The returned array contains arrays with the following keys:
     * - label: The label of the field
     * - type:  The type of the field
     * - path:  The path of the folder where the field can be found
     *
     * @return  array
     *
     * @since   3.7.0
     */
    public static function getFieldTypes()
    {
        $plugin_types = [];

        $pluginGroup = 'alfa-shipments';

        $plugins = PluginHelper::getPlugin($pluginGroup);// Get a list of all plugins in the specified group

        foreach ($plugins as $plugin) {// Process each shipment payment group plugin.
            $plugin_types[] =
                [
                    'id' => $plugin->id,
                    'name' => $plugin->name,
                    'params' => $plugin->params
                ];
        }
        return $plugin_types;
    }



}
