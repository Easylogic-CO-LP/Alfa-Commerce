<?php

/**
 * Docblock lint: the docblocks a plugin author reads stay true to the code they describe.
 *
 * The repository's copy, run by the Copy-clean job of .github/workflows/code-quality.yml over the checked-out tree.
 * Reads the PHP of the component and its bundled plugins and modules (administrator/src, site/src, api/src, and every
 * src folder under plugins and modules), never libraries, tests, templates or layouts. Code is never judged, only
 * docblocks:
 *
 *   D1  no docblock directly followed by another docblock, in a file that declares a class, an interface, a trait or
 *       an enum: the first describes nothing (a docblock left behind when its method moved or went), and a reader
 *       takes it for the next element's;
 *   D2  a sample `targets('x')` names an entity some screen declares (a `$alfaEntity = 'x'` of the code);
 *   D3  a sample `Name::method(` whose Name is a class, interface or trait of this code calls a method that class or
 *       one of its parents in this code declares (a parent outside this code, Joomla's for example, is not judged);
 *   D4  a sample `$event->method(` in the docblocks of an event class calls a method the event or one of its parents
 *       in this code declares, with a number of arguments the method accepts.
 *
 * What stays on purpose is listed in docblock-lint-allow.json beside this file, one entry per file, rule and the
 * first words of the docblock or the sample, with its reason; an entry that matches nothing fails, so the list never
 * outlives its reason.
 *
 * Reads files only: no database, no Joomla application, no write. Runs in seconds.
 *
 *   php scripts/docblock-lint/docblock-lint.php [--root=<repository root>] [--allow=<file>]
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

/** The rules this lint enforces, as its docblock lists them. */
const DL_RULE_IDS = ['D1', 'D2', 'D3', 'D4'];

/** Findings printed before the count alone is given. */
const DL_PRINT_LIMIT = 60;

/**
 * The PHP files judged, relative to the root, sorted.
 *
 * @return list<string>
 */
function dl_files(string $root): array
{
    $files = [];

    foreach (['administrator/src', 'site/src', 'api/src', 'plugins', 'modules'] as $base) {
        if (!is_dir($root . '/' . $base)) {
            continue;
        }

        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $base, FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $file) {
            $rel = substr($file->getPathname(), \strlen($root) + 1);

            if (!$file->isFile() || $file->getExtension() !== 'php' || !str_contains('/' . $rel, '/src/')) {
                continue;
            }

            if (preg_match('#/(tests|tmpl|layouts|vendor)/#', '/' . $rel)) {
                continue;
            }

            $files[] = $rel;
        }
    }

    sort($files);

    return $files;
}

/**
 * What one file declares: its class-like names (short name => [fqcn, parent fqcn or null]), each one's methods with
 * their parameter counts, the traits it uses and its docblocks, where a docblock directly follows another, and the
 * entity names its code assigns to $alfaEntity.
 *
 * @return array{classes: array<string, array{fqcn: string, parent: ?string, event: bool, methods: array<string, array{0: int, 1: int, 2: bool}>, traits: list<string>, docs: list<array{0: string, 1: int}>}>, pairs: list<array{0: int, 1: string}>, entities: list<string>, declares: bool}
 */
function dl_read(string $src): array
{
    $tokens = token_get_all($src);
    $count = \count($tokens);
    $namespace = '';
    $uses = [];
    $classes = [];
    $pairs = [];
    $entities = [];
    $current = null;
    $depth = 0;
    $classDepth = null;
    $lastDoc = null;

    $next = static function (int $i) use ($tokens, $count): int {
        for ($j = $i + 1; $j < $count; $j++) {
            if (!\is_array($tokens[$j]) || !\in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
                return $j;
            }
        }

        return $count;
    };

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if (!\is_array($t)) {
            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth--;

                if ($classDepth !== null && $depth < $classDepth) {
                    $current = null;
                    $classDepth = null;
                }
            }

            continue;
        }

        [$id, $text, $line] = $t;

        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }

        if ($id === T_DOC_COMMENT) {
            // D1: the next token that is not plain whitespace is another docblock.
            $j = $i + 1;

            while ($j < $count && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if ($j < $count && \is_array($tokens[$j]) && $tokens[$j][0] === T_DOC_COMMENT) {
                $pairs[] = [$tokens[$j][2], dl_first_words($text)];
            }

            if ($current !== null) {
                $classes[$current]['docs'][] = [$text, $line];
            } else {
                $lastDoc = [$text, $line];
            }

            continue;
        }

        if ($id === T_NAMESPACE) {
            $j = $next($i);
            $namespace = '';

            while ($j < $count && \is_array($tokens[$j]) && \in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                $namespace .= $tokens[$j][1];
                $j++;
            }

            continue;
        }

        if ($id === T_USE && $current !== null && $depth === $classDepth) {
            // A trait the class uses: its methods are the class's.
            for ($j = $next($i); $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    $classes[$current]['traits'][] = dl_resolve($tokens[$j][1], $namespace, $uses);
                }
            }

            continue;
        }

        if ($id === T_USE && $current === null && $depth === 0) {
            $j = $next($i);

            if ($j < $count && \is_array($tokens[$j]) && \in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($tokens[$j][1], '\\');
                $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
                $k = $next($j);

                if ($k < $count && \is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                    $alias = $tokens[$next($k)][1];
                }

                $uses[$alias] = $name;
            }

            continue;
        }

        if (\in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $prev = $i - 1;

            while ($prev >= 0 && \is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }

            if ($prev >= 0 && \is_array($tokens[$prev]) && \in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $j = $next($i);

            if ($j >= $count || !\is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }

            $short = $tokens[$j][1];
            $parent = null;
            $k = $next($j);

            if ($k < $count && \is_array($tokens[$k]) && $tokens[$k][0] === T_EXTENDS) {
                $p = $next($k);
                $parent = dl_resolve($tokens[$p][1], $namespace, $uses);
            }

            $current = $short;
            $classes[$short] = [
                'fqcn' => ltrim($namespace . '\\' . $short, '\\'),
                'parent' => $parent,
                'event' => str_ends_with($short, 'Event'),
                'methods' => [],
                'traits' => [],
                'docs' => $lastDoc !== null ? [$lastDoc] : [],
            ];
            $lastDoc = null;

            // The class body opens at the next '{'.
            for ($b = $j; $b < $count; $b++) {
                if ($tokens[$b] === '{') {
                    $classDepth = $depth + 1;
                    $depth++;
                    $i = $b;

                    break;
                }
            }

            continue;
        }

        if ($id === T_FUNCTION && $current !== null && $depth === $classDepth) {
            $j = $next($i);

            if ($j < $count && $tokens[$j] === '&') {
                $j = $next($j);
            }

            if ($j < $count && \is_array($tokens[$j])) {
                $classes[$current]['methods'][strtolower($tokens[$j][1])] = dl_params($tokens, $j + 1);
            }

            continue;
        }

        if ($id === T_VARIABLE && $text === '$alfaEntity') {
            for ($j = $i + 1; $j < $count && $j < $i + 8; $j++) {
                if (\is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $entities[] = trim($tokens[$j][1], '\'"');

                    break;
                }
            }
        }
    }

    return ['classes' => $classes, 'pairs' => $pairs, 'entities' => $entities, 'declares' => $classes !== []];
}

/**
 * A class name as written, resolved against the file's namespace and use statements.
 */
function dl_resolve(string $name, string $namespace, array $uses): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    $head = explode('\\', $name)[0];

    if (isset($uses[$head])) {
        return $uses[$head] . substr($name, \strlen($head));
    }

    return ltrim($namespace . '\\' . $name, '\\');
}

/**
 * A method's parameters from its opening parenthesis: [required, total, variadic].
 *
 * @return array{0: int, 1: int, 2: bool}
 */
function dl_params(array $tokens, int $from): array
{
    $count = \count($tokens);

    for ($i = $from; $i < $count && $tokens[$i] !== '('; $i++) {
    }

    $level = 0;
    $params = [];
    $currentParam = '';

    for ($i++; $i < $count; $i++) {
        $t = $tokens[$i];
        $text = \is_array($t) ? $t[1] : $t;

        if ($text === '(' || $text === '[') {
            $level++;
        } elseif ($text === ')' || $text === ']') {
            if ($level === 0) {
                break;
            }

            $level--;
        } elseif ($text === ',' && $level === 0) {
            $params[] = $currentParam;
            $currentParam = '';

            continue;
        }

        $currentParam .= $text;
    }

    if (trim($currentParam) !== '') {
        $params[] = $currentParam;
    }

    $required = 0;
    $variadic = false;

    foreach ($params as $param) {
        if (str_contains($param, '...')) {
            $variadic = true;
        } elseif (!str_contains($param, '=')) {
            $required++;
        }
    }

    return [$required, \count($params), $variadic];
}

/**
 * The first words of a docblock, the key its allow-list entries use.
 */
function dl_first_words(string $doc): string
{
    foreach (explode("\n", $doc) as $line) {
        $line = trim(preg_replace(['#^\s*(/\*\*|\*/|\*)#', '#\*/\s*$#'], '', $line) ?? '');

        if ($line !== '') {
            return mb_strimwidth($line, 0, 60, '');
        }
    }

    return '';
}

/**
 * The argument count of a call written in a sample, from the parenthesis after the method's name, or null when the
 * sample is cut off or elided (an ellipsis, a parenthesis that does not close).
 */
function dl_args(string $text, int $open): ?int
{
    $level = 0;
    $args = 0;
    $any = false;
    $len = \strlen($text);

    for ($i = $open + 1; $i < $len; $i++) {
        $c = $text[$i];

        if ($c === "'" || $c === '"') {
            // The string ends at the next quote of its kind that no backslash escapes.
            for ($end = $i + 1; $end < $len && $text[$end] !== $c; $end++) {
                if ($text[$end] === '\\') {
                    $end++;
                }
            }

            if ($end >= $len) {
                return null;
            }

            $i = $end;
            $any = true;

            continue;
        }

        if ($c === '(' || $c === '[') {
            $level++;
        } elseif ($c === ')' || $c === ']') {
            if ($level === 0) {
                return $any ? $args + 1 : 0;
            }

            $level--;
        } elseif ($c === ',' && $level === 0) {
            $args++;
        } elseif ($c === '.' && substr($text, $i, 3) === '...') {
            return null;
        } elseif (!ctype_space($c) && $c !== '*') {
            $any = true;
        }
    }

    return null;
}

/**
 * The findings of one docblock, as [rule, why, sample].
 *
 * @param array<string, true> $entities The entity names the code declares.
 * @param callable(string): ?array{0: int, 1: int, 2: bool} $methodOf A method's parameter counts by "fqcn::method", or null when unknown.
 * @param callable(string): ?string $classOf The fqcn of a short class name of this code, or null.
 * @param string|null $eventClass The fqcn of the event class whose docblock this is.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function dl_judge_doc(string $doc, array $entities, callable $methodOf, callable $classOf, ?string $eventClass): array
{
    $findings = [];

    if (preg_match_all('/targets\(\s*[\'"]([a-z_]+)[\'"]/', $doc, $m)) {
        foreach ($m[1] as $entity) {
            if (!isset($entities[$entity])) {
                $findings[] = ['D2', "targets('{$entity}') names an entity no screen declares", "targets('{$entity}')"];
            }
        }
    }

    if (preg_match_all('/(?<![\\\\\w$>])([A-Z]\w+)::([a-z_]\w*)\(/', $doc, $m, PREG_SET_ORDER)) {
        foreach ($m as [$sample, $class, $method]) {
            $fqcn = $classOf($class);

            if ($fqcn === null) {
                continue;
            }

            $known = $methodOf($fqcn . '::' . strtolower($method));

            if ($known === []) {
                $findings[] = ['D3', "{$class}::{$method}() is declared nowhere in {$class} or its parents", $sample];
            }
        }
    }

    if ($eventClass !== null && preg_match_all('/\$event->([a-z_]\w*)\(/', $doc, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $method = $hit[1][0];
            $known = $methodOf($eventClass . '::' . strtolower($method));

            if ($known === null) {
                continue;
            }

            if ($known === []) {
                $findings[] = ['D4', "\$event->{$method}() is declared nowhere on the event or its parents", '$event->' . $method . '('];

                continue;
            }

            $args = dl_args($doc, $hit[0][1] + \strlen($hit[0][0]) - 1);
            [$required, $total, $variadic] = $known;

            if ($args !== null && ($args < $required || (!$variadic && $args > $total))) {
                $findings[] = ['D4', "\$event->{$method}() is given {$args} argument(s), and takes " . ($required === $total ? $total : "{$required} to " . ($variadic ? 'any' : $total)), '$event->' . $method . '('];
            }
        }
    }

    return $findings;
}

// Nothing above runs; the lint starts here.

$root = \dirname(__DIR__, 2);
$allowPath = __DIR__ . '/docblock-lint-allow.json';

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/');
    } elseif (str_starts_with($argument, '--allow=')) {
        $allowPath = substr($argument, 8);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$pass = 0;
$fail = 0;

$check = static function (string $what, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;

        return;
    }

    $fail++;
    echo "  FAIL {$what}" . ($detail !== '' ? "\n    {$detail}" : '') . "\n";
};

// [DL0] the judge itself: each rule goes red on a planted sample, and a true sample stays green.
$plantRead = dl_read("<?php\nnamespace P;\nabstract class BaseEvent { public function action(string \$c, string \$n, string \$t = ''): static { return \$this; } }\n"
    . "/** One. */\n/** Two. */\nfinal class SampleEvent extends BaseEvent { public static function make(int \$a): void {} }\n");
$plantMethod = static function (string $key) use ($plantRead): ?array {
    [$class, $method] = explode('::', $key);
    $short = substr((string) strrchr('\\' . $class, '\\'), 1);

    for ($guard = 0; $guard < 10 && $short !== null && isset($plantRead['classes'][$short]); $guard++) {
        if (isset($plantRead['classes'][$short]['methods'][$method])) {
            return $plantRead['classes'][$short]['methods'][$method];
        }

        $parent = $plantRead['classes'][$short]['parent'];
        $short = $parent === null ? null : substr((string) strrchr('\\' . $parent, '\\'), 1);
    }

    return $short === null ? [] : null;
};
$plantClass = static fn (string $short): ?string => isset($plantRead['classes'][$short]) ? $plantRead['classes'][$short]['fqcn'] : null;
$reached = [];
$plant = static function (string $doc) use (&$reached, $plantMethod, $plantClass): array {
    $rules = array_values(array_unique(array_column(dl_judge_doc($doc, ['item' => true], $plantMethod, $plantClass, 'P\\SampleEvent'), 0)));
    $reached = array_merge($reached, $rules);

    return $rules;
};
$check('[DL0a] two docblocks in a row are D1', \count($plantRead['pairs']) === 1 && $plantRead['pairs'][0][1] === 'One.');
$reached[] = 'D1';
$check('[DL0b] targets() naming no declared entity is D2', $plant("/**\n * if (targets('product')) {}\n */") === ['D2']);
$check('[DL0c] a declared entity is not', $plant("/**\n * targets('item')\n */") === []);
$check('[DL0d] a static call to a method the class lacks is D3', $plant("/**\n * SampleEvent::usd()\n */") === ['D3']);
$check('[DL0e] one it declares is not, and a class of another code is not judged', $plant("/**\n * SampleEvent::make(1); Factory::getDate()\n */") === []);
$check('[DL0f] an event sample with too few arguments is D4', $plant("/**\n * \$event->action('a.b');\n */") === ['D4']);
$check('[DL0g] an inherited method with the arguments it takes is not', $plant("/**\n * \$event->action('a.b', 'name', 'title');\n */") === []);
$check('[DL0h] an elided sample is not counted', $plant("/**\n * \$event->action(...);\n */") === []);
$check('[DL0j] an escaped quote does not end a sample\'s string', dl_args("x('a\\'b, c', 'd')", 1) === 2);
$reached = array_values(array_unique($reached));
sort($reached);
$check('[DL0i] the planted samples reach exactly the rules DL_RULE_IDS names', $reached === DL_RULE_IDS, 'reached: ' . implode(', ', $reached));

// The code: read every file once, then judge.
$files = dl_files($root);
$check('[DL1] the source files are found', \count($files) > 500, \count($files) . ' files');

$read = [];
$byShort = [];
$byFqcn = [];
$entities = [];

foreach ($files as $rel) {
    $read[$rel] = dl_read((string) file_get_contents($root . '/' . $rel));

    foreach ($read[$rel]['classes'] as $short => $class) {
        $byShort[$short][] = $class['fqcn'];
        $byFqcn[$class['fqcn']] = $class;
    }

    foreach ($read[$rel]['entities'] as $entity) {
        $entities[$entity] = true;
    }
}

$methodOf = static function (string $key) use ($byFqcn): ?array {
    [$fqcn, $method] = explode('::', $key);
    // A class, then its traits, then its parent, as PHP looks a method up; null when the search leaves this code.
    $find = static function (string $fqcn, int $guard) use (&$find, $byFqcn, $method): ?array {
        if ($guard > 20 || !isset($byFqcn[$fqcn])) {
            return null;
        }

        if (isset($byFqcn[$fqcn]['methods'][$method])) {
            return $byFqcn[$fqcn]['methods'][$method];
        }

        foreach ($byFqcn[$fqcn]['traits'] as $trait) {
            $found = $find($trait, $guard + 1);

            if ($found === null || $found !== []) {
                return $found;
            }
        }

        return $byFqcn[$fqcn]['parent'] === null ? [] : $find($byFqcn[$fqcn]['parent'], $guard + 1);
    };

    return $find($fqcn, 0);
};
$classOf = static fn (string $short): ?string => \count($byShort[$short] ?? []) === 1 ? $byShort[$short][0] : null;

$allow = [];
$allowed = [];

if (is_file($allowPath)) {
    $list = json_decode((string) file_get_contents($allowPath), true, 512, JSON_THROW_ON_ERROR);

    foreach ($list['entries'] as $n => $entry) {
        $allow[$entry['file'] . "\0" . $entry['rule'] . "\0" . $entry['text']] = $n;
    }
}

$findings = 0;
$printed = 0;
$byRule = [];

$report = static function (string $rel, int $line, string $rule, string $why, string $key) use (&$allow, &$allowed, &$findings, &$printed, &$byRule, $check): void {
    $id = $rel . "\0" . $rule . "\0" . $key;

    if (isset($allow[$id])) {
        $allowed[$allow[$id]] = true;

        return;
    }

    $findings++;
    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;

    if ($printed++ < DL_PRINT_LIMIT) {
        $check("[DL2] {$rel}:{$line} {$rule} {$why}", false, 'key: ' . $key);
    }
};

foreach ($read as $rel => $file) {
    if ($file['declares']) {
        foreach ($file['pairs'] as [$line, $first]) {
            $report($rel, $line, 'D1', 'a docblock directly follows another; the first describes nothing', $first);
        }
    }

    foreach ($file['classes'] as $class) {
        $event = $class['event'] ? $class['fqcn'] : null;

        foreach ($class['docs'] as [$doc, $line]) {
            foreach (dl_judge_doc($doc, $entities, $methodOf, $classOf, $event) as [$rule, $why, $sample]) {
                $report($rel, $line, $rule, $why, $sample);
            }
        }
    }
}

if ($findings === 0) {
    $check('[DL2] no docblock breaks ' . implode(', ', DL_RULE_IDS), true);
}

if ($findings > DL_PRINT_LIMIT) {
    echo '  ... and ' . ($findings - DL_PRINT_LIMIT) . " more finding(s) not printed\n";
}

foreach ($allow as $key => $n) {
    if (!isset($allowed[$n])) {
        [$file, $rule, $text] = explode("\0", $key);
        $check("[DL3] allow-list entry {$n} still matches something", false, "{$file} {$rule} {$text}");
    }
}

if ($allow !== [] && \count($allowed) === \count($allow)) {
    $check('[DL3] every allow-list entry still matches a finding', true);
}

printf("docblock lint: %d files, %d classes; %d finding(s) allowed by %d entries; %d not allowed %s\n",
    \count($files), \count($byFqcn), \count($allowed), \count($allow), $findings, $byRule === [] ? '' : json_encode($byRule));
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
