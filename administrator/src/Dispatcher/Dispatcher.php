<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Dispatcher;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Throwable;

/**
 * ComponentDispatcher class for Com_Alfa
 *
 * @since  1.0.1
 */
class Dispatcher extends ComponentDispatcher
{
    /**
     * Dispatch a controller task. Redirecting the user if appropriate.
     *
     * @return void
     *
     * @since   1.0.1
     */
    public function dispatch()
    {
        // Override-proof tamper banner: when the last integrity sync recorded a real
        // alarm (modified / bad signature), surface it in the admin chrome on every
        // com_alfa HTML page. Decided here (non-overridable source, signed) and read
        // from the stored verdict (no CDN call -> never blocks), so faking the Tools
        // card or overriding the notification layout still can't hide tampering.
        try {
            if (
                $this->input->getCmd('format', 'html') === 'html'
                && SyncHelper::hasIntegrityAlarm()
            ) {
                // No language string (can't be silenced by a language override) and the
                // text is character-encoded so a plaintext file search for the wording doesn't
                // surface it. Editing it means touching this signed source (which is checked).
                $this->app->enqueueMessage(
                    "\x3c\x73\x74\x72\x6f\x6e\x67\x3e\x53\x65\x63\x75\x72\x69\x74\x79\x20\x63\x6f\x6e\x63\x65\x72\x6e\x3a\x3c\x2f\x73\x74\x72\x6f\x6e\x67\x3e\x20\x74\x68\x69\x73\x20\x41\x6c\x66\x61\x20\x43\x6f\x6d\x6d\x65\x72\x63\x65\x20\x69\x6e\x73\x74\x61\x6c\x6c\x61\x74\x69\x6f\x6e\x20\x64\x6f\x65\x73\x20\x6e\x6f\x74\x20\x6d\x61\x74\x63\x68\x20\x74\x68\x65\x20\x73\x69\x67\x6e\x65\x64\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x2e\x20\x49\x74\x73\x20\x66\x69\x6c\x65\x73\x20\x68\x61\x76\x65\x20\x62\x65\x65\x6e\x20\x63\x68\x61\x6e\x67\x65\x64\x2e",
                    'error'
                );
            }
        } catch (Throwable) {
            // Never let the tamper banner break the page.
        }

        parent::dispatch();
    }
}
