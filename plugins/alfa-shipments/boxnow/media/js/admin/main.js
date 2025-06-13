document.addEventListener("DOMContentLoaded", (event) => {
    
    // Configure the page's buttons.
    configureForm();

    // document.querySelector("body").addEventListener('click', (event) => {
        // if (event.target.matches('.print-invoice, .print-invoice *')) {
        //     // alert('print invoice');
        //     let orderIdElement = event.target.closest('[data-orderid]');
        //     if (orderIdElement) {
        //         let orderId = orderIdElement.getAttribute('data-orderid');
        //         console.log(orderId); // Now you have the value of data-orderid
        //     }

        //     // Find the closest <tr> and determine its index
        //     let tr = event.target.closest('tr');
        //     if (tr) {
        //         let trIndex = Array.from(tr.parentNode.children).indexOf(tr);
        //         console.log('The clicked <tr> is the'+(trIndex+1)+'th row.'); // Index is 0-based, so add 1 for a human-readable index
        //     }
        // }
    // });
    
    // Logic handling the request delivery boxnow button.
    // document.getElementById("boxnow_request_delivery_btn").addEventListener("click", fn => {

});


/*
 *   BUTTON CONFIGURATION.
 */

// Configures (css) the selected cancel button to appear active.


// Configures (css) the selected create label button to appear active.
function configureCreateLabelButtonActive(button, data){
    button.classList.add("box-now-links");
    button.classList.remove("box-now-inactive");
    button.href = data.fullPath;
    button.innerText = "Voucher"; // button.querySelector('span').querySelector('a').innerHTML = "Voucher";
    button.classList.remove("btn-warning")
    button.classList.add("btn-success");
}

// Updates all buttons 
function updateButtonsAfterLabelCreate(data){
    
    let createLabelButtons = document.querySelectorAll("#boxnow_parcel_print_label_button");
    let cancelDeliveryButtons = document.querySelectorAll("#boxnow_parcel_cancel_parcel_button");
    data = data.data;
    
    data.forEach((currentData, index) => {
        // Activate buttons of parcels that have not been cancelled.
        cancelDeliveryButtons.forEach((button, index) => {
            
            // If the parcel is in the created label's ID list, then it's not cancelled,
            // so we update the buttons.
            if(cancelDeliveryButtons[index].getAttribute('data-parcel-id') === currentData.id){
                // Create label buttons.
                configureCreateLabelButtonActive(createLabelButtons[index], currentData);

                disableCancelButton(cancelDeliveryButtons[index],false,currentData.id); //ebable cancel buttons
            }    
        })
    })               
}

// Configures the boxnow parcel form appropriately.
function configureForm(){
    // Get parcel data.
    // let orderID = '$orderID';
    // let vouchersURL = '$basicVoucherURL';
    // let rawParcelData = '{$parcelData}';

    let parcelData = rawParcelData ? JSON.parse(rawParcelData) : null;

    if(parcelData === null)
        return;

    let parcels = parcelData.parcels;

    disableAllBoxNowFormActions(true);
    
    // Find create label and cancel delivery buttons.
    let createLabelButtons = document.querySelectorAll(".boxnow-print-label");
    let cancelDeliveryButtons = document.querySelectorAll(".boxnow-cancel-voucher");
    
    // For every create label button,
    createLabelButtons.forEach((button, index) => {
        
        button.classList.remove("box-now-inactive");
        
        // For every active parcel,
        if(parcels[index].cancelled === 0){
            // Set correct link, text and style.
            if(parcels[index].created_sticker === 1){
                button.href = vouchersURL+ '/' + orderId + "_" + parcels[index].id + ".pdf";
                button.innerText = "Voucher"; // button.querySelector('span').querySelector('a').innerHTML = "Voucher";
                button.classList.remove("btn-warning")
                button.classList.add("btn-success");
            }
            else{
                button.classList.add("box-now-inactive");
            }
            
        }   // Cancelled parcel.
        else{
            button.innerText = "Parcel cancelled"; //button.querySelector('span').querySelector('a').innerHTML = "Parcel cancelled";
            button.classList.add("box-now-inactive");
        }
    });
    
    // For every cancel delivery button,
    cancelDeliveryButtons.forEach((button, index) => {
        
        // For uncancelled parcels,
        if(parcels[index].cancelled === 0){
            disableCancelButton(button,false, parcels[index].id);//enable cancel button
        }// For cancelled parcels
        else{
            disableCancelButton(button,true);//disable cancel button
        }
        
    })
    
}


// Turns boxnow delivery inputs on or off with the disabled tag.
function disableAllBoxNowFormActions(disable){
    document.getElementById("jform_shipment_order_price").disabled = disable;
    document.getElementById("jform_shipment_payment_mode1").disabled = disable;
    document.getElementById("jform_shipment_amount_to_be_collected").disabled = disable;

    // Disable all active repeatable-fields inputs, as they were brought from an already requested delivery.
    let repeatTable = document.querySelector("#subfieldList_jform_shipment_packages");
    repeatTable.querySelectorAll("input").forEach(input => {
        input.disabled = disable;
    });
   
    // Also disable the buttons allowing field generation.
    repeatTable.querySelectorAll(".group-add").forEach(btn => {
        btn.disabled = disable;
    });
    repeatTable.querySelectorAll(".group-remove").forEach(btn => {
        btn.disabled = disable;
    });
}

function disableCancelButton(button,disable,parcelID=0){
    if(disable){
        button.classList.remove("box-now-inactive");
        button.querySelector('span').innerHTML = "Parcel cancelled";
        button.classList.remove("btn-warning");
        button.classList.add("btn-danger");
        button.classList.add("box-now-inactive");
        button.setAttribute('data-parcel-id',0);
    }else{
        button.classList.remove("box-now-inactive");
        button.querySelector('span').innerHTML = "Cancel delivery";
        button.querySelector('span').style.fontWeight = "bold";
        button.classList.remove("btn-warning");
        button.classList.add("btn-danger");
        button.setAttribute('data-parcel-id',parcelID);
        button.addEventListener("click", () => cancelIndividualParcel(parcelID));
    }
}