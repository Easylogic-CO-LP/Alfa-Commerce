<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Media;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * An image the shop did not fetch from the address a client gave ({@see MediaFetch}), with the reason in words a
 * client reads: the message is the sentence, completed by the figure the reason names.
 *
 * @since  2.2.2
 */
final class MediaFetchRefused extends RuntimeException
{
    /** Not a web address with a host, or one carrying a user name or password. @since 2.2.2 */
    public const NOT_URL = 'it is not a web address with a host, or it carries a user name or password';

    /** A scheme other than http and https. @since 2.2.2 */
    public const SCHEME = 'only an http or https address is fetched';

    /** A host that does not resolve, or resolves to an address that is not public. @since 2.2.2 */
    public const NOT_PUBLIC = 'its host does not resolve, or resolves to an address that is not public (loopback, private, link-local or reserved)';

    /** More redirects than followed. @since 2.2.2 */
    public const REDIRECTS = 'it redirects more than %d times';

    /** An answer that is not a success. @since 2.2.2 */
    public const STATUS = 'the server answered %d';

    /** A body larger than the size allowed. @since 2.2.2 */
    public const TOO_LARGE = 'the file is larger than %d MB (Options, Images, Images by URL)';

    /** A fetch that took longer than allowed. @since 2.2.2 */
    public const TIMEOUT = 'it did not arrive within %d seconds (Options, Images, Images by URL)';

    /** A transfer that failed (the connection, TLS). @since 2.2.2 */
    public const TRANSFER = 'the connection to it failed';

    /** A file that is not an image of a type the shop takes. @since 2.2.2 */
    public const TYPE = 'its content is %s, not an image of a type the shop takes (Options, Images, Allowed Image Types)';

    /** No transport, or no temporary file. @since 2.2.2 */
    public const UNAVAILABLE = 'this server cannot fetch it (PHP curl, or the temporary folder)';

    /**
     * @param string $reason One of the constants.
     * @param int|string ...$figures What the reason names (a count, a status, a type).
     *
     * @since  2.2.2
     */
    public function __construct(public readonly string $reason, int|string ...$figures)
    {
        parent::__construct($figures === [] ? $reason : vsprintf($reason, $figures));
    }
}
