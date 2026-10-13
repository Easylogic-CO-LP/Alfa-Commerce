<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartOwner;
use Joomla\Input\Input;

/**
 * The recognition cookie, and the only class that touches it.
 *
 * A visitor with no account still has a cart, and this is the whole of how they
 * are recognised on the next request. It is deliberately the ONLY thing in the
 * cart that reads or writes a cookie: everything above it works with a
 * CartOwner, which is a value, not a request.
 *
 * WHAT IS READ BACK IS NEVER TRUSTED. The value goes verbatim into a char(64)
 * column, so a tampered, truncated or over-length cookie truncates on write and
 * can then never match on read: one new cart row per page view, forever. A
 * value that is not exactly what mint() produces is treated as absent, and the
 * caller mints a fresh one.
 *
 * @since  2.0.0
 */
final class RecognizeKey
{
    /**
     * @var string The cookie's name. Unchanged since the first release: renaming
     *             it would orphan every cart a visitor is holding right now.
     * @since  2.0.0
     */
    public const COOKIE = 'recognize_key';

    /**
     * @var int How long a visitor stays recognised, in seconds.
     * @since  2.0.0
     */
    public const LIFETIME = 3600 * 24 * 30;

    /**
     * @param Input $input The request the cookie is read from and written to.
     *
     * @since  2.0.0
     */
    public function __construct(private Input $input)
    {
    }

    /**
     * The key this request presents, or '' when there is none worth having.
     *
     *
     * @since  2.0.0
     */
    public function read(): string
    {
        $value = (string) $this->input->cookie->get(self::COOKIE, '');

        return CartOwner::isWellFormedKey($value) ? $value : '';
    }

    /**
     * A key, and nothing else: no cookie, no browser.
     *
     * The back office mints carts on a customer's behalf from the OPERATOR's
     * browser, so a walk-in cart needs a recognition identity of its own that
     * the operator's browser never carries: writing the cookie there would make
     * the operator's next storefront visit that walk-in cart's owner, and would
     * make the second walk-in find the first. This is the generator on its own;
     * {@see self::mint()} is this plus the cookie, for the visitor whose browser
     * the key is actually about.
     *
     * The randomness is the key; the address, the agent and the clock are
     * stirred in so that two callers who hit the same seed still differ. It is
     * hashed to a fixed 64 hex characters because the column is char(64) and the
     * shape is what the read path checks.
     *
     * @return string The new key.
     *
     * @since  2.0.0
     */
    public function generate(): string
    {
        return hash('sha256', \sprintf(
            '%s_%s_%s_%s',
            bin2hex(random_bytes(16)),
            $this->input->server->getString('REMOTE_ADDR', ''),
            substr(md5($this->input->server->getString('HTTP_USER_AGENT', '')), 0, 10),
            time(),
        ));
    }

    /**
     * Mint a key, put it in the browser, and return it.
     *
     * The key itself comes from {@see self::generate()}; what this adds is the
     * cookie, so the browser making the request is recognised as holding the
     * cart. A caller minting a cart for SOMEBODY ELSE wants the generator.
     *
     * @return string The new key.
     *
     * @since  2.0.0
     */
    public function mint(): string
    {
        $key = $this->generate();

        $this->input->cookie->set(
            self::COOKIE,
            $key,
            [
                'expires' => time() + self::LIFETIME,
                'path' => '/',
                'domain' => '',
                // Only over TLS when the request itself is: a Secure cookie on a
                // plain-HTTP install is never sent back, and the visitor would
                // get a new cart on every page.
                'secure' => $this->input->server->getBool('HTTPS', false),
                'httponly' => true,
                'samesite' => 'Strict',
            ],
        );

        return $key;
    }
}
