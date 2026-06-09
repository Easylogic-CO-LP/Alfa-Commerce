<?php

// Constants Joomla defines at runtime (not discoverable by scanning the source),
// declared so the component code analyses cleanly under PHPStan.
defined('_JEXEC') or define('_JEXEC', 1);
defined('DS') or define('DS', DIRECTORY_SEPARATOR);
defined('JPATH_ROOT') or define('JPATH_ROOT', __DIR__);
defined('JPATH_BASE') or define('JPATH_BASE', JPATH_ROOT);
defined('JPATH_SITE') or define('JPATH_SITE', JPATH_ROOT);
defined('JPATH_ADMINISTRATOR') or define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
defined('JPATH_API') or define('JPATH_API', JPATH_ROOT . '/api');
defined('JPATH_LIBRARIES') or define('JPATH_LIBRARIES', JPATH_ROOT . '/libraries');
defined('JPATH_PLUGINS') or define('JPATH_PLUGINS', JPATH_ROOT . '/plugins');
defined('JPATH_THEMES') or define('JPATH_THEMES', JPATH_ROOT . '/templates');
defined('JPATH_CACHE') or define('JPATH_CACHE', JPATH_ROOT . '/cache');
defined('JPATH_CONFIGURATION') or define('JPATH_CONFIGURATION', JPATH_ROOT);
defined('JPATH_MANIFESTS') or define('JPATH_MANIFESTS', JPATH_ROOT . '/administrator/manifests');
defined('JDEBUG') or define('JDEBUG', 0);
