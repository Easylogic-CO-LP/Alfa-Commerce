<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\DocumentTypes;

\defined('_JEXEC') or die;

/**
 * Fluent definition of ONE document type: the recommended authoring surface of
 * {@see DocumentTypeRegistry::register()}, in the same builder style as the rest of Alfa
 * (ActionBuilder, PanelToolbar, MenuBuilder):
 *
 *     $registry->type('vendor.invoice')
 *         ->family(DocumentHelper::FAMILY_SALES)
 *         ->pool(DocumentHelper::POOL_SALES)
 *         ->movement('optional')
 *         ->requiresBuyerTaxId()
 *         ->name('el-GR', 'Τιμολόγιο Πώλησης')
 *         ->name('en-GB', 'Sales invoice')
 *         ->register();
 *
 *     $registry->type('vendor.credit_note')
 *         ->family(DocumentHelper::FAMILY_CREDIT)
 *         ->pool(DocumentHelper::POOL_CREDIT)
 *         ->corrects('vendor.invoice')
 *         ->name('en-GB', 'Credit invoice')
 *         ->register();
 *
 * The builder only SHAPES the definition: every rule lives in the registry's one
 * validator (alias namespacing, the family/pool matrix, the closed axes, ownership,
 * core protection), so the two authoring surfaces can never drift apart. Inside a
 * provider's register() the final register() call needs no arguments: the registry
 * already knows which package it is talking to and stamps provider + version itself.
 *
 * @since  2.0.0
 */
final class DocumentTypeBuilder
{
    /**
     * @var array<string, mixed> The definition being built.
     * @since  2.0.0
     */
    private array $definition;

    /**
     * Created by {@see DocumentTypeRegistry::type()}, not directly.
     *
     * @param DocumentTypeRegistry $registry The owning registry.
     * @param string $alias The namespaced machine key (vendor.type).
     *
     * @since  2.0.0
     */
    public function __construct(private readonly DocumentTypeRegistry $registry, string $alias)
    {
        $this->definition = ['alias' => $alias];
    }

    /**
     * The semantic family: a closed engine axis.
     *
     * @param string $family One of DocumentHelper::FAMILY_* (sales | credit | logistics | informational).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function family(string $family): static
    {
        $this->definition['family'] = $family;

        return $this;
    }

    /**
     * The counting semantics: a closed engine axis, constrained per family.
     *
     * @param string $pool One of DocumentHelper::POOL_* (sales | credit | independent | none).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function pool(string $pool): static
    {
        $this->definition['quantity_pool'] = $pool;

        return $this;
    }

    /**
     * Whether money columns and tax/total blocks render.
     *
     * @param bool $show True to render prices (the default when never called).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function showsPrices(bool $show = true): static
    {
        $this->definition['shows_prices'] = $show;

        return $this;
    }

    /**
     * Movement-block applicability.
     *
     * @param string $mode 'none' | 'optional' | 'required'.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function movement(string $mode): static
    {
        $this->definition['movement_mode'] = $mode;

        return $this;
    }

    /**
     * Corrective-association strictness (credit kinds referencing what they correct).
     *
     * @param string $strictness 'no' | 'optional' | 'required'.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function requiresOriginal(string $strictness = 'required'): static
    {
        $this->definition['requires_original'] = $strictness;

        return $this;
    }

    /**
     * The sales types a credit type corrects: a document of one of them that
     * an order change leaves over-documented is corrected automatically on
     * this type. Register those sales types first. A credit type that names
     * none is never issued by an automatic correction.
     *
     * @param string ...$salesTypes The sales type aliases (core.invoice, vendor.type, ...).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function corrects(string ...$salesTypes): static
    {
        $this->definition['corrects'] = array_values($salesTypes);

        return $this;
    }

    /**
     * Whether issuance requires a buyer tax/VAT number.
     *
     * @param bool $required True to require it.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function requiresBuyerTaxId(bool $required = true): static
    {
        $this->definition['requires_buyer_tax_id'] = $required;

        return $this;
    }

    /**
     * What issuing this type does to physical stock.
     *
     * @param string $effect 'none' | 'deduct' | 'restore'.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function stockEffect(string $effect): static
    {
        $this->definition['stock_effect'] = $effect;

        return $this;
    }

    /**
     * A layout id override; empty keeps the convention 'documents.<alias>.default'.
     *
     * @param string $layoutId The layout id.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function layout(string $layoutId): static
    {
        $this->definition['default_layout'] = $layoutId;

        return $this;
    }

    /**
     * Picker icon.
     *
     * @param string $icon Icon class.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function icon(string $icon): static
    {
        $this->definition['icon'] = $icon;

        return $this;
    }

    /**
     * Picker colour.
     *
     * @param string $color Hex colour (#rrggbb).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function color(string $color): static
    {
        $this->definition['color'] = $color;

        return $this;
    }

    /**
     * Picker ordering.
     *
     * @param int $ordering Sort weight.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function ordering(int $ordering): static
    {
        $this->definition['ordering'] = $ordering;

        return $this;
    }

    /**
     * One display name. Call once per language; the registry stores them as the
     * type's translations.
     *
     * @param string $language Content-language tag (en-GB, el-GR, ...).
     * @param string $text The display name in that language.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function name(string $language, string $text): static
    {
        $this->definition['name'][$language] = $text;

        return $this;
    }

    /**
     * One extension-metadata entry: capabilities and provider hints, NEVER semantics
     * (the registry refuses semantic keys here).
     *
     * @param string $key The params key.
     * @param mixed $value The value.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function param(string $key, mixed $value): static
    {
        $this->definition['params'][$key] = $value;

        return $this;
    }

    /**
     * Register the built definition: the terminal call, delegating to the registry's
     * one validating {@see DocumentTypeRegistry::register()}.
     *
     * Inside a provider's register() both arguments stay empty: the registry stamps the
     * package it is currently syncing. Outside a sync (scripts, tests) name yourself.
     *
     * @param string $provider Owning extension element ('' = the provider being synced).
     * @param string $providerVersion Its version ('' = the version it announced).
     *
     * @return object The registered type row.
     *
     * @since  2.0.0
     */
    public function register(string $provider = '', string $providerVersion = ''): object
    {
        return $this->registry->register($this->definition, $provider, $providerVersion);
    }
}
