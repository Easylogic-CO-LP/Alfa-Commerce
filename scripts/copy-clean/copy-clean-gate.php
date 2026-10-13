<?php

/**
 * Copy-clean gate: the shipped comments and the texts a person reads stay clean.
 *
 * The repository's copy, run by the Copy-clean job of .github/workflows/code-quality.yml over the checked-out
 * tree (--package=.). The development site keeps the same file as tests/tools/copy-clean-gate.php; the two stay equal.
 *
 * THE RULING THIS PINS (owner, 2026-10-12, chat-rulings/2026-10-12-copy-clean-before-release.md, and
 * "keep this logic for future edits so not rehappen again"):
 *
 *   R1  no box or banner comment: no box-drawing or block character, no rule line of = - * # ~ _
 *       (a line that is only decoration, or a heading framed by a rule run);
 *   R2  no em-dash or en-dash in a comment or a human-read text;
 *   R3  no typographic artifact there either: arrows, ellipsis, curly quotes, x and comparison
 *       signs, plus-minus, bullets, middle dots, section signs, non-breaking spaces;
 *   R5  no carrier, payment-gateway, other shop system or other product name there.
 *
 * Scope: exactly the files the package ships, enumerated the way the export does
 * (PackageHelper::enumerateShippedFiles; never tests/, never libraries/). Each file is read with the
 * tokenizer of its type (PHP token_get_all; a JS lexer that knows strings, template literals and regex
 * literals; CSS, SQL, XML/HTML/SVG and INI lexers), and only comments and human-read texts are judged:
 * comments, string literals, template text, XML attribute values and text, SQL schema COMMENTs and seed
 * strings, language-file values. Code is never judged.
 *
 * What stays on purpose (real data, a glyph the screen shows, a name that IS code) is listed in
 * copy-clean-allow.json beside this file, one entry per file + rule + exact token or line text, with
 * its class and reason; never a whole file. A new occurrence fails with its file:line and the rule.
 * An allow-list entry that matches nothing fails too, so the list never outlives its reason.
 *
 * Reads files only: no database, no Joomla application, no write. Runs in seconds.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/tools/copy-clean-gate.php
 *   ... --root=<an install-layout tree>       scan that tree instead of the docroot
 *   ... --package=<an extracted package>      scan an extracted installable (repo layout)
 *   ... --allow=<file>                        another allow-list (an empty one lists every occurrence)
 *   ... --json=<file>                         also write each occurrence not allowed, with its exact key, as JSON
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

\defined('_JEXEC') or \define('_JEXEC', 1);

/** Box drawing and block elements (R1), as a character class. */
const CC_BOX = '\x{2500}-\x{259F}';

/** The R2 and R3 characters that may not stand in a comment or a human-read text. */
const CC_FORBIDDEN = [
    "\u{2014}" => 'R2 em-dash', "\u{2013}" => 'R2 en-dash',
    "\u{2192}" => 'R3 arrow', "\u{2190}" => 'R3 arrow', "\u{21D2}" => 'R3 arrow', "\u{21D0}" => 'R3 arrow',
    "\u{2194}" => 'R3 arrow', "\u{21C4}" => 'R3 arrow', "\u{21D4}" => 'R3 arrow', "\u{27F6}" => 'R3 arrow',
    "\u{27F5}" => 'R3 arrow', "\u{279C}" => 'R3 arrow', "\u{2794}" => 'R3 arrow',
    "\u{2026}" => 'R3 ellipsis', "\u{201C}" => 'R3 curly quote', "\u{201D}" => 'R3 curly quote',
    "\u{201E}" => 'R3 curly quote', "\u{2018}" => 'R3 curly quote', "\u{2019}" => 'R3 curly quote',
    "\u{201A}" => 'R3 curly quote', "\u{2032}" => 'R3 prime', "\u{2033}" => 'R3 prime',
    "\u{00D7}" => 'R3 multiplication sign', "\u{2264}" => 'R3 comparison sign', "\u{2265}" => 'R3 comparison sign',
    "\u{2260}" => 'R3 comparison sign', "\u{00B1}" => 'R3 plus-minus', "\u{2022}" => 'R3 bullet',
    "\u{00B7}" => 'R3 middle dot', "\u{00A7}" => 'R3 section sign', "\u{00A0}" => 'R3 non-breaking space',
    "\u{2009}" => 'R3 thin space', "\u{202F}" => 'R3 narrow space', "\u{2002}" => 'R3 en space',
    "\u{2003}" => 'R3 em space', "\u{2212}" => 'R3 minus sign', "\u{00F7}" => 'R3 division sign',
    "\u{203A}" => 'R3 angle quote', "\u{2039}" => 'R3 angle quote', "\u{00AB}" => 'R3 angle quote',
    "\u{00BB}" => 'R3 angle quote', "\u{25BA}" => 'R3 pointer', "\u{25B6}" => 'R3 pointer',
    "\u{25C4}" => 'R3 pointer', "\u{25C0}" => 'R3 pointer', "\u{2248}" => 'R3 approximately',
];

/** R5: names that must leave comments and texts. Case-insensitive words, then case-sensitive acronyms. */
const CC_NAMES_CI = [
    'DHL', 'FedEx', 'Speedex', 'Geniki', 'Taxydromiki', 'BOX\s?NOW', 'BoxNow', 'USPS', 'Royal\s+Mail', 'Skroutz',
    'Aramex', 'Courier\s+Center', 'Wolt', 'efood', 'Glovo', 'Econt', 'Purolator', 'Chronopost', 'Colissimo',
    'PostNL', 'Correos',
    'Stripe', 'PayPal', 'Viva(?:\s+Wallet)?', 'Revolut', 'Cardlink', 'Klarna', 'Adyen', 'Ayden', 'Braintree',
    'Worldpay', 'Mollie', 'Apple\s+Pay', 'Google\s+Pay', 'Everypay', 'Alpha\s+Bank', 'Eurobank', 'Piraeus',
    'Magento', 'PrestaShop', 'VirtueMart', 'WooCommerce', 'Shopify', 'HikaShop', 'J2Store', 'BigCommerce', 'Odoo',
    'osCommerce', 'JoomShopping', 'Phoca(?:\s+Cart)?', 'WordPress', 'Drupal', 'Shopware', 'Ecwid',
    'SP\s+Page\s+Builder', 'YOOtheme', 'Akeeba', 'Gantry', 'Regular\s+Labs', 'Sourcerer', 'RSForm', 'Balbooa',
    'Gridbox', 'Elementor', 'Falang', 'Nicepage', 'Smart\s+Slider', 'jQuery', 'myDATA', 'AADE', 'Google',
    'Facebook', 'Microsoft', 'Amazon', 'Mailchimp', 'SendGrid', 'Mailgun', 'Twilio', 'Zapier', 'Algolia',
    'Elasticsearch', 'Meilisearch', 'Typesense', 'OpenSearch', 'Cloudflare', 'ChatGPT', 'OpenAI', 'Anthropic',
    'Copilot',
];
const CC_NAMES_CS = [
    'ACS', 'ELTA', 'TNT', 'DPD', 'GLS', 'UPS', 'Hermes', 'InPost', 'Speedy', 'IRIS', 'Nexi', 'JCC', 'OpenCart',
    'Helix', 'Quix', 'Astroid', 'S3', 'Claude',
];

/**
 * R5 inside identifiers, paths, keys and URLs: found without word boundaries, kept only on an identifier
 * boundary (the neighbour is not a letter, or a camelCase step separates them), so "viva" in "survival" or
 * "acs" inside a word never hits.
 */
const CC_EMBEDDED = [
    'cardlink', 'box_now', 'boxnow', 'stripe', 'paypal', 'viva', 'revolut', 'klarna', 'adyen', 'ayden', 'dhl', 'fedex',
    'speedex', 'skroutz', 'elta', 'iris', 'acs', 'geniki', 'taxydromiki', 'aramex', 'usps', 'everypay', 'braintree',
    'worldpay', 'mollie', 'magento', 'prestashop', 'virtuemart', 'opencart', 'woocommerce', 'shopify', 'hikashop',
    'j2store', 'phoca', 'mydata', 'aade', 'google', 'gmail', 'outlook', 'jquery', 'akeeba', 'yootheme', 'helix',
    'gantry', 'falang',
];

/**
 * R6 (the owner's standing rule: a comment states the domain reason, never the process that produced it):
 * ruling keys, decision and finding ids, "owner ruling", decision-register citations, review references and
 * session dates. Lines carrying @since, @deprecated or @version are not judged.
 */
const CC_R6 = [
    'R6 ruling key'               => '/\\bw\\d{1,2}[a-z]?-(?:q\\d+[a-z]?|[a-z][a-z0-9]*(?:-[a-z0-9]+)+)/',
    'R6 decision or finding id'   => '/\\b(?:OD\\d+[a-z]?|PS-?\\d+|P[1-4]-\\d+[a-z]?|Q\\d+[a-z]?(?:\\/[A-Z]\\d+)?)\\b/',
    'R6 owner-ruling reference'   => '/\\bowner(?:\'s)?\\s+(?:ruling|rulings|ruled|decision|decided|rule|rules|call)\\b|\\bruled by the owner\\b|\\(owner\\b[^)]{0,40}\\)|\\bthe owner\'s (?:canonical|ruling|rulings|contract|decision)\\b|\\bowner,\\s*20\\d\\d-/i',
    'R6 decision-register citation' => '/\\bDECISIONS\\.md\\b|\\bRATIFIED\\b|\\bLOCKED\\s+20\\d\\d|\\bratified\\b/i',
    'R6 process date'             => '/\\b20(?:2[3-7])-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\\d|3[01])\\b/',
    'R6 review reference'         => '/\\b(?:review|reviewer|verifier|re-check|recheck|audit)\\s+(?:round\\s+)?\\d{4}-\\d\\d-\\d\\d\\b|\\bfound in the browser\\b/i',
    'R6 finding key'              => '/\\bpass\\d::[A-Za-z]+-\\d+\\b|\\bnew::[a-z][a-z0-9-]+|\\b(?:MONEY|PAY|MV|ADR|DOC)-\\d+\\b|\\b[A-Z]{1,2}-R\\d+\\b|\\bItem \\d+ \\//',
    'R6 bare ruling key'          => '/\\bw1\\d[a-z]?\\b/',
    'R6 review label'             => '/\\((?:D\\d+|arm\\s+[A-Z]?\\d+)[,)]|\\bruling\\s+\\d+\\b|\\bverification\\s+\\d+\\b|\\(option\\s*$|\\boption\\s+[A-D]\\b(?=[).,])|\\(owner\\s*$/',
];

/** The embedded R5 hits in a text: [byte offset, name]. */
function cc_embedded(string $text): array
{
    $out = [];

    if (!preg_match_all('/' . implode('|', CC_EMBEDDED) . '/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }

    foreach ($m[0] as [$w, $at]) {
        $prev   = $at > 0 ? $text[$at - 1] : '';
        $next   = $text[$at + \strlen($w)] ?? '';
        $isA    = static fn (string $c): bool => $c !== '' && ctype_alpha($c);
        $leftOk = !$isA($prev) || (ctype_lower($prev) && ctype_upper($w[0]));

        if (ctype_upper($w)) {
            $rightOk = !$isA($next);
        } else {
            $rightOk = !$isA($next) || (ctype_lower($w[-1]) && ctype_upper($next));
        }

        if ($leftOk && $rightOk) {
            $out[] = [$at, $w];
        }
    }

    return $out;
}

/** The judged spans: comments, and texts a person reads. */
const CC_COMMENT = 'C';
const CC_TEXT    = 'T';

/**
 * PHP: comments, string literals, inline HTML (its comments and its text).
 *
 * @return array<int, array{0: string, 1: string, 2: int, 3: int}> [class, kind, start, end] in bytes.
 */
function cc_php_spans(string $src): array
{
    $spans = [];
    $pos   = 0;

    foreach (token_get_all($src) as $t) {
        $text = \is_array($t) ? $t[1] : $t;
        $len  = \strlen($text);

        if (\is_array($t)) {
            switch ($t[0]) {
                case T_COMMENT:
                case T_DOC_COMMENT:
                    $spans[] = [CC_COMMENT, 'php-comment', $pos, $pos + $len];
                    break;
                case T_CONSTANT_ENCAPSED_STRING:
                case T_ENCAPSED_AND_WHITESPACE:
                    $spans[] = [CC_TEXT, 'php-string', $pos, $pos + $len];
                    break;
                case T_INLINE_HTML:
                    foreach (cc_html_chunk_spans($text, $pos) as $s) {
                        $spans[] = $s;
                    }
                    break;
            }
        }

        $pos += $len;
    }

    return $spans;
}

/** A template's inline HTML chunk: its <!-- --> comments, and the rest as text. */
function cc_html_chunk_spans(string $text, int $offset): array
{
    $spans = [];
    $pos   = 0;

    if (preg_match_all('/<!--.*?-->/s', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$c, $at]) {
            if ($at > $pos) {
                $spans[] = [CC_TEXT, 'template-text', $offset + $pos, $offset + $at];
            }

            $spans[] = [CC_COMMENT, 'html-comment', $offset + $at, $offset + $at + \strlen($c)];
            $pos     = $at + \strlen($c);
        }
    }

    if ($pos < \strlen($text)) {
        $spans[] = [CC_TEXT, 'template-text', $offset + $pos, $offset + \strlen($text)];
    }

    return $spans;
}

/**
 * JavaScript: comments, string literals, template chunks and regex literals (judged as code, never
 * as text). A '/' starts a regex where an expression may start: after an operator, an opening
 * bracket, a comma or semicolon, or a keyword such as return; never after a name, a number, a closing
 * bracket or a literal.
 */
function cc_js_spans(string $src): array
{
    static $regexKeywords = ['return', 'typeof', 'case', 'do', 'else', 'in', 'instanceof', 'new', 'delete', 'void',
        'throw', 'of', 'yield', 'await'];
    $spans = [];
    $n     = \strlen($src);
    $i     = 0;
    $last  = null;   // [type, value] of the last significant token
    $stack = [];     // '{' for a brace, '${' for an open template substitution

    $templateChunk = static function (int $from) use ($src, $n, &$spans, &$stack): int {
        $j = $from;

        while ($j < $n) {
            $c = $src[$j];

            if ($c === '\\') {
                $j += 2;
                continue;
            }

            if ($c === '`') {
                $spans[] = [CC_TEXT, 'js-template', $from, $j];

                return $j + 1;
            }

            if ($c === '$' && ($src[$j + 1] ?? '') === '{') {
                $spans[] = [CC_TEXT, 'js-template', $from, $j];
                $stack[] = '${';

                return $j + 2;
            }

            $j++;
        }

        $spans[] = [CC_TEXT, 'js-template', $from, $n];

        return $n;
    };

    while ($i < $n) {
        $c = $src[$i];

        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v") {
            $i++;
            continue;
        }

        $two = substr($src, $i, 2);

        if ($two === '//') {
            $e       = strpos($src, "\n", $i);
            $e       = $e === false ? $n : $e;
            $e       = ($e > $i && $src[$e - 1] === "\r") ? $e - 1 : $e;
            $spans[] = [CC_COMMENT, 'js-comment', $i, $e];
            $i       = $e;
            continue;
        }

        if ($two === '/*') {
            $e       = strpos($src, '*/', $i + 2);
            $e       = $e === false ? $n : $e + 2;
            $spans[] = [CC_COMMENT, 'js-comment', $i, $e];
            $i       = $e;
            continue;
        }

        if ($c === '"' || $c === "'") {
            $j = $i + 1;

            while ($j < $n && $src[$j] !== $c) {
                $j += $src[$j] === '\\' ? 2 : 1;
            }

            $spans[] = [CC_TEXT, 'js-string', $i, min($j + 1, $n)];
            $i       = $j + 1;
            $last    = ['str', ''];
            continue;
        }

        if ($c === '`') {
            $i    = $templateChunk($i + 1);
            $last = ['tmpl', ''];
            continue;
        }

        if ($c === '/') {
            $regexOk = $last === null
                || ($last[0] === 'punct' && !\in_array($last[1], [')', ']', '}'], true))
                || ($last[0] === 'name' && \in_array($last[1], $regexKeywords, true));

            if ($regexOk) {
                $j       = $i + 1;
                $inClass = false;

                while ($j < $n) {
                    $d = $src[$j];

                    if ($d === '\\') {
                        $j += 2;
                        continue;
                    }

                    if ($d === "\n") {
                        break;
                    }

                    if ($inClass) {
                        $inClass = $d !== ']';
                    } elseif ($d === '[') {
                        $inClass = true;
                    } elseif ($d === '/') {
                        break;
                    }

                    $j++;
                }

                $j++;

                while ($j < $n && ctype_alpha($src[$j])) {
                    $j++;
                }

                $spans[] = [CC_TEXT, 'js-regex', $i, $j];
                $i       = $j;
                $last    = ['regex', ''];
                continue;
            }

            $i++;
            $last = ['punct', '/'];
            continue;
        }

        if ($c === '{') {
            // A block after ')', ';', '{', '}', '=>', a keyword or at the start; an object literal elsewhere.
            $block = $last === null
                || ($last[0] === 'punct' && \in_array($last[1], [')', ';', '{', '}', '=>'], true))
                || ($last[0] === 'name' && \in_array($last[1], ['else', 'do', 'try', 'finally'], true));
            $stack[] = $block ? '{b' : '{e';
            $i++;
            $last = ['punct', '{'];
            continue;
        }

        if ($c === '}') {
            $top = array_pop($stack);
            $i++;

            if ($top === '${') {
                $i    = $templateChunk($i);
                $last = ['tmpl', ''];
                continue;
            }

            // After a block a new statement (and so a regex) may start; after an object literal it may not.
            $last = $top === '{b' ? ['punct', ';'] : ['punct', '}'];
            continue;
        }

        if (preg_match('/\G[A-Za-z_$\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $src, $m, 0, $i)) {
            $i   += \strlen($m[0]);
            $last = ['name', $m[0]];
            continue;
        }

        if (preg_match('/\G(?:0[xXbBoO][0-9a-fA-F_]+n?|(?:\d[\d_]*\.?[\d_]*|\.\d[\d_]*)(?:[eE][+-]?\d+)?n?)/', $src, $m, 0, $i)) {
            $i   += \strlen($m[0]);
            $last = ['num', ''];
            continue;
        }

        // Punctuators: the longest that matches; only its last character matters for a regex decision.
        if (preg_match('/\G(?:>>>=|\.\.\.|===|!==|\*\*=|<<=|>>=|>>>|\?\?=|&&=|\|\|=|=>|==|!=|<=|>=|&&|\|\||\?\?|\?\.|\+\+|--|\+=|-=|\*=|%=|&=|\|=|\^=|<<|>>|\*\*|[\(\)\[\];,<>+\-*%&|\^!~?:=.@#])/', $src, $m, 0, $i)) {
            $i   += \strlen($m[0]);
            $last = ['punct', $m[0]];
            continue;
        }

        $i++;
    }

    return $spans;
}

/** CSS: comments and string literals. */
function cc_css_spans(string $src): array
{
    $spans = [];
    $n     = \strlen($src);
    $i     = 0;

    while ($i < $n) {
        if (substr($src, $i, 2) === '/*') {
            $e       = strpos($src, '*/', $i + 2);
            $e       = $e === false ? $n : $e + 2;
            $spans[] = [CC_COMMENT, 'css-comment', $i, $e];
            $i       = $e;
        } elseif ($src[$i] === '"' || $src[$i] === "'") {
            $q = $src[$i];
            $j = $i + 1;

            while ($j < $n && $src[$j] !== $q && $src[$j] !== "\n") {
                $j += $src[$j] === '\\' ? 2 : 1;
            }

            $spans[] = [CC_TEXT, 'css-string', $i, min($j + 1, $n)];
            $i       = $j + 1;
        } else {
            $i++;
        }
    }

    return $spans;
}

/** SQL: -- # and block comments; quoted strings (a COMMENT '...' is schema documentation). */
function cc_sql_spans(string $src): array
{
    $spans = [];
    $n     = \strlen($src);
    $i     = 0;

    while ($i < $n) {
        $c   = $src[$i];
        $two = substr($src, $i, 2);

        if ($two === '/*') {
            $e       = strpos($src, '*/', $i + 2);
            $e       = $e === false ? $n : $e + 2;
            $spans[] = [CC_COMMENT, 'sql-comment', $i, $e];
            $i       = $e;
        } elseif ($two === '--' && ($i + 2 >= $n || strpbrk($src[$i + 2], " \t\r\n-") !== false)) {
            $e       = strpos($src, "\n", $i);
            $e       = $e === false ? $n : $e;
            $spans[] = [CC_COMMENT, 'sql-comment', $i, $e];
            $i       = $e;
        } elseif ($c === '#') {
            $e       = strpos($src, "\n", $i);
            $e       = $e === false ? $n : $e;
            $spans[] = [CC_COMMENT, 'sql-comment', $i, $e];
            $i       = $e;
        } elseif ($c === "'" || $c === '"') {
            $j = $i + 1;

            while ($j < $n) {
                if ($src[$j] === '\\') {
                    $j += 2;
                    continue;
                }

                if ($src[$j] === $c) {
                    if (($src[$j + 1] ?? '') === $c) {
                        $j += 2;
                        continue;
                    }

                    break;
                }

                $j++;
            }

            $spans[] = [CC_TEXT, 'sql-string', $i, min($j + 1, $n)];
            $i       = $j + 1;
        } elseif ($c === '`') {
            $e = strpos($src, '`', $i + 1);
            $i = ($e === false ? $n : $e) + 1;
        } else {
            $i++;
        }
    }

    return $spans;
}

/** XML, SVG, HTML: comments, attribute values, text and CDATA. */
function cc_xml_spans(string $src): array
{
    $spans = [];
    $n     = \strlen($src);
    $i     = 0;
    $text  = 0;

    $flush = static function (int $s, int $e) use ($src, &$spans): void {
        if ($e > $s && trim(substr($src, $s, $e - $s)) !== '') {
            $spans[] = [CC_TEXT, 'xml-text', $s, $e];
        }
    };

    while ($i < $n) {
        if (substr($src, $i, 4) === '<!--') {
            $flush($text, $i);
            $e       = strpos($src, '-->', $i + 4);
            $e       = $e === false ? $n : $e + 3;
            $spans[] = [CC_COMMENT, 'xml-comment', $i, $e];
            $i       = $text = $e;
        } elseif (substr($src, $i, 9) === '<![CDATA[') {
            $flush($text, $i);
            $e       = strpos($src, ']]>', $i + 9);
            $e       = $e === false ? $n : $e;
            $spans[] = [CC_TEXT, 'xml-text', $i + 9, $e];
            $i       = $text = $e + 3;
        } elseif (substr($src, $i, 2) === '<?') {
            $flush($text, $i);
            $e = strpos($src, '?>', $i + 2);
            $i = $text = ($e === false ? $n : $e + 2);
        } elseif ($src[$i] === '<') {
            $flush($text, $i);
            $j = $i + 1;

            while ($j < $n && $src[$j] !== '>') {
                if ($src[$j] === '"' || $src[$j] === "'") {
                    $k       = strpos($src, $src[$j], $j + 1);
                    $k       = $k === false ? $n : $k;
                    $spans[] = [CC_TEXT, 'xml-attribute', $j + 1, $k];
                    $j       = $k;
                }

                $j++;
            }

            $i = $text = $j + 1;
        } else {
            $i++;
        }
    }

    $flush($text, $n);

    return $spans;
}

/** INI: ; comment lines and the value inside the quotes. */
function cc_ini_spans(string $src): array
{
    $spans = [];
    $pos   = 0;

    foreach (explode("\n", $src) as $line) {
        $trimmed = ltrim($line);
        $lead    = \strlen($line) - \strlen($trimmed);
        $body    = rtrim($line, "\r");

        if (str_starts_with($trimmed, ';')) {
            $spans[] = [CC_COMMENT, 'ini-comment', $pos + $lead, $pos + \strlen($body)];
        } elseif (preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*"(.*)"\s*$/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $spans[] = [CC_TEXT, 'language-value', $pos + $m[2][1], $pos + $m[2][1] + \strlen($m[2][0])];
        }

        $pos += \strlen($line) + 1;
    }

    return $spans;
}

/** The lexer for a shipped file, by its extension; null when the type holds no comment or text. */
function cc_spans_for(string $path, string $src): ?array
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'php'                => cc_php_spans($src),
        'js', 'mjs'          => cc_js_spans($src),
        'css'                => cc_css_spans($src),
        'sql'                => cc_sql_spans($src),
        'xml', 'svg', 'html' => cc_xml_spans($src),
        'ini'                => cc_ini_spans($src),
        'json', 'txt'        => [[CC_TEXT, 'text-file', 0, \strlen($src)]],
        default              => null,
    };
}

/**
 * R1 for one comment line: the decoration left once the comment syntax is gone.
 *
 * @return string|null Why the line is a banner, or null.
 */
function cc_banner(string $line): ?string
{
    $content = preg_replace('~^\s*(?://+|#+|/\*+|\*+(?!/)|--|;|<!--)?~', '', $line);
    $content = trim(preg_replace('~(?:\*+/|-->)\s*$~', '', $content));

    if ($content === '') {
        return null;
    }

    if (preg_match('/[' . CC_BOX . ']/u', $content)) {
        return 'R1 box-drawing character';
    }

    if (preg_match('%^[=\-*#~_+|/<>\s]+$%', $content) && preg_match('/([=\-*#~_])\1\1/', $content)) {
        return 'R1 rule line';
    }

    if (preg_match('/^([=\-*~_])\1\1+\s*\S/', $content) || preg_match('/\S\s*([=\-*#~_])\1\1+$/', $content)) {
        return 'R1 heading framed by a rule';
    }

    if (preg_match('/^([=\-~_])\1\s.*\s([=\-~_])\2+$/', $content) || preg_match('/^--\s+[A-Za-z]/', $content)) {
        return 'R1 heading framed by a rule';
    }

    return null;
}

/** Every finding in one span: [rule, detail, byte offset inside the source]. */
function cc_judge_span(string $src, array $span, string $namesRegex): array
{
    [$class, $kind, $s, $e] = $span;
    $text     = substr($src, $s, $e - $s);
    $findings = [];

    if ($kind === 'js-regex' || $kind === 'text-file') {
        if ($kind === 'text-file') {
            foreach (CC_FORBIDDEN as $char => $rule) {
                $at = strpos($text, $char);

                if ($at !== false) {
                    $findings[] = [substr($rule, 0, 2), $rule, $s + $at];
                }
            }
        }

        return $findings;
    }

    if ($class === CC_COMMENT) {
        $off = 0;

        foreach (explode("\n", $text) as $line) {
            $why = cc_banner($line);

            if ($why !== null) {
                $findings[] = ['R1', $why, $s + $off];
            }

            $off += \strlen($line) + 1;
        }
    }

    foreach (CC_FORBIDDEN as $char => $rule) {
        $from = 0;

        while (($at = strpos($text, $char, $from)) !== false) {
            $findings[] = [substr($rule, 0, 2), $rule, $s + $at];
            $from       = $at + \strlen($char);
        }
    }

    $named = [];

    if (preg_match_all($namesRegex, $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$name, $at]) {
            $findings[] = ['R5', 'R5 third-party name "' . $name . '"', $s + $at];
            $named[$at] = true;
        }
    }

    foreach (cc_embedded($text) as [$at, $name]) {
        if (!isset($named[$at])) {
            $findings[] = ['R5', 'R5 third-party name inside an identifier, path or key "' . $name . '"', $s + $at];
        }
    }

    if ($class === CC_COMMENT) {
        $off = 0;

        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/@(?:since|deprecated|version)\\b/', $line)) {
                foreach (CC_R6 as $why => $pattern) {
                    if (preg_match_all($pattern, $line, $m6, PREG_OFFSET_CAPTURE)) {
                        foreach ($m6[0] as [$hit, $at]) {
                            $findings[] = ['R6', $why . ' "' . $hit . '"', $s + $off + $at];
                        }
                    }
                }
            }

            $off += \strlen($line) + 1;
        }
    }

    return $findings;
}

// Nothing above runs; the gate starts here.

$docroot   = \dirname(__DIR__, 5);
$root      = $docroot;
$package   = null;
$allowPath = __DIR__ . '/copy-clean-allow.json';
$jsonPath  = null;

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/');
    } elseif (str_starts_with($argument, '--package=')) {
        $package = rtrim(substr($argument, 10), '/');
    } elseif (str_starts_with($argument, '--allow=')) {
        $allowPath = substr($argument, 8);
    } elseif (str_starts_with($argument, '--json=')) {
        $jsonPath = substr($argument, 7);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}


$namesRegex = '/(?<![A-Za-z0-9_])(?:(?i:' . implode('|', CC_NAMES_CI) . ')|' . implode('|', CC_NAMES_CS) . ')(?![A-Za-z0-9])/u';

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

// [CC0] the judge itself: each rule goes red on a planted line, and code is never judged.
$plant = static function (string $file, string $src) use ($namesRegex): array {
    $rules = [];

    foreach (cc_spans_for($file, $src) as $span) {
        foreach (cc_judge_span($src, $span, $namesRegex) as $f) {
            $rules[] = $f[0];
        }
    }

    return array_values(array_unique($rules));
};
$check('[CC0a] a Unicode banner line in a PHP comment is R1', $plant('a.php', "<?php\n// \u{2550}\u{2550}\u{2550} Totals \u{2550}\u{2550}\n") === ['R1']);
$check('[CC0b] an ASCII rule line in a docblock is R1', $plant('a.php', "<?php\n/**\n * =========\n */\n") === ['R1']);
$check('[CC0c] an em-dash in a JS comment is R2', $plant('a.js', "var a = 1; // one \u{2014} two\n") === ['R2']);
$check('[CC0d] an em-dash inside a JS regex literal is code, not judged', $plant('a.js', "var r = /\u{2014}/g;\n") === []);
$check('[CC0e] an arrow in a template literal is R3', $plant('a.js', "var t = `a \u{2192} \${x} b`;\n") === ['R3']);
$check('[CC0f] a carrier in a language value is R5', $plant('a.ini', "KEY=\"Ship with DHL\"\n") === ['R5']);
$check('[CC0g] a carrier name in PHP code (an identifier) is not judged', $plant('a.php', "<?php\n\$dhl = new Dhl();\n") === []);
$check('[CC0h] a heading framed by -- is R1', $plant('a.css', "/* -- Buttons -- */\n") === ['R1']);
$check('[CC0i] a markdown ## heading in a docblock is not a banner', $plant('a.php', "<?php\n/**\n * ## The rule\n */\n") === []);
$check('[CC0j] a name inside a path in a comment is R5', $plant('a.php', "<?php\n// see logs/alfa_payments_cardlink.php\n") === ['R5']);
$check('[CC0k] a name hidden inside an ordinary word is not', $plant('a.php', "<?php\n// survival of the access rules\n") === []);
$check('[CC0l] a ruling key and an owner ruling in a comment are R6', $plant('a.php', "<?php\n// stays (owner ruling OD21, w10a-q7)\n") === ['R6']);
$check('[CC0m] a @since date is not a process date', $plant('a.php', "<?php\n/**\n * @since 2026-09-24\n */\n") === []);

// The shipped files, as the export enumerates them.
$helper = ($package ?? $root . '/administrator/components/com_alfa') . ($package !== null ? '/administrator/src/Helper/PackageHelper.php' : '/src/Helper/PackageHelper.php');

if (!is_file($helper)) {
    echo "  FAIL [CC1] PackageHelper not found at {$helper}\n";
    echo "0 passed, 1 failed\n";
    exit(1);
}

require_once $helper;

$map = $package !== null
    ? \Alfa\Component\Alfa\Administrator\Helper\PackageHelper::enumerateFromPackage(packageRoot: $package)
    : \Alfa\Component\Alfa\Administrator\Helper\PackageHelper::enumerateShippedFiles(installRoot: $root);
ksort($map);
$check('[CC1] the export enumerates shipped files', \count($map) > 1000, \count($map) . ' files');

$allow   = [];
$allowed = [];

if (is_file($allowPath)) {
    $list = json_decode((string) file_get_contents($allowPath), true, 512, JSON_THROW_ON_ERROR);

    foreach ($list['entries'] as $n => $entry) {
        $allow[$entry['file'] . "\0" . $entry['rule'] . "\0" . $entry['text']] = $n;
    }
}

$findings = [];
$scanned  = 0;

foreach ($map as $live => $info) {
    $src   = (string) file_get_contents($info['abs']);
    $spans = cc_spans_for($live, $src);

    if ($spans === null) {
        continue;
    }

    $scanned++;

    foreach ($spans as $span) {
        foreach (cc_judge_span($src, $span, $namesRegex) as [$rule, $why, $at]) {
            $line      = substr_count($src, "\n", 0, $at) + 1;
            $lineStart = strrpos(substr($src, 0, $at), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd   = strpos($src, "\n", $at);
            $lineText  = trim(substr($src, $lineStart, ($lineEnd === false ? \strlen($src) : $lineEnd) - $lineStart));
            // A string literal is keyed by its exact token; a comment, template text or XML text by its line.
            $token     = $span[0] === CC_TEXT && !\in_array($span[1], ['template-text', 'xml-text', 'text-file'], true)
                ? substr($src, $span[2], $span[3] - $span[2])
                : $lineText;
            $key       = $live . "\0" . $rule . "\0" . $token;

            if (isset($allow[$key])) {
                $allowed[$allow[$key]] = true;
                continue;
            }

            $findings[] = [$live, $line, $rule, $why, $lineText, $token];
        }
    }
}

$check('[CC2] every shipped file with comments or texts was read', $scanned > 1000, "{$scanned} files");

$byRule = [];

foreach ($findings as [$file, $line, $rule, $why, $text]) {
    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
}

if ($jsonPath !== null) {
    $rows = array_map(static fn (array $f): string => json_encode(
        ['file' => $f[0], 'line' => $f[1], 'rule' => $f[2], 'why' => $f[3], 'text' => $f[5]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ), $findings);
    file_put_contents($jsonPath, implode("\n", $rows) . ($rows === [] ? '' : "\n"));
}

ksort($byRule);

/** Occurrences printed one by one; the rest are counted, so a red run never floods the runner. */
const CC_PRINT_LIMIT = 300;

foreach ($findings as $n => [$file, $line, $rule, $why, $text]) {
    if ($n < CC_PRINT_LIMIT) {
        $check("[CC3] {$file}:{$line} {$why}", false, mb_strimwidth($text, 0, 160, '...'));
    } else {
        $fail++;
    }
}

if (\count($findings) > CC_PRINT_LIMIT) {
    echo '  ... and ' . (\count($findings) - CC_PRINT_LIMIT) . " more occurrence(s) not printed (--json writes them all)\n";
}

if ($findings === []) {
    $check('[CC3] no comment or human-read text breaks R1, R2, R3 or R5', true);
}

foreach ($allow as $key => $n) {
    if (!isset($allowed[$n])) {
        [$file, $rule, $text] = explode("\0", $key);
        $check("[CC4] allow-list entry {$n} still matches something", false, "{$file} {$rule} " . mb_strimwidth($text, 0, 120, '...'));
    }
}

if ($allow !== [] && \count($allowed) === \count($allow)) {
    $check('[CC4] every allow-list entry still matches an occurrence', true);
}

printf("copy-clean gate: %d shipped files, %d judged; %d occurrence(s) allowed by %d entries; %d not allowed %s\n",
    \count($map), $scanned, \count($allowed), \count($allow), \count($findings),
    $byRule === [] ? '' : json_encode($byRule));
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
