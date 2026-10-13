<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Setup;

\defined('_JEXEC') or die;

use Throwable;

/**
 * One item of the store-setup checklist: a DETECTOR, not a record of progress.
 *
 * A check never stores "done". Every evaluation re-derives its state from what is
 * actually true right now (a row exists AND is published AND its plugin is enabled),
 * so the checklist self-heals after imports and restores, and un-ticks itself when
 * configuration regresses. The probe must therefore stay cheap (a LIMIT 1 existence
 * read or a params read); anything expensive belongs behind its own cache.
 *
 * Checks are collected the way tool actions are ({@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry}):
 * core registers through {@see \Alfa\Component\Alfa\Administrator\Service\Setup\CoreSetupProvider}
 * and platform plugins answer `onAlfaCollectSetupChecks`, with ownership attributed by
 * the collector, never self-declared.
 *
 * @since  2.0.0
 */
final class SetupCheck
{
    /**
     * Tiers. `required` gates "your store can take an order"; `recommended` is best
     * practice the merchant should not skip silently; `optional` is polish.
     *
     * @var string
     * @since  2.0.0
     */
    public const TIER_REQUIRED = 'required';
    public const TIER_RECOMMENDED = 'recommended';
    public const TIER_OPTIONAL = 'optional';

    /**
     * Probe outcomes. `warn` is reserved for "the probe itself could not answer"
     * (or a soft condition); it never counts as complete.
     *
     * @var string
     * @since  2.0.0
     */
    public const STATE_PASS = 'pass';
    public const STATE_FAIL = 'fail';
    public const STATE_WARN = 'warn';

    /**
     * @var string Stable identity, e.g. `payments.active`. Shared vocabulary like tool
     *             action ids: identity is owner + id, never id alone.
     * @since  2.0.0
     */
    public string $id;

    /**
     * @var string Language key for the checklist row label.
     * @since  2.0.0
     */
    public string $label;

    /**
     * @var string One of the TIER_* constants.
     * @since  2.0.0
     */
    public string $tier = self::TIER_REQUIRED;

    /**
     * @var string Language key explaining WHY the check is incomplete and what to do.
     * @since  2.0.0
     */
    public string $reason = '';

    /**
     * @var string Admin deep link where the task is done: raw (un-routed), the
     *             renderer routes it. '' = no link.
     * @since  2.0.0
     */
    public string $cta = '';

    /**
     * @var string Guided-tour uid (`com_alfa.*`) teaching this task; '' = none.
     * @since  2.0.0
     */
    public string $tour = '';

    /**
     * @var int Display order, higher first. Core encodes the natural DOING order.
     * @since  2.0.0
     */
    public int $priority = 0;

    /**
     * @var array<string, mixed> Collector-stamped metadata (`owner_group`/`owner_name`).
     * @since  2.0.0
     */
    public array $metadata = [];

    /**
     * @var callable|null The detector: fn(): bool|string (a STATE_* string passes through).
     * @since  2.0.0
     */
    private $probe = null;

    /**
     * @var callable|null One-click auto-fix (cascade provisioning). Wired by the wizard
     *                    surface; the engine only carries it.
     * @since  2.0.0
     */
    private $provision = null;

    /**
     * @var array<int, array{name: string, label: string, options: callable}> Questions the auto-fix asks first.
     * @since  2.0.0
     */
    private array $choices = [];

    /**
     * @param string $id Stable check id.
     * @param string $label Language key for the row label.
     *
     * @since  2.0.0
     */
    public function __construct(string $id, string $label)
    {
        $this->id = trim($id);
        $this->label = trim($label);
    }

    /**
     * @param string $tier One of the TIER_* constants (anything else keeps `required`,
     *                     failing toward visibility rather than silence).
     *
     *
     * @since  2.0.0
     */
    public function tier(string $tier): static
    {
        if (\in_array($tier, [self::TIER_REQUIRED, self::TIER_RECOMMENDED, self::TIER_OPTIONAL], true)) {
            $this->tier = $tier;
        }

        return $this;
    }

    /**
     * @param string $key Language key for the incomplete-state explanation.
     *
     *
     * @since  2.0.0
     */
    public function reason(string $key): static
    {
        $this->reason = $key;

        return $this;
    }

    /**
     * @param string $url Raw admin URL where the task is done.
     *
     *
     * @since  2.0.0
     */
    public function cta(string $url): static
    {
        $this->cta = $url;

        return $this;
    }

    /**
     * @param string $uid Guided-tour uid (`com_alfa.*`).
     *
     *
     * @since  2.0.0
     */
    public function tour(string $uid): static
    {
        $this->tour = $uid;

        return $this;
    }

    /**
     * @param int $priority Display order, higher first.
     *
     *
     * @since  2.0.0
     */
    public function priority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @param callable $probe The detector: fn(): bool|string.
     *
     *
     * @since  2.0.0
     */
    public function probe(callable $probe): static
    {
        $this->probe = $probe;

        return $this;
    }

    /**
     * @param callable $provision One-click auto-fix.
     *
     *
     * @since  2.0.0
     */
    public function provision(callable $provision): static
    {
        $this->provision = $provision;

        return $this;
    }

    /**
     * A question the auto-fix asks first, rendered as a select beside its button.
     *
     * Provisioning that guesses is provisioning a merchant has to undo: where the
     * storefront's menu items go is theirs to say, not ours to assume. The options
     * are a callable so the list is read when the card renders, never at collect
     * time.
     *
     * @param string $name Parameter name handed to the provisioner.
     * @param string $label Language key for the field label.
     * @param callable $options fn(): array<string, string> value => label (already translated).
     *
     *
     * @since  2.0.0
     */
    public function choice(string $name, string $label, callable $options): static
    {
        $this->choices[] = ['name' => $name, 'label' => $label, 'options' => $options];

        return $this;
    }

    /**
     * The questions this check asks before provisioning, options resolved.
     *
     * @return array<int, array{name: string, label: string, options: array<string, string>}>
     *
     * @since  2.0.0
     */
    public function choices(): array
    {
        $out = [];

        foreach ($this->choices as $choice) {
            $out[] = [
                'name' => $choice['name'],
                'label' => $choice['label'],
                'options' => (array) ($choice['options'])(),
            ];
        }

        return $out;
    }

    /**
     * @param string $key Metadata key.
     * @param mixed $value Metadata value.
     *
     *
     * @since  2.0.0
     */
    public function meta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * A check without an id, a label, or a probe cannot render or answer; drop it.
     *
     *
     * @since  2.0.0
     */
    public function isValid(): bool
    {
        return $this->id !== '' && $this->label !== '' && $this->probe !== null;
    }

    /**
     * Run the detector and normalise its answer to a STATE_* string. A throwing probe
     * answers `warn`, never `pass`: an unanswerable check is not a completed one.
     *
     * @return string One of the STATE_* constants.
     *
     * @since  2.0.0
     */
    public function run(): string
    {
        try {
            $result = ($this->probe)();
        } catch (Throwable) {
            return self::STATE_WARN;
        }

        if (\is_string($result) && \in_array($result, [self::STATE_PASS, self::STATE_FAIL, self::STATE_WARN], true)) {
            return $result;
        }

        return $result ? self::STATE_PASS : self::STATE_FAIL;
    }

    /**
     * @return bool Whether a one-click auto-fix is available.
     *
     * @since  2.0.0
     */
    public function canProvision(): bool
    {
        return $this->provision !== null;
    }

    /**
     * Run the auto-fix. The caller (wizard surface) invalidates the cache afterwards.
     *
     * @param array<string, string> $answers The merchant's answers to {@see self::choice()}.
     *
     *
     * @throws Throwable Whatever the provisioner throws; a failed fix must surface.
     *
     * @since  2.0.0
     */
    public function runProvision(array $answers = []): void
    {
        if ($this->provision !== null) {
            ($this->provision)($answers);
        }
    }
}
