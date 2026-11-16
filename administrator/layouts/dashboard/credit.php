<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The Easylogic credit at the foot of the Alfa Dashboard overview, drawn by the server: the words in English on every
 * site, a heart and the Easylogic logo, which ships in the package (media/com_alfa/images/admin/easylogic.svg) and is
 * drawn into the page from that file, so no request leaves the site and its letters take the text colour of the
 * administrator's light or dark scheme. No script. Its class is made for each request and its few rules are written
 * into the page with it (the page's content security nonce applies to them); the colours are the administrator
 * template's own (--body-bg, --body-color, --border-color). From 768 px wide it stays at the bottom right of the
 * window; narrower, it closes the page in its flow, so it never covers anything on a phone.
 *
 * Display data: none.
 *
 * @since  2.2.1
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

$class = 'c' . bin2hex(random_bytes(6));
$logo = (string) file_get_contents(JPATH_ROOT . '/media/com_alfa/images/admin/easylogic.svg');

Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineStyle(
    ".{$class}{display:flex;justify-content:flex-end;margin:2rem 0 0}"
    . ".{$class}>p{display:inline-flex;align-items:center;gap:.35em;margin:0;padding:.3rem .75rem;"
    . "border:1px solid var(--border-color);border-radius:999px;background:var(--body-bg);color:var(--body-color);"
    . "font-size:.8125rem;line-height:1.2;box-shadow:0 1px 3px rgba(0,0,0,.15)}"
    . ".{$class} p>svg{width:.95em;height:.95em;fill:#e0245e}"
    . ".{$class} a{display:inline-flex;align-items:center;color:inherit}"
    . ".{$class} a>svg{display:block;width:3.85rem;height:.95rem}"
    . "@media (min-width:768px){.{$class}{position:fixed;right:1rem;bottom:.75rem;z-index:10;margin:0}"
    . ".alfa-dashboard:has(>.{$class}){padding-bottom:3rem}}",
);
?>
<div class="<?php echo $class; ?>">
    <p>
        Designed and developed with
        <svg viewBox="0 0 24 24" role="img" aria-label="love" focusable="false"><title>love</title>
            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
        </svg>
        by
        <a href="https://www.easylogic.gr" target="_blank" rel="noopener" title="Easylogic"><?php echo $logo; ?></a>
    </p>
</div>
