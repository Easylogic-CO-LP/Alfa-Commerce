<?php

/**
 * @package    Easy Articles
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Easy\Module\EasyArticles\Site\Helper;

use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Component\Content\Site\Model\ArticlesModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use stdClass;
use Throwable;

defined('_JEXEC') or die;

/**
 * Resolves what an articles module shows and renders it.
 *
 * The module IS its tabs: each configured row is one listing of Joomla articles
 * with its own source, categories, order and size, shown as a grid or a slider;
 * one row renders as a plain list, several as a tab strip. The module never
 * queries articles itself: it declares a state on com_content's ArticlesModel (
 * categories, featured filter, ordering, or a ranked id list) and the model
 * applies publishing, access and language exactly as the site's own listings do.
 * The cards render through the module's `_card` layout, so a template override
 * restyles every card at once.
 *
 * @since  1.0.0
 */
final class EasyArticlesHelper
{
    public const SOURCE_ARTICLES = 'articles';
    public const SOURCE_FEATURED = 'featured';
    public const SOURCE_MOST_READ = 'most_read';
    public const SOURCE_RECENTLY_READ = 'recently_read';

    /**
     * @var int Screens up to this width are tablets: the second column count / slides-per-view applies.
     * @since  1.0.0
     */
    public const TABLET = 1023;

    /**
     * @var int Screens up to this width are phones: the third column count / slides-per-view applies.
     * @since  1.0.0
     */
    public const MOBILE = 767;

    /**
     * @var string Session key of the visitor's recently read articles, shared by
     *             both article editions, so either reads what either recorded.
     * @since  1.0.0
     */
    public const READ_SESSION_KEY = 'easy.recently_read';

    /**
     * Orderings the module may hand the model, keyed by the parameter value.
     * `random` is resolved to the database's own random function.
     *
     * @var array<string, array{0: string, 1: string}>
     * @since  1.0.0
     */
    public const ORDERINGS = [
        'a.publish_up DESC' => ['a.publish_up', 'DESC'],
        'a.publish_up ASC' => ['a.publish_up', 'ASC'],
        'a.modified DESC' => ['a.modified', 'DESC'],
        'a.title ASC' => ['a.title', 'ASC'],
        'a.title DESC' => ['a.title', 'DESC'],
        'a.ordering ASC' => ['a.ordering', 'ASC'],
        'a.ordering DESC' => ['a.ordering', 'DESC'],
        'fp.ordering ASC' => ['fp.ordering', 'ASC'],
        'random' => ['', ''],
    ];

    /**
     * @var array<int, array{key: string, max: int}> The module's two breakpoints, widest first: what a card setting may be set again for.
     * @since  1.0.0
     */
    public const BREAKPOINTS = [
        ['key' => 'tablet', 'max' => self::TABLET],
        ['key' => 'mobile', 'max' => self::MOBILE],
    ];

    /**
     * @var string Group key prefix of a tab (followed by its row index).
     * @since  1.0.0
     */
    private const TAB_KEY = 'tab-';

    /**
     * @var string Ordering of a row that has none stored.
     * @since  1.0.0
     */
    private const DEFAULT_ORDERING = 'a.publish_up DESC';

    /**
     * @var int Size of a row that has none stored.
     * @since  1.0.0
     */
    private const DEFAULT_LIMIT = 8;

    /**
     * @var int Upper bound of the per-group limit parameter.
     * @since  1.0.0
     */
    private const LIMIT_MAX = 60;

    /**
     * @var int How many read articles the session keeps; the oldest drop off.
     * @since  1.0.0
     */
    private const READ_MAX = 24;

    /**
     * @var array<string, string> Resolved layout paths, one lookup per layout per request.
     * @since  1.0.0
     */
    private static array $layoutPaths = [];

    // Groups

    /**
     * The groups the module renders, in order: one per configured tab row the
     * visitor may see. With several tabs only the first non-empty one carries
     * articles; the rest are read and fetched when opened: a tab strip never
     * pays for cards nobody looks at. A group found empty on the way is dropped
     * rather than offered as a tab that opens on nothing.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance id.
     * @param string|null $onlyKey Resolve just this group, with its articles (the on-demand fetch).
     *
     * @return array<int, array{key: string, label: string, items: array|null, lazy: bool, first: bool, link: string, look: array}>
     *
     * @since  1.0.0
     */
    public static function getGroups(Registry $params, int $moduleId, ?string $onlyKey = null): array
    {
        $definitions = self::groupDefinitions($params);
        $lazy = $onlyKey === null && \count($definitions) > 1;
        $groups = [];
        $eagerDone = false;

        foreach ($definitions as $definition) {
            if ($onlyKey !== null && $onlyKey !== $definition['key']) {
                continue;
            }

            if ($lazy && $eagerDone) {
                $groups[] = $definition + ['items' => null, 'lazy' => true, 'first' => false];

                continue;
            }

            $items = self::cachedItems(
                moduleId:    $moduleId,
                params:      $params,
                categoryIds: $definition['categories'],
                source:      $definition['source'],
                limit:       $definition['limit'],
                ordering:    $definition['ordering'],
            );

            if ($items === [] && $onlyKey === null) {
                continue;
            }

            // The first group rendered with the page may hold the page's largest image.
            $groups[] = $definition + ['items' => $items, 'lazy' => false, 'first' => $onlyKey === null && !$eagerDone];
            $eagerDone = true;
        }

        return $groups;
    }

    /**
     * What each tab IS, before any article is read: key, label, categories,
     * source, order and size, its "view all" link and its own
     * card look.
     * Categories the visitor may not see are dropped here, so a tab never names
     * a restricted category; a tab whose every category is hidden is dropped
     * with them.
     *
     * @param Registry $params Module parameters.
     *
     * @return array<int, array{key: string, label: string, categories: int[], source: string, limit: int, ordering: string, link: string, look: array}>
     *
     * @since  1.0.0
     */
    private static function groupDefinitions(Registry $params): array
    {
        $definitions = [];

        foreach (self::tabRows($params) as $index => $row) {
            $names = [];

            foreach ($row['categories'] as $categoryId) {
                $name = self::categoryName($categoryId);

                if ($name !== null) {
                    $names[$categoryId] = $name;
                }
            }

            if ($row['categories'] !== [] && $names === []) {
                continue;
            }

            $visible = array_keys($names);

            $definitions[] = [
                'key' => self::TAB_KEY . $index,
                'label' => $row['label'] !== ''
                    ? Text::_($row['label'])
                    : ($names !== [] ? implode(', ', $names) : Text::_('MOD_EASY_ARTICLES_SOURCE_' . strtoupper($row['source']))),
                'categories' => $visible,
                'source' => $row['source'],
                'limit' => $row['limit'],
                'ordering' => $row['ordering'],
                'link' => $row['link'] ? self::viewAllUrl(source: $row['source'], categoryIds: $visible) : '',
                'look' => $row['look'],
            ];
        }

        return $definitions;
    }

    /**
     * Where "view all" of a tab leads: one category = that category's page; a
     * featured tab without categories = the featured articles page. Joomla has
     * no page listing several categories, the most read or a visitor's reading
     * history, so those link nowhere.
     *
     * @param string $source The tab's source.
     * @param int[] $categoryIds The tab's visible categories.
     *
     * @return string A routed URL, or '' when there is nowhere to go.
     *
     * @since  1.0.0
     */
    private static function viewAllUrl(string $source, array $categoryIds): string
    {
        if (\count($categoryIds) === 1) {
            $category = Categories::getInstance('Content')->get((int) $categoryIds[0]);

            return $category ? Route::_(RouteHelper::getCategoryRoute((int) $category->id, $category->language)) : '';
        }

        if ($categoryIds === [] && $source === self::SOURCE_FEATURED) {
            return Route::_('index.php?option=com_content&view=featured');
        }

        return '';
    }

    // Articles

    /**
     * The articles of one group, read through com_content's listing model.
     *
     * Categories include their subcategories as deep as com_content's own
     * "Include Subcategories" option says. Recently read hands the model the
     * visitor's id list and restores its order afterwards, since the model
     * orders only by columns; most read orders by hits.
     *
     * @param int[] $categoryIds The tab's categories (empty = all).
     * @param string $source The tab's source (a SOURCE_* constant).
     * @param int $limit The tab's size.
     * @param string $ordering The tab's ordering (a key of ORDERINGS).
     *
     * @return object[] Articles, in display order.
     *
     * @since  1.0.0
     */
    public static function getItems(array $categoryIds, string $source, int $limit, string $ordering): array
    {
        $source = self::source($source);
        $limit = self::bounded($limit, 1, self::LIMIT_MAX);
        $ranked = $source === self::SOURCE_RECENTLY_READ ? self::recentlyReadIds() : null;

        if ($ranked === []) {
            return [];
        }

        $model = self::createModel();

        if ($categoryIds !== []) {
            $model->setState('filter.category_id', self::withSubcategories($categoryIds));
        }

        switch ($source) {
            case self::SOURCE_FEATURED:
                $model->setState('filter.featured', 'only');
                self::order($model, $ordering);
                break;

            case self::SOURCE_MOST_READ:
                $model->setState('list.ordering', 'a.hits');
                $model->setState('list.direction', 'DESC');
                break;

            case self::SOURCE_RECENTLY_READ:
                $model->setState('filter.article_id', $ranked);
                $model->setState('filter.article_id.include', true);
                self::order($model, self::DEFAULT_ORDERING);
                break;

            case self::SOURCE_ARTICLES:
            default:
                self::order($model, $ordering);
                break;
        }

        $model->setState('list.start', 0);
        $model->setState('list.limit', $ranked !== null ? 0 : $limit);

        try {
            $items = $model->getItems();
        } catch (Throwable $e) {
            Log::add('mod_easy_articles could not read articles: ' . $e->getMessage(), Log::ERROR, 'mod_easy_articles');

            return [];
        }

        if (!\is_array($items) || $items === []) {
            return [];
        }

        if ($ranked !== null) {
            $rank = array_flip($ranked);

            usort(
                $items,
                static fn (object $a, object $b): int =>
                    ($rank[(int) $a->id] ?? PHP_INT_MAX) <=> ($rank[(int) $b->id] ?? PHP_INT_MAX),
            );

            $items = \array_slice($items, 0, $limit);
        }

        return array_values($items);
    }

    /**
     * A tab's (or a block's) articles through Joomla's module cache, the way the
     * core articles module reads its list: the Advanced tab's Caching and Cache
     * Time decide, Global Configuration must have caching on, and a logged-in
     * visitor always reads fresh. What belongs to one visitor's session (recently
     * read) and a random order are never cached.
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters (owncache, cache_time).
     * @param int[] $categoryIds The categories (empty = all).
     * @param string $source The source (a SOURCE_* constant).
     * @param int $limit The size.
     * @param string $ordering The ordering (a key of ORDERINGS).
     *
     * @return object[] Articles, in display order.
     *
     * @since  1.0.0
     */
    public static function cachedItems(int $moduleId, Registry $params, array $categoryIds, string $source, int $limit, string $ordering): array
    {
        if (self::source($source) === self::SOURCE_RECENTLY_READ || $ordering === 'random') {
            return self::getItems(categoryIds: $categoryIds, source: $source, limit: $limit, ordering: $ordering);
        }

        $cache = new stdClass();
        $cache->cachemode = 'id';
        $cache->class = self::class;
        $cache->method = 'getItems';
        $cache->methodparams = [$categoryIds, $source, $limit, $ordering];
        // One entry per module and query; the cache keeps languages apart itself.
        $cache->modeparams = md5(serialize([$moduleId, $categoryIds, $source, $limit, $ordering]));

        $items = ModuleHelper::moduleCache((object) ['id' => $moduleId, 'module' => 'mod_easy_articles'], $params, $cache);

        return \is_array($items) ? $items : [];
    }

    /**
     * A fresh listing model that reads nothing from the request: published,
     * visible to this visitor, in the page's language, without tags.
     *
     *
     * @since  1.0.0
     */
    private static function createModel(): ArticlesModel
    {
        $app = Factory::getApplication();

        /** @var ArticlesModel $model */
        $model = $app->bootComponent('com_content')->getMVCFactory()
            ->createModel('Articles', 'Site', ['ignore_request' => true]);

        // Under ignore_request populateState() never runs: the state is exactly
        // what is set here and by the caller.
        $model->setState('params', clone ComponentHelper::getParams('com_content'));
        $model->setState('filter.published', 1);
        $model->setState('filter.access', true);
        $model->setState('filter.language', $app->getLanguageFilter());
        $model->setState('load_tags', false);

        return $model;
    }

    /**
     * Apply one of the allowed orderings to the model; unknown values fall to newest first.
     *
     * @param ArticlesModel $model The listing model.
     * @param string $ordering A key of self::ORDERINGS.
     *
     *
     * @since  1.0.0
     */
    private static function order(ArticlesModel $model, string $ordering): void
    {
        if ($ordering === 'random') {
            $model->setState('list.ordering', Factory::getContainer()->get(DatabaseInterface::class)->createQuery()->rand());
            $model->setState('list.direction', '');

            return;
        }

        [$column, $direction] = self::ORDERINGS[$ordering] ?? self::ORDERINGS[self::DEFAULT_ORDERING];

        $model->setState('list.ordering', $column);
        $model->setState('list.direction', $direction);
    }

    /**
     * The categories with their subcategories, as deep as com_content's
     * "Include Subcategories" option allows (none, a number of levels, or all);
     * only published subcategories this visitor may see.
     *
     * @param int[] $categoryIds The chosen categories.
     *
     * @return int[]
     *
     * @since  1.0.0
     */
    private static function withSubcategories(array $categoryIds): array
    {
        $levels = (int) ComponentHelper::getParams('com_content')->get('show_subcategory_content', 0);

        if ($levels === 0) {
            return $categoryIds;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('sub.id'))
            ->from($db->quoteName('#__categories', 'sub'))
            ->join(
                'INNER',
                $db->quoteName('#__categories', 'this'),
                $db->quoteName('sub.lft') . ' > ' . $db->quoteName('this.lft') . ' AND ' . $db->quoteName('sub.rgt') . ' < ' . $db->quoteName('this.rgt'),
            )
            ->whereIn($db->quoteName('this.id'), $categoryIds)
            ->where($db->quoteName('sub.extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('sub.published') . ' = 1')
            ->whereIn($db->quoteName('sub.access'), Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());

        if ($levels > 0) {
            $query->where($db->quoteName('sub.level') . ' <= ' . $db->quoteName('this.level') . ' + :levels')
                ->bind(':levels', $levels, ParameterType::INTEGER);
        }

        try {
            $children = (array) $db->setQuery($query)->loadColumn();
        } catch (Throwable $e) {
            Log::add('mod_easy_articles could not read subcategories: ' . $e->getMessage(), Log::ERROR, 'mod_easy_articles');
            $children = [];
        }

        return array_values(array_unique(array_map('intval', array_merge($categoryIds, $children))));
    }

    // Recently read

    /**
     * Record the article on screen, if this page is one: its id moves to the
     * front of the visitor's session list. Joomla keeps no reading history, so
     * the article modules keep it, on the article pages they are published on.
     *
     *
     * @since  1.0.0
     */
    public static function recordRead(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_content' || $input->getCmd('view') !== 'article') {
            return;
        }

        $id = $input->getInt('id', 0);

        if ($id <= 0) {
            return;
        }

        $ids = array_values(array_diff(self::readIds(), [$id]));
        array_unshift($ids, $id);

        $app->getSession()->set(self::READ_SESSION_KEY, \array_slice($ids, 0, self::READ_MAX));
    }

    /**
     * The visitor's recently read ids, most recent first, without the article
     * on screen, which a "recently read" strip on its own page must not repeat.
     *
     * @return int[]
     *
     * @since  1.0.0
     */
    private static function recentlyReadIds(): array
    {
        $input = Factory::getApplication()->getInput();
        $current = ($input->getCmd('option') === 'com_content' && $input->getCmd('view') === 'article')
            ? $input->getInt('id', 0)
            : 0;

        $ids = self::readIds();

        return $current > 0 ? array_values(array_diff($ids, [$current])) : $ids;
    }

    /**
     * The recorded ids, most recent first.
     *
     * @return int[]
     *
     * @since  1.0.0
     */
    private static function readIds(): array
    {
        $ids = Factory::getApplication()->getSession()->get(self::READ_SESSION_KEY, []);

        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    // Rendering

    /**
     * Render one group (a grid or a slider of cards) through the module's group
     * layout, template overrides included. Used by the page render and by the
     * on-demand tab fetch alike, so both produce the same markup. A tab with its
     * own card look hands it to its cards here.
     *
     * @param object $module The module instance (id, params, layout).
     * @param Registry $params Module parameters.
     * @param array $group A group as getGroups() builds it, with its articles.
     *
     *
     * @since  1.0.0
     */
    public static function renderGroup(object $module, Registry $params, array $group): string
    {
        $path = self::layoutPath($params, '_group');

        if ($path === '') {
            return '';
        }

        $moduleId = (int) $module->id;
        $display = self::displayOptions($params);
        // The tab: the module's settings, then its own - a grid or a slider, the slider's
        // settings on every device, and its cards, named after their place.
        $resolved = CardLook::resolve(levels: [$display['level'], $group['look'] ?? []], breakpoints: self::BREAKPOINTS);
        $display['mode'] = $resolved['']['display'];
        $display['bands'] = CardLook::bands($resolved, self::BREAKPOINTS);
        $display['card'] = CardLook::shown($resolved) + ['place' => $moduleId . '-' . $group['key']];

        ob_start();

        try {
            include $path;

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Render one article card through the module's `_card` layout.
     *
     * @param object $article An article from getItems().
     * @param Registry $params Module parameters (the layout choice).
     * @param array $card The card of its place (card()).
     * @param bool $eager Load the image with the page: the first card of the page's first group.
     *
     *
     * @since  1.0.0
     */
    public static function renderCard(object $article, Registry $params, array $card, bool $eager = false): string
    {
        $path = self::layoutPath($params, '_card');

        if ($path === '') {
            return '';
        }

        $view = self::cardData($article, $card);

        ob_start();

        try {
            include $path;

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * What a card shows, prepared for the layout: link, image with its intrinsic
     * size, date, category, author, a plain-text teaser and the data attributes
     * of its look.
     *
     * The image source is made root-relative: a tab fetched on demand is JSON, which
     * the SEF plugin never rewrites, so a relative path would break on nested URLs.
     *
     * @param object $article An article from getItems().
     * @param array $card Card options.
     *
     * @return array{title: string, link: string, image: array{src: string, alt: string, width: int, height: int}|null, date: array{iso: string, text: string}|null, category: string, author: string, intro: string, attributes: string}
     *
     * @since  1.0.0
     */
    public static function cardData(object $article, array $card): array
    {
        $image = null;

        if ($card['image'] !== 'none') {
            $images = json_decode((string) ($article->images ?? ''), true) ?: [];
            $keys = $card['image'] === 'full' ? ['image_fulltext'] : ['image_intro', 'image_fulltext'];

            foreach ($keys as $key) {
                $src = trim((string) ($images[$key] ?? ''));

                if ($src === '') {
                    continue;
                }

                $clean = HTMLHelper::_('cleanImageURL', $src);
                $url = (string) $clean->url;

                if (!preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $url)) {
                    $url = Uri::root(true) . '/' . $url;
                }

                $image = [
                    'src' => $url,
                    // The title beside it names the card; an image without its own text is decorative.
                    'alt' => trim((string) ($images[$key . '_alt'] ?? '')),
                    'width' => (int) $clean->attributes['width'],
                    'height' => (int) $clean->attributes['height'],
                ];

                break;
            }
        }

        $intro = '';

        if ($card['showIntro'] && $card['intro'] > 0) {
            // Plain text: no markup to break when cut, no plugin tags left behind.
            $text = preg_replace('/\{[a-z][^{}]*\}/i', '', strip_tags((string) ($article->introtext ?? ''))) ?? '';
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            $intro = $text !== '' ? (string) HTMLHelper::_('string.truncate', $text, $card['intro'], true, false) : '';
        }

        $published = (string) ($article->publish_up ?? '');

        return [
            'title' => (string) $article->title,
            'link' => Route::_(RouteHelper::getArticleRoute($article->id . ':' . $article->alias, (int) $article->catid, $article->language)),
            'image' => $image,
            'date' => $card['date'] && $published !== ''
                ? ['iso' => HTMLHelper::_('date', $published, 'c'), 'text' => HTMLHelper::_('date', $published, Text::_('DATE_FORMAT_LC3'))]
                : null,
            'category' => $card['category'] ? (string) ($article->category_title ?? '') : '',
            'author' => $card['author'] ? (string) ($article->author ?? '') : '',
            'intro' => $intro,
            'attributes' => ($card['place'] ?? '') !== '' ? ' data-look="' . htmlspecialchars((string) $card['place'], ENT_QUOTES, 'UTF-8') . '"' : '',
        ];
    }

    /**
     * The module's own settings (CardLook), for every screen and per breakpoint: how a
     * tab shows its cards (a grid or a slider, its columns or cards per view, its gap)
     * and how the cards look. A tab lays its own over them.
     *
     * @param Registry $params Module parameters.
     *
     * @return array{level: array{base: array, bp: array}}
     *
     * @since  1.0.0
     */
    public static function displayOptions(Registry $params): array
    {
        return ['level' => CardLook::level($params->toArray())];
    }

    /**
     * The card rules of a module instance (CardLook::css): one place per tab, fetched
     * yet or not, so a tab opened later finds its rules in the page.
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters.
     * @param array $groups The module's groups (getGroups()).
     *
     * @return string CSS.
     *
     * @since  1.0.0
     */
    public static function lookCss(int $moduleId, Registry $params, array $groups): string
    {
        $level = self::displayOptions($params)['level'];
        $places = [];

        foreach ($groups as $group) {
            $places[$moduleId . '-' . $group['key']] = CardLook::resolve(levels: [$level, $group['look'] ?? []], breakpoints: self::BREAKPOINTS);
        }

        return CardLook::css(places: $places, breakpoints: self::BREAKPOINTS);
    }

    /**
     * The per-instance CSS of the grids: every tab's gap and columns on wide screens,
     * then what changes at the module's two widths (per tab, since a tab may set its own).
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters.
     * @param array $groups The module's groups (getGroups()).
     *
     * @return string CSS.
     *
     * @since  1.0.0
     */
    public static function responsiveCss(int $moduleId, Registry $params, array $groups): string
    {
        $level = self::displayOptions($params)['level'];
        $rules = [];

        foreach ($groups as $group) {
            $selector = '.mod-easy-articles-' . $moduleId . ' [data-panel="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $group['key']) . '"]';
            $before = [];

            foreach (CardLook::resolve(levels: [$level, $group['look'] ?? []], breakpoints: self::BREAKPOINTS) as $device => $there) {
                $now = ['--mod-easy-articles-gap' => (int) $there['gap'] . 'px', '--mod-easy-articles-columns' => (string) (int) $there['columns']];
                $rule = '';

                foreach (array_diff_assoc($now, $before) as $property => $value) {
                    $rule .= $property . ':' . $value . ';';
                }

                // Tabs that say the same share the rule.
                if ($rule !== '') {
                    $rules[$device][rtrim($rule, ';')][] = $selector;
                }

                $before = $now;
            }
        }

        $out = '';

        foreach (array_merge([['key' => '', 'max' => 0]], self::BREAKPOINTS) as $breakpoint) {
            $css = '';

            foreach ($rules[$breakpoint['key']] ?? [] as $body => $selectors) {
                $css .= implode(',', $selectors) . '{' . $body . '}';
            }

            if ($css !== '') {
                $out .= (int) $breakpoint['max'] > 0 ? '@media (max-width:' . (int) $breakpoint['max'] . 'px){' . $css . '}' : $css;
            }
        }

        return $out;
    }

    /**
     * Whether any tab is a slider: decides whether the slider library is
     * loaded with the page. A tab still to be fetched counts, since it will be.
     *
     * @param Registry $params Module parameters.
     *
     *
     * @since  1.0.0
     */
    public static function usesSlider(Registry $params): bool
    {
        $level = self::displayOptions($params)['level'];

        foreach (self::tabRows($params) as $row) {
            if (CardLook::resolve(levels: [$level, $row['look']], breakpoints: [])['']['display'] === 'slider') {
                return true;
            }
        }

        return false;
    }

    /**
     * The on-demand tab endpoint for a module instance; the script appends the group key.
     *
     * @param int $moduleId Module instance id.
     *
     * @return string A rooted, SEF-aware URL.
     *
     * @since  1.0.0
     */
    public static function lazyUrl(int $moduleId): string
    {
        return Route::_(
            'index.php?option=com_ajax&module=easy_articles&method=getTab&format=json&module_id=' . $moduleId,
            false,
        );
    }

    /**
     * A layout file of this module for the instance's chosen layout, template
     * overrides first; resolved once per request.
     *
     * @param Registry $params Module parameters.
     * @param string $suffix The sub-layout suffix, e.g. `_card`.
     *
     * @return string The path, or '' when no such layout exists.
     *
     * @since  1.0.0
     */
    private static function layoutPath(Registry $params, string $suffix): string
    {
        $name = (string) $params->get('layout', 'default') . $suffix;

        if (!isset(self::$layoutPaths[$name])) {
            $path = ModuleHelper::getLayoutPath('mod_easy_articles', $name);
            self::$layoutPaths[$name] = is_file($path) ? $path : '';
        }

        return self::$layoutPaths[$name];
    }

    // On-demand tab (com_ajax)

    /**
     * AJAX endpoint: render one group of a published module instance and return
     * it as JSON. The instance is resolved by id and gated by its access level,
     * so a tab can only ever show what the page itself would.
     *
     *
     * @since  1.0.0
     */
    public function getTabAjax(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        header('Content-Type: application/json');

        $app->getLanguage()->load('mod_easy_articles', JPATH_SITE);

        $moduleId = $input->getInt('module_id', 0);
        $key = $input->getCmd('tab', '');
        $module = self::loadModule($moduleId);

        if (!$module || $key === '') {
            echo new JsonResponse(null, Text::_('MOD_EASY_ARTICLES_ERROR_NOT_FOUND'), true);
            $app->close();
        }

        $params = new Registry($module->params);
        $groups = self::getGroups(params: $params, moduleId: $moduleId, onlyKey: $key);
        $group = $groups[0] ?? ['key' => $key, 'label' => '', 'items' => [], 'lazy' => false, 'first' => false, 'link' => '', 'look' => []];

        echo new JsonResponse(['html' => self::renderGroup(module: $module, params: $params, group: $group)]);

        $app->close();
    }

    /**
     * A published site instance of this module the current visitor may see.
     *
     * @param int $moduleId Module instance id.
     *
     * @return object|null id, module, params, or null.
     *
     * @since  1.0.0
     */
    private static function loadModule(int $moduleId): ?object
    {
        if ($moduleId <= 0) {
            return null;
        }

        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $levels = array_map('intval', (array) $app->getIdentity()->getAuthorisedViewLevels());
        $now = Factory::getDate()->toSql();

        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'module', 'params']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_easy_articles'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('client_id') . ' = 0')
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= :up)')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' >= :down)')
            ->bind(':id', $moduleId, ParameterType::INTEGER)
            ->bind(':up', $now, ParameterType::STRING)
            ->bind(':down', $now, ParameterType::STRING);

        if ($levels !== []) {
            $query->whereIn($db->quoteName('access'), $levels);
        }

        return $db->setQuery($query)->loadObject() ?: null;
    }

    // Parameter readers

    /**
     * A row's source, defaulting to plain articles.
     *
     * @param string $source The stored value.
     *
     * @return string One of the SOURCE_* constants.
     *
     * @since  1.0.0
     */
    private static function source(string $source): string
    {
        return \in_array($source, [
            self::SOURCE_ARTICLES,
            self::SOURCE_FEATURED,
            self::SOURCE_MOST_READ,
            self::SOURCE_RECENTLY_READ,
        ], true) ? $source : self::SOURCE_ARTICLES;
    }

    /**
     * The tab rows, normalised: label, source, category ids, ordering, size,
     * its link and its own card
     * look (none while it follows the module). Row order is tab order.
     *
     * @param Registry $params Module parameters.
     *
     * @return array<int, array{label: string, source: string, categories: int[], limit: int, ordering: string, link: bool, look: array{base: array, bp: array}}>
     *
     * @since  1.0.0
     */
    private static function tabRows(Registry $params): array
    {
        $rows = [];

        foreach ((array) $params->get('tabs', []) as $row) {
            $row = (array) $row;
            $ordering = (string) ($row['ordering'] ?? '');

            $rows[] = [
                'label' => trim((string) ($row['label'] ?? '')),
                'source' => self::source((string) ($row['source'] ?? '')),
                'categories' => array_values(array_unique(array_filter(array_map('intval', (array) ($row['categories'] ?? []))))),
                'limit' => self::bounded($row['limit'] ?? self::DEFAULT_LIMIT, 1, self::LIMIT_MAX),
                'ordering' => isset(self::ORDERINGS[$ordering]) ? $ordering : self::DEFAULT_ORDERING,
                'link' => (bool) ($row['show_link'] ?? false),
                'look' => CardLook::level($row),
            ];
        }

        return $rows;
    }

    /**
     * A category's title, or null when this visitor may not see it
     * (unpublished, or outside their access levels).
     *
     * @param int $categoryId Category id.
     *
     *
     * @since  1.0.0
     */
    private static function categoryName(int $categoryId): ?string
    {
        $category = Categories::getInstance('Content')->get($categoryId);

        return $category ? (string) $category->title : null;
    }

    /**
     * An integer parameter clamped to a range.
     *
     * @param mixed $value The stored value.
     * @param int $min Lower bound.
     * @param int $max Upper bound.
     *
     *
     * @since  1.0.0
     */
    private static function bounded(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
