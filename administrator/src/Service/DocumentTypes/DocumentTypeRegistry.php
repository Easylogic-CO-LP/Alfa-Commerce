<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\DocumentTypes;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Documents\CollectDocumentTypeProvidersEvent;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Provider\DocumentTypeProviderInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * DocumentTypeRegistry: THE write path of the document type registry.
 *
 * Providers declare; this service decides. It owns every rule that keeps
 * the semantic core safe:
 *
 *   - Layer-1 validation: known family, known pool, allowed combination.
 *   - Namespace protection: packages can never register or touch `core.*`;
 *     a type belongs to the provider that first registered it.
 *   - Lifecycle: re-registration by the owner updates presentation and
 *     metadata freely, but family/pool changes are REJECTED while any
 *     document of the alias exists: accounting history is inviolable.
 *   - Uninstall means DISABLE, never delete.
 *
 * Discovery: syncProviders() imports the `alfa` plugin group, dispatches
 * the `onCollectDocumentTypeProviders` collection event, and runs every
 * returned DocumentTypeProviderInterface. Idempotent: safe to run on
 * install scripts and from the Types-screen toolbar.
 *
 * @since  1.3.0
 */
final class DocumentTypeRegistry
{
    /**
     * The canonical document-type alias width, in characters.
     *
     * Every column that persists an alias is varchar(60): this registry's own
     * `alias`, `#__alfa_document_series.doc_type` and `#__alfa_documents.doc_type`.
     * Registration is where a package finds out it is too long; the alternative is
     * a driver error at series creation or at issuance, which names no package.
     *
     * @since  1.10.0
     */
    public const ALIAS_MAX_LENGTH = 60;

    /**
     * The width of the stored pairing, in characters: `corrects` is varchar(1000).
     *
     * @since  1.14.0
     */
    public const CORRECTS_MAX_LENGTH = 1000;

    /**
     * Allowed quantity pools per family: the Layer-1 combination matrix.
     *
     * The credit family has ONE pool and that is a hard invariant: a credit
     * type must draw on issued sales quantity, because every money safeguard
     * that makes a credit note safe (the id_corrects requirement, the
     * cumulative cap, the wallet clawback on void, the credited-units figure
     * and the refund picker) keys on the CREDIT POOL, while the issue screen
     * keys on the family. A credit/none type would therefore render the credit
     * UI with every one of those guards switched off. A document that neither
     * consumes nor reverses credited quantities is not a credit note; it
     * belongs to another family.
     */
    private const FAMILY_POOLS = [
        DocumentHelper::FAMILY_SALES => [DocumentHelper::POOL_SALES, DocumentHelper::POOL_NONE],
        DocumentHelper::FAMILY_CREDIT => [DocumentHelper::POOL_CREDIT],
        DocumentHelper::FAMILY_LOGISTICS => [DocumentHelper::POOL_INDEPENDENT, DocumentHelper::POOL_NONE],
        DocumentHelper::FAMILY_INFORMATIONAL => [DocumentHelper::POOL_NONE],
    ];

    /**
     * Definition keys that map to semantic/presentation columns. Anything
     * else (except 'name') is rejected: semantics never hide in params.
     */
    private const DEFINITION_COLUMNS = [
        'family', 'quantity_pool', 'shows_prices', 'movement_mode',
        'requires_original', 'corrects', 'requires_buyer_tax_id', 'stock_effect',
        'default_layout', 'icon', 'color', 'ordering', 'params',
    ];

    /**
     * @var string The provider element currently being synced; stamps implicit register() calls.
     * @since  1.8.0
     */
    private string $activeProvider = '';

    /**
     * @var string The synced provider's announced version.
     * @since  1.8.0
     */
    private string $activeVersion = '';

    /**
     * Open a fluent definition: the recommended authoring surface. The terminal
     * {@see DocumentTypeBuilder::register()} lands in {@see self::register()}, so both
     * surfaces share the one validator.
     *
     * @param string $alias The namespaced machine key (vendor.type).
     *
     *
     * @since  1.8.0
     */
    public function type(string $alias): DocumentTypeBuilder
    {
        return new DocumentTypeBuilder($this, $alias);
    }

    /**
     * Register (insert or lifecycle-update) one document type for a
     * package provider.
     *
     * @param array $definition alias (REQUIRED, namespaced), family,
     *                          quantity_pool, flags, optional
     *                          'corrects' => [sales type aliases] for a
     *                          credit type, optional presentation
     *                          defaults, optional 'name' => {langTag:
     *                          text} translations, optional 'params' =>
     *                          metadata array.
     * @param string $provider Owning extension element (REQUIRED).
     * @param string $providerVersion The registering package version.
     *
     * @return object The persisted registry row.
     *
     * @throws RuntimeException On any contract violation; atomic, never
     *                          half-writes.
     *
     * @since  1.3.0
     */
    public function register(array $definition, string $provider = '', string $providerVersion = ''): object
    {
        $alias = (string) ($definition['alias'] ?? '');
        $provider = trim($provider);

        // Inside syncProviders() the registry knows which package it is talking to, so a
        // provider never has to name itself; explicit arguments still win (scripts, tests).
        if ($provider === '') {
            $provider = $this->activeProvider;
        }

        if ($providerVersion === '') {
            $providerVersion = $this->activeVersion;
        }

        if ($provider === '') {
            throw new RuntimeException('DocumentTypeRegistry: a provider identity is required.');
        }

        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $alias)) {
            throw new RuntimeException("DocumentTypeRegistry: alias '{$alias}' must be namespaced lowercase (vendor.type).");
        }

        // After the shape rule, so a garbage alias gets the error it deserves and
        // the value here is ASCII; mb_strlen is then the column's character count.
        if (mb_strlen($alias) > self::ALIAS_MAX_LENGTH) {
            throw new RuntimeException(
                "DocumentTypeRegistry: alias '{$alias}' is " . mb_strlen($alias)
                . ' characters; the maximum is ' . self::ALIAS_MAX_LENGTH
                . '; every column that freezes an alias is that wide.',
            );
        }

        if (str_starts_with($alias, 'core.')) {
            throw new RuntimeException("DocumentTypeRegistry: the core namespace is reserved ('{$alias}').");
        }

        $family = (string) ($definition['family'] ?? '');
        $pool = (string) ($definition['quantity_pool'] ?? '');

        if (!isset(self::FAMILY_POOLS[$family])) {
            throw new RuntimeException("DocumentTypeRegistry: unknown family '{$family}' for '{$alias}'.");
        }

        if (!\in_array($pool, self::FAMILY_POOLS[$family], true)) {
            // The credit family is the one closed to a single pool, and this
            // message is what a package author reads in the sync warning.
            $hint = $family === DocumentHelper::FAMILY_CREDIT
                ? ' A credit type must draw on issued sales quantity; a document that neither consumes'
                  . ' nor reverses credited quantities is not a credit note; declare another family.'
                : '';

            throw new RuntimeException(
                "DocumentTypeRegistry: pool '{$pool}' is not allowed for family '{$family}' ('{$alias}')." . $hint,
            );
        }

        foreach (array_keys($definition) as $key) {
            if ($key !== 'alias' && $key !== 'name' && !\in_array($key, self::DEFINITION_COLUMNS, true)) {
                throw new RuntimeException("DocumentTypeRegistry: unknown definition key '{$key}' for '{$alias}'.");
            }
        }

        $params = $definition['params'] ?? [];

        if (!\is_array($params)) {
            throw new RuntimeException("DocumentTypeRegistry: params must be an array ('{$alias}').");
        }

        foreach (['family', 'quantity_pool', 'shows_prices', 'movement_mode', 'requires_original', 'corrects', 'requires_buyer_tax_id', 'stock_effect', 'is_core', 'alias', 'provider'] as $semantic) {
            if (\array_key_exists($semantic, $params)) {
                throw new RuntimeException("DocumentTypeRegistry: semantics never live in params ('{$semantic}' on '{$alias}').");
            }
        }

        $corrects = $definition['corrects'] ?? [];

        if (!\is_array($corrects)) {
            throw new RuntimeException("DocumentTypeRegistry: corrects must be a list of sales type aliases ('{$alias}').");
        }

        $corrects = $this->pairing(alias: $alias, family: $family, corrects: $corrects);

        $db = Factory::getContainer()->get('DatabaseDriver');
        $existing = $this->loadRow(alias: $alias);

        if ($existing !== null) {
            if ((int) $existing->is_core === 1 || (string) $existing->provider !== $provider) {
                throw new RuntimeException("DocumentTypeRegistry: '{$alias}' belongs to another owner.");
            }

            $semanticsChange = (string) $existing->family !== $family
                || (string) $existing->quantity_pool !== $pool;

            if ($semanticsChange && $this->hasDocuments(alias: $alias)) {
                throw new RuntimeException(
                    "DocumentTypeRegistry: '{$alias}' has issued documents; family/pool can never change. Register a new alias instead.",
                );
            }
        }

        $row = (object) [
            'alias' => $alias,
            'family' => $family,
            'quantity_pool' => $pool,
            'shows_prices' => (int) (bool) ($definition['shows_prices'] ?? true),
            'movement_mode' => $this->enumValue($definition['movement_mode'] ?? 'none', ['none', 'optional', 'required'], 'movement_mode', $alias),
            'requires_original' => $this->enumValue($definition['requires_original'] ?? 'no', ['no', 'optional', 'required'], 'requires_original', $alias),
            'corrects' => $corrects,
            'requires_buyer_tax_id' => (int) (bool) ($definition['requires_buyer_tax_id'] ?? false),
            'stock_effect' => $this->enumValue($definition['stock_effect'] ?? 'none', ['none', 'deduct', 'restore'], 'stock_effect', $alias),
            'default_layout' => (string) ($definition['default_layout'] ?? ''),
            'icon' => (string) ($definition['icon'] ?? ''),
            'color' => (string) ($definition['color'] ?? ''),
            'ordering' => (int) ($definition['ordering'] ?? 0),
            'is_core' => 0,
            'provider' => $provider,
            'provider_version' => $providerVersion,
            'params' => $params !== [] ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            'modified' => Factory::getDate('now', 'UTC')->toSql(),
        ];

        if ($existing !== null) {
            $row->id = (int) $existing->id;
            // Re-registration re-enables a type its package disabled on a
            // previous uninstall; merchants can hide it again afterwards.
            $row->state = 1;
            $db->updateObject('#__alfa_document_types', $row, 'id');
        } else {
            $row->state = 1;
            $row->created = $row->modified;
            $db->insertObject('#__alfa_document_types', $row, 'id');
        }

        $this->saveTranslations(typeId: (int) $row->id, names: (array) ($definition['name'] ?? []));

        return $this->loadRow(alias: $alias);
    }

    /**
     * The stored form of the sales types a credit type corrects: the one rule
     * every writer of the pairing goes through: a provider's declaration and
     * the document type form.
     *
     * Only a credit type corrects, and only a sales document is corrected (a
     * credit draws on issued sales quantity). Each named type must already be
     * in the registry, so a provider registers its sales type before the
     * credit type that corrects it. The set is stored sorted and without
     * repeats, so one pairing has one stored form.
     *
     * @param string $alias The type the pairing belongs to (named in a refusal).
     * @param string $family That type's family.
     * @param string[] $corrects The sales type aliases it corrects; empty for none.
     *
     * @return string The comma set to store; '' for none.
     *
     * @throws PairingRefusedException When the pairing breaks a rule; nothing is written.
     *
     * @since  1.14.0
     */
    public function pairing(string $alias, string $family, array $corrects): string
    {
        $named = [];

        foreach ($corrects as $sales) {
            if (!\is_string($sales)) {
                throw new PairingRefusedException(
                    reason:  PairingRefusedException::NOT_SALES,
                    named:   [get_debug_type($sales)],
                    message: "DocumentTypeRegistry: corrects must name sales types by alias ('{$alias}').",
                );
            }

            if (trim($sales) !== '') {
                $named[trim($sales)] = true;
            }
        }

        $named = array_keys($named);

        if ($named === []) {
            return '';
        }

        if ($family !== DocumentHelper::FAMILY_CREDIT) {
            throw new PairingRefusedException(
                reason:  PairingRefusedException::NOT_CREDIT,
                named:   [],
                message: "DocumentTypeRegistry: only a credit type corrects other document types ('{$alias}' is {$family}).",
            );
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // The table, not the request cache: the sales type may have been
        // registered a moment ago in this same sync.
        $sales = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('alias'))
                ->from($db->quoteName('#__alfa_document_types'))
                ->where($db->quoteName('quantity_pool') . ' = ' . $db->quote(DocumentHelper::POOL_SALES))
                ->whereIn($db->quoteName('alias'), $named, ParameterType::STRING),
        )->loadColumn() ?: [];

        $unknown = array_values(array_diff($named, $sales));

        if ($unknown !== []) {
            throw new PairingRefusedException(
                reason:  PairingRefusedException::NOT_SALES,
                named:   $unknown,
                message: "DocumentTypeRegistry: '{$alias}' can correct only sales document types; '"
                    . implode("', '", $unknown) . "' is not one.",
            );
        }

        sort($named);
        $set = implode(',', $named);

        if (\strlen($set) > self::CORRECTS_MAX_LENGTH) {
            throw new PairingRefusedException(
                reason:  PairingRefusedException::TOO_LONG,
                named:   [],
                message: "DocumentTypeRegistry: '{$alias}' corrects more types than its pairing holds ("
                    . self::CORRECTS_MAX_LENGTH . ' characters).',
            );
        }

        return $set;
    }

    /**
     * Disable every type a provider owns: the uninstall path. Rows are
     * never deleted: issued documents keep resolving their type forever.
     *
     * @param string $provider The extension element.
     *
     * @return int Number of rows disabled.
     *
     * @since  1.3.0
     */
    public function disableByProvider(string $provider): int
    {
        if (trim($provider) === '') {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_document_types'))
            ->set($db->quoteName('state') . ' = 0')
            ->where([
                $db->quoteName('provider') . ' = :provider',
                $db->quoteName('is_core') . ' = 0',
            ])
            ->bind(':provider', $provider);
        $db->setQuery(query: $query);
        $db->execute();

        return (int) $db->getAffectedRows();
    }

    /**
     * Which providers are present but NOT in step with the registry: a DRY RUN of
     * {@see self::syncProviders()}: it collects the providers exactly as the sync does but
     * never calls register(), so it writes nothing.
     *
     * A provider is "pending" when it has no registered types at all (installed, never
     * synced) or when the version it announces differs from the version its rows carry (the
     * package was upgraded and may have changed definitions). That is what lets the Document
     * Types screen say "1 provider needs syncing" instead of leaving the operator to guess:
     * types are DATA and only an explicit sync writes them, so the screen owes them the hint.
     *
     * @return string[] The provider elements that would change something, alphabetically.
     *
     * @since  1.8.0
     */
    public function pendingProviders(): array
    {
        $event = new CollectDocumentTypeProvidersEvent('onCollectDocumentTypeProviders', ['providers' => []]);

        try {
            foreach (PluginHelper::getPlugin('alfa') as $descriptor) {
                try {
                    $plugin = Factory::getApplication()->bootPlugin($descriptor->name, 'alfa');

                    if (method_exists($plugin, 'onCollectDocumentTypeProviders')) {
                        $plugin->onCollectDocumentTypeProviders($event);
                    }
                } catch (Throwable $e) {
                    // Skipping the provider is right; doing it SILENTLY was not.
                    // A document type simply disappeared from the registry with
                    // nothing anywhere saying which plugin dropped it.
                    Log::add(
                        entry:    "DocumentTypeRegistry: provider plugin '{$descriptor->name}' failed and was skipped: "
                                  . $e->getMessage(),
                        priority: Log::ERROR,
                        category: 'com_alfa.documents',
                    );
                }
            }
        } catch (Throwable) {
            return [];
        }

        $declared = [];

        foreach ($event->getProviders() as $entry) {
            $element = (string) ($entry['element'] ?? '');

            if ($element !== '') {
                $declared[$element] = (string) ($entry['version'] ?? '');
            }
        }

        if ($declared === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $registered = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('provider'), 'MAX(' . $db->quoteName('provider_version') . ') AS version'])
                ->from($db->quoteName('#__alfa_document_types'))
                ->whereIn($db->quoteName('provider'), array_keys($declared), ParameterType::STRING)
                ->group($db->quoteName('provider')),
        )->loadAssocList('provider', 'version') ?: [];

        $pending = [];

        foreach ($declared as $element => $version) {
            if (!isset($registered[$element]) || (string) $registered[$element] !== $version) {
                $pending[] = $element;
            }
        }

        sort($pending);

        return $pending;
    }

    /**
     * Discover and run every document type provider: boot-and-call the `alfa` PLATFORM group
     * and register what each provider declares. A failing provider is logged and skipped:
     * one bad package never blocks the others.
     *
     * THE `alfa` GROUP ON PURPOSE: registering document types is shared BACKEND integration,
     * an operator-driven sync into the persistent registry, fired only from the admin
     * Document Types screen, so it belongs to the platform layer (a capability that ships
     * types provides an `alfa`-group companion, the gateway-admin pattern). Boot-and-call rather
     * than the app dispatcher so only this group hears it, matching every other collect point.
     *
     * @return array{registered: string[], errors: array<string, string>}
     *
     * @since  1.3.0
     */
    public function syncProviders(): array
    {
        $summary = ['registered' => [], 'errors' => []];

        $event = new CollectDocumentTypeProvidersEvent('onCollectDocumentTypeProviders', ['providers' => []]);

        try {
            foreach (PluginHelper::getPlugin('alfa') as $descriptor) {
                try {
                    $plugin = Factory::getApplication()->bootPlugin($descriptor->name, 'alfa');

                    if (method_exists($plugin, 'onCollectDocumentTypeProviders')) {
                        $plugin->onCollectDocumentTypeProviders($event);
                    }
                } catch (Throwable $e) {
                    $summary['errors'][$descriptor->name] = $e->getMessage();
                    Log::add(
                        entry:    "DocumentTypeRegistry: plugin '{$descriptor->name}' failed to boot: " . $e->getMessage(),
                        priority: Log::WARNING,
                        category: 'com_alfa.documents',
                    );
                }
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'DocumentTypeRegistry: could not enumerate platform plugins: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.documents',
            );
        }

        foreach ($event->getProviders() as $entry) {
            $provider = $entry['provider'] ?? null;
            $element = (string) ($entry['element'] ?? '');
            $version = (string) ($entry['version'] ?? '');

            if (!$provider instanceof DocumentTypeProviderInterface || $element === '') {
                continue;
            }

            try {
                $before = $this->aliasesOf(provider: $element);

                $this->activeProvider = $element;
                $this->activeVersion = $version;

                $provider->register($this);
                $summary['registered'] = array_merge(
                    $summary['registered'],
                    array_values(array_diff($this->aliasesOf(provider: $element), $before)),
                );
            } catch (Throwable $e) {
                $summary['errors'][$element] = $e->getMessage();
                Log::add(
                    entry:    "DocumentTypeRegistry: provider '{$element}' failed: " . $e->getMessage(),
                    priority: Log::WARNING,
                    category: 'com_alfa.documents',
                );
            } finally {
                // The stamp belongs to one provider's run; never let it leak onto the next.
                $this->activeProvider = '';
                $this->activeVersion = '';
            }
        }

        return $summary;
    }

    /**
     * Aliases currently owned by a provider.
     *
     * @param string $provider The extension element.
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public function aliasesOf(string $provider): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__alfa_document_types'))
            ->where($db->quoteName('provider') . ' = :provider')
            ->bind(':provider', $provider);
        $db->setQuery(query: $query);

        return $db->loadColumn() ?: [];
    }

    /**
     * Persist per-language names into the aux tables.
     *
     * @param int $typeId The registry row id.
     * @param array $names {langTag: text}.
     *
     * @since  1.3.0
     */
    private function saveTranslations(int $typeId, array $names): void
    {
        if ($typeId <= 0 || $names === []) {
            return;
        }

        $data = ['id' => $typeId];

        foreach ($names as $langTag => $text) {
            $suffix = strtolower(str_replace('-', '_', (string) $langTag));
            $data['name_' . $suffix] = (string) $text;
        }

        try {
            MultilingualHelper::saveMultilingualData(
                currentId:         $typeId,
                primaryColumnName: 'id_document_type',
                tableName:         '#__alfa_document_types',
                data:              $data,
                aliasFields:       [],
            );
        } catch (Throwable $e) {
            // Aux tables absent (pre-sync installs): names fall back to
            // the humanised alias until the language schema sync runs.
            Log::add(
                entry:    'DocumentTypeRegistry: translations skipped: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.documents',
            );
        }
    }

    /**
     * Load one registry row by alias.
     *
     * @param string $alias The type alias.
     *
     *
     * @since  1.3.0
     */
    private function loadRow(string $alias): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_types'))
            ->where($db->quoteName('alias') . ' = :alias')
            ->bind(':alias', $alias);
        $db->setQuery(query: $query);

        return $db->loadObject();
    }

    /**
     * Whether any document (any status) stores this alias.
     *
     * @param string $alias The type alias.
     *
     *
     * @since  1.3.0
     */
    private function hasDocuments(string $alias): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_documents'))
            ->where($db->quoteName('doc_type') . ' = :alias')
            ->bind(':alias', $alias)
            ->setLimit(1);
        $db->setQuery(query: $query);

        return (bool) $db->loadResult();
    }

    /**
     * Validate an enum-ish definition value.
     *
     * @param mixed $value The raw value.
     * @param string[] $allowed Allowed values.
     * @param string $key Definition key (for the error message).
     * @param string $alias Type alias (for the error message).
     *
     *
     * @throws RuntimeException On an unknown value.
     *
     * @since  1.3.0
     */
    private function enumValue(mixed $value, array $allowed, string $key, string $alias): string
    {
        $value = (string) $value;

        if (!\in_array($value, $allowed, true)) {
            throw new RuntimeException("DocumentTypeRegistry: invalid {$key} '{$value}' for '{$alias}'.");
        }

        return $value;
    }
}
