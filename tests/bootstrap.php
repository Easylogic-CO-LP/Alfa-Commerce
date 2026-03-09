<?php
/**
 * PHPUnit Bootstrap for Alfa Commerce
 *
 * Sets up the minimal environment needed for unit testing
 * without requiring a full Joomla installation.
 */

defined('_JEXEC') or define('_JEXEC', 1);
defined('JPATH_ROOT') or define('JPATH_ROOT', dirname(__DIR__));
defined('JPATH_ADMINISTRATOR') or define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
defined('JPATH_COMPONENT') or define('JPATH_COMPONENT', JPATH_ROOT . '/site');
defined('JPATH_COMPONENT_ADMINISTRATOR') or define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ROOT . '/administrator');

// Autoload via Composer if available
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
