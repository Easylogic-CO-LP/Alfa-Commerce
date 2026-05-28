<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Multilanguage;

/**
 * Frontend association resolver for the language switcher.
 *
 * com_alfa keeps ONE record id shared across every content language — the
 * translations live in per-language auxiliary tables (#__alfa_<entity>_<lang>),
 * each row keyed by that same id. There are therefore no per-language sibling
 * rows and no #__associations entries: the SAME id IS the association under
 * every installed language. Only the alias (URL slug) differs per language, and
 * the component Router emits the correct one because Route::_() carries &lang.
 *
 * mod_languages / plg_system_languagefilter call
 *   $component->getAssociationsExtension()->getAssociationsForItem()
 * which delegates here. We return an array keyed by language code (e.g.
 * 'el-GR', 'en-GB') whose values are internal URLs carrying &lang=<code>;
 * Route::_() + the RouterView then build /el/... and /en/... server-side.
 *
 * @since  1.0.1
 */
class AssociationHelper
{
    /**
     * Build the per-language URL set for the current (or given) Alfa page.
     *
     * Only the detail and category-listing views take part in language
     * switching; everything else returns [] so Joomla falls back to native
     * menu-item associations.
     *
     * @param   int          $id    Record id (0 = read from the request).
     * @param   string|null  $view  View name (null = read from the request).
     *
     * @return  array  [ 'el-GR' => 'index.php?...&lang=el-GR', 'en-GB' => … ]
     *
     * @since   1.0.1
     */
    public static function getAssociations(int $id = 0, ?string $view = null): array
    {
        if (!Multilanguage::isEnabled()) {
            return [];
        }

        $input = Factory::getApplication()->getInput();
        $view  = $view ?: $input->get('view');
        $id    = $id ?: $input->getInt('id');

        switch ($view) {
            case 'item':
                if (!$id) {
                    return [];
                }
                // The id (and its category) are shared across languages; only the
                // emitted slug differs, which the Router handles via &lang.
                $base   = 'index.php?option=com_alfa&view=item&id=' . $id;
                $catId  = $input->getInt('category_id');
                $base  .= $catId ? '&category_id=' . $catId : '';
                break;

            case 'manufacturer':
                if (!$id) {
                    return [];
                }
                $base = 'index.php?option=com_alfa&view=manufacturer&id=' . $id;
                break;

            case 'items':
                // Category product listing (id lives in category_id, not id).
                $catId = $input->getInt('category_id');
                $base  = 'index.php?option=com_alfa&view=items' . ($catId ? '&category_id=' . $catId : '');
                break;

            default:
                return [];
        }

        $associations = [];

        // Key by lang_code exactly the way mod_languages consumes it.
        foreach (LanguageHelper::getLanguages() as $language) {
            if (empty($language->lang_code) || $language->lang_code === '*') {
                continue;
            }

            $associations[$language->lang_code] = $base . '&lang=' . $language->lang_code;
        }

        return $associations;
    }
}
