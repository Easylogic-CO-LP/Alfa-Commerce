<?php
/**
 * Install script for lib_libphonenumber
 *
 * Deletes Joomla's cached PSR-4 namespace map so it gets regenerated
 * on the next request, picking up our <namespace> tag.
 */
defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;

class Lib_libphonenumberInstallerScript
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
