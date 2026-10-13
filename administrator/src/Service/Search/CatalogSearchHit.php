<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * One hydrated, typed search hit: the fields EVERY entity guarantees, plus the
 * entity's own row for everything else.
 *
 * WHY TWO LAYERS OF DATA. The mandatory fields (`title`, `url`, `description`) are the
 * cross-entity contract a generic surface renders without knowing what it is holding:
 * this is what lets one dropdown template walk three sections. Everything
 * entity-specific (images, prices, layouts, stock) rides on `$row`, the Site model's
 * own hydrated object, COMPOSED rather than flattened: a metadata bag would be
 * stringly-typed and unownable, per-entity subclasses would multiply shapes that
 * differ only in data. A surface that knows its entity reads the row; a surface that
 * doesn't reads the contract.
 *
 * SEARCH-ONLY, BY DESIGN. A recommendation ("bought with", "similar") has no query, no
 * relevance and no attribution; when that subsystem arrives it returns its own shape.
 * Borrowing this one would smuggle search semantics into surfaces that have none.
 *
 * Per-hit relevance is deliberately ABSENT until its first real consumer (the premium
 * module / section-order policy) lands; it will arrive as an appended nullable field
 * fed by the model's scores passback, the same route the eventId travels.
 *
 * @since  2.0.0
 */
final class CatalogSearchHit
{
    /**
     * The per-entity mapping from model row properties onto the contract fields.
     *
     * ONE TABLE, ONE PLACE: this is the job {@see CatalogSearchPayload}'s FIELD_MAP
     * used to do at the wire; it moved here so the typed hit and the wire can never
     * disagree about which column carries a description. The three entities differ
     * only in which property holds the description and whether a route exists.
     *
     * KEYS ARE THE ENUM'S BACKED VALUES SPELLED AS LITERALS, deliberately: fetching an
     * enum property inside a constant expression (`SearchEntity::ITEM->value`) parses
     * only from PHP 8.2, and this class must load on 8.1, Joomla 5's floor. The
     * spellings are pinned to the enum by construction, since lookup is always
     * `$entity->value`.
     *
     * Warning: ITEM descriptions are structurally EMPTY on the list path today: the items
     * list query's multilingual join does not select `short_desc` (only the
     * single-item query does), so the wire's product `description` is '' until that
     * column joins the list projection: a deliberate follow-up, not a hit bug.
     *
     * @var array<string, array{url: string|null, description: string}>
     *
     * @since  2.0.0
     */
    private const ROW_MAP = [
        'item' => ['url' => 'link', 'description' => 'short_desc'],
        'category' => ['url' => 'link', 'description' => 'desc'],
        // Manufacturer rows DO carry a routed `link`; the null here is CONTRACT POLICY
        // carried over from wire v1 (the dropdown renders manufacturers unrouted, and
        // the suite pins `url: null`). Routing them is a deliberate contract change:
        // flip this to 'link' and update SECTIONS_WITHOUT_URL in the suite together.
        'manufacturer' => ['url' => null, 'description' => 'desc'],
    ];

    /**
     * @param SearchEntity $entity What kind of thing this hit is.
     * @param int $id The row id in the entity's own table domain.
     * @param string $title Display name, as the model translated it.
     * @param string|null $url Routed link, or null when the entity has no route.
     * @param string $description Plain text, cleaned but NOT truncated; deciding
     *                            that 50 characters fit a dropdown is presentation.
     * @param object $row The hydrated Site-model row this hit was built
     *                    from; all entity-specific data rides here.
     *
     * @throws InvalidArgumentException When the id is not positive.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly SearchEntity $entity,
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $description,
        public readonly object $row,
    ) {
        if ($this->id < 1) {
            throw new InvalidArgumentException('A search hit requires a positive row id.');
        }
    }

    /**
     * Build a hit from a Site model row.
     *
     * The one place row properties become contract fields. A row without a positive id
     * cannot become a hit: the CALLER filters those out (and logs), because inside a
     * per-entity assembly loop a throw would blank the whole section for one bad row.
     *
     * @param SearchEntity $entity Which entity's model produced the row.
     * @param object $row The hydrated row.
     *
     *
     * @throws InvalidArgumentException When the row carries no positive id.
     *
     * @since  2.0.0
     */
    public static function fromRow(SearchEntity $entity, object $row): self
    {
        $map = self::ROW_MAP[$entity->value];
        $urlProperty = $map['url'];
        $descProperty = $map['description'];

        return new self(
            entity:      $entity,
            id:          (int) ($row->id ?? 0),
            title:       (string) ($row->name ?? ''),
            url:         $urlProperty !== null && isset($row->{$urlProperty})
                ? (string) $row->{$urlProperty}
                : null,
            description: isset($row->{$descProperty})
                ? PlainText::fromHtml((string) $row->{$descProperty})
                : '',
            row:         $row,
        );
    }
}
