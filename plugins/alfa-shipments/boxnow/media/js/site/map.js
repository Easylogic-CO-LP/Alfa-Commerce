// ISSUE: There are function/variable declarations needed outside onDOMContent loaded, which may (or may not) cause
//  problems in the future.


var _bn_map_widget_config = {
  // partnerId: 0,
  parentElement: '#boxnowmap',
  type: 'popup',
  afterSelect: function (selected) {

      console.log(selected);

    // Boxnow returns an object if the user selected a locker.
    if(typeof selected === 'object' && selected !== null && !Array.isArray(selected))   // Check if selected is an object.
    {

        document.querySelector("#boxNowAddress span").innerHTML = selected.boxnowLockerAddressLine1 + ", " + selected.boxnowLockerPostalCode;
        document.querySelector('#boxNowLockerIdHidden').value = selected.boxnowLockerId;


        // save the selected params by triggering saveCurrentCartBoxNowData function below
        let url = "index.php?option=com_alfa&task=plugin.trigger&type=alfa-shipments&name=boxnow&func=saveCurrentCartBoxNowData";
        const params = new URLSearchParams();
        params.append("boxNowPostalCode", selected.boxnowLockerPostalCode);
        params.append('boxNowAddress', encodeURIComponent(selected.boxnowLockerAddressLine1));
        params.append('boxNowLockerId', selected.boxnowLockerId);

        fetch(url + '&' + params.toString(), {
            method: "GET",
            headers: {"Content-Type": "application/json"},
            // body: params,
        }).catch(error => console.error("Error:", error));

        checkAndDisableFormButton();
    }
  }
};

function checkAndDisableFormButton(){
    let lockerIdValue = document.querySelector('#boxNowLockerIdHidden')?.value ?? null;
    let disabled = (lockerIdValue !== null && lockerIdValue.trim()==''); //will be null if not exist or empty if exist and disabled
    document.querySelector('[data-cart-outer] form [type="submit"]').disabled = disabled; // Disable submit button at start
}

// adds box now map widget script
(function(d){var e = d.createElement("script");e.src = "https://widget-cdn.boxnow.gr/map-widget/client/v5.js";e.async = true;e.defer =true;d.getElementsByTagName("head")[0].appendChild(e);})(document);

var submitButton;
document.addEventListener('DOMContentLoaded', function () {

    const cartContainer = document.querySelector('[data-cart-outer] form [type="submit"]');
    submitButton = cartContainer.querySelector('form [type="submit"]');
    
    checkAndDisableFormButton();

    document.addEventListener('alfaCartShipmentChanged', function (e) {
        checkAndDisableFormButton();
    });

    // function checkAndDisableFormButton(){
    //     let lockerIdValue = document.querySelector('#boxNowLockerIdHidden')?.value ?? null;
    //     let disabled = (lockerIdValue !== null && lockerIdValue.trim()==''); //will be null if not exist or empty if exist and disabled
    //     document.querySelector('[data-cart-outer] form [type="submit"]').disabled = disabled; // Disable submit button at start
    // }
});