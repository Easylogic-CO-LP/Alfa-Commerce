<?php

/**
 * Media forms build: every stylesheet and script the packages ship exists in three forms.
 *
 *   x.css / x.js            the source, what Joomla loads with Debug System on;
 *   x.min.css / x.min.js    its minified twin, what Joomla loads with debug off;
 *   x.min.css.gz / .js.gz   the gzip of the minified file, served precompressed by the web server.
 *
 * A gzip of a source is never made. A library that ships already minified (a .min file with no source beside it)
 * gets its .gz only. Registrations name the .min file; Joomla swaps in the source under debug by itself.
 *
 * Which folders: the <media folder="..."> of every extension manifest in the tree (alfa.xml at the root, each
 * modules/<name>/<name>.xml and plugins/<group>/<name>/<name>.xml), so a new module or plugin is covered by its
 * manifest alone. Pointed with --root at one extension's own folder, its manifest there is read the same way.
 *
 * How:
 *   - styles: the minifier below removes comments and the whitespace CSS does not need, and nothing else. Every
 *     result is checked before it is written: the same braces and at-rules as the source, and the same text once
 *     whitespace and comments are set aside on both sides. A mismatch stops the build;
 *   - scripts: terser at the exact version in TERSER (npx), compress and mangle, no comments, ASCII output; the
 *     result must pass node --check;
 *   - .gz: zlib level 9 of the .min file; an existing .gz that decompresses to exactly the .min is kept.
 *
 * The minified files are made from their sources only. media-min.lock.json beside this file records, for each
 * source, the hash of the source and of the .min the build wrote. When a .min no longer matches its record while its
 * source still does, the .min was edited after the build and holds changes its source lacks: the build refuses and
 * writes nothing, so that change is carried into the source first (or the .min deleted to be made again).
 *
 * Idempotent: a file is written only when its content changes; a second run changes nothing.
 *
 *   php build/media-min/build.php            make or refresh every .min, .gz and the lock (needs node for scripts
 *                                            whose .min is missing or out of date)
 *   php build/media-min/build.php --check    change nothing; exit 1 when any file is missing, out of date, or
 *                                            does not belong (a .gz of a source, a .gz without its .min, ._ files)
 *   ... --root=<dir>                         the tree to work on (default: the repository this file is in)
 *
 * Exit codes: 0 done (or nothing to do); 1 refused or a check failed; 2 wrong usage or a tool missing.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

final class MediaMin
{
    /** The terser release every script is minified with. Raise it on purpose, never by a range. */
    public const TERSER = '5.51.3';

    /** The style minifier's own revision, recorded in the lock: raising it remakes every .min.css. */
    public const CSS_MINIFIER = '1';

    /** The lock file, beside this script. */
    public const LOCK = 'media-min.lock.json';

    /** The at-rules whose count must be the same in a source and its .min. */
    private const AT_RULES = [
        '@charset', '@container', '@counter-style', '@font-face', '@font-feature-values', '@import', '@keyframes',
        '@layer', '@media', '@namespace', '@page', '@property', '@scope', '@starting-style', '@supports',
    ];

    private string $root;

    private bool $check;

    /** @var array{tools: array<string, string>, files: array<string, array{0: string, 1: string}>} */
    private array $lock = ['tools' => [], 'files' => []];

    /** @var string[] */
    private array $problems = [];

    /** @var array<string, int> */
    private array $tally = [
        'sources' => 0, 'min made' => 0, 'min kept' => 0, 'vendor min' => 0, 'gz made' => 0, 'gz kept' => 0,
        'removed' => 0, 'bytes source' => 0, 'bytes min' => 0, 'bytes gz' => 0,
    ];

    public function __construct(string $root, bool $check)
    {
        $this->root  = rtrim($root, '/');
        $this->check = $check;
    }

    public function run(): int
    {
        $lockPath = __DIR__ . '/' . self::LOCK;

        if (realpath($this->root) !== realpath(\dirname(__DIR__, 2))) {
            // A tree other than this repository keeps its record in its own build/media-min folder.
            $lockPath = $this->root . '/build/media-min/' . self::LOCK;
        }

        if (is_file($lockPath)) {
            $data = json_decode((string) file_get_contents($lockPath), true);

            if (!\is_array($data) || !isset($data['files']) || !\is_array($data['files'])) {
                fwrite(STDERR, "The lock file {$lockPath} cannot be read.\n");

                return 2;
            }

            $this->lock = ['tools' => (array) ($data['tools'] ?? []), 'files' => $data['files']];
        }

        $folders = $this->mediaFolders();

        if ($folders === []) {
            fwrite(STDERR, "No extension manifest with a <media> folder under {$this->root}.\n");

            return 2;
        }

        $plan = [];
        $seen = [];

        foreach ($folders as $folder) {
            foreach ($this->plan($folder) as $step) {
                $plan[] = $step;

                if (isset($step['source'])) {
                    $seen[$step['source']] = true;
                }
            }
        }

        // A recorded source that is gone: its .min and .gz go with it, and so does its record.
        foreach (array_keys($this->lock['files']) as $source) {
            if (!isset($seen[$source]) && !is_file($this->root . '/' . $source)) {
                $plan[] = ['action' => 'forget', 'source' => $source, 'why' => "{$source} is recorded in the lock but gone"];
            }
        }

        if (!$this->check) {
            $this->needTools($plan);

            // A .min the lock does not know is taken only when it is exactly what the build makes from its source.
            foreach ($plan as $k => $step) {
                if ($step['action'] === 'adopt') {
                    $made = $this->minify($step['source']);

                    $plan[$k] = $made === $this->read($step['min'])
                        ? ['action' => 'keep', 'source' => $step['source'], 'min' => $step['min'], 'why' => '']
                        : ['action' => 'refuse', 'source' => $step['source'], 'min' => $step['min'], 'why' => "{$step['min']} "
                            . "is not what the build makes from {$step['source']}: carry its change into the source (or delete the "
                            . '.min) and build again'];
                }
            }
        }

        $refusals = array_values(array_filter($plan, static fn (array $s): bool => $s['action'] === 'refuse'));

        if ($refusals !== []) {
            foreach ($refusals as $step) {
                $this->problems[] = $step['why'];
            }

            return $this->finish(refused: true, lockPath: $lockPath);
        }

        if ($this->check) {
            foreach ($plan as $step) {
                if ($step['action'] !== 'keep') {
                    $this->problems[] = $step['why'];
                }
            }

            $this->gzPass($folders);

            return $this->finish(refused: false, lockPath: $lockPath);
        }

        foreach ($plan as $step) {
            $this->apply($step);
        }

        $this->gzPass($folders);
        $this->writeLock($lockPath);

        return $this->finish(refused: false, lockPath: $lockPath);
    }

    /**
     * The media folders the manifests in the tree declare, relative to the root.
     *
     * @return string[]
     */
    private function mediaFolders(): array
    {
        $manifests = array_merge(
            glob($this->root . '/*.xml') ?: [],
            glob($this->root . '/modules/*/*.xml') ?: [],
            glob($this->root . '/plugins/*/*/*.xml') ?: [],
        );
        $folders = [];

        foreach ($manifests as $manifest) {
            $xml = @simplexml_load_file($manifest);

            if (!$xml instanceof SimpleXMLElement || $xml->getName() !== 'extension' || !isset($xml->media)) {
                continue;
            }

            $folder = trim((string) ($xml->media['folder'] ?? ''), '/');
            $base   = substr(\dirname($manifest), \strlen($this->root) + 1);
            $path   = ltrim(($base === false ? '' : $base) . '/' . $folder, '/');

            if ($folder !== '' && is_dir($this->root . '/' . $path)) {
                $folders[$path] = $path;
            }
        }

        sort($folders);

        return array_values($folders);
    }

    /**
     * Every file of one media folder, relative to the root, sorted.
     *
     * @return string[]
     */
    private function files(string $folder): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root . '/' . $folder, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($this->root) + 1);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * What to do with each style and script of one folder.
     *
     * @return array<int, array<string, string>>
     */
    private function plan(string $folder): array
    {
        $steps = [];
        $files = $this->files($folder);
        $exists = array_flip($files);

        foreach ($files as $path) {
            $name = basename($path);

            if (str_starts_with($name, '._')) {
                $steps[] = ['action' => 'remove', 'path' => $path, 'why' => "{$path}: a macOS metadata file, never shipped"];
                continue;
            }

            if (!preg_match('/^(.+)\.(css|js)$/', $path, $m) || preg_match('/\.min$/', $m[1])) {
                continue;
            }

            $min = $m[1] . '.min.' . $m[2];
            $this->tally['sources']++;
            $this->tally['bytes source'] += (int) filesize($this->root . '/' . $path);
            $steps[] = $this->decide($path, $min, isset($exists[$min]));
        }

        return $steps;
    }

    /**
     * The decision for one source and its .min.
     *
     * @return array<string, string>
     */
    private function decide(string $source, string $min, bool $minExists): array
    {
        $sourceHash = $this->hash($this->read($source));
        $record = $this->lock['files'][$source] ?? null;
        $tools = $this->toolsFor($source);
        $toolsChanged = ($this->lock['tools'][$tools[0]] ?? null) !== $tools[1];

        if (!$minExists) {
            return ['action' => 'make', 'source' => $source, 'min' => $min, 'why' => "{$source}: its .min is missing"];
        }

        $minHash = hash_file('sha256', $this->root . '/' . $min);

        if ($record !== null) {
            if ($minHash === $record[1]) {
                if ($sourceHash === $record[0] && !$toolsChanged) {
                    return ['action' => 'keep', 'source' => $source, 'min' => $min, 'why' => ''];
                }

                $why = $sourceHash !== $record[0] ? 'the source changed since its .min was made' : 'the minifier changed';

                return ['action' => 'make', 'source' => $source, 'min' => $min, 'why' => "{$source}: {$why}"];
            }

            if ($sourceHash === $record[0]) {
                return ['action' => 'refuse', 'source' => $source, 'min' => $min, 'why' => "{$min} was changed after the build "
                    . "made it, while {$source} was not: carry the change into the source (or delete the .min) and build again"];
            }

            // Both changed (a merge brought another .min with its source): the source decides.
            return ['action' => 'make', 'source' => $source, 'min' => $min, 'why' => "{$source}: the source and its .min changed"];
        }

        // A .min the lock does not know: adopted only when it is exactly what the build makes from the source.
        return ['action' => 'adopt', 'source' => $source, 'min' => $min, 'why' => "{$min} is not recorded in the lock"];
    }

    /**
     * The tool a source is minified with and its revision.
     *
     * @return array{0: string, 1: string}
     */
    private function toolsFor(string $source): array
    {
        return str_ends_with($source, '.css') ? ['css', self::CSS_MINIFIER] : ['terser', self::TERSER];
    }

    /** Stops before anything is written when a script needs terser and node is not there. */
    private function needTools(array $plan): void
    {
        foreach ($plan as $step) {
            if (\in_array($step['action'], ['make', 'adopt'], true) && str_ends_with($step['source'], '.js')) {
                exec('node --version 2>/dev/null', $out, $rc);

                if ($rc !== 0) {
                    fwrite(STDERR, "Scripts need minifying and node is not on PATH: {$step['source']}\n");
                    exit(2);
                }

                return;
            }
        }
    }

    private function apply(array $step): void
    {
        switch ($step['action']) {
            case 'remove':
                unlink($this->root . '/' . $step['path']);
                $this->tally['removed']++;
                echo "  removed {$step['path']}\n";

                return;

            case 'forget':
                $source = $step['source'];
                $min = preg_replace('/\.(css|js)$/', '.min.$1', $source);

                foreach ([$min, $min . '.gz'] as $gone) {
                    if (is_file($this->root . '/' . $gone)) {
                        unlink($this->root . '/' . $gone);
                        $this->tally['removed']++;
                        echo "  removed {$gone} (its source is gone)\n";
                    }
                }

                unset($this->lock['files'][$source]);

                return;

            case 'keep':
                $this->tally['min kept']++;
                $this->lock['files'][$step['source']] = [
                    $this->hash($this->read($step['source'])),
                    hash_file('sha256', $this->root . '/' . $step['min']),
                ];

                return;
        }

        $source = $step['source'];
        $min = $step['min'];
        $made = $this->minify($source);
        $current = is_file($this->root . '/' . $min) ? $this->read($min) : null;

        if ($current !== $made) {
            file_put_contents($this->root . '/' . $min, $made);
            echo "  made {$min} (" . \strlen($this->read($source)) . ' to ' . \strlen($made) . " bytes)\n";
            $this->tally['min made']++;
        } else {
            $this->tally['min kept']++;
        }

        $this->lock['files'][$source] = [$this->hash($this->read($source)), hash('sha256', $made)];
    }

    /** The minified text of one source; stops the build when the result fails its check. */
    private function minify(string $source): string
    {
        $text = $this->read($source);

        if (str_ends_with($source, '.css')) {
            $out = self::minifyCss($text);
            $fault = self::compareCss($text, $out);

            if ($fault !== null) {
                fwrite(STDERR, "The style minifier's result for {$source} does not match its source ({$fault}); nothing of it was written.\n");
                exit(1);
            }

            return $out . "\n";
        }

        $work = sys_get_temp_dir() . '/media-min-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($work, 0700);
        $in = $work . '/in.js';
        $out = $work . '/out.js';
        file_put_contents($in, $text);
        $command = [
            'npx', '--yes', 'terser@' . self::TERSER, $in,
            '--compress', '--mangle', '--comments', 'false', '--format', 'ascii_only=true', '--output', $out,
        ];
        $rc = $this->exec($command, $error);

        if ($rc === 0) {
            $rc = $this->exec(['node', '--check', $out], $error);
        }

        $result = $rc === 0 ? (string) file_get_contents($out) : '';
        @unlink($in);
        @unlink($out);
        @rmdir($work);

        if ($rc !== 0 || $result === '') {
            fwrite(STDERR, "terser could not minify {$source}: " . trim($error) . "\n");
            exit(1);
        }

        return rtrim($result, "\n") . "\n";
    }

    /** Runs a command without a shell; returns its exit code and its error output. */
    private function exec(array $command, ?string &$error): int
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            $error = 'cannot start ' . $command[0];

            return 127;
        }

        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    /**
     * The .gz pass over every .min file (made here or shipped minified): each gets the gzip of its exact bytes, and
     * a .gz that is not the twin of a .min goes.
     *
     * @param string[] $folders
     */
    private function gzPass(array $folders): void
    {
        foreach ($folders as $folder) {
            $files = $this->files($folder);
            $exists = array_flip($files);

            foreach ($files as $path) {
                if (str_starts_with(basename($path), '._')) {
                    continue;
                }

                if (str_ends_with($path, '.gz')) {
                    $twin = substr($path, 0, -3);

                    if (!preg_match('/\.min\.(css|js)$/', $twin) || !isset($exists[$twin])) {
                        $what = preg_match('/\.(css|js)$/', $twin) && !preg_match('/\.min\.(css|js)$/', $twin)
                            ? 'a gzip of a source, never shipped' : 'a gzip without its .min';
                        $this->stray($path, $what);
                    }

                    continue;
                }

                if (!preg_match('/\.min\.(css|js)$/', $path)) {
                    continue;
                }

                $plain = preg_replace('/\.min\.(css|js)$/', '.$1', $path);

                if (!isset($exists[$plain])) {
                    $this->tally['vendor min']++;
                }

                $bytes = $this->read($path);
                $this->tally['bytes min'] += \strlen($bytes);
                $gz = $path . '.gz';
                $current = isset($exists[$gz]) ? (string) file_get_contents($this->root . '/' . $gz) : null;

                if ($current !== null && @gzdecode($current) === $bytes) {
                    $this->tally['gz kept']++;
                    $this->tally['bytes gz'] += \strlen($current);
                    continue;
                }

                if ($this->check) {
                    $this->problems[] = $current === null ? "{$path}: its .gz is missing" : "{$gz} is not the gzip of {$path}";
                    continue;
                }

                $packed = gzencode($bytes, 9);
                file_put_contents($this->root . '/' . $gz, $packed);
                $this->tally['gz made']++;
                $this->tally['bytes gz'] += \strlen($packed);
                echo "  made {$gz}\n";
            }
        }
    }

    private function stray(string $path, string $what): void
    {
        if ($this->check) {
            $this->problems[] = "{$path}: {$what}";

            return;
        }

        unlink($this->root . '/' . $path);
        $this->tally['removed']++;
        echo "  removed {$path} ({$what})\n";
    }

    private function writeLock(string $lockPath): void
    {
        ksort($this->lock['files']);
        $this->lock['tools'] = ['css' => self::CSS_MINIFIER, 'terser' => self::TERSER];

        // One line per source, so two branches that rebuild different files merge without a conflict.
        $lines = [];

        foreach ($this->lock['files'] as $source => [$sourceHash, $minHash]) {
            $lines[] = '    ' . json_encode($source, JSON_UNESCAPED_SLASHES) . ': ["' . $sourceHash . '", "' . $minHash . '"]';
        }

        $text = "{\n  \"tools\": " . json_encode($this->lock['tools'], JSON_UNESCAPED_SLASHES) . ",\n  \"files\": {\n"
            . implode(",\n", $lines) . "\n  }\n}\n";

        if (!is_file($lockPath) || file_get_contents($lockPath) !== $text) {
            if (!is_dir(\dirname($lockPath))) {
                mkdir(\dirname($lockPath), 0755, true);
            }

            file_put_contents($lockPath, $text);
            echo '  wrote ' . substr($lockPath, \strlen($this->root) + 1) . "\n";
        }
    }

    private function finish(bool $refused, string $lockPath): int
    {
        $t = $this->tally;

        if (!$this->check && !$refused) {
            printf(
                "media-min: %d sources; .min made %d, kept %d; shipped minified %d; .gz made %d, kept %d; removed %d\n",
                $t['sources'], $t['min made'], $t['min kept'], $t['vendor min'], $t['gz made'], $t['gz kept'], $t['removed'],
            );
            printf("media-min: bytes %d in sources, %d in .min files, %d in .gz files\n", $t['bytes source'], $t['bytes min'], $t['bytes gz']);
        }

        if ($this->problems === []) {
            if ($this->check) {
                printf("media-min check: %d sources, every one with its .min and every .min with its .gz; nothing stray\n", $t['sources']);
            }

            return 0;
        }

        fwrite(STDERR, ($refused ? "media-min refused, nothing was written:\n" : "media-min check failed:\n"));

        foreach ($this->problems as $problem) {
            fwrite(STDERR, "  {$problem}\n");
        }

        if ($this->check && !$refused) {
            fwrite(STDERR, "Run php build/media-min/build.php and commit what it makes.\n");
        }

        return 1;
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    /** The content hash a source is recorded by: line endings as LF, so a Windows checkout records the same. */
    private function hash(string $text): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $text));
    }

    /**
     * Splits a stylesheet into runs: 's' a string with its quotes, 'c' a comment, 't' everything else.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function cssRuns(string $css): array
    {
        $runs = [];
        $n = \strlen($css);
        $i = 0;
        $start = 0;

        while ($i < $n) {
            $ch = $css[$i];

            if ($ch === '"' || $ch === "'") {
                if ($i > $start) {
                    $runs[] = ['t', substr($css, $start, $i - $start)];
                }

                $j = $i + 1;

                while ($j < $n) {
                    if ($css[$j] === '\\') {
                        $j += 2;
                        continue;
                    }

                    if ($css[$j] === $ch) {
                        $j++;
                        break;
                    }

                    if ($css[$j] === "\n") {
                        break;
                    }

                    $j++;
                }

                $j = min($j, $n);
                $runs[] = ['s', substr($css, $i, $j - $i)];
                $i = $start = $j;
                continue;
            }

            if ($ch === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
                if ($i > $start) {
                    $runs[] = ['t', substr($css, $start, $i - $start)];
                }

                $end = strpos($css, '*/', $i + 2);
                $j = $end === false ? $n : $end + 2;
                $runs[] = ['c', substr($css, $i, $j - $i)];
                $i = $start = $j;
                continue;
            }

            $i++;
        }

        if ($start < $n) {
            $runs[] = ['t', substr($css, $start)];
        }

        return $runs;
    }

    /**
     * The stylesheet without its comments. A comment between two characters of one word becomes a space, so it
     * never joins two tokens; anywhere else it goes without a trace.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function withoutComments(string $css): array
    {
        $runs = self::cssRuns($css);
        $kept = [];
        $word = '/[A-Za-z0-9_\\\\\x80-\xff-]/';

        foreach ($runs as $k => [$kind, $text]) {
            if ($kind !== 'c') {
                if ($kind === 't' && $kept !== [] && $kept[\count($kept) - 1][0] === 't') {
                    $kept[\count($kept) - 1][1] .= $text;
                } else {
                    $kept[] = [$kind, $text];
                }

                continue;
            }

            $before = $kept === [] ? '' : substr($kept[\count($kept) - 1][1], -1);
            $after = isset($runs[$k + 1]) ? $runs[$k + 1][1][0] : '';

            if ($before !== '' && $after !== '' && preg_match($word, $before) && preg_match($word, $after)) {
                if ($kept !== [] && $kept[\count($kept) - 1][0] === 't') {
                    $kept[\count($kept) - 1][1] .= ' ';
                } else {
                    $kept[] = ['t', ' '];
                }
            }
        }

        return $kept;
    }

    /** Minifies a stylesheet: comments and unneeded whitespace go, strings stay byte for byte. */
    public static function minifyCss(string $css): string
    {
        $css = preg_replace('/^\xEF\xBB\xBF/', '', $css);
        $out = '';

        foreach (self::withoutComments($css) as [$kind, $text]) {
            if ($kind === 's') {
                $out .= $text;
                continue;
            }

            $text = preg_replace('/\s+/', ' ', $text);
            // Never before ':' (" :is(" is a descendant combinator), never around '+' or '-' (calc() needs them spaced).
            $text = preg_replace('/ ?([{};,>]) ?/', '$1', $text);
            $text = str_replace([': ', ' !'], [':', '!'], $text);
            $out .= str_replace(';}', '}', $text);
        }

        return trim($out);
    }

    /**
     * Null when a minified stylesheet is its source without comments and whitespace, else what differs: the braces,
     * an at-rule count, or the text itself once both sides are set side by side without whitespace.
     */
    public static function compareCss(string $source, string $min): ?string
    {
        $a = self::skeleton(preg_replace('/^\xEF\xBB\xBF/', '', $source));
        $b = self::skeleton($min);

        if (substr_count($a['code'], '{') !== substr_count($b['code'], '{')
            || substr_count($a['code'], '}') !== substr_count($b['code'], '}')
            || substr_count($b['code'], '{') !== substr_count($b['code'], '}')) {
            return 'the braces differ';
        }

        foreach (self::AT_RULES as $rule) {
            if (substr_count($a['code'], $rule) !== substr_count($b['code'], $rule)) {
                return "the {$rule} count differs";
            }
        }

        if (str_contains($b['code'], '/*')) {
            return 'a comment is left';
        }

        return $a['bare'] === $b['bare'] ? null : 'the text differs once whitespace is set aside';
    }

    /**
     * A stylesheet's code outside strings and comments, and its whole text without comments and without any
     * whitespace outside strings (and without a ';' right before a '}').
     *
     * @return array{code: string, bare: string}
     */
    private static function skeleton(string $css): array
    {
        $code = '';
        $bare = '';

        foreach (self::withoutComments($css) as [$kind, $text]) {
            if ($kind === 's') {
                $bare .= $text;
                continue;
            }

            $code .= $text;
            $bare .= str_replace(';}', '}', preg_replace('/\s+/', '', $text));
        }

        return ['code' => $code, 'bare' => $bare];
    }
}

$options = getopt('', ['check', 'root:', 'help']);

if (isset($options['help'])) {
    echo "usage: php build/media-min/build.php [--check] [--root=<dir>]\n";
    exit(0);
}

$root = isset($options['root']) ? (string) $options['root'] : \dirname(__DIR__, 2);

if (!is_dir($root)) {
    fwrite(STDERR, "No such folder: {$root}\n");
    exit(2);
}

exit((new MediaMin(realpath($root), isset($options['check'])))->run());
