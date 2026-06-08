<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * Class AlfaFrontendHelper
 *
 * @since  1.0.1
 */
class AlfaHelper
{
    /**
     * Return the com_alfa component global parameters/settings.
     *
     * @return \Joomla\Registry\Registry The component parameters.
     *
     * @since   1.0.1
     */
    public static function getGeneralSettings()
    {
        // $cache = Factory::getCache('com_alfa','');
        // $cacheKey = "com_alfa_settings";
        // $cachedSettings = $cache->get($cacheKey);

        // if(!empty($cachedSettings)) {
        //     return $cachedSettings;
        // }

        $settings = ComponentHelper::getParams('com_alfa');

        // $cache->store($settings, $cacheKey);

        return $settings;
    }

    /**
     *  Filters out the payment methods that are not common in all items.
     *
     *
     * @return array the common payment methods.
     */
    public static function getFilteredMethods($categories, $manufacturers, $usergroups, $userId, $baseTable = 'payment')
    {
        $categories[] = 0; //to support all categories for payment method
        $manufacturers[] = 0; //to support all manufacturers for payment method
        $usergroups[] = 0; //to support all usergroups for payment method
        // $users[] = 0; //to support all users for payment method

        // GET ALL PAYMENT METHODS
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
            ->select('m.*')  // Select all payment method fields
            ->select('GROUP_CONCAT(DISTINCT mc.category_id) AS categories')  // Get all unique categories for the payment
            ->select('GROUP_CONCAT(DISTINCT mm.manufacturer_id) AS manufacturers')  // Get all unique manufacturers for the payment
            ->select('GROUP_CONCAT(DISTINCT mu.user_id) AS users')  // Get all unique manufacturers for the payment
            ->select('GROUP_CONCAT(DISTINCT mug.usergroup_id) AS groups')  // Get all unique manufacturers for the payment
            ->from('#__alfa_' . $baseTable . 's AS m')  // Main table

            // Join related tables
            ->join('LEFT', '#__alfa_' . $baseTable . '_categories AS mc ON mc.' . $baseTable . '_id = m.id')
            ->join('LEFT', '#__alfa_' . $baseTable . '_manufacturers AS mm ON mm.' . $baseTable . '_id = m.id')
            ->join('LEFT', '#__alfa_' . $baseTable . '_users AS mu ON mu.' . $baseTable . '_id = m.id')
            ->join('LEFT', '#__alfa_' . $baseTable . '_usergroups AS mug ON mug.' . $baseTable . '_id = m.id')
            ->where('m.state = 1')

            // Group by payment method ID to combine categories and manufacturers
            ->group('m.id');

        // MULTILINGUAL: resolve the method name + description in the active
        // language. 1:1 join on m.id, so the GROUP BY above is unaffected.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'm',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_' . $baseTable . 's',
            langPrimaryColumn: 'id_' . $baseTable,
            fields:            ['name', 'description'],
        );

        $db->setQuery($query);
        $filteredMethods = $db->loadObjectList('id');

        // FILTER PAYMENT METHODS
        // Compare ids given with payment ids.
        foreach ($filteredMethods as $index => $method) {
            $isValid = true;

            $methodCategories = explode(',', $method->categories);
            $methodManufacturers = explode(',', $method->manufacturers);
            $methodUsers = explode(',', $method->users);
            $methodUsersgroups = explode(',', $method->groups);

            //if payment method don't have common categories with those given turn is valid to false to unset it
            if (empty(array_intersect($categories, $methodCategories))) {
                $isValid = false;
            }

            //if payment method dont have common manufacturers with those given turn is valid to false to unset it
            if (empty(array_intersect($manufacturers, $methodManufacturers))) {
                $isValid = false;
            }

            //if payment method don't have common manufacturers with those given turn is valid to false to unset it
            if (empty(array_intersect($usergroups, $methodUsersgroups))) {
                $isValid = false;
            }

            // check if 0 is setted on method to allow all users or at list the user is inside the users array of the method
            if (!in_array(0, $methodUsers) && !in_array($userId, $methodUsers)) {
                $isValid = false;
            }

            if (!$isValid) {
                unset($filteredMethods[$index]);
            }
        }

        return $filteredMethods;
    }

    /**
     * Normalise an HTML string to plain, search-friendly text.
     * Optionally strips scripts/styles/comments and remaining tags (block elements become spaces),
     * replaces disallowed characters with spaces, optionally drops isolated punctuation, then collapses
     * whitespace.
     *
     * @param string $html The HTML/text to clean.
     * @param bool $removeTags Strip remaining HTML tags when true.
     * @param bool $removeScripts Strip <script>/<style> blocks and HTML comments when true.
     * @param bool $removeIsolatedPunctuation Remove standalone punctuation characters when true.
     *
     * @return string The cleaned, whitespace-collapsed text.
     *
     * @since   1.0.1
     */
    public static function cleanContent(
        string $html,
        bool $removeTags = false,
        bool $removeScripts = false,
        bool $removeIsolatedPunctuation = false,
    ): string {
        mb_internal_encoding('UTF-8');

        // Optionally remove <script>, <style>, and comments
        if ($removeScripts) {
            $search = [
                '@<script[^>]*?>.*?</script>@si', // Remove scripts
                '@<style[^>]*?>.*?</style>@siU',  // Remove styles
                '@<![\s\S]*?--[ \t\n\r]*>@',       // Remove HTML comments
            ];
            $html = preg_replace($search, '', $html);
        }

        // Optionally remove remaining HTML tags
        if ($removeTags) {
            // Convert block-level elements and <br> to spaces BEFORE stripping tags
            $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
            $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|td|th|blockquote|pre)>/i', ' ', $html);

            $html = strip_tags($html);
        }

        // Replace non-letter/number characters (except punctuation) with spaces
        $pattern = '/[^\p{L}\p{N}\'\"\.\!\?\;\,\/\-\:\&@]+/u';
        $html = preg_replace($pattern, ' ', $html);

        // Optionally remove isolated punctuation "words"
        if ($removeIsolatedPunctuation) {
            $html = preg_replace('/\s*[\.\!\?\;\,\/\-\:\&@]\s*/u', ' ', $html);
        }

        // Collapse multiple spaces and trim
        $html = preg_replace('/\s\s+/u', ' ', $html);
        $html = trim($html);

        return $html ?? '';
    }
    //	public function pluginLayout($fileName){
    //		$path = dirname(PluginHelper::getLayoutPath($this->_type, $this->_name, $fileName));
    //		return new FileLayout($fileName,$path);
    //	}
}
