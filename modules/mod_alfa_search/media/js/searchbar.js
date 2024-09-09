document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.querySelector('#search-container-input');
    const searchPopup = document.querySelector('#search-container-popup');
    const searchLoadingImg = document.querySelector('#search-container-loading-img');

    const mainElementsIdStringCombined = '#'+searchInput.id+ ' , #'+searchPopup.id;

    const form = searchInput.closest('form');

    let abortController = new AbortController();
    let isPopupFocused = false;

    function handleFocusIn(e) {
        searchPopup.style.maxHeight = getMaxHeight(searchInput) + "px";
        isPopupFocused = true;
    }

    function handleFocusOut(e) {
        setTimeout(function () {
            if (!isPopupFocused) {
                searchPopup.classList.remove("active");
            }
        }, 120);
    }

    searchInput.addEventListener("focus", handleFocusIn);
    form.addEventListener('focusout', handleFocusOut);
    searchPopup.addEventListener('focusout', handleFocusOut);

    // Focus out event on the document to handle when focus leaves the popup
    document.addEventListener("click", (e) => {
        if (e.target.closest(mainElementsIdStringCombined)==null) {
            isPopupFocused = false;
        }
    });

    // Keyboard Shortcuts
    const searchBar = document.querySelector('.searchbar');
    document.addEventListener('keydown', function (event) {
        if (event.key === '/' && !event.ctrlKey && !event.altKey && !event.metaKey) {
            if (document.activeElement === searchBar) {
                return;
            }
            event.preventDefault();
            if (searchBar) {
                searchBar.focus();
            } else {
            }
        }
        if (event.key === 'Escape') {
            if (document.activeElement.closest(mainElementsIdStringCombined)) {
                event.preventDefault(); 
                searchBar.blur();
                searchPopup.classList.remove("active");
            }
        }

    });

    let typingTimer;
    searchInput.addEventListener("input", () => {
        clearTimeout(typingTimer);
        const query = searchInput.value.trim();
        if (query.length >= parseInt(form.getAttribute('data-minchars'))) {

            if (abortController) {
                abortController.abort();
            }

            // Create a new AbortController for the current request
            abortController = new AbortController();

            typingTimer = setTimeout(() => {
                fetchResults(query);
            }, 150);
        } else {
            searchPopup.classList.remove("active");
            searchPopup.innerHTML = '';
        }
    });

    // returns the height between the referenceContainer and the end of page
    function getMaxHeight(referenceContainer, addMoreSpace = 20){
        if(!referenceContainer) return 10000; //return 10000 as max height if container div given doesn't exist

        // Get the min height between the window and the body heights
        let maxAvailableHeight = Math.min(window.innerHeight, document.body.scrollHeight);

        // Get the distance of the referenceContainer from the top of the document also mind the user scrolled position
        let referenceContainerTop = referenceContainer.getBoundingClientRect().top + window.scrollY;

        return maxAvailableHeight - referenceContainerTop - referenceContainer.offsetHeight - addMoreSpace;
    }

    function fetchResults(query) {
        
        // Show loading image
        searchLoadingImg.classList.add("show");
        
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
                body: params,
                signal: abortController.signal // Pass the signal to fetch
            })
            // fetch('alfa.el3.demosites.gr/modules/mod_alfa_search/ajax/getitems.php')
            .then(
                response => response.json()
            )
            .then(data => {
                // hide loading
                // Assuming `data` contains the search results
                displayResults(data);
                searchPopup.classList.add("active");

                searchLoadingImg.classList.remove("show");
            })
            .catch(error => {
                if (error.name === 'AbortError') {
                    console.log('Fetch aborted');
                } else {
                    searchLoadingImg.classList.remove("show");
                    // console.error('Error fetching search results:', error);
                }
                // console.error('Error fetching search results:', error)

            })
            .finally(() => {
                // searchLoadingImg.classList.remove("show");
            });
    }

    function displayResults(data) {
        // console.log('Data received:', data); // Debugging line
        searchPopup.innerHTML = '';
        let dataRaw = data.data.suggestions;
        if (dataRaw) {
            dataRaw.forEach(item => {
                searchPopup.innerHTML += item;
            });
        }
    }
});
