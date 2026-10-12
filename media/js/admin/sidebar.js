/**
 * Alfa Commerce: place the Alfa menu into Joomla's native admin sidebar.
 *
 * The layout emits the Alfa nav as hidden markup inside the component output; this moves it
 * into #sidebarmenu (Joomla's real sidebar) and swaps it in for the CMS nav. A "Back" arrow
 * returns to the CMS menu, and a "Alfa Commerce" entry pinned to the TOP of the CMS menu
 * swaps back in; both toggle without a reload. No library, no framework; the inline FOUC
 * guard keeps the CMS nav hidden until this runs, so there is no flicker.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var sidebarmenu = document.getElementById('sidebarmenu');
        var alfaWrap    = document.querySelector('[data-alfa-sidebar]');

        if (!sidebarmenu || !alfaWrap) {
            return;
        }

        var alfaNav = alfaWrap.querySelector('ul.alfa-nav');
        var cmsNav  = sidebarmenu.querySelector('ul.main-nav:not(.alfa-nav)')
            || sidebarmenu.querySelector('ul:not(.alfa-nav)');

        if (!alfaNav) {
            return;
        }

        function showAlfa() {
            alfaNav.style.display = 'flex';

            if (cmsNav) {
                cmsNav.style.display = 'none';
            }
        }

        function showCms() {
            alfaNav.style.display = 'none';

            if (cmsNav) {
                cmsNav.style.display = 'flex';
            }
        }

        // Move the Alfa menu into the real sidebar, then drop the now-empty wrapper.
        sidebarmenu.appendChild(alfaNav);
        alfaWrap.remove();

        // Pin a "jump back into Alfa" entry to the TOP of the CMS menu, so it is the first
        // thing you see when you switch to Joomla's menu.
        var backWrap = document.querySelector('[data-alfa-back]');

        if (backWrap) {
            var jump = backWrap.querySelector('li');

            if (jump && cmsNav) {
                cmsNav.insertBefore(jump, cmsNav.firstChild);

                var toApp = jump.querySelector('[data-alfa-to-app]');

                if (toApp) {
                    toApp.addEventListener('click', function (event) {
                        event.preventDefault();
                        showAlfa();
                    });
                }
            }

            backWrap.remove();
        }

        // The Back arrow inside the Alfa menu returns to the CMS menu.
        var toJoomla = alfaNav.querySelector('[data-alfa-to-joomla]');

        if (toJoomla) {
            toJoomla.addEventListener('click', function (event) {
                event.preventDefault();
                showCms();
            });
        }

        // We are inside com_alfa, so land on the Alfa menu.
        showAlfa();
    });
})();
