<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAudit;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditStore;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * The price check page's data: the stored result of the check's last complete run and where the check stands, read
 * from the store (PriceAuditStore). Nothing is computed here: the check is the job's, and the page shows what it kept.
 *
 * @since  2.3.0
 */
class PriceauditModel extends BaseDatabaseModel
{
    /**
     * Where the check stands: its state, the last complete summary, the job running, the failure, the next run.
     *
     * @return array See PriceAuditStore::status().
     *
     * @since  2.3.0
     */
    public function getStatus(): array
    {
        return $this->store()->status();
    }

    /**
     * One page of a section's stored findings, the products named.
     *
     * @param string $section The section.
     * @param int $start The rows before the page.
     * @param int $limit The page's rows.
     *
     * @return array{rows: list<array>, total: int, start: int, limit: int}
     *
     * @since  2.3.0
     */
    public function getPage(string $section, int $start, int $limit): array
    {
        $page = $this->store()->rows(section: $section, offset: $start, limit: $limit);

        return [
            'rows' => PriceAudit::named(db: TransactionState::driver(db: $this->getDatabase()), rows: $page['rows']),
            'total' => (int) $page['total'],
            'start' => max(0, $start),
            'limit' => max(1, $limit),
        ];
    }

    /**
     * @since  2.3.0
     */
    private function store(): PriceAuditStore
    {
        return new PriceAuditStore(db: TransactionState::driver(db: $this->getDatabase()));
    }
}
