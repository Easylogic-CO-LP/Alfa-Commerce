// Glightbox
document.addEventListener('DOMContentLoaded', function (event) {
    productMediasIntoSlider();
    productMediasGallery();
});

function productMediasGallery(){
    // product gallery support
    var galleryFlag = document.querySelector(".product-images-wrapper .main-images .product-image");
    if(galleryFlag!==null){
        GLightbox({
            selector: '.product-images-wrapper .main-images .product-image',
            zoomable: true, // Enable zoom functionality
        });
    }
    //end of product gallery support
}

// Keen-slider

function productMediasIntoSlider(){
    //if we have additional images we need to slide them else we don't need to carousel our images
    $thumbnailImages = document.querySelector(".thumbnail-images");
    if($thumbnailImages==null)return;

    $mainImages = document.querySelector('.product-images-wrapper .main-images');
    $mainImages.classList.add("keen-slider");
    // // var slider = new KeenSlider($mediaCarousel);
    var slider = new KeenSlider($mainImages, {
            loop: false,
            selector: '.product-image'
        },
        [navigation]
    )

    $thumbnailImages.classList.add("keen-slider");
    // perViewThumbs=20;
    var thumbnails = new KeenSlider( $thumbnailImages,{
            selector: '.product-image',
            initial: 0,
            breakpoints: {
                "(min-width: 560px)": {
                    slides: { perView: 5 , spacing: 8}
                },
                "(min-width: 760px)": {
                    slides: { perView: 6 , spacing: 8}
                },
                "(min-width: 992px)": {
                    slides: { perView: 7 , spacing: 8}
                },
                "(min-width: 1200px)": {
                    slides: { perView: "auto" , spacing: 8}
                },
                "(min-width: 1366px)": {
                    slides: { perView: "auto" , spacing: 8}
                },
                "(min-width: 1600px)": {
                    slides: { perView: "auto" , spacing: 8}
                },
            },
            slides: { perView: 3 , spacing: 8}
            // slides: {
            //   perView: 4,
            //   spacing: 10,
            // },
        },
        [ThumbnailPlugin(slider)]
    );

}

function ThumbnailPlugin(main) {
    return (slider) => {
        function removeActive() {
            slider.slides.forEach((slide) => {
                slide.classList.remove("active")
            })
        }
        function addActive(idx) {
            slider.slides[idx].classList.add("active")
        }

        function addClickEvents() {
            slider.slides.forEach((slide, idx) => {
                slide.addEventListener("click", () => {
                    main.moveToIdx(idx)
                })
            })
        }

        slider.on("created", () => {
            addActive(slider.track.details.rel)
            addClickEvents()
            main.on("animationStarted", (main) => {
                removeActive()
                const next = main.animator.targetIdx || 0
                addActive(main.track.absToRel(next))
                slider.moveToIdx(Math.min(slider.track.details.maxIdx, next))
            })
        })
    }
}

// navigation actions for keen-slider
function navigation(slider) {
    let wrapper, dots, arrowLeft, arrowRight

    //to control the navigation if we want to add it or not
    if(!slider.container.classList.contains(".navigation-controls") && slider.container.closest(".navigation-controls")==null)return;

    function markup(remove) {
        wrapperMarkup(remove)
        dotMarkup(remove)
        arrowMarkup(remove)
    }

    function removeElement(elment) {
        elment.parentNode.removeChild(elment)
    }
    function createDiv(className) {
        var div = document.createElement("div")
        var classNames = className.split(" ")
        classNames.forEach((name) => div.classList.add(name))
        return div
    }

    function arrowMarkup(remove) {
        if (remove) {
            removeElement(arrowLeft)
            removeElement(arrowRight)
            return
        }
        arrowLeft = createDiv("arrow arrow--left")
        arrowLeft.appendChild(createDiv("arrow--left-img"));
        arrowLeft.addEventListener("click", () => slider.prev())
        arrowRight = createDiv("arrow arrow--right");
        arrowRight.appendChild(createDiv("arrow--right-img"));
        arrowRight.addEventListener("click", () => slider.next())

        wrapper.appendChild(arrowLeft)
        wrapper.appendChild(arrowRight)
    }

    function wrapperMarkup(remove) {
        if (remove) {
            var parent = wrapper.parentNode
            while (wrapper.firstChild)
                parent.insertBefore(wrapper.firstChild, wrapper)
            removeElement(wrapper)
            return
        }
        wrapper = createDiv("navigation-wrapper")
        // slider.container.parentNode.appendChild(wrapper)
        slider.container.parentNode.prepend(wrapper)
        wrapper.appendChild(slider.container)
    }

    function dotMarkup(remove) {
        if (remove) {
            removeElement(dots)
            return
        }
        dots = createDiv("dots")
        slider.track.details.slides.forEach((_e, idx) => {
            var dot = createDiv("dot")
            dot.addEventListener("click", () => slider.moveToIdx(idx))
            dots.appendChild(dot)
        })
        wrapper.appendChild(dots)
    }

    function updateClasses() {
        var slide = slider.track.details.rel
        slide === 0
            ? arrowLeft.classList.add("arrow--disabled")
            : arrowLeft.classList.remove("arrow--disabled")
        slide === slider.track.details.slides.length - 1
            ? arrowRight.classList.add("arrow--disabled")
            : arrowRight.classList.remove("arrow--disabled")
        Array.from(dots.children).forEach(function (dot, idx) {
            idx === slide
                ? dot.classList.add("dot--active")
                : dot.classList.remove("dot--active")
        })
    }

    slider.on("created", () => {
        markup()
        updateClasses()
    })
    slider.on("optionsChanged", () => {
        console.log(2)
        markup(true)
        markup()
        updateClasses()
    })
    slider.on("slideChanged", () => {
        updateClasses()
    })
    slider.on("destroyed", () => {
        markup(true)
    })
}

// Tabs & Amount
function openTab(evt, cityName) {
    let i, tabcontent, tablinks;

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
    const selectedTab = document.getElementById(cityName);
    selectedTab.style.display = "block";
    setTimeout(function () {
        selectedTab.style.opacity = "1";
    }, 10); // Slight delay for transition effect
    evt.currentTarget.className += " active";
}

function incrementValue() {
    let value = parseInt(document.getElementById('customNumberInput').value, 10);
    value = isNaN(value) ? 1 : value;
    value++;
    document.getElementById('customNumberInput').value = value;
}

function decrementValue() {
    let value = parseInt(document.getElementById('customNumberInput').value, 10);
    value = isNaN(value) ? 1 : value;
    value = value > 1 ? value - 1 : 1;
    document.getElementById('customNumberInput').value = value;
}


