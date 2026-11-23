<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;
use RuntimeException;

/**
 * A write gave a product or a combination a SKU another unit holds ({@see SkuIndex::keep()}): the write's transaction
 * rolls back, and its caller says the refusal's sentence.
 *
 * @since  2.3.0
 */
final class SkuInUseException extends RuntimeException
{
    /**
     * @param Refusal $refusal The refusal, sku_in_use, 409.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly Refusal $refusal)
    {
        parent::__construct($refusal->sentence, $refusal->status);
    }
}
