<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Throwable;

/**
 * What an adjustment of stock came to ({@see InventoryCommands::apply()}): every line applied, or none.
 *
 * Applied: each line's unit, location, figure before and after, the change and the operation that recorded it (null
 * when the figure was already true). Refused: the refusals, each pointing at its line; the dry run's problems as the
 * inventory service states them (for a screen that words them itself); the kernel's exception that ended the
 * transaction; or a line whose unit no longer takes stock.
 *
 * @since  2.3.0
 */
final class InventoryAdjustment
{
    /**
     * @param bool $applied Whether every line was applied.
     * @param list<array<string, mixed>> $lines The lines applied, in the request's order: line, item_id, combination_id,
     *                                          location_id, before, after, delta, operation_id.
     * @param list<Refusal> $refusals Why nothing was applied, each pointing at its line.
     * @param list<array{line: int, item_id: int, combination_id: int, problem: array}> $problems The dry run's problems.
     * @param Throwable|null $failure The exception that ended the transaction.
     * @param int|null $failedLine The line being applied when the transaction ended.
     * @param bool $unapplied A line's unit was no longer a tracked stock unit under its lock.
     * @param bool $replayed The same request was applied before under its key: these are its lines, nothing applied now.
     * @param list<array{code: string, title: string, pointer: string}> $warnings Keys of a line the adjustment does not take.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly bool $applied,
        public readonly array $lines = [],
        public readonly array $refusals = [],
        public readonly array $problems = [],
        public readonly ?Throwable $failure = null,
        public readonly ?int $failedLine = null,
        public readonly bool $unapplied = false,
        public readonly bool $replayed = false,
        public readonly array $warnings = [],
    ) {
    }

    /**
     * The dry run's problems, as validateTargets() states them.
     *
     * @return list<array>
     *
     * @since  2.3.0
     */
    public function problemsAsStated(): array
    {
        return array_column($this->problems, 'problem');
    }

    /**
     * How many lines moved stock (an operation recorded them).
     *
     * @since  2.3.0
     */
    public function operations(): int
    {
        return \count(array_filter($this->lines, static fn (array $line): bool => $line['operation_id'] !== null));
    }

    /**
     * The status a REST answer of the refusal takes: the first refusal's.
     *
     * @since  2.3.0
     */
    public function status(): int
    {
        return $this->refusals === [] ? 200 : $this->refusals[0]->status;
    }
}
