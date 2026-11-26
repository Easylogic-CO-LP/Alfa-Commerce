/**
 * @package    Alfa Commerce
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The height of the page's sticky header, for a script that brings a part of the page under it: the listing's
 * results after a filter or a page change, the cart's picker after its redirect. One measure for every page of the
 * shop, published as Alfa.headerOffset(element).
 */
(function () {
    'use strict';

    window.Alfa = window.Alfa || {};

    /**
     * The height the page's sticky header covers at the viewport's top, with the room the element keeps under it:
     * the element's scroll-margin-top alone when the template sets --alfa-scroll-offset (the stylesheets set the
     * margin from it); otherwise the header measured now, plus that margin. The measure: the boxes of the page's
     * elements outside the element, those whose position is sticky or fixed, that reach into the viewport's top 8 px,
     * that are no taller than half the viewport (a cookie wall's backdrop or an open off-canvas menu is a layer, not a
     * header) and that are on screen (visible, and not moved wholly out of the viewport sideways, as a closed side
     * menu often is): the lowest bottom among them. The boxes, not a hit test at the top edge: right after a smooth
     * scroll a hit test there can find nothing but the page itself.
     *
     * @param {Element} element The element to bring under the header.
     *
     * @return {number} Pixels from the viewport's top.
     */
    window.Alfa.headerOffset = function (element) {
        var style = window.getComputedStyle(element);
        var margin = parseFloat(style.scrollMarginTop) || 0;

        if (style.getPropertyValue('--alfa-scroll-offset').trim() !== '') {
            return margin;
        }

        var band = 8;
        var half = window.innerHeight / 2;
        var bottom = 0;
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT, {
            acceptNode: function (node) {
                return node === element ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            },
        });

        for (var node = walker.nextNode(); node; node = walker.nextNode()) {
            if (node.contains(element)) {
                continue;
            }

            var box = node.getBoundingClientRect();

            if (box.top > band || box.bottom <= bottom || box.height > half || box.width === 0) {
                continue;
            }

            var computed = window.getComputedStyle(node);

            if ((computed.position === 'sticky' || computed.position === 'fixed')
                && computed.visibility !== 'hidden' && computed.visibility !== 'collapse'
                && box.right > 0 && box.left < window.innerWidth) {
                bottom = box.bottom;
            }
        }

        return bottom + margin;
    };
})();
