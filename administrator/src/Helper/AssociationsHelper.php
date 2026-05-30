<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\AssociationHelper;
use Joomla\CMS\Association\AssociationExtensionHelper;

/**
 * Association extension helper, registered on the component via
 * setAssociationExtension() (see services/provider.php). The frontend language
 * switcher and plg_system_languagefilter call getAssociationsForItem(), which we
 * delegate to the site-side AssociationHelper (same pattern as com_content).
 *
 * Mirrors com_content: this Administrator helper intentionally references the
 * Site helper namespace; the Site namespace is registered for the component on
 * the frontend where the switcher runs.
 *
 * @since  1.0.1
 */
class AssociationsHelper extends AssociationExtensionHelper
{
    /**
     * The extension name.
     *
     * @var  string
     * @since  1.0.1
     */
    protected $extension = 'com_alfa';

    /**
     * Item types that take part in language switching.
     *
     * @var  string[]
     * @since  1.0.1
     */
    protected $itemTypes = ['item', 'manufacturer', 'items'];

    /**
     * This component supports associations.
     *
     * @var  boolean
     * @since  1.0.1
     */
    protected $associationsSupport = true;

    /**
     * Get the associations for an item, keyed by language code.
     *
     * Called with no arguments by mod_languages / languagefilter, so the site
     * helper resolves the current view + id from the request itself.
     *
     * @param   integer      $id    Id of the item.
     * @param   string|null  $view  Name of the view.
     *
     * @return  array
     *
     * @since   1.0.1
     */
    public function getAssociationsForItem($id = 0, $view = null)
    {
        return AssociationHelper::getAssociations((int) $id, $view);
    }
}
