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
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * The price check page's report: the catalogue's prices read by the price audit.
 *
 * Read only. The report is taken inside one consistent, read-only snapshot, so every figure it compares was read at
 * the same moment while the shop keeps selling, and the snapshot is rolled back whatever happens. It changes nothing:
 * every finding links to the screen where it is put right.
 *
 * @since  2.3.0
 */
class PriceauditModel extends BaseDatabaseModel
{
    /**
     * Read the report.
     *
     * Opens its own transaction on the model's connection, so it must not run inside a caller's transaction.
     *
     * @return array The report (PriceAudit::report()).
     *
     * @since  2.3.0
     */
    public function getReport(): array
    {
        return PriceAudit::read(db: TransactionState::driver(db: $this->getDatabase()));
    }
}
