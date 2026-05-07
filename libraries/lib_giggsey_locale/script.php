<?php
/**
 * Install script for lib_giggsey_locale
 * Clears Joomla's PSR-4 cache so the <namespace> tag is picked up.
 */
defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;

class Lib_giggsey_localeInstallerScript
{
	public function preflight($type, InstallerAdapter $adapter): bool
	{
		if (PHP_VERSION_ID < 80100) {
			return false;
		}
		return true;
	}

	public function install(InstallerAdapter $adapter): bool   { return $this->refreshMap(); }
	public function update(InstallerAdapter $adapter): bool    { return $this->refreshMap(); }
	public function uninstall(InstallerAdapter $adapter): bool { return $this->refreshMap(); }

	public function postflight($type, InstallerAdapter $adapter): void
	{
		$this->refreshMap();
	}

	private function refreshMap(): bool
	{
		$file = JPATH_CACHE . '/autoload_psr4.php';
		if (is_file($file)) {
			@unlink($file);
		}
		return true;
	}
}
