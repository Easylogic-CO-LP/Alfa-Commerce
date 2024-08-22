function openCity(evt, cityName) {
    var i, tabcontent, tablinks;

    // Get all elements with class="tabcontent" and hide them
    tabcontent = document.getElementsByClassName("tabcontent");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
        tabcontent[i].style.opacity = "0";
    }

    // Get all elements with class="tablinks" and remove the class "active"
    tablinks = document.getElementsByClassName("tablinks");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }

    // Show the current tab, and add an "active" class to the button that opened the tab
    var selectedTab = document.getElementById(cityName);
    selectedTab.style.display = "block";
    setTimeout(function() {
        selectedTab.style.opacity = "1";
    }, 10); // Slight delay for transition effect
    evt.currentTarget.className += " active";
}