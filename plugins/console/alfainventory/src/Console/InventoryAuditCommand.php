<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\Console\Alfainventory\Console;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityReader;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * alfa:inventory:audit, the read-only inventory consistency audit.
 *
 * Runs InventoryIntegrityReader::audit() inside a read-only transaction with a
 * consistent snapshot, so every figure is read at one point in time and the
 * database refuses any write for its duration; the transaction is always
 * rolled back. The command has no repair option: repairs are explicit, audited
 * inventory operations taken on the Inventory audit page.
 *
 * Exit codes: 0 when nothing is found, 1 when there are findings, 2 when the
 * audit could not run. Classifications (order lines listed for information,
 * such as lines sold on a product itself before it had combinations) are
 * printed in their own section and never change the exit code. Suitable for
 * scheduled integrity checks.
 *
 * @since  1.0.0
 */
final class InventoryAuditCommand extends AbstractCommand
{
    /** No inconsistency found. */
    public const EXIT_CLEAN = 0;

    /** At least one finding. */
    public const EXIT_FINDINGS = 1;

    /** The audit could not run. */
    public const EXIT_ERROR = 2;

    /**
     * Makes the next transaction, the one the whole audit reads in, read-only.
     *
     * @since  1.0.0
     */
    private const READ_ONLY = 'SET TRANSACTION READ ONLY';

    /**
     * The default command name.
     *
     * @var string
     *
     * @since  1.0.0
     */
    protected static $defaultName = 'alfa:inventory:audit';

    /**
     * @param DatabaseInterface $db The connection the snapshot is opened on and the audit reads through.
     *
     * @since  1.0.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
        parent::__construct();
    }

    /**
     * Configure the command: --item and --json are its only options.
     *
     *
     * @since  1.0.0
     */
    protected function configure(): void
    {
        $this->setDescription(description: Text::_(string: 'PLG_CONSOLE_ALFAINVENTORY_AUDIT_DESCRIPTION'));
        $this->addOption(
            name: 'item',
            shortcut: null,
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: Text::_(string: 'PLG_CONSOLE_ALFAINVENTORY_AUDIT_OPTION_ITEM'),
        );
        $this->addOption(
            name: 'json',
            shortcut: null,
            mode: InputOption::VALUE_NONE,
            description: Text::_(string: 'PLG_CONSOLE_ALFAINVENTORY_AUDIT_OPTION_JSON'),
        );
    }

    /**
     * Run the audit and print the report.
     *
     * @param InputInterface $input The console input.
     * @param OutputInterface $output The console output.
     *
     * @return int EXIT_CLEAN, EXIT_FINDINGS or EXIT_ERROR.
     *
     * @since  1.0.0
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle(input: $input, output: $output);
        $language = Factory::getApplication()->getLanguage();
        $language->load(extension: 'plg_console_alfainventory', basePath: JPATH_ADMINISTRATOR);
        $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);

        try {
            $itemIds = $this->itemIds(values: (array) $input->getOption(name: 'item'));
        } catch (InvalidArgumentException $e) {
            $io->getErrorStyle()->error(message: Text::sprintf('PLG_CONSOLE_ALFAINVENTORY_AUDIT_INVALID_ITEM', $e->getMessage()));

            return self::EXIT_ERROR;
        }

        // Maintenance (Clear everything): the tables the audit reads are being removed and made again.
        if (MaintenanceState::active()) {
            $io->getErrorStyle()->error(message: Text::_('COM_ALFA_MAINTENANCE_API'));

            return self::EXIT_ERROR;
        }

        try {
            $report = $this->snapshotAudit(itemIds: $itemIds);
            $total = array_sum(array_map('count', $report['findings']));

            if ($input->getOption(name: 'json')) {
                $output->writeln(messages: json_encode(
                    $report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->render(io: $io, report: $report, total: $total);
            }
        } catch (Throwable $e) {
            $io->getErrorStyle()->error(message: Text::sprintf('PLG_CONSOLE_ALFAINVENTORY_AUDIT_ERROR', $e->getMessage()));

            return self::EXIT_ERROR;
        }

        return $total === 0 ? self::EXIT_CLEAN : self::EXIT_FINDINGS;
    }

    /**
     * The audit, read from one read-only snapshot that is always rolled back.
     * The connection must not be inside a transaction: starting the snapshot
     * would end it.
     *
     * @param int[]|null $itemIds The items to audit; null audits the whole catalogue.
     *
     * @return array The report of InventoryIntegrityReader::audit().
     *
     * @since  1.0.0
     */
    private function snapshotAudit(?array $itemIds): array
    {
        // Opened and rolled back by the driver: MySQL cannot prepare START TRANSACTION, and the
        // MySQLi driver prepares every statement it is handed. Under REPEATABLE READ the first
        // read fixes the snapshot every later read sees.
        $this->db->setQuery(query: self::READ_ONLY)->execute();
        $this->db->transactionStart();

        try {
            return (new InventoryIntegrityReader(db: $this->db))->audit(itemIds: $itemIds);
        } finally {
            TransactionState::rollBackLevel(db: $this->db);
        }
    }

    /**
     * The --item values as product ids: each value may list ids separated by
     * commas. No value audits the whole catalogue.
     *
     * @param array $values The raw option values.
     *
     * @return int[]|null The ids, or null for the whole catalogue.
     *
     * @throws InvalidArgumentException Naming the first value that is not a positive id.
     *
     * @since  1.0.0
     */
    private function itemIds(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        $ids = [];

        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $part = trim($part);

                if (!preg_match('/^\d+$/', $part) || (int) $part <= 0) {
                    throw new InvalidArgumentException(message: $part);
                }

                $ids[(int) $part] = (int) $part;
            }
        }

        return array_values($ids);
    }

    /**
     * Print the report: the scanned figures, one table per category that has
     * findings, the classifications in their own section, and the verdict,
     * which counts findings only.
     *
     * @param SymfonyStyle $io The console style.
     * @param array $report The audit report.
     * @param int $total Number of findings.
     *
     *
     * @since  1.0.0
     */
    private function render(SymfonyStyle $io, array $report, int $total): void
    {
        $io->title(message: Text::_(string: 'COM_ALFA_TITLE_INVENTORY_AUDIT'));
        $io->text(message: Text::sprintf('PLG_CONSOLE_ALFAINVENTORY_AUDIT_SCANNED', $report['scanned']['units'], $report['scanned']['rows']));

        $this->renderGroups(io: $io, groups: $report['findings']);

        if (array_sum(array_map('count', $report['classifications'])) > 0) {
            $io->section(message: Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_CLASSIFICATIONS'));
            $this->renderGroups(io: $io, groups: $report['classifications']);
        }

        if ($total === 0) {
            $io->success(message: Text::_(string: 'PLG_CONSOLE_ALFAINVENTORY_AUDIT_CLEAN'));

            return;
        }

        $io->warning(message: Text::plural(string: 'PLG_CONSOLE_ALFAINVENTORY_AUDIT_FOUND', n: $total));
    }

    /**
     * One table per category or classification that has entries: its label with
     * the count, its description and one row per entry.
     *
     * @param SymfonyStyle $io The console style.
     * @param array $groups Category or classification => entries.
     *
     *
     * @since  1.0.0
     */
    private function renderGroups(SymfonyStyle $io, array $groups): void
    {
        $headers = array_map(
            static fn (string $heading): string => Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_HEADING_' . $heading),
            ['ITEM', 'COMBINATION', 'LOCATION', 'UNIT', 'PROJECTION', 'CANONICAL', 'DRIFT', 'DETAIL'],
        );

        foreach ($groups as $category => $findings) {
            if ($findings === []) {
                continue;
            }

            $label = 'COM_ALFA_INVENTORY_AUDIT_CATEGORY_' . strtoupper($category);
            $io->section(message: Text::_(string: $label) . ' (' . \count($findings) . ')');
            $io->text(message: Text::_(string: $label . '_DESC'));
            $io->table(
                headers: $headers,
                rows: array_map(fn (array $finding): array => [
                    $finding['item_id'],
                    $finding['combination_id'],
                    $finding['location_id'] ?? '-',
                    $finding['unit'],
                    $this->quantity(value: $finding['projection']),
                    $this->quantity(value: $finding['canonical']),
                    $this->quantity(value: $finding['drift']),
                    $this->detail(finding: $finding),
                ], $findings),
            );
        }
    }

    /**
     * The detail column: stranded stock and stock on an archived combination
     * are labelled as excluded from availability, stock on an untracked unit
     * carries the operator hint, and every entry lists its facts.
     *
     * @param array $finding One finding or classified entry.
     *
     *
     * @since  1.0.0
     */
    private function detail(array $finding): string
    {
        $parts = [];

        if (\in_array($finding['category'], [InventoryIntegrityReader::CATEGORY_STRANDED_PARENT, InventoryIntegrityReader::CATEGORY_ARCHIVED_STOCK], true)) {
            $parts[] = Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_EXCLUDED');
        }

        if ($finding['category'] === InventoryIntegrityReader::CATEGORY_UNTRACKED_STOCK) {
            $parts[] = Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_UNTRACKED_HINT');
        }

        $parts[] = $this->facts(values: $finding['detail'], separator: ', ');

        return implode("\n", $parts);
    }

    /**
     * Facts as name=value pairs: a list by its count, a record (such as a
     * recorded resolution) by its own facts in brackets.
     *
     * @param array $values Name => value.
     * @param string $separator Between two facts.
     *
     *
     * @since  1.0.0
     */
    private function facts(array $values, string $separator): string
    {
        $facts = [];

        foreach ($values as $name => $value) {
            if ($name === 'excluded') {
                continue;
            }

            $facts[] = $name . '=' . match (true) {
                \is_array($value) && array_is_list($value) => (string) \count($value),
                \is_array($value) => '(' . $this->facts(values: $value, separator: '; ') . ')',
                \is_float($value) => $this->quantity(value: $value),
                \is_bool($value) => $value ? '1' : '0',
                default => (string) ($value ?? '-'),
            };
        }

        return implode($separator, $facts);
    }

    /**
     * A quantity as the inventory screens show it.
     *
     * @param float|null $value The quantity.
     *
     *
     * @since  1.0.0
     */
    private function quantity(?float $value): string
    {
        return $value === null ? '-' : InventoryAvailabilityService::formatQuantity(value: $value);
    }
}
