<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * An outside address the shop does not connect to ({@see OutboundAddressGuard::vet()}), with the reason as a language
 * key staff read.
 *
 * @since  2.2.2
 */
final class OutboundAddressRefused extends RuntimeException
{
    /** Not a URL with a host, or one carrying a user name or password. @since 2.2.2 */
    public const NOT_URL = 'COM_ALFA_OUTBOUND_ADDRESS_NOT_URL';

    /** A scheme other than http or https, where both are taken (an image fetched from its address). @since 2.2.2 */
    public const SCHEME = 'COM_ALFA_OUTBOUND_ADDRESS_SCHEME';

    /** A scheme other than https, where only https is taken (a webhook's address). @since 2.3.0 */
    public const HTTPS_ONLY = 'COM_ALFA_WEBHOOK_ADDRESS_SCHEME';

    /** The host does not resolve, or resolves to an address that is not public. @since 2.2.2 */
    public const NOT_PUBLIC = 'COM_ALFA_OUTBOUND_ADDRESS_NOT_PUBLIC';

    /**
     * @param string $reason One of the constants: the language key of the reason.
     *
     * @since  2.2.2
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
