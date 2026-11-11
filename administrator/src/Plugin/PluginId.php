<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use InvalidArgumentException;

\defined('_JEXEC') or die;

/**
 * A plugin's identity: its group (the folder) and its element, both lower case, written `group.element` (2.2.0, design
 * 2.15.2). The identity a contributor names itself by and the one the helper boots a plugin by; attribution and routing,
 * never an authorisation.
 *
 * @since  2.2.0
 */
final class PluginId
{
    private const PART = '/^[a-z0-9_-]+$/';

    /**
     * @param string $group The plugin group (folder).
     * @param string $element The plugin element.
     *
     * @throws InvalidArgumentException When either part is not lower case letters, digits, underscores or hyphens.
     *
     * @since  2.2.0
     */
    private function __construct(
        public readonly string $group,
        public readonly string $element,
    ) {
        if (preg_match(self::PART, $group) !== 1 || preg_match(self::PART, $element) !== 1) {
            throw new InvalidArgumentException('A plugin identity is group.element in lower case letters, digits, underscores or hyphens (given: ' . $group . '.' . $element . ').');
        }
    }

    /**
     * The identity from its string form `group.element`.
     *
     * @param string $id The identity, for example alfa-payments.standard.
     *
     * @throws InvalidArgumentException When the string is not group.element.
     *
     * @since  2.2.0
     */
    public static function of(string $id): self
    {
        $parts = explode('.', $id);

        if (\count($parts) !== 2) {
            throw new InvalidArgumentException('A plugin identity is group.element (given: ' . $id . ').');
        }

        return new self($parts[0], $parts[1]);
    }

    /**
     * The identity from its two parts.
     *
     * @param string $group The plugin group (folder).
     * @param string $element The plugin element.
     *
     * @since  2.2.0
     */
    public static function from(string $group, string $element): self
    {
        return new self($group, $element);
    }

    /**
     * The string form, group.element.
     *
     * @since  2.2.0
     */
    public function key(): string
    {
        return $this->group . '.' . $this->element;
    }

    /**
     * The string form, group.element.
     *
     * @since  2.2.0
     */
    public function __toString(): string
    {
        return $this->key();
    }
}
