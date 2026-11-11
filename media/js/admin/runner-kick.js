/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The staff kick: an administrator page, shown while background work was due, asks for a staff tick after it has
 * loaded (a POST with the session token in its body, never in the address) and keeps asking while work remains, so an
 * open staff page keeps the shop's background work going (DESIGN-2.2.0.md 9.44, amending 2.7).
 *
 * The tick answers 204 with no body; its header (options.header) says whether work remains. After each answer that
 * says so the page waits options.pauseMs and ticks again. It stops when nothing remains, when the answer is anything
 * but that 204 (a 403 after the session ended included), when the request fails, when the page goes away, and after
 * options.capMs of ticking without a break (the next page load asks again). While the tab is hidden it sends nothing;
 * shown again, it goes on at once. One request at a time.
 *
 * On the Dashboard, while its background-work card is on the page (options.cardUrl), the card is drawn again by the
 * server after each answer and swapped in, so it follows the job; when nothing is left to show, the card goes
 * (entry 27). No timer of its own: it rides on the ticks.
 */
(() => {
  'use strict';

  const options = (window.Joomla && Joomla.getOptions('com_alfa.runner')) || {};

  if (!options.url || !options.token || !options.header) {
    return;
  }

  const pauseMs = Number(options.pauseMs) || 0;
  const capMs = Number(options.capMs) || 0;
  let startedAt = 0;
  let timer = 0;
  let inFlight = false;
  let remains = false;
  let ended = false;

  const visible = () => document.visibilityState === 'visible';

  // The background-work card, drawn again by its own layout on the server; left as it was when that fails.
  const refreshCard = () => {
    const node = options.cardUrl ? document.querySelector('[data-alfa-background-card]') : null;

    if (!node) {
      return Promise.resolve();
    }

    const body = new FormData();
    body.set(options.token, '1');

    return fetch(options.cardUrl, {
      method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((reply) => {
        if (!reply || !reply.success || !reply.data || typeof reply.data.html !== 'string') {
          return;
        }

        const drawn = document.createElement('template');
        drawn.innerHTML = reply.data.html.trim();
        const fresh = drawn.content.querySelector('[data-alfa-background-card]');

        if (fresh) {
          node.replaceWith(fresh);
        } else {
          (node.closest('.alfa-card') || node).remove();
        }
      })
      .catch(() => {});
  };

  const later = () => {
    if (ended || !remains || inFlight || timer || !visible()) {
      return;
    }

    if (pauseMs <= 0 || Date.now() - startedAt >= capMs) {
      ended = true;
      return;
    }

    timer = window.setTimeout(() => {
      timer = 0;
      tick();
    }, pauseMs);
  };

  const tick = () => {
    if (ended || inFlight || !visible()) {
      return;
    }

    startedAt = startedAt || Date.now();
    inFlight = true;
    const body = new URLSearchParams();
    body.set(options.token, '1');

    fetch(options.url, {
      method: 'POST', body, credentials: 'same-origin', keepalive: true,
    })
      .then((response) => {
        remains = response.status === 204 && response.headers.get(options.header) === '1';
      })
      .catch(() => {
        remains = false;
      })
      .then(refreshCard)
      .finally(() => {
        inFlight = false;
        ended = ended || !remains;
        later();
      });
  };

  // Hidden, the page sends nothing; shown again, a new stretch of ticking begins at once.
  document.addEventListener('visibilitychange', () => {
    if (!visible()) {
      window.clearTimeout(timer);
      timer = 0;
    } else if (remains && !ended && !inFlight && !timer) {
      startedAt = 0;
      tick();
    }
  });

  window.addEventListener('pagehide', () => {
    ended = true;
    window.clearTimeout(timer);
    timer = 0;
  });

  // The first tick once the page has loaded; in a tab not shown, once it is.
  const first = () => {
    remains = true;
    tick();
  };

  if (document.readyState === 'complete') {
    first();
  } else {
    window.addEventListener('load', first, { once: true });
  }
})();
