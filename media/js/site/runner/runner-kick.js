/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The storefront kick: the page that took the page gate while background work was due posts once to the guest
 * endpoint after it has loaded, same origin, and never again while the visitor stays on it. The endpoint reads
 * nothing from the request and answers 204 with no body; a failure is ignored.
 */
(() => {
  'use strict';

  const options = (window.Joomla && Joomla.getOptions('com_alfa.runner')) || {};

  if (!options.url) {
    return;
  }

  const kick = () => {
    fetch(options.url, { method: 'POST', credentials: 'same-origin', keepalive: true }).catch(() => {});
  };

  if (document.readyState === 'complete') {
    kick();
  } else {
    window.addEventListener('load', kick, { once: true });
  }
})();
