<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use InvalidArgumentException;
use Throwable;

defined('_JEXEC') or die;

/**
 * A content language, in every form the search subsystem needs.
 *
 * Two representations exist and they are NOT interchangeable: the BCP-47 display
 * form (`en-GB`) that Joomla and external engines use to pick an analyzer, and
 * the lower-snake storage key (`en_gb`) used by the `lang` column and by every
 * per-language table suffix. Passing the wrong one silently returns nothing.
 *
 * THIS IS THE ONLY CLASS PERMITTED TO TRANSLATE BETWEEN THEM. Before it existed,
 * five call sites re-implemented `str_replace('-', '_')` independently; the moment
 * a plugin contract publishes a bare `string` language, every engine plugin
 * becomes the sixth. Accept a LanguageTag, never a string.
 *
 * Construction is forgiving (`en-GB`, `en_gb`, `EN-gb` all resolve identically);
 * only the output is strict.
 *
 * @since  2.0.0
 */
final class LanguageTag
{
    /**
     * @param string $storageKey Lower-snake form, e.g. "en_gb".
     *
     * @since  2.0.0
     */
    private function __construct(private readonly string $storageKey)
    {
    }

    /**
     * The accepted storage form. Forgiving about SPELLING, strict about CHARSET:
     * `#__languages.lang_code` is merchant-editable free text, and a tag such as
     * "el/gr" would travel into a document key that external engines expose
     * as a URL path segment. Rejecting it here is cheaper than escaping it in
     * every engine adapter.
     *
     * @var string
     *
     * @since  2.0.0
     */
    private const STORAGE_PATTERN = '/^[a-z]{2,3}(_[a-z0-9]{2,8})*$/';

    /**
     * Build from any accepted spelling of a tag.
     *
     * @param string $tag Language tag in display or storage form.
     *
     *
     * @throws InvalidArgumentException When the tag is not a legal language tag.
     *
     * @since  2.0.0
     */
    public static function from(string $tag): self
    {
        $storageKey = strtolower(str_replace('-', '_', trim($tag)));

        if (!preg_match(self::STORAGE_PATTERN, $storageKey)) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a usable content language tag.', $tag),
            );
        }

        return new self($storageKey);
    }

    /**
     * Every published content language: the set a complete index must cover.
     *
     * Read straight from `#__languages` rather than through `LanguageHelper`, which
     * needs a running application: index writes happen from CLI, cron and update
     * scripts, and the old path failed there SILENTLY (the projection caught the
     * exception, logged it, and indexed nothing).
     *
     * Malformed tags are skipped, not fatal: `lang_code` is merchant-editable, and
     * one bad row must not be able to abort a whole rebuild.
     *
     * @return self[] Never empty; falls back to the site default.
     *
     * @since  2.0.0
     */
    public static function allContent(): array
    {
        $languages = [];

        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('lang_code'))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('ordering') . ' ASC');

            foreach ((array) $db->setQuery($query)->loadColumn() as $code) {
                try {
                    $languages[] = self::from((string) $code);
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        } catch (Throwable) {
            // Fall through to the site default below.
        }

        return $languages ?: [self::siteDefault()];
    }

    /**
     * The site's default content language: the fallback the display path uses,
     * and therefore the fallback the index must mirror.
     *
     *
     * @since  2.0.0
     */
    public static function siteDefault(): self
    {
        return self::from(MultilingualHelper::getDefaultLanguageTag());
    }

    /**
     * Any tag form reduced to the storage form (`en-GB` and `EN_gb` both to `en_gb`).
     *
     * This class's own docblock declares it the only place permitted to translate
     * between tag forms, while several call sites re-implemented
     * `strtolower(str_replace('-', '_', ...))` inline. Accepting a raw string here,
     * rather than forcing every caller through {@see self::from()}, which validates
     * and throws, is what makes that claim true in practice: a provider handed a
     * display-form tag by a caller it does not control normalises it the same way as
     * everything else, instead of inventing one more spelling.
     *
     * @param string $tag Any tag form.
     *
     * @return string Lower-snake storage form.
     *
     * @since  2.0.0
     */
    public static function normalize(string $tag): string
    {
        return strtolower(str_replace('-', '_', trim($tag)));
    }

    /**
     * Storage form: the `lang` column value and the per-language table suffix.
     *
     * @return string e.g. "en_gb"
     *
     * @since  2.0.0
     */
    public function storageKey(): string
    {
        return $this->storageKey;
    }

    /**
     * Alias of {@see self::storageKey()}, named for the call site that appends it
     * to a table name (`#__alfa_items_` . $tag->tableSuffix()).
     *
     *
     * @since  2.0.0
     */
    public function tableSuffix(): string
    {
        return $this->storageKey;
    }

    /**
     * Display form: the BCP-47 spelling Joomla's language layer expects
     * (`Language::getInstance('el-GR')`), reconstructed from the storage key.
     *
     * Two-letter subtags after the first are uppercased (the region convention
     * every Joomla language code follows) and anything longer (scripts,
     * variants) is left as stored: `el_gr` -> `el-GR`, `ca_es_valencia` ->
     * `ca-ES-valencia`. Lives here because this class is the only one permitted
     * to translate between tag forms; a caller reassembling the display form
     * inline would be the sixth re-implementation this class exists to end.
     *
     * @return string e.g. "el-GR"
     *
     * @since  2.0.0
     */
    public function displayTag(): string
    {
        $parts = explode('_', $this->storageKey);

        foreach ($parts as $i => $part) {
            if ($i > 0 && \strlen($part) === 2) {
                $parts[$i] = strtoupper($part);
            }
        }

        return implode('-', $parts);
    }

    /**
     * Storage form, so accidental string interpolation still hits a table/column
     * value rather than the display form.
     *
     *
     * @since  2.0.0
     */
    public function __toString(): string
    {
        return $this->storageKey;
    }
}
