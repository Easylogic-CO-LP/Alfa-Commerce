<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Place;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The places Alfa Commerce ships, and how a shop installed before 2.2.1 comes to them (2.2.1).
 *
 * WHAT A NEW SHOP GETS. The install SQL seeds the countries of ISO 3166-1 (249,
 * by their English short names; Greece published) and, unpublished, the ISO
 * 3166-2 places below Greece and Spain listed in SUBDIVISIONS. The install SQL
 * is the fresh shop's copy of that list; SUBDIVISIONS is the one a shop adds
 * from (Places > Add subdivisions), and the two are the same places.
 *
 * WHAT AN UPDATE DOES (correctOnUpdate()). It never deletes, renames, moves or
 * unpublishes a place the shop changed, and never infers a place from a name:
 * - a country row still exactly as the seed up to 2.2.0 shipped it (the same
 *   id, name, codes and no parent) is corrected in place (CORRECTIONS), so
 *   every address, price and rule that names it keeps its id;
 * - an ISO 3166-1 country the seed lacked is added, unpublished, when no
 *   country of the shop has its code (ADDED);
 * - the rows with no single ISO successor (NO_SUCCESSOR, the development row
 *   Larisa among them), the rows the shop changed and the countries whose
 *   default currency the seed set to a withdrawn code (CURRENCIES) are left as
 *   they are and named for staff;
 * - the places below Greece and Spain are not added: a shop may have made its
 *   own, which only a match by name could find. Staff add them by the action.
 *
 * @since  2.2.1
 */
final class PlaceSeed
{
    /**
     * The ISO 3166-2 places shipped below a country, by the country's ISO 3166-1 code: code => English name. Greece's 13
     * regions and Mount Athos (ISO 3166-2:GR); the Canary Islands, Ceuta and Melilla (ISO 3166-2:ES), the places of
     * Spain outside the EU VAT area that have no country code of their own.
     *
     * @var array<string, array<string, string>>
     *
     * @since  2.2.1
     */
    public const SUBDIVISIONS = [
        'GR' => [
            'GR-A' => 'East Macedonia and Thrace',
            'GR-B' => 'Central Macedonia',
            'GR-C' => 'West Macedonia',
            'GR-D' => 'Epirus',
            'GR-E' => 'Thessaly',
            'GR-F' => 'Ionian Islands',
            'GR-G' => 'West Greece',
            'GR-H' => 'Central Greece',
            'GR-I' => 'Attica',
            'GR-J' => 'Peloponnese',
            'GR-K' => 'North Aegean',
            'GR-L' => 'South Aegean',
            'GR-M' => 'Crete',
            'GR-69' => 'Mount Athos',
        ],
        'ES' => [
            'ES-CN' => 'Canary Islands',
            'ES-CE' => 'Ceuta',
            'ES-ML' => 'Melilla',
        ],
    ];

    /**
     * The country rows of the seed up to 2.2.0 that 2.2.1 corrects where the shop never changed them: id => [the row as
     * shipped, the row now], each as [name, code2, code3].
     *
     * @var array<int, array{0: array{0: string, 1: string, 2: string}, 1: array{0: string, 1: string, 2: string}}>
     *
     * @since  2.2.1
     */
    public const CORRECTIONS = [
        27 => [['Bosnia and Herzegowina', 'BA', 'BIH'], ['Bosnia and Herzegovina', 'BA', 'BIH']],
        39 => [['Cape Verde', 'CV', 'CPV'], ['Cabo Verde', 'CV', 'CPV']],
        52 => [["Cote D'Ivoire", 'CI', 'CIV'], ["Côte d'Ivoire", 'CI', 'CIV']],
        56 => [['Czech Republic', 'CZ', 'CZE'], ['Czechia', 'CZ', 'CZE']],
        61 => [['East Timor', 'TP', 'TMP'], ['Timor-Leste', 'TL', 'TLS']],
        91 => [['Guinea-bissau', 'GW', 'GNB'], ['Guinea-Bissau', 'GW', 'GNB']],
        94 => [['Heard and Mc Donald Islands', 'HM', 'HMD'], ['Heard Island and McDonald Islands', 'HM', 'HMD']],
        125 => [['Macau', 'MO', 'MAC'], ['Macao', 'MO', 'MAC']],
        126 => [['Macedonia, The Former Yugoslav Republic of', 'MK', 'MKD'], ['North Macedonia', 'MK', 'MKD']],
        174 => [['Reunion', 'RE', 'REU'], ['Réunion', 'RE', 'REU']],
        175 => [['Romania', 'RO', 'ROM'], ['Romania', 'RO', 'ROU']],
        197 => [['St. Helena', 'SH', 'SHN'], ['Saint Helena, Ascension and Tristan da Cunha', 'SH', 'SHN']],
        198 => [['St. Pierre and Miquelon', 'PM', 'SPM'], ['Saint Pierre and Miquelon', 'PM', 'SPM']],
        201 => [['Svalbard and Jan Mayen Islands', 'SJ', 'SJM'], ['Svalbard and Jan Mayen', 'SJ', 'SJM']],
        202 => [['Swaziland', 'SZ', 'SWZ'], ['Eswatini', 'SZ', 'SWZ']],
        215 => [['Turkey', 'TR', 'TUR'], ['Türkiye', 'TR', 'TUR']],
        228 => [['Vatican City State (Holy See)', 'VA', 'VAT'], ['Holy See', 'VA', 'VAT']],
        233 => [['Wallis and Futuna Islands', 'WF', 'WLF'], ['Wallis and Futuna', 'WF', 'WLF']],
        237 => [['The Democratic Republic of Congo', 'DC', 'DRC'], ['Congo, Democratic Republic of the', 'CD', 'COD']],
        242 => [['St. Barthelemy', 'XB', 'XSB'], ['Saint Barthélemy', 'BL', 'BLM']],
        246 => [['Sint Maarten (French Antilles)', 'MF', 'MAF'], ['Saint Martin (French part)', 'MF', 'MAF']],
        247 => [['Sint Maarten (Netherlands Antilles)', 'SX', 'SXM'], ['Sint Maarten (Dutch part)', 'SX', 'SXM']],
        248 => [['Palestinian Territory, occupied', 'PS', 'PSE'], ['Palestine, State of', 'PS', 'PSE']],
    ];

    /**
     * The ISO 3166-1 countries the seed up to 2.2.0 lacked: code2 => [name, code3, the code of its default currency].
     * Timor-Leste, Saint Barthélemy and the Democratic Republic of the Congo replace shipped rows (CORRECTIONS) and are
     * added only where the shop changed those rows.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     *
     * @since  2.2.1
     */
    public const ADDED = [
        'AX' => ['Åland Islands', 'ALA', 'EUR'],
        'BL' => ['Saint Barthélemy', 'BLM', 'EUR'],
        'BQ' => ['Bonaire, Sint Eustatius and Saba', 'BES', 'USD'],
        'CD' => ['Congo, Democratic Republic of the', 'COD', 'CDF'],
        'CW' => ['Curaçao', 'CUW', 'XCG'],
        'GG' => ['Guernsey', 'GGY', 'GBP'],
        'IM' => ['Isle of Man', 'IMN', 'GBP'],
        'ME' => ['Montenegro', 'MNE', 'EUR'],
        'SS' => ['South Sudan', 'SSD', 'SSP'],
        'TL' => ['Timor-Leste', 'TLS', 'USD'],
    ];

    /**
     * The rows of the seed up to 2.2.0 with no single ISO successor, kept on update and named: id => [name, code2, code3]
     * as shipped. 249 is Larisa, a row of the development database, published inside Greece.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     *
     * @since  2.2.1
     */
    public const NO_SUCCESSOR = [
        151 => ['Netherlands Antilles', 'AN', 'ANT'],
        240 => ['East Timor', 'XE', 'XET'],
        243 => ['St. Eustatius', 'XU', 'XSE'],
        244 => ['Canary Islands', 'XC', 'XCA'],
        249 => ['Larisa', 'ZZ', 'LAR'],
    ];

    /**
     * The id of the development row Larisa in the seed up to 2.2.0.
     *
     * @since  2.2.1
     */
    public const LARISA = 249;

    /**
     * The countries whose default currency the seed up to 2.2.0 mapped to a code since withdrawn: code2 => [the code
     * then, the code now]. Where the shipped code was not in the currency seed, the country got no default currency.
     *
     * @var array<string, array{0: string, 1: string}>
     *
     * @since  2.2.1
     */
    public const CURRENCIES = [
        'BG' => ['BGN', 'EUR'],
        'BY' => ['BYR', 'BYN'],
        'MR' => ['MRO', 'MRU'],
        'SL' => ['SLL', 'SLE'],
        'ST' => ['STD', 'STN'],
        'SX' => ['ANG', 'XCG'],
        'VE' => ['VEF', 'VES'],
        'ZM' => ['ZMK', 'ZMW'],
        'ZW' => ['ZWD', 'ZWG'],
    ];

    /**
     * Bring a shop's places from a seed up to 2.2.0 to 2.2.1's, as the class description says. Run once, by the update
     * from a version before 2.2.1; run again it finds nothing to do.
     *
     * @param DatabaseInterface $db The shop's connection.
     *
     * @return array{corrected: array<int, string>, added: array<string, string>, kept: array<int, array{0: string, 1: string}>,
     *               noSuccessor: array<int, string>, currencies: array<int, array{0: string, 1: string}>}
     *               corrected: id => the name now; added: code2 => name; kept: id => [the shop's name, the name 2.2.1 ships];
     *               noSuccessor: id => the shop's name, Larisa included; currencies: id => [the country's name, the code now].
     *
     * @since  2.2.1
     */
    public static function correctOnUpdate(DatabaseInterface $db): array
    {
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'name', 'code2', 'code3', 'currency_id']))
                ->select('COALESCE(' . $db->quoteName('parent_id') . ', 0) AS ' . $db->quoteName('parent_id'))
                ->from($db->quoteName('#__alfa_places')),
        )->loadObjectList('id') ?: [];

        $result = ['corrected' => [], 'added' => [], 'kept' => [], 'noSuccessor' => [], 'currencies' => []];

        // 1. The rows exactly as shipped, compared byte for byte (the table's collation would match "TURKEY" to "Turkey").
        foreach (self::CORRECTIONS as $id => [$shipped, $now]) {
            $row = $rows[$id] ?? null;

            if ($row === null) {
                continue;
            }

            $current = [(string) $row->name, (string) $row->code2, (string) $row->code3];

            if ($current === $now && (int) $row->parent_id === 0) {
                continue;
            }

            if ($current !== $shipped || (int) $row->parent_id !== 0) {
                $result['kept'][$id] = [(string) $row->name, $now[0]];

                continue;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_places'))
                    ->set($db->quoteName('name') . ' = :name')
                    ->set($db->quoteName('code2') . ' = :code2')
                    ->set($db->quoteName('code3') . ' = :code3')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':name', $now[0])
                    ->bind(':code2', $now[1])
                    ->bind(':code3', $now[2])
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();

            $row->name = $now[0];
            $row->code2 = $now[1];
            $row->code3 = $now[2];
            $result['corrected'][$id] = $now[0];
        }

        // 2. The countries the shop has no country for, by code.
        $countryCodes = [];

        foreach ($rows as $row) {
            if ((int) $row->parent_id === 0) {
                $countryCodes[strtoupper(trim((string) $row->code2))] = true;
            }
        }

        foreach (self::ADDED as $code2 => [$name, $code3, $currencyCode]) {
            if (isset($countryCodes[$code2])) {
                continue;
            }

            $place = (object) [
                'name' => $name,
                'code2' => $code2,
                'code3' => $code3,
                'subdivision_code' => null,
                'currency_id' => self::currencyId(db: $db, code: $currencyCode),
                'parent_id' => 0,
                'state' => 0,
                'ordering' => 0,
                'created_by' => 0,
                'modified_by' => 0,
            ];
            $db->insertObject('#__alfa_places', $place, 'id');
            $result['added'][$code2] = $name;
        }

        // 3. Named, never changed: the rows with no single successor still there by their shipped code, and the
        //    countries whose default currency is still none or the withdrawn one.
        foreach (self::NO_SUCCESSOR as $id => [, $code2]) {
            $row = $rows[$id] ?? null;

            if ($row !== null && strtoupper(trim((string) $row->code2)) === $code2) {
                $result['noSuccessor'][$id] = (string) $row->name;
            }
        }

        foreach ($rows as $id => $row) {
            $code2 = strtoupper(trim((string) $row->code2));

            if ((int) $row->parent_id !== 0 || !isset(self::CURRENCIES[$code2])) {
                continue;
            }

            [$then, $now] = self::CURRENCIES[$code2];
            $currencyId = (int) $row->currency_id;

            if ($currencyId === 0 || $currencyId === self::currencyId(db: $db, code: $then)) {
                $result['currencies'][(int) $id] = [(string) $row->name, $now];
            }
        }

        return $result;
    }

    /**
     * The shop's currency of an ISO 4217 code, or 0 when it has none.
     *
     * @param DatabaseInterface $db The shop's connection.
     * @param string $code The ISO 4217 code.
     *
     * @since  2.2.1
     */
    private static function currencyId(DatabaseInterface $db, string $code): int
    {
        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('MIN(' . $db->quoteName('id') . ')')
                ->from($db->quoteName('#__alfa_currencies'))
                ->where($db->quoteName('code') . ' = :code')
                ->bind(':code', $code),
        )->loadResult();
    }
}
