<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Tools;

defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * Clear Data: an Alfa plugin removes the persistent data it owns.
 *
 * A plugin supports Clear Data by having the hook, and nothing else declares it:
 *
 *     public function onClearData(ClearDataEvent $event): void
 *
 * Delivered to a SINGLE booted plugin, never broadcast, enabled or not (core gives a disabled plugin its name, group
 * and params first). The same hook serves Tools > Extensions (Clear Data, Remove extension and its data) and Clear
 * everything: one cleanup per extension. A plugin without the hook is shown "Automatic Clear Data not supported" and
 * none of its data is touched.
 *
 * AN EXPECTED FAILURE is reported with {@see self::setError()} (already translated: what may remain, and why), and
 * the hook returns. Core reads the event after every call; an error means the data was not confirmed removed. An
 * unexpected Throwable is caught by core and treated the same, its details logged.
 *
 * @since  2.1.0
 */
class ClearDataEvent extends AbstractEvent
{
    /**
     * Tools > Extensions > Clear Data.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const CLEAR_DATA = 'clear_data';

    /**
     * Tools > Extensions > Remove extension and its data: the extension is uninstalled after a clear that succeeded.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const REMOVE_WITH_DATA = 'remove_with_data';

    /**
     * Tools > Reset and clear > Clear everything: core's data follows after every extension's.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const CLEAR_EVERYTHING = 'clear_everything';

    //  INPUT: set by core, read by the plugin

    /**
     * Which action asked: {@see self::CLEAR_DATA}, {@see self::REMOVE_WITH_DATA} or {@see self::CLEAR_EVERYTHING}.
     * The cleanup is the same for each; the source is for the plugin's own log.
     *
     * @since  2.1.0
     */
    public function getSource(): string
    {
        return (string) ($this->arguments['source'] ?? '');
    }

    //  OUTPUT: set by the plugin, read by core

    /**
     * What was removed, in one line for the result page and the maintenance log.
     *
     * @param string $message Already-translated text.
     *
     * @since  2.1.0
     */
    public function setMessage(string $message): static
    {
        $this->arguments['message'] = $message;

        return $this;
    }

    /**
     * @return string '' when the plugin said nothing.
     *
     * @since  2.1.0
     */
    public function getMessage(): string
    {
        return (string) ($this->arguments['message'] ?? '');
    }

    /**
     * Report that the data was not all removed, without throwing.
     *
     * @param string $error Already-translated text: what may remain, and why.
     *
     * @since  2.1.0
     */
    public function setError(string $error): static
    {
        $this->arguments['error'] = $error;

        return $this;
    }

    /**
     * @return string '' when no error was reported.
     *
     * @since  2.1.0
     */
    public function getError(): string
    {
        return (string) ($this->arguments['error'] ?? '');
    }
}
