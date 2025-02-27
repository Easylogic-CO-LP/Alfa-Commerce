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
// use Joomla\CMS\Event\CustomFields\AfterPrepareFieldEvent;
// use Joomla\CMS\Event\CustomFields\BeforePrepareFieldEvent;
// use Joomla\CMS\Event\CustomFields\GetTypesEvent;
// use Joomla\CMS\Event\CustomFields\PrepareDomEvent;
// use Joomla\CMS\Event\CustomFields\PrepareFieldEvent;
// use Joomla\CMS\Factory;
// use Joomla\CMS\Fields\FieldsServiceInterface;
// use Joomla\CMS\Form\Form;
// use Joomla\CMS\Form\FormHelper;
// use Joomla\CMS\Language\Multilanguage;
// use Joomla\CMS\Layout\LayoutHelper;
// use Joomla\CMS\Plugin\PluginHelper;
// use Joomla\Component\Fields\Administrator\Model\FieldModel;
// use Joomla\Component\Fields\Administrator\Model\FieldsModel;
// use Joomla\Database\ParameterType;
// use Joomla\Event\DispatcherInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * FieldsHelper
 *
 * @since  3.7.0
 */
class PaymentsHelper
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

         // load all payment plugins from dppayment folder and attach the fields to the form
        $pluginGroup = 'alfa-payments';
        // $pluginDir = JPATH_PLUGINS . '/' . $pluginGroup;
        // PluginHelper::importPlugin($pluginGroup);

        $plugins = PluginHelper::getPlugin($pluginGroup);// Get a list of all plugins in the specified group

        // $lang = Factory::getApplication()->getLanguage();

        // echo '<pre>';
        // print_r($plugins);
        // echo '</pre>';
        // exit;

        foreach ($plugins as $plugin) {// Process each dppayment group plugin
            
            $plugin_types[] =   
                                [
                                    'id' => $plugin->id,
                                    'name' => $plugin->name,
                                    'params' => $plugin->params

                                ];

            // $lang->load('plg_'.$pluginGroup.'_'.$pluginName, JPATH_ADMINISTRATOR , $lang->getTag(), true);
            // Factory::getLanguage()->load('plg_dppayment_viva', JPATH_SITE.'/plugins/'.$pluginGroup.'/'.$plugin->name);

            // $paymentMethodsFieldText = Text::_('PLG_'.$pluginGroup.'_'.$plugin->name.'_DISPLAY_NAME');
            // $paymentMethodsFieldValue = $pluginName;
            // $this->paymentMethodOptions[$paymentMethodsFieldValue] = $paymentMethodsFieldText;
        }
        // end of load all plugins from dppayment

        // print_r($plugin_types);
        // exit;
        return $plugin_types;
    }



}
