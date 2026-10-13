<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginLogTable;
use Joomla\CMS\Factory;

/**
 * Install script for the standard shipments plugin.
 *
 * THE LOG TABLE. The install SQL creates it; this completes it on every install
 * and update (a column logs.xml gained, the method index), so no request ever
 * has to: a log row can be written inside an open transaction, and MySQL
 * commits an open transaction before any CREATE or ALTER.
 *
 * Plain English on purpose: this runs inside the installer, where the plugin's
 * language file may not be loaded yet, and a key would render raw.
 *
 * @since  2.0.0
 */
class PlgAlfaShipmentsStandardInstallerScript
{
    /**
     * Complete the log table on every install and update.
     *
     * A step that fails is said, never a reason to refuse the plugin.
     *
     * @param string $type Install trigger: install|update|discover_install|uninstall.
     * @param object $adapter The installer adapter.
     *
     * @return bool Always true.
     *
     * @since  2.0.0
     */
    public function postflight(string $type, object $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        foreach ($this->completeLogTable() as $failure) {
            Factory::getApplication()->enqueueMessage($failure, 'warning');
        }

        return true;
    }

    /**
     * Create the log table when it is missing and add what it lacks.
     *
     * Runs in the installer, where no transaction is open. The component's
     * install script registers the component's namespace before it installs
     * this plugin, so PluginLogTable and the classes it uses autoload.
     *
     * @return string[] What could not be done, in words; empty when the table is complete.
     *
     * @since  2.0.0
     */
    public function completeLogTable(): array
    {
        $table = PluginLogTable::ofPlugin(Factory::getContainer()->get('DatabaseDriver'), 'alfa-shipments', 'standard');

        if ($table === null) {
            return ['Standard shipments: params/logs.xml could not be read, so the log table was not checked.'];
        }

        $failures = [];
        $table->complete(static function (string $detail) use (&$failures): void {
            $failures[] = 'Standard shipments: ' . $detail;
        });

        return $failures;
    }
}
