<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * The single source of truth for the ShowOn operator VOCABULARY: the frozen set,
 * their display labels, which value domains each suits, and the value-control rules
 * (multi-select, no-value, range). Both the builder field ({@see ShowonField}) and
 * the human summary ({@see ShowonSummary}) read it, and the builder serialises the
 * same lists into the page for the JS to consume, so the wording/domains live in
 * ONE place.
 *
 * Note this is the *vocabulary*, not the *behaviour*: how each operator actually
 * compares is implemented twice on purpose: PHP (RuleEvaluator, render + submit)
 * and JS (rule-evaluator.js, instant client toggling); a tiny frozen mirror that
 * is the price of visibility being both server-authoritative and client-instant.
 *
 * @since  1.3.0
 */
final class ShowonOperators
{
    /** The frozen operators -> their display string keys (definition order). */
    public const LABELS = [
        '=' => 'COM_ALFA_SHOWON_IS',
        '!=' => 'COM_ALFA_SHOWON_ISNOT',
        'in' => 'COM_ALFA_SHOWON_IN',
        '!in' => 'COM_ALFA_SHOWON_NIN',
        'empty' => 'COM_ALFA_SHOWON_EMPTY',
        '!empty' => 'COM_ALFA_SHOWON_NEMPTY',
        'contains' => 'COM_ALFA_SHOWON_CONTAINS',
        'starts_with' => 'COM_ALFA_SHOWON_STARTS',
        'ends_with' => 'COM_ALFA_SHOWON_ENDS',
        '<' => 'COM_ALFA_SHOWON_LT',
        '<=' => 'COM_ALFA_SHOWON_LTE',
        '>' => 'COM_ALFA_SHOWON_GT',
        '>=' => 'COM_ALFA_SHOWON_GTE',
        'between' => 'COM_ALFA_SHOWON_BETWEEN',
    ];

    /**
     * Which operators a value domain offers: comparing value-pool ids with
     * ordering/string ops is meaningless, so an enum only gets equality / membership
     * / presence. Each list is a subset of {@see self::LABELS}.
     */
    public const DOMAIN_OPS = [
        'enum' => ['=', '!=', 'in', '!in', 'empty', '!empty'],
        'bool' => ['=', 'empty', '!empty'],
        'number' => ['=', '!=', '<', '<=', '>', '>=', 'between', 'empty', '!empty'],
        'text' => ['=', '!=', 'contains', 'starts_with', 'ends_with', 'empty', '!empty'],
    ];

    /** Operators whose value control is a multi-select of the operand's pool. */
    public const MULTI_OPS = ['in', '!in'];

    /** Operators that take no value (the value control is hidden). */
    public const NO_VALUE_OPS = ['empty', '!empty'];

    /** The range operator (two-bound value control). */
    public const BETWEEN_OP = 'between';

    /**
     * The frozen operator keys, in order.
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * operator => translated label, in frozen order.
     *
     * @return array<string, string>
     *
     * @since  1.3.0
     */
    public static function labels(): array
    {
        $out = [];

        foreach (self::LABELS as $operator => $key) {
            $out[$operator] = Text::_($key);
        }

        return $out;
    }
}
