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
use SimpleXMLElement;

/**
 * The Alfa Commerce versions an extension says it is built for: the one reader of the alfacore element of an
 * extension's manifest (2.2.1).
 *
 * An Alfa extension declares, once, in its manifest beside its version, the lines of Alfa Commerce it is built for:
 *
 *     <alfacore min="2.2" max="2.3"/>
 *
 * A line is a major and a minor version (2.2 covers 2.2.0, 2.2.1 and every 2.2 release). min is required; max is
 * optional, and without it the range is open: every line from min on. An extension that declares no range says
 * nothing about it, and nothing is inferred for it from its own version: core's notice after an update names it
 * "compatibility not stated" ({@see ExtensionCompatibility}).
 *
 * The same reader serves core's notice after an update and an extension's own install script
 * ({@see AlfaInstallerGate::rangeRefusal()}). Plain PHP, no Joomla class, for the reason {@see AlfaVersion} is: an
 * extension's installer loads it from {@see self::FILE} when Joomla's namespace map does not know the component.
 *
 * @since  2.2.1
 */
final class AlfaCoreRange
{
    /**
     * The manifest element, a child of the manifest's root element.
     *
     * @var string
     *
     * @since  2.2.1
     */
    public const ELEMENT = 'alfacore';

    /**
     * This file, relative to the site root: where another extension's installer loads it from when Joomla's namespace
     * map does not know the component.
     *
     * @var string
     *
     * @since  2.2.1
     */
    public const FILE = 'administrator/components/com_alfa/src/Service/Setup/AlfaCoreRange.php';

    /**
     * What a line is written as: a major and a minor version, digits only.
     *
     * @var string
     *
     * @since  2.2.1
     */
    private const LINE = '/^\d+\.\d+$/';

    /**
     * @param string $min The first line, as declared.
     * @param string|null $max The last line, as declared; null for an open range.
     *
     * @since  2.2.1
     */
    private function __construct(private readonly string $min, private readonly ?string $max)
    {
    }

    /**
     * The range a manifest declares.
     *
     * @param SimpleXMLElement $manifest The manifest's root element.
     *
     * @return self|null Null when the manifest declares no range.
     *
     * @throws InvalidArgumentException When it declares one that cannot be read: min missing or not a line, max not a
     *                                  line, or max before min. The message names the values written.
     *
     * @since  2.2.1
     */
    public static function declaredBy(SimpleXMLElement $manifest): ?self
    {
        $element = $manifest->{self::ELEMENT}[0] ?? null;

        if ($element === null) {
            return null;
        }

        $min = trim((string) ($element['min'] ?? ''));
        $max = isset($element['max']) ? trim((string) $element['max']) : null;

        if (preg_match(self::LINE, $min) !== 1) {
            throw new InvalidArgumentException('its alfacore element has min="' . $min . '", which is not a line of Alfa Commerce such as 2.2');
        }

        if ($max !== null && preg_match(self::LINE, $max) !== 1) {
            throw new InvalidArgumentException('its alfacore element has max="' . $max . '", which is not a line of Alfa Commerce such as 2.3');
        }

        if ($max !== null && version_compare($max, $min, '<')) {
            throw new InvalidArgumentException('its alfacore element has max="' . $max . '" before min="' . $min . '"');
        }

        return new self(min: $min, max: $max);
    }

    /**
     * The range a manifest file declares.
     *
     * @param string $path Absolute path of the manifest.
     *
     * @return self|null Null when it declares no range.
     *
     * @throws InvalidArgumentException When the file is missing or is not XML, or the range it declares cannot be read.
     *
     * @since  2.2.1
     */
    public static function ofFile(string $path): ?self
    {
        $xml = is_file($path) ? @simplexml_load_file($path) : false;

        if (!$xml instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('its manifest ' . basename($path) . ' could not be read');
        }

        return self::declaredBy(manifest: $xml);
    }

    /**
     * The line of a version: its major and minor parts (2.2 for 2.2.1).
     *
     * @param string $version The version, as an alfa.xml declares it.
     *
     * @throws InvalidArgumentException When the text does not start with a major and a minor version.
     *
     * @since  2.2.1
     */
    public static function line(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)(?:\D|$)/', trim($version), $parts) !== 1) {
            throw new InvalidArgumentException('"' . $version . '" is not an Alfa Commerce version.');
        }

        return (int) $parts[1] . '.' . (int) $parts[2];
    }

    /**
     * Whether the range includes the line of an Alfa Commerce version.
     *
     * @param string $version The Alfa Commerce version, for example 2.2.1.
     *
     * @throws InvalidArgumentException When the text is not a version.
     *
     * @since  2.2.1
     */
    public function includes(string $version): bool
    {
        $line = self::line(version: $version);

        return version_compare($line, $this->min, '>=') && ($this->max === null || version_compare($line, $this->max, '<='));
    }

    /**
     * The first line, as declared.
     *
     * @since  2.2.1
     */
    public function min(): string
    {
        return $this->min;
    }

    /**
     * The last line, as declared; null for an open range.
     *
     * @since  2.2.1
     */
    public function max(): ?string
    {
        return $this->max;
    }

    /**
     * The range in words for the logs and the installer: "2.1 to 2.1", "2.2 and later".
     *
     * @since  2.2.1
     */
    public function describe(): string
    {
        return $this->max === null ? $this->min . ' and later' : $this->min . ' to ' . $this->max;
    }
}
