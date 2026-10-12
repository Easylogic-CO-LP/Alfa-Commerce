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
use Joomla\CMS\MVC\Controller\FormController;

/**
 * Document type edit controller. Types are never created from the
 * backend (registry rows come from the installer or package providers),
 * so `add` is blocked at the controller level too.
 *
 * @since  1.3.0
 */
class DocumenttypeController extends FormController
{
    use HasFormPermissions;

    /**
     * View used by the cancel/save toolbar buttons.
     *
     * @var string
     */
    protected $view_list = 'documenttypes';

    /**
     * Creation is not a backend capability, whatever the documents permissions say
     * (overrides the trait's allowAdd; editing follows the documents edit permission).
     *
     * @param array $data Form payload.
     *
     * @return bool Always false.
     *
     * @since  1.3.0
     */
    protected function allowAdd($data = [])
    {
        return false;
    }
}
