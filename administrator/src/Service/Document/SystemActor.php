<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

/**
 * The shop itself as the one who issues a document: a policy the shop configured, not a person.
 *
 * DocumentIssuer::issue() asks a person for the Sales Documents: Create permission (2.2.1). Issuance by a
 * policy, such as the automatic issue on a status change, asks no staff permission whoever triggered it, and its
 * caller says so by naming the policy here. Nothing else stands in for a person: a caller that names no actor is
 * judged as the request's own identity, and a request with none is refused.
 *
 * @since  2.2.1
 */
final class SystemActor
{
    /**
     * @param string $policy What issues, as the log names it (for example "automatic issue on a status change").
     *
     * @since  2.2.1
     */
    public function __construct(public readonly string $policy)
    {
    }
}
