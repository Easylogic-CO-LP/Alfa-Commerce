<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * The identity of ONE search document, in the only spelling every engine accepts.
 *
 * A document is identified by (type, item, language). Language is STRUCTURAL and
 * mandatory.
 *
 * INVARIANT PROTECTED: item 123's Greek document can never overwrite item 123's
 * English one. Today `PRIMARY KEY (item_id, lang)` enforces that and nothing else
 * has to. Hosted document stores have no such constraint: a key that
 * omits the language upserts Greek content over English and the engine answers
 * 200 OK. Nobody learns of it until a merchant reports English search returning
 * Greek titles. This type is where that invariant lives once the database is no
 * longer there to hold it.
 *
 * The rendered key is the intersection of what the whole engine roster permits:
 * inside the strictest primary-key charset in common use, URL-safe unencoded for
 * engines that expose keys as path segments, and far below the tightest id length
 * limit among them. It renders LanguageTag::storageKey() and NEVER
 * bcp47(): `en-GB` would make the separator ambiguous and the key unparseable.
 *
 * The key carries no engine and no generation: which index it lands in is the
 * indexer's business. Identity must stay stable across engines and generations,
 * because diffing one engine's keys against another's is the only safe way to
 * change engines, and diffing generations is the only way to verify a rebuild.
 *
 * Prefix deletion (`123-*`) is deliberately NOT a mechanism here: no engine on the
 * roster supports it on the id, and `12-en_gb` would match `12*` anyway. Delete by
 * enumerating allForEntity(); sweep whatever enumeration can no longer name with
 * scopeFilters().
 *
 * THE TYPE SEGMENT IS THE {@see SearchEntity} VOCABULARY. It was reserved in
 * payloadVersion 1 ("so a unified index can arrive later without every existing key
 * changing") and became constructible for categories and manufacturers with the
 * entity-typed index, through {@see self::forEntity()}, never a free string, so a
 * document key and a postings `entity` value can never disagree about a spelling.
 * Variant-grained keys remain rejected outright.
 *
 * @since  1.5.0
 */
final class DocumentId
{
    /**
     * The only document type constructible in payloadVersion 1.
     *
     * @var string
     *
     * @since  1.5.0
     */
    public const TYPE_ITEM = 'item';

    /**
     * The published key format: the sole authority on what an engine will accept.
     *
     * @var string
     *
     * @since  1.5.0
     */
    public const KEY_PATTERN = '/^[a-z]+-[0-9]+-[a-z0-9_]+$/';

    /**
     * @param string $type Document type segment; always TYPE_ITEM today.
     * @param int $itemId The #__alfa_items id.
     * @param LanguageTag $language The content language; never optional.
     *
     * @throws InvalidArgumentException When the assembled key is not engine-legal.
     *
     * @since  1.5.0
     */
    private function __construct(
        private readonly string $type,
        private readonly int $itemId,
        private readonly LanguageTag $language,
    ) {
        if ($this->itemId < 1) {
            throw new InvalidArgumentException('DocumentId requires a positive item id.');
        }

        if (!preg_match(self::KEY_PATTERN, $this->key())) {
            throw new InvalidArgumentException(
                sprintf('DocumentId "%s" is not accepted by every supported engine.', $this->key()),
            );
        }
    }

    /**
     * The document for one item in one language.
     *
     * @param int $itemId The #__alfa_items id.
     * @param LanguageTag $language The content language.
     *
     *
     * @since  1.5.0
     */
    public static function forItem(int $itemId, LanguageTag $language): self
    {
        return self::forEntity(entity: SearchEntity::ITEM, id: $itemId, language: $language);
    }

    /**
     * The document for one row of ANY catalogue entity in one language.
     *
     * The entity arrives as the ENUM, never a string: the enum's backed values ARE
     * the type segments, so a key like `category-5-en_gb` can only be spelled the
     * one way the whole subsystem spells it.
     *
     * @param SearchEntity $entity Whose row this document describes.
     * @param int $id The row id in the entity's own table.
     * @param LanguageTag $language The content language.
     *
     *
     * @since  1.9.0
     */
    public static function forEntity(SearchEntity $entity, int $id, LanguageTag $language): self
    {
        return new self(type: $entity->value, itemId: $id, language: $language);
    }

    /**
     * Every document that exists for one item across a known language roster.
     *
     * This is the delete path. Deleting an item means deleting N exact keys (which
     * every engine supports natively and in batches) and never delete-by-query,
     * the weakest and most conditional primitive on the roster.
     *
     * @param int $itemId The #__alfa_items id.
     * @param LanguageTag ...$languages The site's content languages.
     *
     * @return self[]
     *
     * @since  1.5.0
     */
    public static function allForItem(int $itemId, LanguageTag ...$languages): array
    {
        return self::allForEntity(SearchEntity::ITEM, $itemId, ...$languages);
    }

    /**
     * Every document that exists for one entity row across a known language roster.
     *
     * @param SearchEntity $entity Whose row.
     * @param int $id The row id in the entity's own table.
     * @param LanguageTag ...$languages The site's content languages.
     *
     * @return self[]
     *
     * @since  1.9.0
     */
    public static function allForEntity(SearchEntity $entity, int $id, LanguageTag ...$languages): array
    {
        return array_map(
            static fn (LanguageTag $language): self => self::forEntity(entity: $entity, id: $id, language: $language),
            $languages,
        );
    }

    /**
     * The engine-legal document key.
     *
     *
     * @since  1.5.0
     */
    public function key(): string
    {
        return $this->type . '-' . $this->itemId . '-' . $this->language->storageKey();
    }

    /**
     * @return int The source row id, in the document entity's own table domain.
     *
     * @since  1.5.0
     */
    public function itemId(): int
    {
        return $this->itemId;
    }

    /**
     * The type segment: a {@see SearchEntity} backed value.
     *
     * This is what an indexer writes into a postings `entity` column: taking it from
     * the key's own segment (rather than a parameter beside the document) makes a
     * document that claims one entity in its key and another in its rows
     * unrepresentable.
     *
     * @return string e.g. "item", "category".
     *
     * @since  1.9.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return LanguageTag The document's content language.
     *
     * @since  1.5.0
     */
    public function language(): LanguageTag
    {
        return $this->language;
    }

    /**
     * @return string The engine-legal key.
     *
     * @since  1.5.0
     */
    public function __toString(): string
    {
        return $this->key();
    }
}
