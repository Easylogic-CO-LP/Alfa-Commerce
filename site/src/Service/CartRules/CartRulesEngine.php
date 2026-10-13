<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\CartRules;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPool;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use LogicException;

defined('_JEXEC') or die;

/**
 * Cart rules engine: evaluates coupons and automatic condition rules against
 * a cart snapshot and returns the applied discounts plus the
 * distance-to-eligibility progress of the near-miss rules.
 *
 * DECOUPLED BY DESIGN: the engine receives a plain line snapshot + context,
 * never the cart object: the same evaluation serves the storefront cart,
 * the admin recalculation and any preview API.
 *
 * CONDITION MODEL (relational, queryable; the flat groups trade-off is a
 * documented decision): a rule carries condition GROUPS; every group must be
 * satisfied (AND). A group is satisfied when the summed quantity of the cart
 * lines matching it reaches min_quantity. A line matches a group when it
 * matches EVERY condition row of the group; a condition row matches when the
 * line hits any of its values (OR), inverted by `exclude`. A rule with no
 * groups is unconditional. `min_amount` gates on top (cart or matching base).
 *
 * STACKING (the class matrix): every rule consumes a discount_class:
 * product -> order -> shipping, evaluated in that fixed sequence, each class on
 * the result of the previous. Inside a class the BEST-VALUE candidate always
 * wins; more rules join only with MUTUAL combines_* opt-in, in priority
 * order. Product-class rules CLAIM lines: one product discount per line,
 * hard invariant.
 *
 * AMOUNT SEMANTICS: customer-facing amounts are tax-INCLUSIVE ('amount'
 * action values included); the excl counterpart derives from the involved
 * base's own incl/excl ratio, so the freeze stays accounting-truthful.
 *
 * WHAT EACH CLASS REDUCES. Product and order rules reduce the goods only,
 * never the shipping or its tax. Shipping rules, free shipping included,
 * reduce the shipping only, its net and its tax in proportion (the carriage's
 * own net over its gross); rules that take the whole carriage take its whole
 * net and tax. A carrier's own "free over X" is the carrier's rating, read on
 * the goods before any rule, and is not this engine's.
 *
 * USAGE LIMITS are counted from the `#__alfa_order_cart_rule` freeze (no
 * counter columns to lock): total per rule + per registered user.
 *
 * Line snapshot shape (built by the caller):
 *   {id: int, id_item: int, quantity: int, line_tax_incl: float, line_tax_excl: float,
 *    has_catalogue_discount: bool, has_custom_price: bool}
 * Context shape:
 *   {userId: int, userGroups: int[], codes: string[],
 *    shipping_tax_incl: float, shipping_tax_excl: float}
 *
 * @since  2.0.0
 */
final class CartRulesEngine
{
    /**
     * The class evaluation sequence: fixed, each on the previous result.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    private const CLASS_SEQUENCE = ['product', 'order', 'shipping'];

    /**
     * Why each entered code did or did not take, keyed by UPPERCASE code.
     *
     * Rebuilt on every evaluate(); the engine is otherwise stateless.
     *
     * @var array<string, string>
     *
     * @since  2.0.0
     */
    private array $codeOutcomes = [];

    /**
     * Evaluate every active rule against a cart snapshot.
     *
     * @param array $lines Line snapshots (see class docblock).
     * @param object $context Evaluation context (see class docblock).
     *
     * @return object {applied: AppliedCartRule[], progress: object[],
     *                rejected_codes: string[]} - progress carries the
     *                distance-to-eligibility structure of near-miss rules.
     *
     * @since  2.0.0
     */
    /**
     * THE ROUNDING CONTRACT. A discount is MONEY the moment it
     * is computed: every figure this engine emits (the rule's incl/excl
     * values, the per-line shares, the shipping benefit) is quantized to the
     * cart currency's scale before anyone else sees it. Display, the freeze
     * to `#__alfa_order_cart_rule`, OrderTotalHelper, the payment ledger and
     * the gateway all consume that ONE cent-exact figure.
     *
     * Before this, the engine kept six decimals and every consumer rounded
     * for itself: the cart display quantized 120.905 UP to 120,91 while the
     * freeze stored 120.905 raw, so the order side computed a grand total of
     * 1088.145 and re-rounded it UP: the buyer saw 1.088,14 and the gateway
     * signed 1088.15. One half-cent, two roundings, a real charge one cent
     * above the agreed price (order #5170; totals-reconcile.php reproduces
     * it). Rounding the DISCOUNT once, here, makes every downstream figure
     * derived instead of re-rounded.
     *
     * The currency arrives in the context. A discount is computed in its
     * minor units, so no rule can be computed for a context that names none:
     * {@see self::currency()} refuses it.
     */
    private ?Currency $currency = null;

    /**
     * The currency a discount is computed in.
     *
     *
     * @throws LogicException When the context named none.
     *
     * @since  2.0.0
     */
    private function currency(): Currency
    {
        return $this->currency
            ?? throw new LogicException('A cart rule is computed in the cart\'s currency, and the context names none.');
    }

    /**
     * The answer when NO rule applies: nothing discounted, shipping exactly as
     * the carrier priced it.
     *
     * This is not a courtesy shape; it is a result the engine genuinely
     * returns, both as the starting point of evaluate() and as its answer for a
     * cart with no lines. Exposing it means a caller that has to degrade
     * ("rules could not be evaluated, charge no discounts") can return the
     * engine's own no-rules answer instead of assembling a second, drifting
     * definition of what a cart-rules result looks like. The rules owner's failure
     * path is exactly that caller; a hand-built substitute once omitted three
     * keys that were then dereferenced without a guard.
     *
     * @param object $context Needs only shipping_tax_incl / shipping_tax_excl;
     *                        everything else about it is irrelevant with no
     *                        rules to apply.
     *
     * @return object The full result shape, with every discount at zero.
     *
     * @since  2.0.0
     */
    public function neutralResult(object $context): object
    {
        return (object) [
            'applied' => [],
            'progress' => [],
            'rejected_codes' => [],
            'code_outcomes' => [],
            'shipping' => $this->shippingBenefit($context, []),
        ];
    }

    public function evaluate(array $lines, object $context): object
    {
        $this->codeOutcomes = [];
        $this->currency = ($context->currency ?? null) instanceof Currency ? $context->currency : null;

        $result = $this->neutralResult($context);

        if ($lines === []) {
            $result->rejected_codes = array_values($context->codes ?? []);
            $result->code_outcomes = $this->codeOutcomes;

            return $result;
        }

        $rules = $this->loadCandidateRules($context);

        if ($rules === []) {
            $result->rejected_codes = array_values($context->codes ?? []);
            $result->code_outcomes = $this->codeOutcomes;

            return $result;
        }

        $facts = $this->loadLineFacts($lines);
        $conditions = $this->loadConditions(array_keys($rules));

        // Condition pass: eligibility + matched lines + progress
        $eligible = [];

        foreach ($rules as $rule) {
            $evaluation = $this->evaluateRule($rule, $conditions[$rule->id] ?? [], $lines, $facts);

            if ($evaluation->satisfied) {
                $rule->_matchedLineIds = $evaluation->matchedLineIds;
                $rule->_matchesAllLines = $evaluation->matchesAll;
                $eligible[] = $rule;
                continue;
            }

            // Near-miss vocabulary: one machine-readable structure feeds every
            // nudge surface (milestone bars, checklists); never bare booleans.
            // A rule no cart can reach has none: it is no near miss.
            if ($evaluation->progress !== null) {
                $result->progress[] = $evaluation->progress;
            }

            if ($rule->code !== null) {
                $result->rejected_codes[] = $rule->code;
                // The code is real and the rule is live; the customer simply
                // has not qualified yet. It stays in the session so the
                // progress surface can say what is still missing.
                $this->noteOutcome($rule->code, CouponOutcome::CONDITIONS_NOT_MET);
            }
        }

        // Codes that matched no rule at all are rejected too.
        $known = array_map(static fn (object $r): string => strtoupper((string) $r->code), $rules);

        foreach ($context->codes ?? [] as $code) {
            if (!\in_array(strtoupper($code), $known, true)) {
                $result->rejected_codes[] = $code;
            }
        }

        // Stacking pass: product -> order -> shipping
        $productDiscountByLine = [];

        foreach (self::CLASS_SEQUENCE as $class) {
            $candidates = array_values(array_filter(
                $eligible,
                static fn (object $r): bool => $r->discount_class === $class,
            ));

            if ($candidates === []) {
                continue;
            }

            foreach ($this->applyClass($class, $candidates, $lines, $context, $productDiscountByLine) as $applied) {
                $result->applied[] = $applied;

                if ($applied->code !== null) {
                    $this->noteOutcome($applied->code, CouponOutcome::APPLIED);
                }
            }
        }

        $result->shipping = $this->shippingBenefit($context, $result->applied);

        // A coded rule that survived the condition pass but never reached
        // $applied lost to a better, non-combining rule of its class. That is
        // not an invalid code and must not be reported as one.
        foreach ($eligible as $rule) {
            if ($rule->code !== null && !isset($this->codeOutcomes[strtoupper($rule->code)])) {
                $this->noteOutcome($rule->code, CouponOutcome::NOT_BETTER_THAN_EXISTING);
            }
        }

        $result->code_outcomes = $this->codeOutcomes;

        return $result;
    }

    /**
     * Classify what the shipping-class rules did to the shipping cost.
     *
     * Shipping is a BENEFIT, not a discount: "free shipping" is a shipping
     * cost of zero, never a negative line sitting next to a full charge. The
     * classification is made here, in PHP, so no display surface has to infer
     * it by comparing two numbers.
     *
     * `cost` is always the GROSS figure the engine was handed; callers must
     * keep feeding it the gross, or the next evaluation would compound.
     *
     * IN THE CART'S MINOR UNITS. The carrier's two prices and what the
     * shipping rules took are read once at the currency's scale; what is left
     * and its net (the cost's own net over its gross, rounded once) are whole
     * minor units. A context that names no currency is the no-rules answer
     * of a cart whose rules could not be read: nothing was taken, so the
     * carrier's figures are the answer as they were given.
     *
     * @param object $context The evaluation context.
     * @param AppliedCartRule[] $applied Everything applied this pass.
     *
     * @return object {cost: float, discount: float, final: float,
     *                benefit: 'free_shipping'|'partial'|'none'}
     *
     * @since  2.0.0
     */
    private function shippingBenefit(object $context, array $applied): object
    {
        $shippingRules = array_filter($applied, static fn (AppliedCartRule $rule): bool => $rule->discountClass === 'shipping');

        if ($this->currency === null && $shippingRules === []) {
            $cost = (float) ($context->shipping_tax_incl ?? 0);
            $costExcl = (float) ($context->shipping_tax_excl ?? 0);

            return (object) [
                'cost' => $cost,
                'cost_excl' => $costExcl,
                'discount' => 0.0,
                'final' => $cost,
                'final_excl' => $costExcl,
                'benefit' => 'none',
            ];
        }

        $currency = $this->currency();
        $cost = Money::of((float) ($context->shipping_tax_incl ?? 0), $currency);
        $costExcl = Money::of((float) ($context->shipping_tax_excl ?? 0), $currency);
        $taken = Money::zero($currency);
        $takenExcl = Money::zero($currency);

        foreach ($shippingRules as $rule) {
            $taken = $taken->add($rule->discountTaxIncl);
            $takenExcl = $takenExcl->add($rule->discountTaxExcl);
        }

        // Aggregate floor. Each rule is already clamped individually and the
        // accumulator stops a second rule re-cutting consumed shipping, so this
        // can only bite if a future action type misbehaves, which is exactly
        // when a negative shipping charge must not reach a customer.
        $taken = Money::min($taken->nonNegative(), $cost);
        $final = $cost->subtract($taken);

        // The net of what is left is the carrier's net less the net the rules
        // took: exactly the figure the order freezes (the parcel's net less its
        // shipping rules' nets), so the cart and the order never differ by a
        // minor unit, and a carriage the rules took whole leaves no net behind.
        $finalExcl = $final->isPositive()
            ? $costExcl->subtract(Money::min($takenExcl->nonNegative(), $costExcl))
            : Money::zero($currency);

        return (object) [
            'cost' => $cost->getAmount(),
            'cost_excl' => $costExcl->getAmount(),
            'discount' => $taken->getAmount(),
            'final' => $final->getAmount(),
            'final_excl' => $finalExcl->getAmount(),
            'benefit' => !$taken->isPositive() ? 'none' : ($final->isPositive() ? 'partial' : 'free_shipping'),
        ];
    }

    /**
     * Record why a code ended up the way it did. First writer wins: the
     * earliest stage to reach a verdict is the most specific one.
     *
     * @param string $code The coupon code.
     * @param string $outcome A CouponOutcome constant.
     *
     *
     * @since  2.0.0
     */
    private function noteOutcome(string $code, string $outcome): void
    {
        $key = strtoupper(trim($code));

        if ($key !== '' && !isset($this->codeOutcomes[$key])) {
            $this->codeOutcomes[$key] = $outcome;
        }
    }

    //  Candidates

    /**
     * The rules worth evaluating: published, inside their window, targeting
     * the customer, code-activated ones only when their code was entered,
     * and within their usage limits.
     *
     * @param object $context The evaluation context.
     *
     * @return array<int, object> Rule rows keyed by id.
     *
     * @since  2.0.0
     */
    private function loadCandidateRules(object $context): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $now = Factory::getDate('now', 'UTC')->toSql();
        $codes = array_values(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            $context->codes ?? [],
        )));

        $query = $db->getQuery(true)
            ->select('r.*')
            ->from($db->quoteName('#__alfa_cart_rules', 'r'))
            ->where($db->quoteName('r.state') . ' = 1')
            ->where('(' . $db->quoteName('r.publish_up') . ' IS NULL OR ' . $db->quoteName('r.publish_up') . ' <= ' . $db->quote($now) . ')')
            ->where('(' . $db->quoteName('r.publish_down') . ' IS NULL OR ' . $db->quoteName('r.publish_down') . ' >= ' . $db->quote($now) . ')');

        if ($codes === []) {
            $query->where($db->quoteName('r.code') . ' IS NULL');
        } else {
            $query->where('(' . $db->quoteName('r.code') . ' IS NULL OR UPPER(' . $db->quoteName('r.code') . ') IN ('
                . implode(',', array_map([$db, 'quote'], $codes)) . '))');
        }

        // MULTILINGUAL: both names live only in the per-language tables. This one
        // join serves the cart totals row, the progress nudge AND the frozen
        // snapshot on the order, because every shopper-facing read goes through
        // displayName(). The join emits '' for a column an older install has not
        // migrated yet, so name_customer simply falls back to name there.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'r',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_cart_rules',
            langPrimaryColumn: 'id_cart_rule',
            fields:            ['name', 'name_customer'],
        );

        $db->setQuery(query: $query);
        $rules = [];

        foreach ($db->loadObjectList('id') ?: [] as $id => $rule) {
            $rule->id = (int) $id;
            $rule->code = $rule->code !== null && $rule->code !== '' ? (string) $rule->code : null;
            $rules[$rule->id] = $rule;
        }

        if ($rules === []) {
            $this->classifyUnmatchedCodes($codes, []);

            return [];
        }

        $rules = $this->filterByTargeting($rules, $context);
        $rules = $this->filterByUsage($rules, (int) ($context->userId ?? 0));

        $this->classifyUnmatchedCodes($codes, $rules);

        return $rules;
    }

    /**
     * Drop rules whose user/usergroup targeting excludes the customer
     * (empty targeting = everyone).
     *
     * @param array<int, object> $rules Candidate rules keyed by id.
     * @param object $context The evaluation context.
     *
     * @return array<int, object>
     *
     * @since  2.0.0
     */
    private function filterByTargeting(array $rules, object $context): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $ruleIds = array_keys($rules);
        $userId = (int) ($context->userId ?? 0);
        $groups = array_map(intval(...), (array) ($context->userGroups ?? []));

        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id_cart_rule'), $db->quoteName('user_id')])
                ->from($db->quoteName('#__alfa_cart_rules_users'))
                ->whereIn($db->quoteName('id_cart_rule'), $ruleIds),
        );
        $userRows = $db->loadObjectList() ?: [];

        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id_cart_rule'), $db->quoteName('usergroup_id')])
                ->from($db->quoteName('#__alfa_cart_rules_usergroups'))
                ->whereIn($db->quoteName('id_cart_rule'), $ruleIds),
        );
        $groupRows = $db->loadObjectList() ?: [];

        $allowedUsers = [];
        $allowedGroups = [];

        foreach ($userRows as $row) {
            $allowedUsers[(int) $row->id_cart_rule][] = (int) $row->user_id;
        }

        foreach ($groupRows as $row) {
            $allowedGroups[(int) $row->id_cart_rule][] = (int) $row->usergroup_id;
        }

        foreach ($rules as $id => $rule) {
            if (isset($allowedUsers[$id]) && !\in_array($userId, $allowedUsers[$id], true)) {
                $this->noteRuleOutcome($rule, CouponOutcome::NOT_ELIGIBLE);
                unset($rules[$id]);
                continue;
            }

            if (isset($allowedGroups[$id]) && array_intersect($groups, $allowedGroups[$id]) === []) {
                $this->noteRuleOutcome($rule, CouponOutcome::NOT_ELIGIBLE);
                unset($rules[$id]);
            }
        }

        return $rules;
    }

    /**
     * Drop rules over their usage limits, counted from the order freeze
     * (deleted rows and trashed orders excluded).
     *
     * @param array<int, object> $rules Candidate rules keyed by id.
     * @param int $userId The customer (0 = guest, per-user limit not countable).
     *
     * @return array<int, object>
     *
     * @since  2.0.0
     */
    private function filterByUsage(array $rules, int $userId): array
    {
        $limited = array_filter(
            $rules,
            static fn (object $r): bool => (int) $r->usage_limit_total > 0 || (int) $r->usage_limit_per_user > 0,
        );

        if ($limited === []) {
            return $rules;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('ocr.id_cart_rule'),
                'COUNT(*) AS total_uses',
                'SUM(CASE WHEN ' . $db->quoteName('o.id_user') . ' = ' . $userId . ' THEN 1 ELSE 0 END) AS user_uses',
            ])
            ->from($db->quoteName('#__alfa_order_cart_rule', 'ocr'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_orders', 'o'),
                $db->quoteName('o.id') . ' = ' . $db->quoteName('ocr.id_order')
                . ' AND ' . $db->quoteName('o.state') . ' = 1',
            )
            ->where($db->quoteName('ocr.deleted') . ' = 0')
            ->whereIn($db->quoteName('ocr.id_cart_rule'), array_keys($limited))
            ->group($db->quoteName('ocr.id_cart_rule'));

        $db->setQuery(query: $query);
        $usage = $db->loadObjectList('id_cart_rule') ?: [];

        foreach ($limited as $id => $rule) {
            $totalUses = (int) ($usage[$id]->total_uses ?? 0);
            $userUses = (int) ($usage[$id]->user_uses ?? 0);

            if ((int) $rule->usage_limit_total > 0 && $totalUses >= (int) $rule->usage_limit_total) {
                $this->noteRuleOutcome($rule, CouponOutcome::USAGE_LIMIT_REACHED);
                unset($rules[$id]);
                continue;
            }

            // A per-customer limit needs a customer. A guest has no identity to
            // count against (every guest order collapses into id_user 0) so
            // the rule is withheld rather than silently unlimited.
            if ($userId <= 0 && (int) $rule->usage_limit_per_user > 0) {
                $this->noteRuleOutcome($rule, CouponOutcome::REQUIRES_LOGIN);
                unset($rules[$id]);
                continue;
            }

            if ((int) $rule->usage_limit_per_user > 0 && $userUses >= (int) $rule->usage_limit_per_user) {
                $this->noteRuleOutcome($rule, CouponOutcome::CUSTOMER_LIMIT_REACHED);
                unset($rules[$id]);
            }
        }

        return $rules;
    }

    /**
     * Note an outcome against a rule's code, if it has one.
     *
     * @param object $rule The rule row.
     * @param string $outcome A CouponOutcome constant.
     *
     *
     * @since  2.0.0
     */
    private function noteRuleOutcome(object $rule, string $outcome): void
    {
        if ($rule->code !== null && $rule->code !== '') {
            $this->noteOutcome((string) $rule->code, $outcome);
        }
    }

    /**
     * Say why an entered code produced no candidate rule at all.
     *
     * The candidate query filters on state and the publish window in one pass,
     * so a code that survives none of it is indistinguishable from a typo
     * unless we go back and ask. One extra query, only for codes that failed,
     * only when the customer actually typed something.
     *
     * @param string[] $codes Entered codes, already uppercased.
     * @param array<int, object> $rules The surviving candidates.
     *
     *
     * @since  2.0.0
     */
    private function classifyUnmatchedCodes(array $codes, array $rules): void
    {
        $unmatched = [];

        foreach ($codes as $code) {
            if (isset($this->codeOutcomes[$code])) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule->code !== null && strtoupper((string) $rule->code) === $code) {
                    continue 2;
                }
            }

            $unmatched[] = $code;
        }

        if ($unmatched === []) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $now = Factory::getDate('now', 'UTC')->toSql();

        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('code'),
                    $db->quoteName('state'),
                    $db->quoteName('publish_up'),
                    $db->quoteName('publish_down'),
                ])
                ->from($db->quoteName('#__alfa_cart_rules'))
                ->whereIn('UPPER(' . $db->quoteName('code') . ')', $unmatched, ParameterType::STRING),
        );

        $found = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $found[strtoupper((string) $row->code)] = $row;
        }

        foreach ($unmatched as $code) {
            $row = $found[$code] ?? null;

            if ($row === null) {
                $this->noteOutcome($code, CouponOutcome::NOT_FOUND);
                continue;
            }

            if ((int) $row->state !== 1) {
                $this->noteOutcome($code, CouponOutcome::UNPUBLISHED);
                continue;
            }

            if ($row->publish_up !== null && $row->publish_up > $now) {
                $this->noteOutcome($code, CouponOutcome::NOT_YET_ACTIVE);
                continue;
            }

            if ($row->publish_down !== null && $row->publish_down < $now) {
                $this->noteOutcome($code, CouponOutcome::EXPIRED);
                continue;
            }

            // Live, in window, but still no candidate: a filter upstream
            // dropped it without a verdict. Better vague than wrong.
            $this->noteOutcome($code, CouponOutcome::NOT_ELIGIBLE);
        }
    }

    //  Conditions

    /**
     * Load the condition trees of the candidate rules: three batched
     * queries, assembled as rule => groups => conditions => values.
     *
     * @param int[] $ruleIds Candidate rule ids.
     *
     * @return array<int, array> rule id => group list.
     *
     * @since  2.0.0
     */
    private function loadConditions(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_cart_rule_groups'))
                ->whereIn($db->quoteName('id_cart_rule'), $ruleIds)
                ->order($db->quoteName('id') . ' ASC'),
        );
        $groupRows = $db->loadObjectList('id') ?: [];

        if ($groupRows === []) {
            return [];
        }

        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_cart_rule_conditions'))
                ->whereIn($db->quoteName('id_group'), array_keys($groupRows))
                ->order($db->quoteName('id') . ' ASC'),
        );
        $conditionRows = $db->loadObjectList('id') ?: [];

        $valueRows = [];

        if ($conditionRows !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_cart_rule_condition_values'))
                    ->whereIn($db->quoteName('id_condition'), array_keys($conditionRows)),
            );
            $valueRows = $db->loadObjectList() ?: [];
        }

        foreach ($conditionRows as $condition) {
            $condition->values = [];
        }

        foreach ($valueRows as $row) {
            $conditionRows[(int) $row->id_condition]->values[] = (int) $row->id_value;
        }

        foreach ($groupRows as $group) {
            $group->conditions = [];
        }

        foreach ($conditionRows as $condition) {
            $groupRows[(int) $condition->id_group]->conditions[] = $condition;
        }

        $byRule = [];

        foreach ($groupRows as $group) {
            $byRule[(int) $group->id_cart_rule][] = $group;
        }

        return $byRule;
    }

    /**
     * The line facts the condition types match against: each cart item's
     * category and manufacturer sets, in two batched queries.
     *
     * @param array $lines Line snapshots.
     *
     * @return object {categories: array<int,int[]>, manufacturers: array<int,int[]>}.
     *
     * @since  2.0.0
     */
    private function loadLineFacts(array $lines): object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $itemIds = array_values(array_unique(array_map(
            static fn (object $line): int => (int) $line->id_item,
            $lines,
        )));

        $categories = [];
        $manufacturers = [];

        if ($itemIds !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_items_categories'))
                    ->whereIn($db->quoteName('item_id'), $itemIds),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                $categories[(int) $row->item_id][] = (int) $row->category_id;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_items_manufacturers'))
                    ->whereIn($db->quoteName('item_id'), $itemIds),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                $manufacturers[(int) $row->item_id][] = (int) $row->manufacturer_id;
            }
        }

        return (object) ['categories' => $categories, 'manufacturers' => $manufacturers];
    }

    /**
     * Evaluate one rule's conditions + min_amount against the lines.
     *
     * @param object $rule The rule row.
     * @param array $groups Its condition groups (with conditions/values).
     * @param array $lines Line snapshots.
     * @param object $facts The line facts.
     *
     * @return object {satisfied: bool, matchedLineIds: int[], matchesAll: bool, progress: ?object}; progress is
     *                null for a rule whose minimum no cart can reach.
     *
     * @throws LogicException When the rule states a minimum amount and the context named no currency.
     *
     * @since  2.0.0
     */
    private function evaluateRule(object $rule, array $groups, array $lines, object $facts): object
    {
        $matchedLineIds = [];
        $groupStates = [];
        $satisfied = true;

        // An unconditional rule matches EVERY line. Carried explicitly because
        // an empty $matchedLineIds otherwise has to mean both "all lines" (the
        // discount base) and "no lines" (the min_amount base) in the same pass.
        $matchesAll = $groups === [];

        foreach ($groups as $group) {
            $groupQty = 0;
            $groupLineIds = [];

            foreach ($lines as $line) {
                if ($this->lineMatchesGroup($line, $group, $facts)) {
                    $groupQty += (int) $line->quantity;
                    $groupLineIds[] = (int) $line->id;
                }
            }

            $minQuantity = max(1, (int) $group->min_quantity);
            $groupSatisfied = $groupQty >= $minQuantity;
            $satisfied = $satisfied && $groupSatisfied;
            $matchedLineIds = array_merge($matchedLineIds, $groupLineIds);

            // The distance vocabulary: target/current/remaining per group;
            // checklists and "add 1 more" strings read straight off this.
            $groupStates[] = (object) [
                'satisfied' => $groupSatisfied,
                'type' => 'quantity',
                'condition_types' => array_map(static fn (object $c): string => (string) $c->type, $group->conditions ?? []),
                'target' => $minQuantity,
                'current' => $groupQty,
                'remaining' => max(0, $minQuantity - $groupQty),
            ];
        }

        $matchedLineIds = array_values(array_unique($matchedLineIds));

        // min_amount gate: on the whole cart or on the matched lines only.
        $minAmount = (float) $rule->min_amount;
        $amountState = null;

        if ($minAmount > 0) {
            $baseLines = $rule->min_amount_base === 'matching' && !$matchesAll
                ? array_filter($lines, static fn (object $l): bool => \in_array((int) $l->id, $matchedLineIds, true))
                : $lines;

            // THE AMOUNT IS ADDED IN MINOR UNITS AND COMPARED THERE. A float sum
            // of line totals does not hold their total (43.11 + 28.13 + 12.49 +
            // 16.27 is 99.999999999999986), so a cart exactly at the minimum
            // missed its rule while the progress line said 0.00 to go.
            $currency = $this->currency();
            $minimum = self::minimumAmount(
                stated: is_numeric($rule->min_amount) ? $rule->min_amount : $minAmount,
                currency: $currency,
            );
            // A minimum the currency's minor units cannot hold lies beyond every
            // cart: the rule does not apply, and a goal no cart can reach is no
            // near miss to show.
            if ($minimum === null) {
                return (object) [
                    'satisfied' => false,
                    'matchedLineIds' => $matchedLineIds,
                    'matchesAll' => $matchesAll,
                    'progress' => null,
                ];
            }

            $current = Money::zero($currency);

            foreach ($baseLines as $line) {
                $current = $current->add(Money::of(
                    (float) ((int) $rule->min_amount_tax_incl === 1 ? $line->line_tax_incl : $line->line_tax_excl),
                    $currency,
                ));
            }

            $amountSatisfied = !$current->lessThan($minimum);
            $satisfied = $satisfied && $amountSatisfied;

            // The progress figures are those same two amounts: what is left to
            // add is nothing exactly when the minimum is reached.
            $amountState = (object) [
                'satisfied' => $amountSatisfied,
                'type' => 'amount',
                'target' => $minAmount,
                'current' => $current->getAmount(),
                'remaining' => $minimum->subtract($current)->nonNegative()->getAmount(),
            ];
        }

        return (object) [
            'satisfied' => $satisfied,
            'matchedLineIds' => $matchedLineIds,
            'matchesAll' => $matchesAll,
            'progress' => (object) [
                'id' => (int) $rule->id,
                'name' => self::displayName($rule),
                'code' => $rule->code,
                'highlight' => (int) $rule->highlight === 1,
                'satisfied' => $satisfied,
                'groups' => $groupStates,
                'min_amount' => $amountState,
            ],
        ];
    }

    /**
     * A rule's minimum amount in the cart's money: the LEAST amount of the
     * currency that reaches it, or null when no amount of the currency can.
     *
     * The minimum is read from its column's decimal text, on its digits. One
     * stated finer than the currency's scale is reached only by the next minor
     * unit: 99.4 where the currency has no decimals is 100, because a cart of
     * 99 is under it.
     *
     * A minimum that the currency's minor units cannot hold (at three decimals,
     * 9,223,372,036,854,775.808 or more) lies beyond every cart: no cart
     * reaches it. The admin form refuses to save one, and a rule that still
     * states one does not apply.
     *
     * @param mixed $stated The stored minimum, above zero: decimal text, or a number.
     * @param Currency $currency The cart's currency.
     *
     * @return Money|null Null when minor units cannot hold the minimum.
     *
     * @since  2.0.0
     */
    public static function minimumAmount(mixed $stated, Currency $currency): ?Money
    {
        $scale = $currency->getDecimalPlaces();
        [$whole, $fraction] = explode('.', PriceComputationEngine::catalogueUnit(value: $stated) . '.');
        $atScale = $whole . substr(str_pad($fraction, $scale, '0'), 0, $scale);

        // The reader pads no zeros, so digits past the scale are an amount the
        // scale does not hold: the minimum lies one minor unit above them.
        $finer = \strlen($fraction) > $scale;

        // Decided on the digits, against the largest count minor units hold.
        $digits = ltrim($atScale, '0');
        $ceiling = (string) PHP_INT_MAX;
        $beyond = \strlen($digits) > \strlen($ceiling)
            || (\strlen($digits) === \strlen($ceiling) && (strcmp($digits, $ceiling) > 0 || ($finer && $digits === $ceiling)));

        if ($beyond) {
            return null;
        }

        $held = Money::of($scale === 0 ? $whole : $whole . '.' . substr($atScale, -$scale), $currency);

        return $finer ? $held->add(Money::ofMinor(1, $currency)) : $held;
    }

    /**
     * Whether a line matches a condition group: EVERY condition row must
     * match; a row matches when the line hits any of its values (OR),
     * inverted by exclude.
     *
     * @param object $line The line snapshot.
     * @param object $group The group (with conditions/values).
     * @param object $facts The line facts.
     *
     *
     * @since  2.0.0
     */
    private function lineMatchesGroup(object $line, object $group, object $facts): bool
    {
        foreach ($group->conditions ?? [] as $condition) {
            $itemId = (int) $line->id_item;

            $lineValues = match ((string) $condition->type) {
                'items' => [$itemId],
                'categories' => $facts->categories[$itemId] ?? [],
                'manufacturers' => $facts->manufacturers[$itemId] ?? [],
                default => null,
            };

            if ($lineValues === null) {
                // Unknown condition type (a future plugin predicate not
                // installed any more): never match; fail safe.
                Log::add(
                    'Unknown cart rule condition type "' . $condition->type . '" (rule group ' . $group->id . ').',
                    Log::WARNING,
                    'com_alfa.cartrules',
                );

                return false;
            }

            $hits = array_intersect($lineValues, $condition->values ?? []) !== [];

            if ($hits === (bool) ((int) $condition->exclude === 1)) {
                return false;
            }
        }

        return true;
    }

    //  Stacking + amounts

    /**
     * Apply one discount class: the best-value candidate always wins; more
     * rules join only with MUTUAL combines opt-in, in priority order.
     * Product-class rules claim lines exclusively.
     *
     * What earlier rules took is kept in the cart's minor units, so the base
     * a later rule measures is the exact remainder however many rules came
     * before it.
     *
     * @param string $class The class being applied.
     * @param array $candidates Eligible rules of this class.
     * @param array $lines Line snapshots.
     * @param object $context The evaluation context.
     * @param Money[] &$productDiscountByLine Accumulated product discounts per
     *                                        line id (feeds the order base).
     *
     * @return AppliedCartRule[]
     *
     * @since  2.0.0
     */
    private function applyClass(string $class, array $candidates, array $lines, object $context, array &$productDiscountByLine): array
    {
        usort($candidates, static fn (object $a, object $b): int => [(int) $a->priority, (int) $a->id] <=> [(int) $b->priority, (int) $b->id]);

        // Standalone value first: the engine auto-picks the best deal for
        // the customer among non-combinable candidates, never errors.
        $claimed = [];
        $values = [];

        // Intra-class consumption. The product class is self-limiting ($claimed
        // removes a line from the next rule's base), but order and shipping had
        // nothing: two combinable 60% rules each measured the untouched base and
        // took 120% between them, driving the grand total negative.
        $orderDiscountByLine = [];
        $shippingConsumed = Money::zero($this->currency());

        foreach ($candidates as $rule) {
            $values[$rule->id] = $this->computeDiscount($rule, $lines, $context, $productDiscountByLine, $claimed)->incl->getMinorUnits();
        }

        usort($candidates, static function (object $a, object $b) use ($values): int {
            return [$values[$b->id], -(int) $b->priority] <=> [$values[$a->id], -(int) $a->priority];
        });

        $applied = [];
        $combineFlag = 'combines_' . $class;

        foreach ($candidates as $rule) {
            if ($applied !== []) {
                // Mutual opt-in with EVERY already-applied rule of the class.
                foreach ($applied as $existing) {
                    if ((int) $existing->_rule->{$combineFlag} !== 1 || (int) $rule->{$combineFlag} !== 1) {
                        continue 2;
                    }
                }
            }

            $discount = $this->computeDiscount(
                $rule,
                $lines,
                $context,
                $productDiscountByLine,
                $claimed,
                $orderDiscountByLine,
                $shippingConsumed,
            );

            if (!$discount->incl->isPositive() && !$discount->freeShipping) {
                continue;
            }

            if ($class === 'product') {
                foreach ($discount->lineIds as $lineId) {
                    $claimed[$lineId] = true;
                    $productDiscountByLine[$lineId] = isset($productDiscountByLine[$lineId])
                        ? $productDiscountByLine[$lineId]->add($discount->perLine[$lineId])
                        : $discount->perLine[$lineId];
                }
            } elseif ($class === 'order') {
                foreach ($discount->lineIds as $lineId) {
                    $orderDiscountByLine[$lineId] = isset($orderDiscountByLine[$lineId])
                        ? $orderDiscountByLine[$lineId]->add($discount->perLine[$lineId])
                        : $discount->perLine[$lineId];
                }
            } else {
                $shippingConsumed = $shippingConsumed->add($discount->incl);
            }

            $applied[] = (object) [
                '_rule' => $rule,
                'result' => new AppliedCartRule(
                    id: (int) $rule->id,
                    name: self::displayName($rule),
                    code: $rule->code,
                    discountClass: $class,
                    action: (string) $rule->action,
                    discountTaxIncl: $discount->incl,
                    discountTaxExcl: $discount->excl,
                    freeShipping: $discount->freeShipping,
                    lineIds: $discount->lineIds,
                ),
            ];
        }

        return array_map(static fn (object $entry): AppliedCartRule => $entry->result, $applied);
    }

    /**
     * Compute one rule's discount on its base.
     *
     * Bases: product class -> the matched (unclaimed) lines; order class ->
     * cart or matched lines, NET of the product-class discounts already
     * applied; shipping class -> the shipping cost. 'cheapest' discounts one
     * unit of the cheapest matched line. Amounts clamp to their base.
     *
     * COMPUTED IN MINOR UNITS. Every amount that enters (a line total, the
     * carrier's price, what earlier rules took) is read once at the
     * currency's scale. From there what a line has left, the base, the
     * discount and the net are whole minor units and exact ratios of them,
     * each rounded once, and the discount is shared over the lines by Money's
     * one allocation ({@see Money::allocateByRatios()}). A float difference of
     * two near amounts does not hold the remainder it stands for (19.95 less
     * 19.00 is 0.9499999999999993), and 10% of it lands under the tie 0.095
     * that the discount is.
     *
     * @param object $rule The rule row (with _matchedLineIds).
     * @param array $lines Line snapshots.
     * @param object $context The evaluation context.
     * @param Money[] $productDiscountByLine Product discounts per line so far.
     * @param array $claimed Line ids already claimed by product rules.
     * @param Money[] $orderDiscountByLine Order-class discounts per line taken
     *                                     by earlier rules of THIS class.
     * @param Money|null $shippingConsumed Shipping already discounted by
     *                                     earlier rules of THIS class; null for none.
     *
     * @return object {incl: Money, excl: Money, freeShipping: bool,
     *                lineIds: int[], perLine: array<int,Money>}.
     *
     * @throws LogicException When the context named no currency.
     *
     * @since  2.0.0
     */
    private function computeDiscount(
        object $rule,
        array $lines,
        object $context,
        array $productDiscountByLine,
        array $claimed,
        array $orderDiscountByLine = [],
        ?Money $shippingConsumed = null,
    ): object {
        $matched = $rule->_matchedLineIds ?? [];
        $matchesAll = (bool) ($rule->_matchesAllLines ?? ($matched === []));

        $currency = $this->currency();
        $none = (object) ['incl' => Money::zero($currency), 'excl' => Money::zero($currency), 'freeShipping' => false, 'lineIds' => [], 'perLine' => []];
        $money = static fn (mixed $amount): Money => Money::of((float) $amount, $currency);

        // Shipping class
        if ($rule->discount_class === 'shipping') {
            // Only what earlier shipping rules left behind. The net keeps the
            // carriage's own net over its gross, CUMULATIVELY, by the one rule
            // every part of a shipping charge follows (ShippingPool): each rule
            // reduces the shipping's net and its tax in proportion, and rules
            // that together take the whole carriage take exactly its net, so
            // free shipping removes the charge and its tax completely.
            $shippingFull = $money($context->shipping_tax_incl ?? 0);
            $shippingFullExcl = $money($context->shipping_tax_excl ?? 0);
            $shippingBefore = $shippingConsumed ?? Money::zero($currency);
            $shippingLeft = $shippingFull->subtract($shippingBefore)->nonNegative();

            // Nothing to cut: no shipping cost, or an earlier shipping rule
            // already consumed it.
            if (!$shippingLeft->isPositive()) {
                return $none;
            }

            $incl = $this->actionAmount($rule, $shippingLeft);

            if (!$incl->isPositive()) {
                return $none;
            }

            return (object) [
                'incl' => $incl,
                'excl' => (new ShippingPool(gross: $shippingFull, net: $shippingFullExcl))->netOf(part: $incl, before: $shippingBefore),
                // DERIVED, never declared: whatever wipes out the remaining
                // shipping cost IS free shipping. A merchant writing "100% off
                // shipping" gets the flag without a second field to contradict.
                'freeShipping' => !$incl->lessThan($shippingLeft),
                'lineIds' => [],
                'perLine' => [],
            ];
        }

        // Base lines
        $baseLines = [];

        $excludeDiscounted = (int) ($rule->exclude_discounted ?? 0) === 1;

        // THE SECOND ELIGIBILITY AXIS, read exactly as the first one is, one
        // line below it. Default 'exclude': an explicit negotiated price is
        // protected from further automatic promotion unless the merchant
        // deliberately allows it. That default is a no-op on every existing
        // rule, because until now no line could be overridden.
        $allowOverridden = ($rule->overridden_lines ?? 'exclude') === 'allow';

        foreach ($lines as $line) {
            $lineId = (int) $line->id;
            $inMatched = $matchesAll || \in_array($lineId, $matched, true);

            // "Don't stack on sale items": a line already carrying a catalogue
            // discount leaves the base entirely: it neither receives this
            // discount nor counts toward it.
            if ($excludeDiscounted && !empty($line->has_catalogue_discount)) {
                continue;
            }

            // The same shape, the other axis. A line the operator priced leaves
            // the base ENTIRELY: it neither receives this discount nor counts
            // toward it, which is what stops the other lines' share shrinking.
            //
            // This is the ONLY place eligibility is decided. Condition
            // evaluation runs on the full $lines and never passes through here,
            // so an overridden line still contributes its real merchandise
            // value to "subtotal >= 100" and rules keep firing; the ruling
            // asks for exactly that, and it is already true structurally.
            if (!$allowOverridden && !empty($line->has_custom_price)) {
                continue;
            }

            if ($rule->discount_class === 'product') {
                // Product rules only touch matched, UNCLAIMED lines.
                if (!$inMatched || isset($claimed[$lineId])) {
                    continue;
                }
            } elseif ($rule->discount_base !== 'cart' && !$inMatched) {
                continue;
            }

            $baseLines[$lineId] = $line;
        }

        if ($baseLines === []) {
            return $none;
        }

        // What a line has left: its gross less what earlier rules already took
        // from it, never below zero. The order class measures on that
        // remainder; the product class is protected by $claimed instead.
        $leftOn = static function (object $line, Money $gross) use ($rule, $productDiscountByLine, $orderDiscountByLine): Money {
            if ($rule->discount_class === 'order') {
                $lineId = (int) $line->id;

                foreach ([$productDiscountByLine[$lineId] ?? null, $orderDiscountByLine[$lineId] ?? null] as $taken) {
                    if ($taken !== null) {
                        $gross = $gross->subtract($taken);
                    }
                }
            }

            return $gross->nonNegative();
        };

        // A line's part of the base's net, as one term of an exact sum: its
        // net times what it has left over its gross. A line with no gross
        // adds its net whole.
        $netTerm = static fn (object $line, Money $gross, Money $left): array => $gross->isPositive()
            ? [$money($line->line_tax_excl)->getMinorUnits(), $left->getMinorUnits(), $gross->getMinorUnits()]
            : [$money($line->line_tax_excl)->getMinorUnits(), 1, 1];

        // Per line what it has left, kept so the allocation below divides the
        // discount on the SAME basis it was measured on.
        $leftByLine = [];
        $netTerms = [];

        // 'cheapest': one unit of the cheapest base line
        if ($rule->discount_base === 'cheapest') {
            // The cheapest line by its own money over its quantity, compared
            // exactly: two units a cart displays alike can differ below the
            // currency's scale. On a tie the first line stays.
            $cheapest = null;

            foreach ($baseLines as $line) {
                if (
                    $cheapest === null
                    || self::unitBelow(
                        gross: $money($line->line_tax_incl),
                        quantity: (int) $line->quantity,
                        than: $money($cheapest->line_tax_incl),
                        thanQuantity: (int) $cheapest->quantity,
                    )
                ) {
                    $cheapest = $line;
                }
            }

            $cheapestId = (int) $cheapest->id;
            $gross = $money($cheapest->line_tax_incl);

            // ONE UNIT OF THE LINE'S OWN MONEY: its gross over its quantity,
            // rounded once. It is clamped to whatever is left on its line, or
            // two combinable rules would each discount the same untouched unit.
            $base = Money::min($gross->divide(divisor: (int) $cheapest->quantity), $leftOn($cheapest, $gross));

            $leftByLine[$cheapestId] = $base;
            $netTerms[] = $netTerm($cheapest, $gross, $base);
        } else {
            $base = Money::zero($currency);

            foreach ($baseLines as $lineId => $line) {
                $gross = $money($line->line_tax_incl);
                $left = $leftOn($line, $gross);

                $leftByLine[$lineId] = $left;
                $base = $base->add($left);
                $netTerms[] = $netTerm($line, $gross, $left);
            }
        }

        if (!$base->isPositive()) {
            return $none;
        }

        $incl = $this->actionAmount($rule, $base);

        if (!$incl->isPositive()) {
            return $none;
        }

        // Shared over the lines on the basis the discount was measured on, what
        // each line has left: weighting by the gross would make the shares sum
        // to more than $incl, and they feed the next rule's base. The one
        // allocation of an amount over parts, in the lines' order.
        $perLine = array_combine(
            array_keys($leftByLine),
            $incl->allocateByRatios(array_map(static fn (Money $left): int => $left->getMinorUnits(), array_values($leftByLine))),
        );

        return (object) [
            'incl' => $incl,
            // The net follows the base's own net over its gross: one sum over
            // the lines, rounded once.
            'excl' => $incl->timesRatioSum(terms: $netTerms, divisor: $base->getMinorUnits()),
            'freeShipping' => false,
            'lineIds' => array_keys($perLine),
            'perLine' => $perLine,
        ];
    }

    /**
     * Whether one line's unit costs less than another's: each line's gross
     * over its quantity, compared exactly on minor units.
     *
     * a / b < c / d is decided on the whole parts first, then on what is left
     * of each, cross-multiplied: each rest is below its own quantity, so the
     * products stay small at any amount.
     *
     * @param Money $gross The line's gross.
     * @param int $quantity Its quantity, above zero.
     * @param Money $than The other line's gross.
     * @param int $thanQuantity Its quantity, above zero.
     *
     * @return bool True when the first unit is strictly below the second.
     *
     * @since  2.0.0
     */
    private static function unitBelow(Money $gross, int $quantity, Money $than, int $thanQuantity): bool
    {
        $a = $gross->getMinorUnits();
        $c = $than->getMinorUnits();
        $wholeA = intdiv($a, $quantity);
        $wholeC = intdiv($c, $thanQuantity);

        if ($wholeA !== $wholeC) {
            return $wholeA < $wholeC;
        }

        return ($a % $quantity) * $thanQuantity < ($c % $thanQuantity) * $quantity;
    }

    /**
     * The action's amount on a tax-inclusive base, clamped to the base.
     * Unknown action types (a future plugin action not installed any more)
     * discount nothing.
     *
     * In the cart's minor units: a fixed amount and a ceiling are read from
     * the rule's columns once, at the currency's scale, half away from zero,
     * and compared with the base exactly. The base is money already, so the
     * clamp to it can never leave the discount above what it measures.
     *
     * @param object $rule The rule row.
     * @param Money $baseIncl The tax-inclusive base.
     *
     *
     * @since  2.0.0
     */
    private function actionAmount(object $rule, Money $baseIncl): Money
    {
        $currency = $baseIncl->getCurrency();
        $value = (float) $rule->action_value;

        $amount = match ((string) $rule->action) {
            // Taken on the base's minor units, from the rate as the rule
            // states it, and rounded once: the float product of the two lands
            // beside a tie (0.985 as 0.9849999999999994) instead of on it.
            'percent' => $baseIncl->percentage($value > 0 && $value < 100 && is_numeric($rule->action_value) ? $rule->action_value : min(100, max(0, $value))),
            'amount' => Money::of(is_numeric($rule->action_value) ? $rule->action_value : $value, $currency),
            default => Money::zero($currency),
        };

        // Merchant ceiling on the payout: "20% off, up to 50 EUR". Applied
        // before the base clamp so a cap can only ever lower the figure. A
        // ceiling is stated when its column is above zero, even below one
        // minor unit; it is then read at the scale like the amount.
        if ((float) ($rule->max_discount_amount ?? 0) > 0) {
            $amount = Money::min($amount, Money::of(is_numeric($rule->max_discount_amount) ? $rule->max_discount_amount : (float) $rule->max_discount_amount, $currency));
        }

        if ($amount->isZero() && !\in_array((string) $rule->action, ['percent', 'amount'], true)) {
            Log::add(
                'Unknown cart rule action "' . $rule->action . '" (rule ' . $rule->id . '): no discount applied.',
                Log::WARNING,
                'com_alfa.cartrules',
            );
        }

        return Money::min($amount, $baseIncl);
    }

    /**
     * The label the SHOPPER reads for a rule.
     *
     * Same contract as the discount screen (PriceComputationEngine): the
     * merchant's internal `name` is the fallback, so a rule needs no customer
     * label until its internal one is unfit to show.
     *
     * @param object $rule Rule row carrying the joined per-language names.
     *
     *
     * @since  2.0.0
     */
    private static function displayName(object $rule): string
    {
        $customer = trim((string) ($rule->name_customer ?? ''));

        return $customer !== '' ? $customer : (string) ($rule->name ?? '');
    }
}
