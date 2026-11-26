// Live instances so a re-init (after the variant swap replaces the gallery DOM)
// can tear the old ones down before binding the fresh nodes.
var itemGlightbox = null;
var itemMainSlider = null;
var itemThumbSlider = null;

// Glightbox
document.addEventListener('DOMContentLoaded', function (event) {
    initItemMedia();
});

/**
 * (Re)initialise the item gallery (GLightbox + Keen-slider). Safe to call again
 * after variant.js swaps #alfa-app[data-view='item']: it destroys the previous
 * instances (now pointing at detached nodes) and rebinds the fresh DOM.
 */
function initItemMedia() {
    destroyItemMedia();
    itemMediasIntoSlider();
    itemMediasGallery();
}

function destroyItemMedia() {
    if (itemThumbSlider) { try { itemThumbSlider.destroy(); } catch (e) {} itemThumbSlider = null; }
    if (itemMainSlider)  { try { itemMainSlider.destroy(); }  catch (e) {} itemMainSlider = null; }
    if (itemGlightbox)   { try { itemGlightbox.destroy(); }   catch (e) {} itemGlightbox = null; }
}

// Let variant.js re-init the gallery after it swaps the item DOM.
window.alfaInitItemMedia = initItemMedia;

// The images that open the lightbox: the slider's main row, and any images variant that marks its container
// data-item-gallery (the grid, default_images-grid.php), whose images are never the slider's.
var ITEM_LIGHTBOX = '.item-images-wrapper .main-images .item-image, .item-images-wrapper [data-item-gallery] .item-image';

function itemMediasGallery(){
    // item gallery support
    var galleryFlag = document.querySelector(ITEM_LIGHTBOX);
    if(galleryFlag!==null){
        itemGlightbox = GLightbox({
            selector: ITEM_LIGHTBOX,
            zoomable: true, // Enable zoom functionality
        });
    }
    //end of item gallery support
}

// Keen-slider

function itemMediasIntoSlider(){
    //if we have additional images we need to slide them else we don't need to carousel our images
    $thumbnailImages = document.querySelector(".thumbnail-images");
    if($thumbnailImages==null)return;

    $mainImages = document.querySelector('.item-images-wrapper .main-images');
    $mainImages.classList.add("keen-slider");
    // // var slider = new KeenSlider($mediaCarousel);
    var slider = new KeenSlider($mainImages, {
            loop: false,
            selector: '.item-image'
        },
        [navigation, wheelSwipe]
    )
    itemMainSlider = slider;

    $thumbnailImages.classList.add("keen-slider");
    // perViewThumbs=20;
    var thumbnails = new KeenSlider( $thumbnailImages,{
            selector: '.item-image',
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
    itemThumbSlider = thumbnails;

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
    if(!slider.container.classList.contains("navigation-controls") && slider.container.closest(".navigation-controls")==null)return;

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

    // The shop's one slider arrow (general.css), the button the listing card's slider draws: a chevron, named for
    // a screen reader, in the tab order, dimmed at the track's end.
    function arrowButton(side) {
        var button = document.createElement("button")
        button.type = "button"
        button.className = "alfa-slider-arrow alfa-slider-arrow--" + side
        button.setAttribute("aria-label", Joomla.Text._(side === "prev" ? "COM_ALFA_CARD_IMAGES_PREVIOUS" : "COM_ALFA_CARD_IMAGES_NEXT"))
        button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' + (side === "prev" ? "M15 18l-6-6 6-6" : "M9 6l6 6-6 6") + '"/></svg>'
        return button
    }

    function arrowMarkup(remove) {
        if (remove) {
            removeElement(arrowLeft)
            removeElement(arrowRight)
            return
        }
        arrowLeft = arrowButton("prev")
        arrowLeft.addEventListener("click", () => slider.prev())
        arrowRight = arrowButton("next")
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
        arrowLeft.setAttribute("aria-disabled", slide === 0 ? "true" : "false")
        arrowRight.setAttribute("aria-disabled", slide === slider.track.details.slides.length - 1 ? "true" : "false")
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

// A trackpad swipe (and a mouse wheel turned sideways) moves the slider as a drag does: the slider reads only a
// mouse and a touch by itself, and a sideways wheel over it would scroll nothing. The wheel's sideways travel is fed
// to the slider as a drag, from its first event to a pause of 50 ms after its last; a wheel that mostly moves up or
// down is left to the page, which scrolls as always.
function wheelSwipe(slider) {
    var timer = 0
    var active = false
    var position = { x: 0, y: 0 }

    function send(name, event) {
        position.x -= event.deltaX
        position.y -= event.deltaY
        slider.container.dispatchEvent(new CustomEvent(name, { detail: { x: position.x, y: position.y } }))
    }

    function onWheel(event) {
        if (Math.abs(event.deltaX) <= Math.abs(event.deltaY)) {
            return
        }

        event.preventDefault()

        if (!active) {
            active = true
            // The drag's start: a point of its own, never the origin, which the slider reads as no point.
            position = { x: 100000, y: 100000 }
            send("ksDragStart", event)
        }

        send("ksDrag", event)
        clearTimeout(timer)
        timer = setTimeout(function () {
            active = false
            send("ksDragEnd", event)
        }, 50)
    }

    slider.on("created", function () {
        slider.container.addEventListener("wheel", onWheel, { passive: false })
    })
    slider.on("destroyed", function () {
        slider.container.removeEventListener("wheel", onWheel)
    })
}

// Amount
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


