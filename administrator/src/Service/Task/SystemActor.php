<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Closure;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Throwable;

/**
 * Background work runs as nobody: the neutral system actor, an empty user, whatever request runs it (a visitor's page,
 * a member of staff's save, the API, the command line). The request's own identity is given back afterwards.
 *
 * @since  2.3.0
 */
final class SystemActor
{
    /**
     * Run some work as the system actor, and give the request its identity back, whatever the work does.
     *
     * @param Closure(): mixed $work The work.
     *
     * @return mixed What the work answered.
     *
     * @since  2.3.0
     */
    public static function around(Closure $work): mixed
    {
        $identity = self::become();

        try {
            return $work();
        } finally {
            self::giveBack(identity: $identity);
        }
    }

    /**
     * Make the application's identity the empty user; the identity to give back, or null where there is no
     * application.
     *
     * @since  2.3.0
     */
    public static function become(): ?User
    {
        try {
            $app = Factory::getApplication();
        } catch (Throwable) {
            return null;
        }

        $identity = $app->getIdentity() ?? new User();
        $app->loadIdentity(new User());

        return $identity;
    }

    /**
     * Give the request its own identity back.
     *
     * @param User|null $identity What become() answered.
     *
     * @since  2.3.0
     */
    public static function giveBack(?User $identity): void
    {
        if ($identity !== null) {
            Factory::getApplication()->loadIdentity($identity);
        }
    }
}
