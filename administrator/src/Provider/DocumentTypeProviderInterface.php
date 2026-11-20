<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Provider;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\DocumentTypes\DocumentTypeRegistry;

/**
 * Contract for extensions that provide document types.
 *
 * The first member of the Alfa provider family: an extension is a
 * per-business-domain container whose capabilities ARE its code: it
 * implements provider interfaces and subscribes to the collection
 * events; no manifest metadata duplicates this. A listener of the
 * `onCollectDocumentTypeProviders` collection event (any loaded listener)
 * adds its provider with addProvider(provider, contributor,
 * version); the registry sync then calls register() and persists the
 * declared types under the contributor's ownership.
 *
 * Providers declare; the registry decides. All validation, lifecycle
 * and protection rules live in DocumentTypeRegistry.
 *
 * @since  2.0.0
 */
interface DocumentTypeProviderInterface
{
    /**
     * Declare this package's document types into the registry.
     *
     * Implementations call $registry->register() once per type. Throwing
     * aborts THIS provider's sync only; other providers still run.
     *
     * @param DocumentTypeRegistry $registry The registry service.
     *
     *
     * @since  2.0.0
     */
    public function register(DocumentTypeRegistry $registry): void;
}
