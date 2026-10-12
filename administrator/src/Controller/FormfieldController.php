<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

/**
 * Form field controller class.
 *
 * @since  1.0.0
 */
class FormfieldController extends FormController
{
    use HasFormPermissions;

    protected $view_list = 'formfields';

    /**
     * Run a batch operation, forcing the Formfield model and redirecting back
     * to the form-fields list afterwards.
     *
     * @param object|null $model Unused; the Formfield model is always loaded internally
     *
     * @return bool True on success
     *
     * @since  1.0.0
     */
    public function batch($model = null)
    {
        $this->checkToken();

        $this->setRedirect(
            Route::_('index.php?option=com_alfa&view=formfields' . $this->getRedirectToListAppend(), false),
        );

        // Regrouping edits the selected fields, and the model's bulk UPDATE checks nothing itself.
        if (!AlfaHelper::can(section: 'formfields', action: 'edit', user: $this->app->getIdentity())) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'), 'error');

            return false;
        }

        $model = $this->getModel('Formfield', 'Administrator', []);

        return parent::batch($model);
    }
}
