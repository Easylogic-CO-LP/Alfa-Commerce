<?php

//use Joomla\CMS\Language\Text;

//extract($displayData);

use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$shipments      = $this->order->shipments;
$shipments_subform        = $this->form->getField('shipments')->loadSubForm();
$shipmentFields = $shipments_subform->getFieldset(); // Optional if you want to control field order


if (!empty($shipments)) : ?>
    <table class="table table-striped table-bordered">
        <thead>
        <tr>
			<?php foreach ($shipmentFields as $field): ?>

				<?php if ($field->getAttribute('type') !== 'hidden') : ?>
                    <th><?php echo Text::_($field->getAttribute('label')); ?></th>
				<?php endif; ?>
			<?php endforeach; ?>
            <th>
                Actions
            </th>
        </tr>
        </thead>
        <tbody id="shipment-rows" class="js-sortable" data-base-name="shipments">
		<?php foreach ($shipments as $index => $shipment): ?>
            <tr>
				<?php foreach ($shipments_subform->getGroup('') as $index => $field): ?>
					<?php

					// Set values into the subform for this shipment
					$fieldName = $field->fieldname;
//					$shipments_subform->setValue($fieldName, null, $shipment->{$fieldName} ?? $field->getAttribute('default'));
					?>

                    <td style="<?php echo $field->getAttribute('type') === 'hidden' ? 'display: none;' : ''; ?>">
						<?php
                        if($fieldName == 'items'){
                            echo 'Items should be integrated to work';
                        }else{
	                        echo $shipment->{$fieldName};
                        }

						//                        echo $field->renderField([
						//							'hiddenLabel'       => true,
						//							'hiddenDescription' => true,
						//						]);
						?>
                    </td>

				<?php endforeach; ?>
                <td>
					<?php printShipmentModal('Edit','Edit Shipment',$this->order->id,$shipment->id); ?>

					<?php
					//                    echo '<pre>';
					//                    print_r($shipment);
					//	                echo '</pre>';

					?>
                </td>
            </tr>
            <!--            <tr class="shipment-details-row">-->
            <!--                <td colspan="--><?php //echo count($subform->getGroup('')); ?><!--">-->
            <!--                    --><?php //printShipmentModal('Edit','Edit shipment',$shipment->id); ?>
            <!--                </td>-->
            <!--            </tr>-->

		<?php endforeach; ?>
        </tbody>
    </table>

<?php else : ?>
    <p>No shipments available.</p>
<?php endif; ?>

<?php printShipmentModal('Add Shipment','Create Shipment',$this->order->id); ?>

    <script>

        document.addEventListener('DOMContentLoaded', function () {
            // Attach a click event listener to the table body
            document.querySelector('tbody').addEventListener('click', function (event) {
                // Check if the clicked element has the class 'remove-shipment'
                if (event.target && event.target.classList.contains('remove-shipment')) {
                    if (confirm('Are you sure you want to remove this shipment?')) {
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

function printShipmentModal($openButtonText,$modalTitle,$orderId,$shipmentId=0){

	$title = $modalTitle;

	$footer_actions = [
		'<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Save</button>',
		'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>'
	];

	if($shipmentId>0){
		$footer_actions[].='<button type="button" class="btn btn btn-danger" data-bs-dismiss="modal">Remove</button>';
	}

	echo HTMLHelper::_('bootstrap.renderModal', 'shipmentOrderViewModal-'.$shipmentId, [
		'title' => $title,
		'url' => 'index.php?option=com_alfa&view=order&layout=edit_shipment&id='.$shipmentId.'&id_order='.$orderId.'&tmpl=component', // URL that renders the layout
		'height' => '800px', // Use viewport height
		'width' => '100%',
		'bodyHeight' => '800px', // Make body take up most of the space
		'modalWidth' => '100%',
		'footer' => implode("\n", $footer_actions)
	]);
	echo '<a href="#shipmentOrderViewModal-'.$shipmentId.'" data-bs-toggle="modal" role="button" class="btn btn-primary">'
		.$openButtonText.
		'</a>';

}
