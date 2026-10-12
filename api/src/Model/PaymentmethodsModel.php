<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\PaymentsModel as AdminPaymentsModel;

/**
 * API payment methods list: the admin grid, reached under the resource name.
 *
 * ApiController resolves a list model from the content type verbatim, and
 * ApiMVCFactory only falls back to an Administrator model of the SAME name.
 * Without this the list route would find no model at all and answer 500.
 *
 * @since  1.10.0
 */
class PaymentmethodsModel extends AdminPaymentsModel
{
}
