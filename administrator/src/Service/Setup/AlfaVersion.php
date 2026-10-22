<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * An Alfa Commerce version: the one reader of the version an alfa.xml declares, and the one
 * comparison of two versions.
 *
 * The installed version is the one administrator/components/com_alfa/alfa.xml declares on disk
 * ({@see self::installed()}): that file is the authority, not the copy Joomla keeps in its
 * extension registry. A package's version is the one its own alfa.xml declares
 * ({@see self::ofManifest()}).
 *
 * Plain PHP, no Joomla class, because three kinds of caller load it on their own: the
 * component's installer, from the package being installed (its preflight runs before the
 * package's files are copied, so an older installed tree has no such class); the installer of
 * another Alfa extension, from {@see self::FILE} when Joomla's namespace map does not know the
 * component; and the release tools, which read a tree that is not the running site.
 *
 * @since  2.1.0
 */
final class AlfaVersion
{
    /**
     * The installed component manifest, relative to the site root.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const MANIFEST = 'administrator/components/com_alfa/alfa.xml';

    /**
     * This file, relative to the site root: where the installer of another Alfa extension loads
     * it from when Joomla's namespace map cannot.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const FILE = 'administrator/components/com_alfa/src/Service/Setup/AlfaVersion.php';

    /**
     * @param string $version The version, trimmed and never empty.
     *
     * @since  2.1.0
     */
    private function __construct(private readonly string $version)
    {
    }

    /**
     * The Alfa Commerce version installed on this site.
     *
     * @return self|null Null when no Alfa Commerce manifest is installed, or it cannot be read,
     *                   or it declares no version.
     *
     * @since  2.1.0
     */
    public static function installed(): ?self
    {
        return self::ofSite(siteRoot: JPATH_ROOT);
    }

    /**
     * The Alfa Commerce version installed in a Joomla tree.
     *
     * @param string $siteRoot Absolute path of the tree's root.
     *
     * @return self|null As {@see self::installed()}.
     *
     * @since  2.1.0
     */
    public static function ofSite(string $siteRoot): ?self
    {
        return self::ofManifest(path: rtrim($siteRoot, '/') . '/' . self::MANIFEST);
    }

    /**
     * The version an alfa.xml declares: its version element, trimmed.
     *
     * @param string $path Absolute path of the manifest.
     *
     * @return self|null Null when the file is missing, is not XML, or declares no version.
     *
     * @since  2.1.0
     */
    public static function ofManifest(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        $xml = @simplexml_load_file($path);

        if ($xml === false) {
            return null;
        }

        $version = trim((string) $xml->version);

        return $version === '' ? null : new self(version: $version);
    }

    /**
     * A version given as text, for comparing it.
     *
     * @param string $version The version, as PHP's version_compare() reads one.
     *
     * @throws InvalidArgumentException When the text is empty: it names no version.
     *
     * @since  2.1.0
     */
    public static function of(string $version): self
    {
        $version = trim($version);

        if ($version === '') {
            throw new InvalidArgumentException('An empty text is not an Alfa Commerce version.');
        }

        return new self(version: $version);
    }

    /**
     * Whether this version is the given one or a later one.
     *
     * @param string $minimum The oldest version accepted.
     *
     * @since  2.1.0
     */
    public function atLeast(string $minimum): bool
    {
        return version_compare($this->version, $minimum, '>=');
    }

    /**
     * Whether this version is later than the given one.
     *
     * @param string $other The version compared with.
     *
     * @since  2.1.0
     */
    public function isNewerThan(string $other): bool
    {
        return version_compare($this->version, $other, '>');
    }

    /**
     * The version as the manifest declares it.
     *
     * @since  2.1.0
     */
    public function toString(): string
    {
        return $this->version;
    }
}
