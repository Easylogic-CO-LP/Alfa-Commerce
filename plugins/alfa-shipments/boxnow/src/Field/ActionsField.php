<?php

namespace Joomla\Plugin\AlfaShipments\Boxnow\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
// use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

class ActionsField extends FormField
{
    protected $type = 'actions';

    protected function getInput()
    {
        $app = Factory::getApplication();
        $input = $app->input;


        // $orderId = $this->form->getData()->get('id', 0);
        $orderId = $input->get('id', 0);
        // print_r($this->form->getData());

        return <<<HTML
        <div data-orderid="{$orderId}" class="d-flex flex-row flex-wrap gap-1 me-1">
            <a href="#!" target="_blank" type="button" class="boxnow-print-label box-now-inactive box-now-links btn btn-warning d-flex align-items-center justify-content-center px-3 py-2 text-nowrap">
                <i class="fas fa-clipboard"></i> 
                <span class="ms-2">Voucher not printed</span>
            </a>

            <button type="button" class="boxnow-cancel-voucher btn btn-warning d-flex align-items-center justify-content-center px-3 py-2 text-nowrap box-now-inactive">
                <i class="fas fa-caret-left"></i> 
                <span class="ms-2">Delivery not Requested</span>
            </button>
        </div>
        HTML;


    }

}
