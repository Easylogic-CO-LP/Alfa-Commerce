<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

/**
 * Form field controller class.
 *
 * @since  1.0.0
 */
class FormfieldController extends FormController
{
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

        $model = $this->getModel('Formfield', 'Administrator', []);

        $this->setRedirect(
            Route::_('index.php?option=com_alfa&view=formfields' . $this->getRedirectToListAppend(), false),
        );

        return parent::batch($model);
    }
}
