<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Versioning\VersionableControllerTrait;
use Joomla\CMS\Router\Route;

/**
 * Item controller class.
 *
 * @since  1.0.1
 */
class ItemController extends FormController
{
    use VersionableControllerTrait;

    protected $view_list = 'items';

    /**
     * Method to run batch operations.
     *
     * @param   object  $model  The model.
     *
     * @return  boolean   True if successful, false otherwise and internal error is set.
     *
     * @since   1.6
     */
    public function batch($model = null)
    {
        $this->checkToken();

        // Set the model
        /** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
        $model = $this->getModel('Item', 'Administrator', []);

        // Preset the redirect
        $this->setRedirect(Route::_('index.php?option=com_alfa&view=items' . $this->getRedirectToListAppend(), false));

        return parent::batch($model);
    }
}
