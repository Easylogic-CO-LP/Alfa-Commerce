<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use JsonSerializable;
use Throwable;
use ValueError;

\defined('_JEXEC') or die;

/**
 * The text of a notification as a language key and its parameters, translated when it is shown (2.1.4).
 *
 * The key, its parameters and the text domain (the extension whose language files hold the key) are the notification.
 * The row's title and message keep a snapshot: the text rendered in the shop's default administrator language when the
 * notification is written, never rendered again. It is shown only when the reader's language can no longer resolve the
 * key (its extension is gone) and it is what search reads besides the parameters.
 *
 * A parameter is a literal (an order id, a formatted quantity, a provider's message), or one of:
 *   - {@see self::key()}: a nested key, translated too;
 *   - {@see self::plural()}: a key chosen by a count, as Text::plural() chooses it;
 *   - {@see self::number()}: a number grouped as the reader's language groups it;
 *   - {@see self::content()}: content frozen in every content language (a product name), shown in the reader's content
 *     language, else the shop's default one;
 *   - {@see self::join()}: items joined, optionally with a word before the last ("a, b and c").
 *
 *   NotificationHelper::push('stock-low:item:7', NotificationText::of('COM_ALFA_NOTIFY_LOW_STOCK_TITLE', $name), [...]);
 *
 * @since  2.1.4
 */
final class NotificationText implements JsonSerializable
{
    /**
     * The text domain of core's notifications.
     *
     * @since  2.1.4
     */
    public const DOMAIN = 'com_alfa';

    /**
     * The key of a message made of sentences: "%s", its one parameter the sentences joined ({@see self::sentences()}).
     *
     * @since  2.1.4
     */
    public const SENTENCES = 'COM_ALFA_NOTIFY_JOIN';

    /**
     * How deep parameters may nest.
     *
     * @since  2.1.4
     */
    private const DEPTH = 4;

    /**
     * The languages made for rendering, by tag, each with the domains loaded into it.
     *
     * @var array<string, array{language: Language, domains: array<string, bool>}>
     *
     * @since  2.1.4
     */
    private static array $languages = [];

    /**
     * The shop's default administrator language, once read ({@see self::defaultAdministratorLanguage()}).
     *
     * @since  2.1.4
     */
    private static ?string $administrator = null;

    /**
     * A text: a key of a domain and its parameters.
     *
     * @param string $key The language key.
     * @param list<mixed> $args Its parameters, in order.
     * @param string $domain The extension whose language files hold the key ({@see self::validDomain()}).
     *
     * @throws InvalidArgumentException When the domain is not an extension's name.
     *
     * @since  2.1.4
     */
    public function __construct(
        public readonly string $key,
        public readonly array $args = [],
        public readonly string $domain = self::DOMAIN,
    ) {
        if (!self::validDomain(domain: $domain)) {
            throw new InvalidArgumentException('A notification\'s text domain is an extension\'s name (a to z, 0 to 9, _ . -); got "' . $domain . '".');
        }
    }

    /**
     * Whether a text domain is an extension's name, so its language files are looked for under the language folders
     * and nowhere else: lower-case letters, digits, "_", "." and "-", never "..". A stored domain that is not is never
     * loaded, and its row shows its snapshot.
     *
     * @param string $domain The domain.
     *
     * @since  2.1.4
     */
    public static function validDomain(string $domain): bool
    {
        return preg_match('/^[a-z0-9_.-]+$/', $domain) === 1 && !str_contains($domain, '..');
    }

    /**
     * A language of its own for a tag: its own files and its own administrator override only, with its own joomla.ini
     * loaded (the language's number separators, which grouped numbers read). Joomla's Language also loads the default
     * language's override into every other language, keeps the override of the request's own application (site or
     * administrator) and, from Joomla 6.2, fills a language's gaps from its fallback languages; any of these would put
     * another language into a snapshot.
     *
     * @param string $tag The language tag.
     *
     * @since  2.1.4
     */
    public static function own(string $tag): Language
    {
        return new class ($tag) extends Language {
            /**
             * The language, with nothing loaded but its own administrator override.
             *
             * @param string $tag The language tag.
             *
             * @since  2.1.4
             */
            public function __construct(string $tag)
            {
                parent::__construct($tag, false);
                $this->strings = [];
                $this->override = $this->parse(JPATH_ADMINISTRATOR . '/language/overrides/' . $tag . '.override.ini');
                // Reloaded: the constructor already read JPATH_BASE's joomla.ini (the administrator's own in an
                // administrator request) and the strings were dropped above, so a plain load would skip it.
                $this->load(extension: 'joomla', basePath: JPATH_ADMINISTRATOR, lang: $tag, reload: true, default: false);
            }

            /**
             * No fallback languages: a text this language lacks is not said in another.
             *
             * @param string $lang The language tag.
             *
             * @return list<string>
             *
             * @since  2.1.4
             */
            protected function getFallbackChain($lang)
            {
                return [];
            }
        };
    }

    /**
     * A core text.
     *
     * @param string $key The language key (com_alfa).
     * @param mixed ...$args Its parameters.
     *
     * @since  2.1.4
     */
    public static function of(string $key, mixed ...$args): self
    {
        return new self(key: $key, args: array_values($args), domain: self::DOMAIN);
    }

    /**
     * A text of another extension's language files.
     *
     * @param string $domain The extension, e.g. plg_alfa_subscriptions or com_alfa.sys.
     * @param string $key The language key.
     * @param mixed ...$args Its parameters.
     *
     * @since  2.1.4
     */
    public static function from(string $domain, string $key, mixed ...$args): self
    {
        return new self(key: $key, args: array_values($args), domain: $domain);
    }

    /**
     * A message made of sentences, each a nested key, a literal or another parameter, joined by a space.
     *
     * @param mixed ...$sentences The sentences, in order.
     *
     * @since  2.1.4
     */
    public static function sentences(mixed ...$sentences): self
    {
        return self::of(self::SENTENCES, self::join(items: array_values($sentences), separator: ' '));
    }

    /**
     * A nested key as a parameter.
     *
     * @param string $key The language key (of the text's domain, or com_alfa's).
     * @param mixed ...$args Its parameters.
     *
     * @return array{k: string, a: list<mixed>}
     *
     * @since  2.1.4
     */
    public static function key(string $key, mixed ...$args): array
    {
        return ['k' => $key, 'a' => array_values($args)];
    }

    /**
     * A key chosen by a count as a parameter: KEY_<suffix> for the language's plural suffix of the count, else KEY;
     * the count is its first value and the arguments follow it.
     *
     * @param string $key The language key.
     * @param int $count The count.
     * @param mixed ...$args The values after the count.
     *
     * @return array{p: string, n: int, a: list<mixed>}
     *
     * @since  2.1.4
     */
    public static function plural(string $key, int $count, mixed ...$args): array
    {
        return ['p' => $key, 'n' => $count, 'a' => array_values($args)];
    }

    /**
     * A number as a parameter, grouped and with the decimal separator of the reader's language.
     *
     * @param int|float $number The number.
     * @param int $decimals Its decimals.
     *
     * @return array{num: int|float, d: int}
     *
     * @since  2.1.4
     */
    public static function number(int|float $number, int $decimals = 0): array
    {
        return ['num' => $number, 'd' => $decimals];
    }

    /**
     * Content in every content language as a parameter (a product name, as an order line freezes it).
     *
     * @param array<string, string> $byLanguage Lower-snake content language => text, e.g. ['en_gb' => 'Shirt'].
     *
     * @return array{ml: array<string, string>}
     *
     * @since  2.1.4
     */
    public static function content(array $byLanguage): array
    {
        $texts = [];

        foreach ($byLanguage as $tag => $text) {
            if ((string) $text !== '') {
                $texts[MultilingualHelper::normaliseTag(tag: (string) $tag)] = (string) $text;
            }
        }

        return ['ml' => $texts];
    }

    /**
     * Items joined as a parameter.
     *
     * @param list<mixed> $items The items, each a parameter of its own.
     * @param string $separator Between two items.
     * @param string|null $and A key said before the last item ("and"), or null to use the separator there too.
     *
     * @return array{list: list<mixed>, sep: string, and?: string}
     *
     * @since  2.1.4
     */
    public static function join(array $items, string $separator = ' ', ?string $and = null): array
    {
        return ['list' => array_values($items), 'sep' => $separator] + ($and !== null ? ['and' => $and] : []);
    }

    /**
     * The text as the journal and the event keep it.
     *
     * @return array{key: string, args: list<mixed>, domain: string}
     *
     * @since  2.1.4
     */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'args' => $this->args, 'domain' => $this->domain];
    }

    /**
     * A title or a message as the notice journal keeps it: a text as {nt: ...}, literal text as it is.
     *
     * @param mixed $text A text, or literal text.
     *
     * @since  2.1.4
     */
    public static function pack(mixed $text): mixed
    {
        return $text instanceof self ? ['nt' => $text->jsonSerialize()] : (string) ($text ?? '');
    }

    /**
     * A title or a message from the notice journal: what {@see self::pack()} kept.
     *
     * @param mixed $packed What it kept.
     *
     * @since  2.1.4
     */
    public static function unpack(mixed $packed): self|string
    {
        if (\is_array($packed) && \is_array($packed['nt'] ?? null)) {
            $text = $packed['nt'];

            return new self(
                key: (string) ($text['key'] ?? ''),
                args: array_values((array) ($text['args'] ?? [])),
                domain: (string) ($text['domain'] ?? self::DOMAIN),
            );
        }

        return \is_string($packed) ? $packed : '';
    }

    /**
     * The columns a notification's title and message are written as: the keys, the domain, the parameters, and the
     * snapshot in the shop's default administrator language.
     *
     * @param self|string $title The title.
     * @param self|string $message The message; '' for none.
     *
     * @return array{text_domain: string, title: string, title_key: string, message: string, message_key: string, text_args: string|null}
     *
     * @throws InvalidArgumentException When the title and the message are keys of two domains.
     *
     * @since  2.1.4
     */
    public static function columns(self|string $title, self|string $message): array
    {
        $domains = array_unique(array_map(
            static fn (self $text): string => $text->domain,
            array_filter([$title, $message], static fn (mixed $text): bool => $text instanceof self),
        ));

        if (\count($domains) > 1) {
            throw new InvalidArgumentException('A notification\'s title and message are keys of one domain; got ' . implode(' and ', $domains) . '.');
        }

        $args = [];

        if ($title instanceof self) {
            $args['title'] = $title->args;
        }

        if ($message instanceof self) {
            $args['message'] = $message->args;
        }

        return [
            'text_domain' => (string) (reset($domains) ?: ''),
            'title' => $title instanceof self ? self::snapshot(text: $title) : $title,
            'title_key' => $title instanceof self ? $title->key : '',
            'message' => $message instanceof self ? self::snapshot(text: $message) : $message,
            'message_key' => $message instanceof self ? $message->key : '',
            'text_args' => $args === [] ? null : json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    /**
     * The text in the shop's default administrator language: the snapshot a notification keeps. In English when that
     * language cannot say it (its pack is not installed), and as its key and literals when no language can.
     *
     * @param self $text The text.
     *
     * @since  2.1.4
     */
    public static function snapshot(self $text): string
    {
        foreach (array_unique([self::defaultAdministratorLanguage(), 'en-GB']) as $tag) {
            $language = self::language(tag: $tag, domain: $text->domain);
            $said = $language === null ? null : $text->renderIn(language: $language, contentTag: MultilingualHelper::getDefaultLanguageTag());

            if ($said !== null) {
                return $said;
            }
        }

        return trim($text->key . ' ' . implode(' ', array_filter($text->args, 'is_scalar')));
    }

    /**
     * The text in a language, or null when the language cannot say it: a key it does not hold, or parameters the key
     * does not take.
     *
     * @param Language $language The language, with the text's domain loaded.
     * @param string $contentTag The reader's content language, lower-snake: where content parameters are read.
     *
     * @since  2.1.4
     */
    public function renderIn(Language $language, string $contentTag): ?string
    {
        return self::say(language: $language, key: $this->key, args: $this->args, contentTag: $contentTag, depth: 0);
    }

    /**
     * A notification's title for the reader: its key in the reader's language, else its snapshot; a row with no key
     * shows its text as written.
     *
     * @param object $row The notification row (title, title_key, text_domain, text_args).
     *
     * @since  2.1.4
     */
    public static function title(object $row): string
    {
        return self::field(row: $row, field: 'title');
    }

    /**
     * A notification's message for the reader, as {@see self::title()}.
     *
     * @param object $row The notification row (message, message_key, text_domain, text_args).
     *
     * @since  2.1.4
     */
    public static function message(object $row): string
    {
        return self::field(row: $row, field: 'message');
    }

    /**
     * A notification's group for the reader: the text of its key when it is a key, else the group as stored.
     *
     * @param object|string $group The notification row (notify_group, text_domain), or the group itself.
     *
     * @since  2.1.4
     */
    public static function group(object|string $group): string
    {
        $stored = \is_string($group) ? $group : (string) ($group->notify_group ?? '');
        $domain = \is_string($group) ? self::DOMAIN : (string) ($group->text_domain ?? '');

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $stored) !== 1) {
            return $stored;
        }

        $language = self::reader(domain: $domain !== '' ? $domain : self::DOMAIN);

        return $language !== null && $language->hasKey($stored) ? $language->_($stored) : $stored;
    }

    /**
     * The parameters a row keeps: {title: [...], message: [...]}, empty for literal text.
     *
     * @param object $row The notification row.
     *
     * @return array<string, list<mixed>>
     *
     * @since  2.1.4
     */
    public static function arguments(object $row): array
    {
        $args = json_decode((string) ($row->text_args ?? ''), true);

        return \is_array($args) ? array_map(static fn (mixed $list): array => array_values((array) $list), $args) : [];
    }

    /**
     * The shop's default administrator language, read once per request ({@see MultilingualHelper::defaultAdministratorLanguage()}).
     *
     * @since  2.1.4
     */
    public static function defaultAdministratorLanguage(): string
    {
        return self::$administrator ??= MultilingualHelper::defaultAdministratorLanguage();
    }

    /**
     * Whether a key is in English, the language every key of core is shipped in.
     *
     * @param string $key The key.
     * @param string $domain Its domain.
     *
     * @since  2.1.4
     */
    public static function exists(string $key, string $domain = self::DOMAIN): bool
    {
        return self::language(tag: 'en-GB', domain: $domain)?->hasKey($key) ?? false;
    }

    /**
     * Forget the languages made so far (tests that install a language pack in the middle of a run).
     *
     * @since  2.1.4
     */
    public static function reset(): void
    {
        self::$languages = [];
        self::$administrator = null;
    }

    /**
     * One field of a row for the reader.
     *
     * @param object $row The row.
     * @param string $field title or message.
     *
     * @since  2.1.4
     */
    private static function field(object $row, string $field): string
    {
        $stored = (string) ($row->{$field} ?? '');
        $key = (string) ($row->{$field . '_key'} ?? '');

        if ($key === '') {
            return $stored;
        }

        $domain = (string) ($row->text_domain ?? '');
        $language = self::reader(domain: $domain !== '' ? $domain : self::DOMAIN);

        if ($language === null) {
            return $stored;
        }

        $said = self::say(
            language: $language,
            key: $key,
            args: self::arguments(row: $row)[$field] ?? [],
            contentTag: MultilingualHelper::normaliseTag(tag: (string) $language->getTag()),
            depth: 0,
        );

        return $said ?? $stored;
    }

    /**
     * The reader's language with a domain and core's own texts loaded, once per request; null with no language, or
     * for a domain that is not an extension's name ({@see self::validDomain()}).
     *
     * @param string $domain The domain.
     *
     * @since  2.1.4
     */
    private static function reader(string $domain): ?Language
    {
        if (!self::validDomain(domain: $domain)) {
            return null;
        }

        try {
            $language = Factory::getApplication()->getLanguage();
        } catch (Throwable) {
            try {
                $language = Factory::getLanguage();
            } catch (Throwable) {
                return null;
            }
        }

        $tag = 'reader:' . spl_object_id($language);

        if (!isset(self::$languages[$tag])) {
            self::$languages[$tag] = ['language' => $language, 'domains' => []];
        }

        foreach (array_unique([self::DOMAIN, $domain]) as $name) {
            if (!isset(self::$languages[$tag]['domains'][$name])) {
                self::loadDomain(language: $language, domain: $name, tag: null);
                self::$languages[$tag]['domains'][$name] = true;
            }
        }

        return $language;
    }

    /**
     * A language of its own for a tag, with a domain and core's own texts loaded ({@see self::own()}): never the one a
     * request reads in (Joomla's container factory shares one per tag, with English loaded under it), so the text is
     * said from the language's own files; null for a domain that is not an extension's name.
     *
     * @param string $tag The language tag.
     * @param string $domain The domain.
     *
     * @since  2.1.4
     */
    private static function language(string $tag, string $domain): ?Language
    {
        if (!self::validDomain(domain: $domain)) {
            return null;
        }

        if (!isset(self::$languages[$tag])) {
            self::$languages[$tag] = [
                'language' => self::own(tag: $tag),
                'domains' => [],
            ];
        }

        foreach (array_unique([self::DOMAIN, $domain]) as $name) {
            if (!isset(self::$languages[$tag]['domains'][$name])) {
                // The language's own file only: a text it lacks is said in English as a whole, never mixed.
                self::loadDomain(language: self::$languages[$tag]['language'], domain: $name, tag: $tag);
                self::$languages[$tag]['domains'][$name] = true;
            }
        }

        return self::$languages[$tag]['language'];
    }

    /**
     * Load a domain's file into a language the way Joomla loads an extension's strings for its action logs: from the
     * administrator language folder, else from the extension's own folder (a plugin that keeps its language files with
     * it). With a tag, that language's own file only; without one, as the request loads it.
     *
     * @param Language $language The language.
     * @param string $domain The domain, an extension's name ({@see self::validDomain()}).
     * @param string|null $tag The language's tag, for a language of its own; null for the reader's.
     *
     * @since  2.1.4
     */
    private static function loadDomain(Language $language, string $domain, ?string $tag): void
    {
        $load = static fn (string $basePath): bool => $tag === null
            ? $language->load(extension: $domain, basePath: $basePath)
            : $language->load(extension: $domain, basePath: $basePath, lang: $tag, reload: false, default: false);
        $source = self::sourceOf(domain: $domain);

        if (!$load(JPATH_ADMINISTRATOR) && $source !== null) {
            $load($source);
        }
    }

    /**
     * An extension's own folder, where its language files may live, by the prefix of its name, as Joomla's action log
     * finds it: a component's administrator folder, a library's, a site module's, a plugin's (plg_<group>_<element>), a
     * template's; null when the name says none.
     *
     * @param string $domain The domain; a ".sys" domain is its extension's.
     *
     * @since  2.1.4
     */
    private static function sourceOf(string $domain): ?string
    {
        $extension = (string) preg_replace('/\.sys$/', '', $domain);

        switch (substr($extension, 0, 4)) {
            case 'lib_':
                return JPATH_LIBRARIES . '/' . substr($extension, 4);
            case 'mod_':
                return JPATH_SITE . '/modules/' . $extension;
            case 'plg_':
                $parts = explode('_', $extension, 3);

                return \count($parts) > 2 && $parts[1] !== '' && $parts[2] !== '' ? JPATH_PLUGINS . '/' . $parts[1] . '/' . $parts[2] : null;
            case 'pkg_':
                return JPATH_SITE;
            case 'tpl_':
                return JPATH_BASE . '/templates/' . substr($extension, 4);
            default:
                return JPATH_ADMINISTRATOR . '/components/' . $extension;
        }
    }

    /**
     * A key and its parameters in a language, or null when it cannot be said.
     *
     * @param Language $language The language.
     * @param string $key The key.
     * @param array<mixed> $args The parameters.
     * @param string $contentTag The reader's content language, lower-snake.
     * @param int $depth How deep in the text this is.
     *
     * @since  2.1.4
     */
    private static function say(Language $language, string $key, array $args, string $contentTag, int $depth): ?string
    {
        if ($depth > self::DEPTH || $key === '' || !$language->hasKey($key)) {
            return null;
        }

        $values = [];

        foreach (array_values($args) as $arg) {
            $value = self::value(language: $language, arg: $arg, contentTag: $contentTag, depth: $depth + 1);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        try {
            return vsprintf($language->_($key), $values);
        } catch (ValueError) {
            return null;
        }
    }

    /**
     * One parameter in a language, or null when it cannot be said.
     *
     * @param Language $language The language.
     * @param mixed $arg The parameter.
     * @param string $contentTag The reader's content language, lower-snake.
     * @param int $depth How deep in the text this is.
     *
     * @since  2.1.4
     */
    private static function value(Language $language, mixed $arg, string $contentTag, int $depth): string|int|float|null
    {
        if ($depth > self::DEPTH) {
            return null;
        }

        if ($arg === null || \is_bool($arg)) {
            return (int) $arg;
        }

        if (\is_string($arg) || \is_int($arg) || \is_float($arg)) {
            return $arg;
        }

        if (!\is_array($arg)) {
            return null;
        }

        if (isset($arg['k'])) {
            return self::say(language: $language, key: (string) $arg['k'], args: (array) ($arg['a'] ?? []), contentTag: $contentTag, depth: $depth);
        }

        if (isset($arg['p'])) {
            $count = (int) ($arg['n'] ?? 0);
            $key = (string) $arg['p'];

            foreach ([$count, ...$language->getPluralSuffixes($count)] as $suffix) {
                if ($language->hasKey($key . '_' . $suffix)) {
                    $key .= '_' . $suffix;

                    break;
                }
            }

            return self::say(language: $language, key: $key, args: [$count, ...(array) ($arg['a'] ?? [])], contentTag: $contentTag, depth: $depth);
        }

        if (isset($arg['num'])) {
            $decimal = $language->hasKey('DECIMALS_SEPARATOR') ? $language->_('DECIMALS_SEPARATOR') : '.';
            $thousands = $language->hasKey('THOUSANDS_SEPARATOR') ? $language->_('THOUSANDS_SEPARATOR') : ',';

            return number_format((float) $arg['num'], max(0, (int) ($arg['d'] ?? 0)), $decimal, $thousands);
        }

        if (isset($arg['ml']) && \is_array($arg['ml'])) {
            $texts = array_map('strval', $arg['ml']);

            return $texts[$contentTag] ?? $texts[MultilingualHelper::getDefaultLanguageTag()] ?? (string) (reset($texts) ?: '');
        }

        if (isset($arg['list']) && \is_array($arg['list'])) {
            $items = [];

            foreach ($arg['list'] as $item) {
                $value = self::value(language: $language, arg: $item, contentTag: $contentTag, depth: $depth + 1);

                if ($value === null) {
                    return null;
                }

                $items[] = (string) $value;
            }

            $separator = (string) ($arg['sep'] ?? ' ');
            $and = isset($arg['and']) ? (string) $arg['and'] : '';

            if ($and === '' || \count($items) < 2) {
                return implode($separator, $items);
            }

            if (!$language->hasKey($and)) {
                return null;
            }

            $last = array_pop($items);

            return implode($separator, $items) . ' ' . $language->_($and) . ' ' . $last;
        }

        return null;
    }
}
