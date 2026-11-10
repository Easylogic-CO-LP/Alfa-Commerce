/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The staff kick: an administrator page outside Alfa Commerce, shown while background work was due, asks for one
 * staff tick after it has loaded, with the session token in the address. The tick answers 204 with no body; a
 * failure is ignored.
 */
(() => {
  'use strict';

  const options = (window.Joomla && Joomla.getOptions('com_alfa.runner')) || {};

  if (!options.url) {
    return;
  }

  const kick = () => {
    fetch(options.url, { method: 'GET', credentials: 'same-origin', keepalive: true }).catch(() => {});
  };

  if (document.readyState === 'complete') {
    kick();
  } else {
    window.addEventListener('load', kick, { once: true });
  }
})();
