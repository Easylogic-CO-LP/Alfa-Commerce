// document.addEventListener("DOMContentLoaded", () => {
//     const searchBar = document.querySelector(".searchbar");
//     const searchPopup = document.querySelector(".searchbar-popup");
//
//     searchBar.addEventListener("focus", () => {
//         searchPopup.classList.add("active");
//     });
//
//     searchBar.addEventListener("blur", () => {
//         searchPopup.classList.remove("active");
//     });
// });


document.addEventListener("DOMContentLoaded", () => {
    const searchContainer = document.querySelector('.search-container');
    const searchField = document.querySelector('.searchbar');
    const searchPopup = document.querySelector('.searchbar-popup');
    const form = searchField.closest('form');
    const loadingImg = document.querySelector('.loading-img');

    console.log(form);

    searchField.addEventListener("focus", (e) => {
        searchPopup.classList.add("active");
    });

    // searchContainer.addEventListener("focusout", (e) => {
    //     searchPopup.classList.remove("active");
    // });

    let typingTimer;
    const typingInterval = 150; // Time in milliseconds (300ms delay)

    searchField.addEventListener("input", () => {
        clearTimeout(typingTimer);
        const query = searchField.value.trim();
        console.log(form.getAttribute('data-minchars'));
        if (query.length >= parseInt(form.getAttribute('data-minchars'))) {
            typingTimer = setTimeout(() => {
                fetchResults(query);
            }, typingInterval);
        } else {
            searchPopup.innerHTML = ''; // Clear results if input is less than 2 characters
        }
    });

    function fetchResults(query) {
        // Show loading image
        loadingImg.classList.add("show");
        // fetch(`index.php?option=com_alfa&view=items&filter[search]=${encodeURIComponent(query)}&format=json`)
        // com_ajax module=alfa_search and format=json url will load the getAjax of the module
        const params = new URLSearchParams();
        params.append("query", query);
        fetch(form.getAttribute('data-action'),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params
            })
            // fetch('alfa.el3.demosites.gr/modules/mod_alfa_search/ajax/getitems.php')
            .then(
                response => response.json()
            )
            .then(data => {
                // hide loading
                // Assuming `data` contains the search results
                displayResults(data);
            })
            .catch(error => console.error('Error fetching search results:', error))
            .finally(() => {
                loadingImg.classList.remove("show");
            });
    }

    function displayResults(data) {

        // for(){
        //     data.sku
        // }
        console.log('Data received:', data); // Debugging line
        // console.log(window.location.origin);
        searchPopup.innerHTML = ''; // Clear previous results
        let dataRaw = data.data.suggestions;
        console.log(dataRaw);
        if (dataRaw) {

            dataRaw.forEach(item => {
                searchPopup.innerHTML += item;
                //         const resultItem = document.createElement("a");
                //         resultItem.classList.add("search-result-item");
                //         resultItem.href = window.location.origin + '/index.php?option=com_alfa&view=item&id=' + item.id;
                //         resultItem.textContent = item.alias; // Assuming each result has a 'title' property
                //         searchPopup.appendChild(resultItem);
            });
        } else {
            searchPopup.innerHTML = 'No results found';
        }
    }
});
