<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Plugin\Task\Alfapricesets\Extension\Alfapricesets;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

/**
 * Install script of plg_task_alfapricesets.
 *
 * Uninstalling the plugin removes the scheduler's tasks of its routines: a task
 * whose routine is gone is met by the scheduler on every run and reported as an
 * orphan. The component's own uninstall removes this plugin through the
 * installer, which runs this script.
 *
 * @since  1.14.0
 */
return new class () implements InstallerScriptInterface {
    /**
     * @param InstallerAdapter $adapter The installer adapter.
     *
     *
     * @since  1.14.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * @param InstallerAdapter $adapter The installer adapter.
     *
     *
     * @since  1.14.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Remove the scheduler's tasks of the plugin's routines (list prices for customer groups).
     *
     * @param InstallerAdapter $adapter The installer adapter.
     *
     * @return bool Always true: a task that could not be removed is reported, and the uninstall goes on.
     *
     * @since  1.14.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        try {
            \JLoader::registerNamespace('Alfa\\Plugin\\Task\\Alfapricesets', __DIR__ . '/src');
            Alfapricesets::removeTasks();
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage('The scheduled tasks of plg_task_alfapricesets were not removed: ' . $e->getMessage(), 'warning');
        }

        return true;
    }

    /**
     * @param string $type The type of change.
     * @param InstallerAdapter $adapter The installer adapter.
     *
     *
     * @since  1.14.0
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * @param string $type The type of change.
     * @param InstallerAdapter $adapter The installer adapter.
     *
     *
     * @since  1.14.0
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }
};
