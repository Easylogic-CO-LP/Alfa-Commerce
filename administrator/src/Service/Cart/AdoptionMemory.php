<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * Whether this session has already tried to adopt this browser's cart.
 *
 * Adoption asks the database two questions and can move a cart between rows, so
 * repeating it on every request of a signed-in session is both wasteful and, on
 * the merge arm, a second chance to get a race wrong. One flag per member per
 * recognition key settles it for the session.
 *
 * The flag lives in the session because that is where "for the rest of this
 * visit" lives. This class exists so that the service deciding what adoption
 * DOES never has to know that.
 *
 * @since  1.10.0
 */
final class AdoptionMemory
{
    /**
     * @param CMSApplicationInterface|null $app The application holding the
     *                                          session; the current one when
     *                                          omitted.
     *
     * @since  1.10.0
     */
    public function __construct(private ?CMSApplicationInterface $app = null)
    {
        $this->app ??= Factory::getApplication();
    }

    /**
     * @param int $memberId Who signed in.
     * @param string $recognizeKey The key their browser carries.
     *
     * @return bool Whether this pair has already been settled this session.
     *
     * @since  1.10.0
     */
    public function settled(int $memberId, string $recognizeKey): bool
    {
        return (string) $this->app->getUserState($this->key($memberId), '') === $recognizeKey;
    }

    /**
     * Settle this pair for the rest of the session.
     *
     * @param int $memberId Who signed in.
     * @param string $recognizeKey The key their browser carries.
     *
     *
     * @since  1.10.0
     */
    public function settle(int $memberId, string $recognizeKey): void
    {
        $this->app->setUserState($this->key($memberId), $recognizeKey);
    }

    /**
     * @param int $memberId Who signed in.
     *
     * @return string The state key, unchanged from the one adoption has always
     *                written, so a session mid-visit is not asked again.
     *
     * @since  1.10.0
     */
    private function key(int $memberId): string
    {
        return 'com_alfa.cart.adopted.' . $memberId;
    }
}
