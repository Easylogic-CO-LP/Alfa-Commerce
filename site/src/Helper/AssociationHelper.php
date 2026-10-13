<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Facet\FilterKeyWhitelist;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Multilanguage;

/**
 * Frontend association resolver for the language switcher.
 *
 * com_alfa keeps ONE record id shared across every content language; the
 * translations live in per-language auxiliary tables (#__alfa_<entity>_<lang>),
 * each row keyed by that same id. There are therefore no per-language sibling
 * rows and no #__associations entries: the SAME id IS the association under
 * every installed language. Only the alias (URL slug) differs per language, and
 * the component Router emits the correct one because Route::_() carries &lang.
 *
 * mod_languages / plg_system_languagefilter call
 *   $component->getAssociationsExtension()->getAssociationsForItem()
 * which delegates here. We return an array keyed by language code (e.g.
 * 'el-GR', 'en-GB') whose values are internal URLs carrying &lang=<code>;
 * Route::_() + the RouterView then build /el/... and /en/... server-side.
 *
 * @since  1.0.0
 */
class AssociationHelper
{
    /**
     * Filter keys whose value is CONTENT rather than an identifier, and therefore does not
     * survive a change of language.
     *
     * A list rather than a single check because the next one is predictable: any future
     * free-text filter (a tag phrase, a "matches description" box) belongs here too. Ids never
     * do; they are the same row in every language.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    private const LANGUAGE_BOUND_FILTERS = ['search'];

    /**
     * Build the per-language URL set for the current (or given) Alfa page.
     *
     * Only the detail and category-listing views take part in language
     * switching; everything else returns [] so Joomla falls back to native
     * menu-item associations.
     *
     * @param int $id Record id (0 = read from the request).
     * @param string|null $view View name (null = read from the request).
     *
     * @return array [ 'el-GR' => 'index.php?...&lang=el-GR', 'en-GB' => ... ]
     *
     * @since  1.0.0
     */
    public static function getAssociations(int $id = 0, ?string $view = null): array
    {
        if (!Multilanguage::isEnabled()) {
            return [];
        }

        $input = Factory::getApplication()->getInput();
        $view = $view ?: $input->get('view');
        $id = $id ?: $input->getInt('id');

        switch ($view) {
            case 'item':
                if (!$id) {
                    return [];
                }
                // The id (and its category) are shared across languages; only the
                // emitted slug differs, which the Router handles via &lang.
                $base = 'index.php?option=com_alfa&view=item&id=' . $id;
                $catId = $input->getInt('category_id');
                $base .= $catId ? '&category_id=' . $catId : '';
                break;

            case 'manufacturer':
                if (!$id) {
                    return [];
                }
                $base = 'index.php?option=com_alfa&view=manufacturer&id=' . $id;
                break;

            case 'items':
                // Category product listing (id lives in category_id, not id).
                $catId = $input->getInt('category_id');
                $base = 'index.php?option=com_alfa&view=items' . ($catId ? '&category_id=' . $catId : '');
                break;

            default:
                return [];
        }

        // SWITCHING LANGUAGE IS NOT NAVIGATING AWAY. The visitor asked to read THIS page in
        // another language, so how they had arranged it has to survive: the facets they ticked,
        // the sort they chose, the page they were on. Carrying only view + id keeps the right PAGE
        // and silently discards the right VIEW OF IT, which reads as the switch having reset the
        // shop.
        //
        // ONLY LANGUAGE-INDEPENDENT STATE TRAVELS, and that qualifier is the whole rule. Facet
        // selections are numeric ids shared across languages (translations share the record id,
        // the premise this entire helper rests on), while prices, sorts and page numbers carry no
        // language at all. Those are safe because they mean the same thing on both sides.
        //
        // THE SEARCH KEYWORD IS DELIBERATELY NOT CARRIED, and it is the one exception worth
        // explaining. A keyword is content, not an id: "παντελόνι" matched against English text
        // means nothing, and transliteration only bridges Greek->Latin, so carrying it would work
        // in one direction and quietly fail in the other. Worse, the carried search RE-EXECUTES,
        // which would record a search event whose keyword belongs to the language the visitor
        // just left, manufacturing zero-result rows nobody typed and corrupting the one report
        // the analytics subsystem exists to produce. Losing a keyword costs one retype; a
        // dishonest zero-result report costs the merchant a decision. See
        // {@see \Alfa\Plugin\AlfaSearch\Enterprise\Analytics\EventRecorder} for the same
        // reasoning applied to type-ahead probes.
        $base .= self::carriedState((string) $view);

        $associations = [];

        // Key by lang_code exactly the way mod_languages consumes it.
        foreach (LanguageHelper::getLanguages() as $language) {
            if (empty($language->lang_code) || $language->lang_code === '*') {
                continue;
            }

            $associations[$language->lang_code] = $base . '&lang=' . $language->lang_code;
        }

        return $associations;
    }

    /**
     * The current filter / list state, as a query-string fragment to append to an association.
     *
     * Read from the REQUEST rather than from a model's state on purpose: this helper runs for the
     * language module, which has no list model, and the request is the only place that describes
     * what the visitor actually asked for on this page.
     *
     * Scalars, flat arrays of scalars, and the one nested shape the catalogue really uses:
     * a map of field id to its value(s), as filter[fields] and filter[groups] arrive. Anything
     * deeper is dropped rather than serialised blindly, and empty values are skipped so the URL
     * stays as short as the state genuinely is. A nested map keeps its branch KEYS: they are
     * field ids, not positions.
     *
     * BECAUSE IT READS THE REQUEST AND NOT A MODEL, IT MUST APPLY THE FILTER-KEY WHITELIST ITSELF.
     * Refusing an unknown key in UrlListModel::populateState() does not cover this method; it
     * never consults model state, so an invented `filter[whatever]` would still be republished
     * here as a public `<link rel="alternate">` URL handed straight to search engines. Same list,
     * second boundary: {@see FilterKeyWhitelist}. The language-bound exclusion below is a further
     * narrowing on top of it, not an alternative to it.
     *
     * @param string $view The view being associated; selects the allow-set. See
     *                     {@see FilterKeyWhitelist::forView()}.
     *
     * @return string '' when there is no state, otherwise '&filter[x]=y&list[z]=w'.
     *
     * @since  2.0.0
     */
    private static function carriedState(string $view): string
    {
        $input = Factory::getApplication()->getInput();
        $carry = [];
        $allowedFilters = FilterKeyWhitelist::forView($view);

        foreach (['filter', 'list'] as $group) {
            $values = (array) $input->get($group, [], 'array');
            $clean = [];

            foreach ($values as $key => $value) {
                if ($group === 'filter') {
                    // Not a key this listing actually supports; it must never reach a public URL.
                    if (!FilterKeyWhitelist::accepts($key, $allowedFilters)) {
                        continue;
                    }

                    // Language-BOUND state stops here; see the class-level reasoning.
                    if (\in_array($key, self::LANGUAGE_BOUND_FILTERS, true)) {
                        continue;
                    }
                }

                if (\is_array($value)) {
                    // A NESTED MAP CARRIES MEANING IN ITS KEYS: for filter[fields] and
                    // filter[groups] the branch key IS the field id. Flattening one with
                    // array_values() re-keyed the branches positionally, so field 1 became
                    // field 0 (a different filter, and field 0 matches the whole catalogue)
                    // while a branch that was itself an array (the browser's own
                    // filter[fields][1][]=1 spelling) mapped to '' and was dropped, losing
                    // the filter from the alternate altogether. Both were reproduced live.
                    // Same discriminator as UrlListModel::isNestedFilterMap(), so the two
                    // boundaries agree on what "nested" means.
                    if (!array_is_list($value)) {
                        $branches = [];

                        foreach ($value as $branchKey => $branch) {
                            if (\is_array($branch)) {
                                $flatBranch = array_values(array_filter(
                                    array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', $branch),
                                    static fn (string $v): bool => $v !== '',
                                ));
                            } else {
                                $flatBranch = \is_scalar($branch) && (string) $branch !== ''
                                    ? (string) $branch
                                    : null;
                            }

                            if ($flatBranch !== null && $flatBranch !== []) {
                                $branches[$branchKey] = $flatBranch;
                            }
                        }

                        if ($branches !== []) {
                            $clean[$key] = $branches;
                        }

                        continue;
                    }

                    $flat = array_values(array_filter(
                        array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', $value),
                        static fn (string $v): bool => $v !== '',
                    ));

                    if ($flat !== []) {
                        $clean[$key] = $flat;
                    }

                    continue;
                }

                if (\is_scalar($value) && (string) $value !== '') {
                    $clean[$key] = (string) $value;
                }
            }

            if ($clean !== []) {
                $carry[$group] = $clean;
            }
        }

        // The page the visitor is on is part of "where I was", so it travels too.
        $limitstart = $input->getInt('limitstart', 0);

        if ($limitstart > 0) {
            $carry['limitstart'] = $limitstart;
        }

        return $carry === [] ? '' : '&' . http_build_query($carry);
    }
}
