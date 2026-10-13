<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\ShowOn;

use Alfa\Component\Alfa\Site\Service\FieldState\StateBag;

defined('_JEXEC') or die;

/**
 * Server-side initial paint for ShowOn: the same {@see RuleEvaluator} the JS runs,
 * applied once at render so a field that should start hidden is emitted already
 * hidden: no flash before the client engine boots (no FOUC). The client then owns
 * every live toggle; because PHP and JS evaluate the identical rule against the same
 * state, the first client pass agrees and nothing moves.
 *
 * Returns only the extra wrapper attributes; the field's own showon attrs come from
 * {@see \Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces::showonAttr}.
 * A `disable`-effect field stays visible (inert), so there is no FOUC to suppress.
 *
 * @since  2.0.0
 */
final class InitialState
{
    /**
     * The wrapper attribute string a not-yet-visible field needs on first paint.
     *
     * @param object $field The per-item field (carries ->params_decoded with the rule).
     * @param StateBag $bag The item's current input state.
     *
     * @return string '' when visible / rule-less / disable-effect; ' hidden data-showon-off=""' otherwise.
     *
     * @since  2.0.0
     */
    public static function hiddenAttr(object $field, StateBag $bag): string
    {
        $params = (array) ($field->params_decoded ?? []);
        $rule = $params['showon'] ?? null;

        if (empty($rule)) {
            return '';
        }

        if (!\is_array($rule)) {
            $rule = json_decode((string) $rule, true);
        }

        if (!\is_array($rule) || empty($rule['rules'])) {
            return '';
        }

        // A disable-effect field stays visible (just inert); nothing to hide.
        if ((string) ($params['showon_effect'] ?? 'hide') === 'disable') {
            return '';
        }

        return (new RuleEvaluator())->isVisible($rule, $bag) ? '' : ' hidden data-showon-off=""';
    }
}
