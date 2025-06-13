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

// on locker select it calls saveCurrentCartBoxNowData via the main.js file we included onBeforeCompileHead
?>


<div id="boxNowData">
    <div id="boxNowPostalCode">Postal code: <span><?= $boxNowPostalCode ?></span></div>
    <input id="boxNowPostalCodeHidden" type="hidden" value="<?= $boxNowPostalCode?>" name="boxNowPostalCodeHidden">

    <div id="boxNowAddress">Locker Address: <span><?= $boxNowAddress?></span></div>
    <input id="boxNowAddressHidden" type="hidden" value="<?= $boxNowAddress?>" name="boxNowAddressHidden">

    <div id="boxNowLockerId">Locker id: <span><?= $boxNowLockerId?></span></div>
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