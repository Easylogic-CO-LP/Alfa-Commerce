<?php

//use Joomla\CMS\Language\Text;

//extract($displayData);

use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$payments      = $this->order->payments;
$payments_subform        = $this->form->getField('payments')->loadSubForm();
$paymentFields = $payments_subform->getFieldset(); // Optional if you want to control field order


if (!empty($payments)) : ?>
    <table class="table table-striped table-bordered">
        <thead>
        <tr>
			<?php foreach ($paymentFields as $field): ?>

				<?php if ($field->getAttribute('type') !== 'hidden') : ?>
                    <th><?php echo Text::_($field->getAttribute('label')); ?></th>
				<?php endif; ?>
			<?php endforeach; ?>
            <th>
                Actions
            </th>
        </tr>
        </thead>
        <tbody id="payment-rows" class="js-sortable" data-base-name="payments">
		<?php foreach ($payments as $index => $payment): ?>
            <tr>
				<?php foreach ($payments_subform->getGroup('') as $index => $field): ?>
					<?php

					// Set values into the subform for this payment
					$fieldName = $field->fieldname;
//					$payments_subform->setValue($fieldName, null, $payment->{$fieldName} ?? $field->getAttribute('default'));
					?>

                    <td style="<?php echo $field->getAttribute('type') === 'hidden' ? 'display: none;' : ''; ?>">
						<?php
                            echo $payment->{$fieldName};
//                        echo $field->renderField([
//							'hiddenLabel'       => true,
//							'hiddenDescription' => true,
//						]);
                        ?>
                    </td>

				<?php endforeach; ?>
                <td>
	                <?php printPaymentModal('Edit','Edit Payment',$this->order->id,$payment->id); ?>

                    <?php
//                    echo '<pre>';
//                    print_r($payment);
//	                echo '</pre>';

                    ?>
                </td>
            </tr>
<!--            <tr class="payment-details-row">-->
<!--                <td colspan="--><?php //echo count($subform->getGroup('')); ?><!--">-->
<!--                    --><?php //printPaymentModal('Edit','Edit payment',$payment->id); ?>
<!--                </td>-->
<!--            </tr>-->

			<?php endforeach; ?>
        </tbody>
    </table>

<?php else : ?>
    <p>No payments available.</p>
<?php endif; ?>

<?php printPaymentModal('Add Payment','Create Payment',$this->order->id); ?>

<script>

    document.addEventListener('DOMContentLoaded', function () {
        // Attach a click event listener to the table body
        document.querySelector('tbody').addEventListener('click', function (event) {
            // Check if the clicked element has the class 'remove-payment'
            if (event.target && event.target.classList.contains('remove-payment')) {
                if (confirm('Are you sure you want to remove this payment?')) {
                    // Find the closest parent <tr> and remove it
                    // const row = event.target.closest('tr');
                    // if (row) {
                    // 	row.remove();
                    // }
                }
            }
        });
    });

</script>


<?php

function printPaymentModal($openButtonText,$modalTitle,$orderId,$paymentId=0){

	$title = $modalTitle;

	$footer_actions = [
			'<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Save</button>',
	    	'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>'
	    ];

	if($paymentId>0){
		$footer_actions[].='<button type="button" class="btn btn btn-danger" data-bs-dismiss="modal">Remove</button>';
	}

    echo HTMLHelper::_('bootstrap.renderModal', 'paymentOrderViewModal-'.$paymentId, [
	    'title' => $title,
	    'url' => 'index.php?option=com_alfa&view=order&layout=edit_payment&id='.$paymentId.'&id_order='.$orderId.'&tmpl=component', // URL that renders the layout
	    'height' => '800px', // Use viewport height
	    'width' => '100%',
	    'bodyHeight' => '800px', // Make body take up most of the space
	    'modalWidth' => '100%',
	    'footer' => implode("\n", $footer_actions)
	]);
    echo '<a href="#paymentOrderViewModal-'.$paymentId.'" data-bs-toggle="modal" role="button" class="btn btn-primary">'
	    	.$openButtonText.
		'</a>';

}
