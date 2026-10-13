<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\ShowOn;

use Alfa\Component\Alfa\Site\Service\FieldState\StateBag;
use Joomla\CMS\Form\Form;

defined('_JEXEC') or die;

/**
 * The SUBMIT half of ShowOn for alfa-form-fields: {@see InitialState} decides the
 * first paint, the JS engine owns every live toggle, and this decides what the
 * server is allowed to demand once the customer presses submit.
 *
 * It exists because the browser and the form disagreed: the engine disables a
 * hidden field's inputs, so the field never posts, while `Form::validate()` went
 * on enforcing `required` against the value that could not possibly arrive, and a
 * hidden-but-required field made checkout unsubmittable.
 *
 * Same rule, same evaluator, same state contract as the JS twin, applied per
 * SECTION: a bucket is one ShowOn scope (`data-showon-scope` in the DOM), so the
 * delivery and invoice copies of a field (identical names, separate answers)
 * never read each other's values.
 *
 * A hidden field is REMOVED from the form, not merely relaxed: dropping the
 * element takes it out of `Form::filter()` too, so a value posted for a field the
 * rules hide can never reach the order or the saved user-info row.
 *
 * @since  2.0.0
 */
final class SubmitAuthority
{
    /**
     * Fixpoint cap for chained rules (A hides B hides C); mirrors MAX_PASSES in
     * media/com_alfa/js/site/showon/showon-engine.js.
     */
    private const MAX_PASSES = 6;

    /**
     * Drop every ShowOn-hidden field of one section from both the form and the
     * submitted values.
     *
     * @param Form $form The form being validated (mutated: hidden fields removed).
     * @param object[] $fields The active field definitions for this section's context.
     * @param array $values The section's posted values, field name => value.
     * @param string $group The form group the section's fields live under.
     * @param array $extra Non-field surfaces of the same scope that a rule may
     *                     address (name => value); the checkout's own
     *                     `invoice_differs` toggle is one. Without them a leaf
     *                     reading such a surface would be neutral here while the
     *                     browser, which sees it in the DOM, found it decisive.
     *
     * @return array The values with every hidden field removed.
     *
     * @since  2.0.0
     */
    public static function prune(Form $form, array $fields, array $values, string $group, array $extra = []): array
    {
        $rules = [];
        $names = array_keys($extra);

        foreach ($fields as $field) {
            $name = (string) ($field->field_name ?? '');

            if ($name === '') {
                continue;
            }

            $names[] = $name;
            $rule = self::ruleOf($field);

            if ($rule !== null) {
                $rules[$name] = $rule;
            }
        }

        if (!$rules) {
            return $values;
        }

        // $extra surfaces answer but are never themselves targets: they carry no
        // definition and so cannot be removed from the form.
        foreach (array_keys(self::hidden($names, $rules, $values + $extra)) as $name) {
            $form->removeField($name, $group);
            unset($values[$name]);
        }

        return $values;
    }

    /**
     * Settle which fields the rules hide. Re-evaluated until stable because a
     * hidden field stops contributing its value, exactly how the JS engine
     * parks a hidden field's inputs before the next pass reads the DOM.
     *
     * @param string[] $names Every active field name in the section.
     * @param array $rules Field name => rule tree, for the fields that carry one.
     * @param array $values The section's posted values.
     *
     * @return array<string, true> The hidden field names, as a set.
     *
     * @since  2.0.0
     */
    private static function hidden(array $names, array $rules, array $values): array
    {
        $evaluator = new RuleEvaluator();
        $hidden = [];

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bag = new StateBag();

            // Every active field carries `.value`: null is a real "not set",
            // never an absent address, so a leaf reading a field of this section
            // is always decisive (and one reading another section stays neutral).
            foreach ($names as $name) {
                $bag->put(
                    field: $name,
                    slice: ['value' => isset($hidden[$name]) ? null : self::normalise($values[$name] ?? null)],
                );
            }

            $changed = false;

            foreach ($rules as $name => $rule) {
                $isHidden = !$evaluator->isVisible($rule, $bag);

                if ($isHidden !== isset($hidden[$name])) {
                    $changed = true;

                    if ($isHidden) {
                        $hidden[$name] = true;
                    } else {
                        unset($hidden[$name]);
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }

        return $hidden;
    }

    /**
     * A field's rule tree, or null when it carries none. The definition stores
     * `showon` as JSON inside the JSON params, so the inner value is still a
     * string at this point.
     *
     * @param object $field The field definition row.
     *
     *
     * @since  2.0.0
     */
    private static function ruleOf(object $field): ?array
    {
        $params = $field->params ?? null;

        if (\is_string($params)) {
            $params = json_decode($params, true);
        }

        $rule = \is_array($params) ? ($params['showon'] ?? null) : null;

        if (\is_string($rule)) {
            $rule = json_decode($rule, true);
        }

        return \is_array($rule) && !empty($rule['rules']) ? $rule : null;
    }

    /**
     * Normalise a posted value the way the JS StateReader does, so both twins
     * compare the same thing: an empty answer is "not set" (null), never the
     * empty string, and a multi-value field keeps only its real picks.
     *
     * @param mixed $value The posted value.
     *
     * @return mixed Scalar, list of scalars, or null.
     *
     * @since  2.0.0
     */
    private static function normalise(mixed $value): mixed
    {
        if (\is_array($value)) {
            $value = array_values(array_filter(
                $value,
                static fn ($entry) => !\is_array($entry) && trim((string) $entry) !== '',
            ));

            return match (\count($value)) {
                0 => null,
                1 => $value[0],
                default => $value,
            };
        }

        return trim((string) ($value ?? '')) === '' ? null : $value;
    }
}
