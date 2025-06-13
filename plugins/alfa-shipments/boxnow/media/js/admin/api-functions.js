function requestDelivery(){
            
    // Make sure all necessary data is present.
    let orderPrice = document.getElementById("jform_shipment_order_price");
    let paymentMode = document.querySelector('input[name="jform[shipment][payment_mode]"]:checked');
    let collectedAmount = document.getElementById("jform_shipment_amount_to_be_collected");
    
    if( orderPrice.value === '' || 
        paymentMode.value === '' ||
        collectedAmount.value === ''){
        // TODO: Load alert messages dynamically via PHP for multilingual purposes.
        alert("Insufficient data to request a delivery.");  
        return;
    }
    
    // Make sure all necessary package data is present.
    let packagesTable = document.querySelectorAll("#subfieldList_jform_shipment_packages tbody tr");
    if(packagesTable.length <= 0){
        alert("No packages configured for request.");
        return;
    }
    
    // Collect packages' data.
    let tableRowData = [];
    let packageValue, packageWeight, packageCompartmentSize;
    
    packagesTable.forEach((tr, index) => {
        // id*="myID", checks if the id contains myID as part of its string. 
        packageValue = tr.querySelector('input[id*="packages_value"]').value;
        packageWeight = tr.querySelector('input[id*="packages_weight"]').value;
        packageCompartmentSize = tr.querySelector('input[type="radio"][name*="compartment_size"]:checked').value;
        tableRowData.push({
            "package_value" : packageValue,
            "package_weight" : packageWeight,
            "package_compartment_size" : packageCompartmentSize
        });
    });
    
    let fetchData = {
        "order_price" : orderPrice.value,
        "payment_mode" : paymentMode.value,
        "collected_amount" : collectedAmount.value,
        "packages_data" : tableRowData
    }
    
    let url = fetchRequestDeliveryURL;
    
    fetch(url, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(fetchData)
    })
    .then(response => response.json())
    .then(response => {
        if(response.success === true){
            // Turn off input fields.
            disableAllBoxNowFormActions(true);
            // Configure cancel buttons.
            let cancelDeliveryButtons = document.querySelectorAll(".boxnow-cancel-voucher");
            
            cancelDeliveryButtons.forEach((button, index) => {
                // Configure and add event listener.
                disableCancelButton(button,false,response.data.parcels[index].id); //ebable cancel buttons
            });
            alert("The request was made successfully!");
        }
        else{
            alert("Delivery request failed. Message: " + response.message);
        }
        console.log(response);
    });

}

// Logic handling the print labels boxnow button.
// document.getElementById("boxnow_create_stickers_btn").addEventListener("click", fn => {
function createStickers(){  
    // Make sure all necessary data is present.
    let orderPrice = document.getElementById("jform_shipment_order_price");
    let paymentMode = document.querySelector('input[name="jform[shipment][payment_mode]"]:checked');
    let collectedAmount = document.getElementById("jform_shipment_amount_to_be_collected");
    
    if( orderPrice.value === '' || 
        paymentMode.value === '' ||
        collectedAmount.value === ''){
        // TODO: Load alert messages dynamically via PHP for multilingual purposes.
        alert("Insufficient data to print labels.");  
        return;
    }
    
    // Make sure all necessary package data is present.
    let packagesTable = document.querySelectorAll("#subfieldList_jform_shipment_packages tbody tr");
    
    // Count says 1 even if there are no table rows for some reason.
    if(packagesTable.length <= 0){
        alert("No packages found for printing.");
        return;
    }
    
    let url = fetchCreateLabelURL;
    
    fetch(url, {
        method: "POST",
        headers: {"Content-Type": "application/json"}
    })
    .then(response => response.json())
    .then(data => {
        if(data.success === true){
            alert("All the stickers were created successfully!");
            updateButtonsAfterLabelCreate(data);
        }
        else{
            alert("Sticker creation failed. Message: " + data.message);
        }
        console.log(data);
    });

}

// Logic handling the cancel delivery boxnow button.
// document.getElementById("boxnow_cancel_delivery_btn").addEventListener("click", fn => {
function cancelDelivery(){
    
    // Make sure all necessary data is present.
    let orderPrice = document.getElementById("jform_shipment_order_price");
    let paymentMode = document.querySelector('input[name="jform[shipment][payment_mode]"]:checked');
    let collectedAmount = document.getElementById("jform_shipment_amount_to_be_collected");
    
    if( orderPrice.value === '' || 
        paymentMode.value === '' ||
        collectedAmount.value === ''){
        // TODO: Load alert messages dynamically via PHP for multilingual purposes.
        alert("Insufficient data to print labels.");  
        return;
    }
    
    // Make sure all necessary package data is present.
    let packagesTable = document.querySelectorAll("#subfieldList_jform_shipment_packages tbody tr");
    
    // Count says 1 even if there are no table rows for some reason.
    if(packagesTable.length <= 0){
        alert("No packages found for printing.");
        return;
    }
    
    let url = fetchCancelDeliveryURL;
    
    fetch(url, {
        method: "POST",
        headers: {"Content-Type": "application/json"}
    })
    .then(response => response.json())
    .then(data => {
        if(data.success === true){
            alert("All the parcels were cancelled successfully!");
            // Remove all parcels in the form.
            let packagesTableInnerBody = document.querySelector("#subfieldList_jform_shipment_packages tbody");
            packagesTableInnerBody.innerHTML = '';
            // Switch on input fields.
            disableAllBoxNowFormActions(false);
        }
        else{
            alert("Delivery cancelling failed. Message: " + data.message);
        }
        console.log(data);
    });
    
}


function cancelIndividualParcel(parcelID){
    
    // ?parcel_id="+parcelID
    let url = fetchCancelIndividualParcelFunctionURL;
    url+="&parcel_id="+parcelID;

    // let passedData = {"parcel_id": parcelID};
    
    console.log("clicked");
    
    fetch(url, {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            // body: JSON.stringify(passedData)
        })
        .then(response => response.json())
        .then(data => {
            
            if(data.success === true){
                alert("The parcel's delivery was cancelled successfully.");
                
                // Cancel button styling.
                let cancelButton = document.querySelector('.boxnow-cancel-voucher[data-parcel-id="' + parcelID + '"]');
                cancelButton.innerText = "Parcel cancelled"; //cancelButton.querySelector('span').innerHTML = "Parcel cancelled";
                cancelButton.classList.add("box-now-inactive");
                
                // Voucher button styling.
                let labelButton = cancelButton.closest('td').querySelector('.boxnow-print-label');
                labelButton.innerText = "Parcel cancelled";//labelButton.querySelector('span').querySelector('a').innerHTML = "Parcel cancelled";
                labelButton.classList.add("box-now-inactive");
                labelButton.classList.add("btn-warning");
                labelButton.classList.remove("btn-success");
            }
            else{
                alert("Parcel cancelling failed. Message: " + data.message);
            }
        });
    
}