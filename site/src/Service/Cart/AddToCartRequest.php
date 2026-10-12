<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\AddToCartCommand;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Joomla\Input\Input;

defined('_JEXEC') or die;

/**
 * A storefront add, read off the request.
 *
 * The chosen options are in the query, the uploads in the files, whether a
 * line is being edited in a posted field. This is the one place they are
 * read; below it the add is a command and knows nothing of a request.
 *
 * @since  1.10.0
 */
final class AddToCartRequest
{
    /**
     * @param Input $input The request.
     * @param int $itemId The product.
     * @param int|float $quantity How many.
     *
     * @since  1.10.0
     */
    public static function command(Input $input, int $itemId, int|float $quantity): AddToCartCommand
    {
        $jform = (array) $input->get('jform', [], 'array');
        $files = $input->files->get('conf_file', [], 'raw');

        return new AddToCartCommand(
            itemId:       $itemId,
            quantity:     $quantity,
            selection:    VariantHelper::requestSelection(),
            fullPayloads: (array) ($jform['itemfields_payload'] ?? []),
            files:        is_array($files) ? $files : [],
            editLineId:   (int) $input->getInt('edit_line', 0),
        );
    }
}
