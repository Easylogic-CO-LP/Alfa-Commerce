<?php

/**
 * Media forms suite: every stylesheet and script a package ships is there in its three forms, and is registered by
 * its .min name.
 *
 * The repository's copy, run by the Media forms step of .github/workflows/code-quality.yml over the checked-out tree.
 * The core test tree keeps the same file as tests/tools/media-forms-suite.php; the two stay equal.
 *
 * The rule: a source (x.css, x.js: what Joomla loads with Debug System on), its minified twin (x.min.css, x.min.js:
 * what Joomla loads with debug off) and the gzip of the minified file only (x.min.css.gz, x.min.js.gz: served
 * precompressed by the web server). Never a gzip of a source. A library shipped already minified (a .min file with no
 * source beside it) has its .gz too. Every registration names the .min file; Joomla loads the source under debug by
 * itself. build/media-min/build.php makes the forms.
 *
 *   MF0  the judge itself: each planted fault of a throwaway package is found, and a clean one passes
 *   MF1  the package was read: its manifests' media folders and their sources
 *   MF2  every source has its .min
 *   MF3  every .min has its .gz, and the .gz decompresses byte for byte to the .min
 *   MF4  no .gz of a source, and no .gz without its .min
 *   MF5  no macOS ._ file anywhere in the package
 *   MF6  every registration names a .min file: the joomla.asset.json uris, the registrations in PHP code
 *        (registerAndUseScript, registerAndUseStyle, registerScript, registerStyle, HTMLHelper script and stylesheet,
 *        addScript, addStyleSheet) and a media path written in a shipped script
 *   MF7  every .min a registry names is in the package, with its .gz
 *   MF8  in a repository checkout (build/media-min/media-min.lock.json present): every .min is the one the build made
 *        from its source as the source is now; a built package has no lock, and the arm is skipped
 *
 * Which folders: the <media folder="..."> of every extension manifest of the package (alfa.xml at its root, each
 * modules/<name>/<name>.xml and plugins/<group>/<name>/<name>.xml).
 *
 * Reads files only: no Joomla, no database, no write outside the system temp folder (MF0's plants). Runs in seconds.
 *
 *   php media-forms-suite.php <the package zip>
 *   php media-forms-suite.php <a package-layout tree, such as the repository checkout>
 *
 * Exit code 0 when every arm passes.
 *
 * Not a test of this suite, a check by hand of the web server (the .htaccess): the package ships the .gz files and
 * Joomla's own htaccess.txt, used as the site's .htaccess with its "GZIP & BROTLI" section left on, serves them. On a
 * site with the package installed, both must hold:
 *
 *   curl -s -D - -o /tmp/a.bin -H 'Accept-Encoding: gzip' https://<site>/media/com_alfa/js/site/list-form.min.js
 *     answers 200 with Content-Encoding: gzip and Content-Type: text/javascript, and
 *     cmp /tmp/a.bin <the site>/media/com_alfa/js/site/list-form.min.js.gz   reports no difference;
 *   the same request without the Accept-Encoding header answers the plain .min file, byte for byte.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

/**
 * A package to read: a folder in the package layout, or a zip of one.
 */
final class MfPackage
{
    /** @var array<string, true> */
    private array $paths = [];

    private ?ZipArchive $zip = null;

    private string $prefix = '';

    public function __construct(private string $where)
    {
        if (is_dir($where)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($where, FilesystemIterator::SKIP_DOTS),
                    static fn (SplFileInfo $f): bool => $f->getFilename() !== '.git' && $f->getFilename() !== 'node_modules',
                ),
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $this->paths[substr($file->getPathname(), \strlen(rtrim($where, '/')) + 1)] = true;
                }
            }

            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($where) !== true) {
            throw new RuntimeException("Neither a folder nor a zip: {$where}");
        }

        $this->zip = $zip;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!str_ends_with($name, '/')) {
                $this->paths[$name] = true;
            }
        }

        // A zip whose files sit in one top folder is read from inside it.
        if (!isset($this->paths['alfa.xml'])) {
            foreach (array_keys($this->paths) as $name) {
                if (preg_match('#^([^/]+/)alfa\.xml$#', $name, $m)) {
                    $this->prefix = $m[1];
                    $inner = [];

                    foreach (array_keys($this->paths) as $path) {
                        if (str_starts_with($path, $this->prefix)) {
                            $inner[substr($path, \strlen($this->prefix))] = true;
                        }
                    }

                    $this->paths = $inner;
                    break;
                }
            }
        }
    }

    /** @return string[] */
    public function paths(): array
    {
        $paths = array_keys($this->paths);
        sort($paths);

        return $paths;
    }

    public function has(string $path): bool
    {
        return isset($this->paths[$path]);
    }

    public function read(string $path): string
    {
        if ($this->zip !== null) {
            return (string) $this->zip->getFromName($this->prefix . $path);
        }

        return (string) file_get_contents(rtrim($this->where, '/') . '/' . $path);
    }
}

/**
 * Every finding of one package, by arm.
 *
 * @return array{folders: string[], sources: string[], mins: string[], MF2: string[], MF3: string[], MF4: string[],
 *               MF5: string[], MF6: string[], MF7: string[], MF8: string[]|null, registrations: int}
 */
function mf_judge(MfPackage $package): array
{
    $found = ['folders' => [], 'sources' => [], 'mins' => [], 'MF2' => [], 'MF3' => [], 'MF4' => [], 'MF5' => [],
        'MF6' => [], 'MF7' => [], 'MF8' => null, 'registrations' => 0];
    $paths = $package->paths();

    // The media folders the manifests declare, each with the name its registry uris start with.
    $folders = [];

    foreach ($paths as $path) {
        if (!preg_match('#^(?:[^/]+\.xml|modules/[^/]+/[^/]+\.xml|plugins/[^/]+/[^/]+/[^/]+\.xml)$#', $path)) {
            continue;
        }

        $xml = @simplexml_load_string($package->read($path));

        if (!$xml instanceof SimpleXMLElement || $xml->getName() !== 'extension' || !isset($xml->media)) {
            continue;
        }

        $folder = trim((string) ($xml->media['folder'] ?? ''), '/');
        $base = \dirname($path) === '.' ? '' : \dirname($path) . '/';

        if ($folder !== '') {
            $folders[$base . $folder] = (string) ($xml->media['destination'] ?? '');
        }
    }

    ksort($folders);
    $found['folders'] = array_keys($folders);

    foreach ($paths as $path) {
        if (str_starts_with(basename($path), '._')) {
            $found['MF5'][] = $path;
        }
    }

    foreach ($folders as $folder => $destination) {
        foreach ($paths as $path) {
            if (!str_starts_with($path, $folder . '/') || str_starts_with(basename($path), '._')) {
                continue;
            }

            if (preg_match('/^(.+)\.min\.(css|js)$/', $path, $m)) {
                $found['mins'][] = $path;

                if (!$package->has($path . '.gz')) {
                    $found['MF3'][] = "{$path}: no .gz";
                } elseif (@gzdecode($package->read($path . '.gz')) !== $package->read($path)) {
                    $found['MF3'][] = "{$path}.gz does not decompress to {$path}";
                }

                continue;
            }

            if (preg_match('/^(.+)\.(css|js)$/', $path, $m)) {
                $found['sources'][] = $path;

                if (!$package->has($m[1] . '.min.' . $m[2])) {
                    $found['MF2'][] = $path;
                }

                continue;
            }

            if (str_ends_with($path, '.gz')) {
                $twin = substr($path, 0, -3);

                if (!preg_match('/\.min\.(css|js)$/', $twin)) {
                    $found['MF4'][] = preg_match('/\.(css|js)$/', $twin) ? "{$path}: a gzip of a source" : "{$path}: a gzip of a file that is not a .min style or script";
                } elseif (!$package->has($twin)) {
                    $found['MF4'][] = "{$path}: a gzip without its .min";
                }
            }
        }

        // The registry of this folder: every style and script uri at a .min file that the package carries.
        $registry = $folder . '/joomla.asset.json';

        if ($package->has($registry)) {
            $assets = json_decode($package->read($registry), true)['assets'] ?? null;

            if (!\is_array($assets)) {
                $found['MF6'][] = "{$registry}: not readable";
                continue;
            }

            foreach ($assets as $asset) {
                $uri = (string) ($asset['uri'] ?? '');
                $type = (string) ($asset['type'] ?? '');

                if ($uri === '' || !\in_array($type, ['script', 'style'], true) || preg_match('#^(https?:)?//#', $uri)) {
                    continue;
                }

                $found['registrations']++;

                if (!preg_match('/\.min\.(css|js)$/', $uri)) {
                    $found['MF6'][] = "{$registry}: {$asset['name']} ({$type}) {$uri}";
                    continue;
                }

                // "<destination>/<path>" is media/<destination>/<js|css>/<path> once installed, <folder>/<js|css>/<path> here.
                $parts = explode('/', $uri, 2);
                $file = $folder . '/' . ($type === 'script' ? 'js' : 'css') . '/' . ($parts[1] ?? '');

                if ($parts[0] !== $destination || !$package->has($file) || !$package->has($file . '.gz')) {
                    $found['MF7'][] = "{$registry}: {$asset['name']} ({$type}) {$uri} names no .min with its .gz in the package";
                }
            }
        }
    }

    // Registrations in PHP code (comments set aside), and media paths written in a shipped script.
    $calls = [
        '/->(?:registerAndUseScript|registerAndUseStyle|registerScript|registerStyle)\(\s*(?:name:\s*)?([\'"])[^\'"]*\1\s*,\s*(?:uri:\s*)?([\'"])([^\'"]+)\2/',
        '/HTMLHelper::(?:_\(\s*([\'"])(?:script|stylesheet)\1\s*,|script\(|stylesheet\()\s*([\'"])([^\'"]+)\2/',
        '/->(?:addScript|addStyleSheet)\(\s*()([\'"])([^\'"]+)\2/',
    ];

    foreach ($paths as $path) {
        $isPhp = str_ends_with($path, '.php');
        $isScript = str_ends_with($path, '.js') && !str_ends_with($path, '.min.js');

        if ((!$isPhp && !$isScript) || preg_match('#(^|/)(tests|libraries|vendor|build|scripts|\.github)/#', $path)) {
            continue;
        }

        $text = $package->read($path);

        if ($isPhp) {
            $code = '';

            foreach (@token_get_all($text) as $token) {
                if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= \is_array($token) ? $token[1] : $token;
            }

            foreach ($calls as $pattern) {
                preg_match_all($pattern, $code, $all, PREG_SET_ORDER);

                foreach ($all as $call) {
                    $uri = $call[3];

                    // A media path is read by the literal pass below, once.
                    if (preg_match('#^/?media/#', $uri)) {
                        continue;
                    }

                    if (preg_match('/\.(css|js)$/', $uri) && !preg_match('/\.min\.(css|js)$/', $uri) && !preg_match('#^(https?:)?//#', $uri)) {
                        $found['MF6'][] = "{$path}: {$uri}";
                    }
                }
            }

            $text = $code;
        }

        preg_match_all('#([\'"`])/?media/[^\'"`\s]*?\.(css|js)\1#', $text, $literals, PREG_SET_ORDER);

        foreach ($literals as $literal) {
            if (!preg_match('/\.min\.(css|js)[\'"`]$/', $literal[0])) {
                $found['MF6'][] = "{$path}: {$literal[0]}";
            }
        }
    }

    $found['MF6'] = array_values(array_unique($found['MF6']));

    // In a repository checkout, the build's record says which .min it made from which source.
    if ($package->has('build/media-min/media-min.lock.json')) {
        $found['MF8'] = [];
        $lock = json_decode($package->read('build/media-min/media-min.lock.json'), true)['files'] ?? null;

        if (!\is_array($lock)) {
            $found['MF8'][] = 'build/media-min/media-min.lock.json: not readable';
        } else {
            foreach ($found['sources'] as $source) {
                $min = preg_replace('/\.(css|js)$/', '.min.$1', $source);
                $record = $lock[$source] ?? null;

                if ($record === null) {
                    $found['MF8'][] = "{$source}: not in the lock";
                } elseif (hash('sha256', str_replace("\r\n", "\n", $package->read($source))) !== $record[0]) {
                    $found['MF8'][] = "{$source}: changed since its .min was made";
                } elseif (!$package->has($min) || hash('sha256', $package->read($min)) !== $record[1]) {
                    $found['MF8'][] = "{$min}: not the file the build made";
                }
            }
        }
    }

    return $found;
}

if ($argc !== 2) {
    fwrite(STDERR, "usage: php media-forms-suite.php <package zip | package-layout tree>\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$skip = 0;

$check = static function (string $what, bool $ok, array $detail = []) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  ok   {$what}\n";

        return;
    }

    $fail++;
    echo "  FAIL {$what}\n";

    foreach (\array_slice($detail, 0, 40) as $line) {
        echo "    {$line}\n";
    }

    if (\count($detail) > 40) {
        echo '    and ' . (\count($detail) - 40) . " more\n";
    }
};

// [MF0] the judge itself, on a throwaway package: one clean extension, then one fault planted at a time.
$plant = sys_get_temp_dir() . '/media-forms-plant-' . getmypid() . '-' . bin2hex(random_bytes(4));
$write = static function (string $relative, string $bytes) use ($plant): void {
    @mkdir(\dirname($plant . '/' . $relative), 0700, true);
    file_put_contents($plant . '/' . $relative, $bytes);
};
$wipe = static function () use ($plant): void {
    if (!is_dir($plant)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plant, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($plant);
};
$clean = static function () use ($write, $wipe): void {
    $wipe();
    $write('alfa.xml', '<?xml version="1.0"?><extension type="component"><media destination="com_x" folder="media"><folder>js</folder></media></extension>');
    $write('media/js/a.js', "var a = 1;\n");
    $write('media/js/a.min.js', "var a=1;\n");
    $write('media/js/a.min.js.gz', gzencode("var a=1;\n", 9));
    $write('media/js/lib.min.js', "var l=1;\n");
    $write('media/js/lib.min.js.gz', gzencode("var l=1;\n", 9));
    $write('media/joomla.asset.json', '{"assets":[{"name":"x.a","type":"script","uri":"com_x/a.min.js"}]}');
    $write('site/src/A.php', "<?php\n// \$wa->registerAndUseScript('x.c', 'media/com_x/js/c.js');\n\$wa->registerAndUseScript('x.a', 'media/com_x/js/a.min.js');\n");
};
$faults = [
    'MF2' => static function () use ($write): void { $write('media/js/b.js', "var b;\n"); },
    'MF3' => static function () use ($write): void { $write('media/js/a.min.js.gz', gzencode("var a=2;\n", 9)); },
    'MF4' => static function () use ($write): void { $write('media/js/a.js.gz', gzencode("var a = 1;\n", 9)); },
    'MF5' => static function () use ($write): void { $write('media/js/._a.min.js', 'x'); },
    'MF6' => static function () use ($write): void { $write('site/src/B.php', "<?php\nHTMLHelper::_('script', 'com_x/a.js');\n"); },
    'MF7' => static function () use ($write): void { $write('media/joomla.asset.json', '{"assets":[{"name":"x.z","type":"script","uri":"com_x/z.min.js"}]}'); },
    'MF8' => static function () use ($write): void { $write('build/media-min/media-min.lock.json', '{"files":{"media/js/a.js":["0","0"]}}'); },
];
$clean();
$base = mf_judge(new MfPackage($plant));
$selfTest = ['clean' => [$base['MF2'], $base['MF3'], $base['MF4'], $base['MF5'], $base['MF6'], $base['MF7'], $base['MF8']] === [[], [], [], [], [], [], null]];

foreach ($faults as $arm => $fault) {
    $clean();
    $fault();
    $judged = mf_judge(new MfPackage($plant));
    $others = array_diff(['MF2', 'MF3', 'MF4', 'MF5', 'MF6', 'MF7'], [$arm]);
    $selfTest[$arm] = ($judged[$arm] ?? []) !== [] && $judged[$arm] !== null
        && array_filter($others, static fn (string $o): bool => $judged[$o] !== []) === [];
}

// The JS literal reader and the JS-to-registry guard, on their own plants.
$clean();
$write('media/js/c.js', "link.href = base + 'media/com_x/css/frame.css';\n");
$write('media/js/c.min.js', "x\n");
$write('media/js/c.min.js.gz', gzencode("x\n", 9));
$judged = mf_judge(new MfPackage($plant));
$selfTest['MF6 script'] = \count($judged['MF6']) === 1 && str_contains($judged['MF6'][0], 'frame.css');
$wipe();
$check('[MF0] each planted fault is found by its own arm and no other, a commented registration is not one, and a clean package passes',
    !\in_array(false, $selfTest, true), array_keys(array_filter($selfTest, static fn (bool $ok): bool => !$ok)));

// The package under test.
try {
    $package = new MfPackage($argv[1]);
} catch (Throwable $e) {
    $check('[MF1] the package can be read', false, [$e->getMessage()]);
    echo "{$pass} passed, {$fail} failed\n";
    exit(1);
}

$found = mf_judge($package);
$check('[MF1] the package was read: ' . \count($found['folders']) . ' media folders, ' . \count($found['sources']) . ' sources, '
    . \count($found['mins']) . ' .min files, ' . $found['registrations'] . ' registry uris',
    $found['folders'] !== [] && $found['sources'] !== [] && $found['registrations'] > 0, $found['folders']);
$check('[MF2] every source has its .min', $found['MF2'] === [], $found['MF2']);
$check('[MF3] every .min has its .gz, which decompresses byte for byte to the .min', $found['MF3'] === [], $found['MF3']);
$check('[MF4] no .gz of a source, and no .gz without its .min', $found['MF4'] === [], $found['MF4']);
$check('[MF5] no macOS ._ file in the package', $found['MF5'] === [], $found['MF5']);
$check('[MF6] every registration names a .min file (registries, PHP registrations, media paths in scripts)', $found['MF6'] === [], $found['MF6']);
$check('[MF7] every .min a registry names is in the package with its .gz', $found['MF7'] === [], $found['MF7']);

if ($found['MF8'] === null) {
    $skip++;
    echo "  skip [MF8] the build's record is not in a built package; a repository checkout is checked against it\n";
} else {
    $check('[MF8] every .min is the one the build made from its source as it is now', $found['MF8'] === [], $found['MF8']);
}

echo "{$pass} passed, {$fail} failed" . ($skip > 0 ? ", {$skip} skipped" : '') . "\n";
exit($fail === 0 ? 0 : 1);
