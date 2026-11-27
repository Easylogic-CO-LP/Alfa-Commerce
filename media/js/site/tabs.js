/**
 * @package     com_alfa
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @author      Agamemnon Fakas <info@easylogic.gr>
 *
 * Tabs, on markup that works without them.
 *
 * A container marked data-alfa-tabs holds a nav.alfa-tabs of links, one per panel (href="#<panel id>"), and the
 * panels those ids name. Without this file the links jump to their panels and every panel is visible. With it the
 * nav becomes a tab list (W3C ARIA tabs pattern): one tab selected and in the tab order, the others reached with
 * Left, Right, Home and End, each panel hidden except the selected one, a click selecting without changing the
 * address; a URL hash naming a panel selects its tab on load and when the hash changes. Any number of containers,
 * any number of panels; attributes only, no element is made or moved.
 */
(() => {
    'use strict';

    const MOVES = { ArrowLeft: -1, ArrowRight: 1, Home: 'first', End: 'last' };

    const enhance = (group) => {
        if (group.hasAttribute('data-alfa-tabs-ready')) {
            return;
        }

        const nav = group.querySelector('.alfa-tabs');
        const tabs = [];
        const panels = [];

        if (!nav) {
            return;
        }

        nav.querySelectorAll('a[href^="#"]').forEach((link) => {
            const id = link.getAttribute('href').slice(1);
            const panel = id ? group.querySelector('#' + CSS.escape(id)) : null;

            if (panel) {
                tabs.push(link);
                panels.push(panel);
            }
        });

        if (!tabs.length) {
            return;
        }

        nav.setAttribute('role', 'tablist');

        tabs.forEach((tab, index) => {
            const panel = panels[index];

            if (!tab.id) {
                tab.id = panel.id + '-tab';
            }

            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
            panel.setAttribute('tabindex', '0');
        });

        const select = (index, focus) => {
            tabs.forEach((tab, i) => {
                const on = i === index;

                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.setAttribute('tabindex', on ? '0' : '-1');
                panels[i].hidden = !on;
            });

            if (focus) {
                tabs[index].focus();
                tabs[index].scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        };

        const fromHash = () => {
            const id = decodeURIComponent(window.location.hash.slice(1));

            return id ? panels.findIndex((panel) => panel.id === id) : -1;
        };

        nav.addEventListener('click', (event) => {
            const tab = event.target.closest('a[role="tab"]');
            const index = tab ? tabs.indexOf(tab) : -1;

            if (index < 0) {
                return;
            }

            event.preventDefault();
            select(index, false);
        });

        nav.addEventListener('keydown', (event) => {
            const move = MOVES[event.key];
            const current = tabs.indexOf(document.activeElement);

            if (move === undefined || current < 0) {
                return;
            }

            event.preventDefault();
            select(
                move === 'first' ? 0 : move === 'last' ? tabs.length - 1 : (current + move + tabs.length) % tabs.length,
                true,
            );
        });

        window.addEventListener('hashchange', () => {
            const index = fromHash();

            if (index >= 0) {
                select(index, false);
            }
        });

        const initial = fromHash();

        select(initial >= 0 ? initial : 0, false);
        group.setAttribute('data-alfa-tabs-ready', '');
    };

    const init = () => document.querySelectorAll('[data-alfa-tabs]').forEach(enhance);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
