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

function itemMediasGallery(){
    // item gallery support
    var galleryFlag = document.querySelector(".item-images-wrapper .main-images .item-image");
    if(galleryFlag!==null){
        itemGlightbox = GLightbox({
            selector: '.item-images-wrapper .main-images .item-image',
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
        [navigation]
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


