<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\Model\OrderPartsModel;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * One part of an order as a list: each row with every field the order read gives it, so a field the order read gains
 * is listed here too.
 *
 * @since  2.2.2
 */
abstract class OrderPartsJsonApiView extends BaseApiView
{
    /**
     * The part's rows, each with the order read's fields.
     *
     * @param array|null $items The rows to render; the model's page when null.
     *
     * @return string
     *
     * @since  2.2.2
     */
    public function displayList(?array $items = null)
    {
        /** @var OrderPartsModel $model */
        $model = $this->getModel();
        $this->fieldsToRenderList = $model->fields();

        return parent::displayList($items);
    }
}
