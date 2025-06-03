<?php
// display data is what we passed to the filelayout on render
$boxNowPostalCode = $displayData['selected_postal_code']??'';
$boxNowAddress = $displayData['selected_address']??'';
$boxNowLockerId = $displayData['selected_locker_id']??'';
$buttonBackgroundColor = $displayData['button_background_color'] ?? '#000000';
$buttonTextColor = $displayData['button_text_color']?? '#ffffff';

$inlineButtonStyle =  "
                    background-color:{$buttonBackgroundColor};
                    color:{$buttonTextColor};
                ";

$boxnowAddressText = (!empty($boxNowAddress) && !empty($boxNowPostalCode)) ? "{$boxNowAddress} , {$boxNowPostalCode}" : "Not selected";

// on locker select it calls saveCurrentCartBoxNowData via the main.js file we included onBeforeCompileHead
?>

<div id="boxNowData">

    <div id="boxNowAddress">Locker Address: <span><?= "$boxnowAddressText" ?></span></div>
    <input id="boxNowLockerIdHidden" type="hidden" value="<?= $boxNowLockerId?>" name="boxNowLockerIdHidden">

    <div id="boxNowButton">
        <a  class="btn"
            style="<?= $inlineButtonStyle?>"
            href="#!"
            onclick="document.querySelector('#boxnow-map-open').click();">
                <?php echo \Joomla\CMS\Language\Text::_('PLG_ALFA_SHIPMENTS_BOXNOW_SELECT_LOCKER'); ?>
        </a>
    </div>
</div>