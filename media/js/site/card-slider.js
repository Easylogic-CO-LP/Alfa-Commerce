/**
 * Alfa Commerce: the listing card's image slider (default_card_images-slider.php).
 *
 * The slider itself is the browser's: its track is a sideways scrolling row that stops on each image (list.css), so
 * a swipe on a touch screen or a sideways scroll moves it with no script, and a swipe never opens the product (a
 * scroll is not a click). This script does only what CSS cannot: the arrows and the dots, which the card's template
 * draws as buttons, move the track one image at a time, and the dots and the arrows follow the image in view. It
 * builds no markup.
 *
 * Any number of sliders, one set of listeners: a click on a control is handled from the document, and each track is
 * watched once for its scroll (data-card-slider-ready marks it), on load and again for the cards a single-card reload
 * (alfa:item:updated) or a filter (joomla:updated) puts on the page.
 *
 * The motion is the stylesheet's: scrollTo() with no behaviour follows the track's scroll-behavior, smooth only where
 * the visitor has not asked for reduced motion, so the script never animates by itself.
 * A mouse drag moves the track too: a touch swipe and a trackpad swipe are the browser's own scroll, but a mouse has no
 * gesture of its own on a scrolling row, so while its button is down the track follows the pointer and snaps to the
 * nearest image where it is let go. A drag never opens the product (the click that ends it is dropped) and never
 * starts the browser's own drag of the image or its link.
 */
(function () {
    'use strict';

    var SLIDER = '[data-card-slider]';
    var TRACK = '[data-card-slider-track]';
    var READY = 'data-card-slider-ready';
    var DRAGGING = 'data-card-slider-dragging';

    /** The mouse drag under way: its track, where it began, the track's scroll then, and whether it has moved yet. */
    var drag = null;

    /** The image in view: the track's scroll in widths of the track (negative in a right-to-left page). */
    function shown(track) {
        var width = track.clientWidth;

        return width > 0 ? Math.round(Math.abs(track.scrollLeft) / width) : 0;
    }

    function slideCount(track) {
        return track.querySelectorAll('[data-card-slider-slide]').length;
    }

    /** Bring one image into view; the stylesheet decides whether the move is animated. */
    function show(track, index) {
        var last = slideCount(track) - 1;
        var target = Math.max(0, Math.min(last, index));
        var direction = getComputedStyle(track).direction === 'rtl' ? -1 : 1;

        track.scrollTo({ left: direction * target * track.clientWidth });
    }

    /** The controls of a slider say where its track is: the dot of the image in view, and an arrow at an end. */
    function follow(slider) {
        var track = slider.querySelector(TRACK);

        if (!track) {
            return;
        }

        var index = shown(track);
        var last = slideCount(track) - 1;

        slider.querySelectorAll('[data-action="card-slider-dot"]').forEach(function (dot) {
            if (parseInt(dot.getAttribute('data-index'), 10) === index) {
                dot.setAttribute('aria-current', 'true');
            } else {
                dot.removeAttribute('aria-current');
            }
        });

        slider.querySelectorAll('[data-action="card-slider-prev"]').forEach(function (arrow) {
            arrow.setAttribute('aria-disabled', index <= 0 ? 'true' : 'false');
        });

        slider.querySelectorAll('[data-action="card-slider-next"]').forEach(function (arrow) {
            arrow.setAttribute('aria-disabled', index >= last ? 'true' : 'false');
        });
    }

    /** Watch every track under root that is not watched yet; once per track, however often this runs. */
    function bind(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }

        var sliders = root.matches && root.matches(SLIDER) ? [root] : Array.prototype.slice.call(root.querySelectorAll(SLIDER));

        sliders.forEach(function (slider) {
            var track = slider.querySelector(TRACK);

            if (!track || track.hasAttribute(READY)) {
                return;
            }

            track.setAttribute(READY, '');

            var pending = false;

            track.addEventListener('scroll', function () {
                if (pending) {
                    return;
                }

                pending = true;
                window.requestAnimationFrame(function () {
                    pending = false;
                    follow(slider);
                });
            }, { passive: true });

            follow(slider);
        });
    }

    document.addEventListener('click', function (event) {
        var control = event.target instanceof Element ? event.target.closest('[data-action^="card-slider-"]') : null;
        var slider = control ? control.closest(SLIDER) : null;
        var track = slider ? slider.querySelector(TRACK) : null;

        if (!track) {
            return;
        }

        event.preventDefault();

        var action = control.getAttribute('data-action');

        if (control.getAttribute('aria-disabled') === 'true') {
            return;
        }

        if (action === 'card-slider-prev') {
            show(track, shown(track) - 1);
        } else if (action === 'card-slider-next') {
            show(track, shown(track) + 1);
        } else if (action === 'card-slider-dot') {
            show(track, parseInt(control.getAttribute('data-index'), 10) || 0);
        }
    });

    document.addEventListener('pointerdown', function (event) {
        var track = event.target instanceof Element ? event.target.closest(TRACK) : null;

        if (!track || event.pointerType === 'touch' || event.button !== 0 || slideCount(track) < 2) {
            return;
        }

        drag = { track: track, id: event.pointerId, x: event.clientX, left: track.scrollLeft, moved: false };

        try {
            track.setPointerCapture(event.pointerId);
        } catch (e) {
            // A pointer gone before the capture: the drag ends with its next event.
        }
    });

    document.addEventListener('pointermove', function (event) {
        if (!drag || event.pointerId !== drag.id) {
            return;
        }

        var dx = event.clientX - drag.x;

        // The first few pixels are a click settling, not a drag.
        if (!drag.moved && Math.abs(dx) < 4) {
            return;
        }

        if (!drag.moved) {
            drag.moved = true;
            drag.track.setAttribute(DRAGGING, '');
        }

        drag.track.scrollLeft = drag.left - dx;
        event.preventDefault();
    });

    /** The drag ends: the track snaps to the image nearest to where it is, and the click that follows a move is dropped. */
    function endDrag(event) {
        if (!drag || event.pointerId !== drag.id) {
            return;
        }

        var track = drag.track;
        var moved = drag.moved;

        drag = null;

        try {
            track.releasePointerCapture(event.pointerId);
        } catch (e) {
            // Already released.
        }

        if (!moved) {
            return;
        }

        track.removeAttribute(DRAGGING);
        show(track, shown(track));

        var swallow = function (click) {
            click.preventDefault();
            click.stopPropagation();
        };

        track.addEventListener('click', swallow, { capture: true, once: true });
        window.setTimeout(function () {
            track.removeEventListener('click', swallow, { capture: true });
        }, 0);
    }

    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', endDrag);

    // The browser's own drag of an image or a link inside the track never starts: the mouse is moving the track.
    document.addEventListener('dragstart', function (event) {
        if (event.target instanceof Element && event.target.closest(TRACK)) {
            event.preventDefault();
        }
    });

    // A card swapped in by its reload, and a result set swapped in by a filter: the event's target holds the new nodes.
    document.addEventListener('alfa:item:updated', function (event) {
        bind(event.target);
    });

    document.addEventListener('joomla:updated', function (event) {
        bind(event.target);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bind(document);
        });
    } else {
        bind(document);
    }
})();
