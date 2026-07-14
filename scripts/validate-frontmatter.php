<?php

declare(strict_types=1);

/**
 * Per-connector README frontmatter validator — PHP mirror of
 * `scripts/validate-frontmatter.mjs` in `location-ts`.
 *
 * Reads every `src/Providers/<Provider>/README.md`, parses the single leading YAML
 * frontmatter block at the top of the file (`providerId` plus an `operations:` map
 * keyed by operation — one entry per operation the provider supports), and validates
 * required keys and value shapes against the schema documented in
 * `schemas/connector-readme-schema.yaml`. Exits 0 on success, 1 with
 * line-prefixed errors on any failure. `--expected-blocks` counts total operations
 * across all READMEs.
 *
 * Wired into the CI lint-gates job. Standalone (no runtime deps; no
 * `symfony/yaml`) so it can run pre-commit too. Minimal parser scoped to the
 * frontmatter shape we accept.
 *
 * Usage:
 *   php scripts/validate-frontmatter.php
 *   php scripts/validate-frontmatter.php --expected-blocks 21
 *   php scripts/validate-frontmatter.php --expected-providers 6
 */

$repoRoot = dirname(__DIR__);
$providersDir = $repoRoot . '/src/Providers';

$args = array_slice($argv, 1);

/** Read a `--flag <int>` CLI arg with a fallback. */
function readArg(array $args, string $name, int $fallback): int
{
    $idx = array_search($name, $args, true);
    if ($idx === false || !isset($args[$idx + 1])) {
        return $fallback;
    }
    $value = $args[$idx + 1];
    return is_numeric($value) ? (int) $value : $fallback;
}

$EXPECTED_BLOCKS = readArg($args, '--expected-blocks', 21);
$EXPECTED_PROVIDERS = readArg($args, '--expected-providers', 6);

// --- minimal YAML parser scoped to the frontmatter shape we accept ---
// Supports: scalar keys, nested objects (2-space indent), arrays of scalars,
// quoted and unquoted strings, booleans, integers, null/~, and the YAML
// literal block scalar (`|`) used by `notes_passthrough`.

/** @return mixed */
function coerceScalar(string $s): mixed
{
    if ($s === '' || $s === 'null' || $s === '~') {
        return null;
    }
    if ($s === 'true') {
        return true;
    }
    if ($s === 'false') {
        return false;
    }
    if (preg_match('/^-?\d+$/', $s) === 1) {
        return (int) $s;
    }
    if (strlen($s) >= 2) {
        $first = $s[0];
        $last = $s[strlen($s) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($s, 1, -1);
        }
    }
    return $s;
}

/**
 * @return array{0: array<string, mixed>, 1: int}
 */
function parseFrontmatter(string $text): array
{
    /** @var list<string> $lines */
    $lines = explode("\n", $text);
    $i = 0;
    $root = [];

    /**
     * @return array<string, mixed>
     */
    $parseBlock = function (int $indent) use (&$lines, &$i, &$parseBlock, &$parseArray): array {
        $obj = [];
        while ($i < count($lines)) {
            $raw = $lines[$i];
            if (trim($raw) === '' || str_starts_with(trim($raw), '#')) {
                $i++;
                continue;
            }
            preg_match('/^ */', $raw, $m);
            $currentIndent = strlen($m[0]);
            if ($currentIndent < $indent) {
                return $obj;
            }
            if ($currentIndent > $indent) {
                throw new RuntimeException(sprintf('Unexpected indent at line %d: "%s"', $i + 1, $raw));
            }
            $line = substr($raw, $indent);
            if (str_starts_with($line, '- ')) {
                return $obj;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new RuntimeException(sprintf('Expected key:value at line %d: "%s"', $i + 1, $raw));
            }
            $key = trim(substr($line, 0, $colon));
            $rest = trim(substr($line, $colon + 1));
            $i++;

            if ($rest === '') {
                $j = $i;
                while ($j < count($lines) && trim($lines[$j]) === '') {
                    $j++;
                }
                if ($j >= count($lines)) {
                    $obj[$key] = null;
                    continue;
                }
                $peek = $lines[$j];
                preg_match('/^ */', $peek, $pm);
                $peekIndent = strlen($pm[0]);
                if ($peekIndent <= $indent) {
                    $obj[$key] = null;
                    continue;
                }
                $peekLine = substr($peek, $peekIndent);
                if (str_starts_with($peekLine, '- ')) {
                    $obj[$key] = $parseArray($peekIndent);
                } else {
                    $obj[$key] = $parseBlock($peekIndent);
                }
            } elseif ($rest === '|') {
                $blockIndent = $indent + 2;
                $collected = [];
                while ($i < count($lines)) {
                    $bl = $lines[$i];
                    if (trim($bl) === '') {
                        $collected[] = '';
                        $i++;
                        continue;
                    }
                    preg_match('/^ */', $bl, $bm);
                    $blIndent = strlen($bm[0]);
                    if ($blIndent < $blockIndent) {
                        break;
                    }
                    $collected[] = substr($bl, $blockIndent);
                    $i++;
                }
                $obj[$key] = rtrim(implode("\n", $collected), "\n");
            } elseif (str_starts_with($rest, '[') && str_ends_with($rest, ']')) {
                $inner = trim(substr($rest, 1, -1));
                if ($inner === '') {
                    $obj[$key] = [];
                } else {
                    $parts = explode(',', $inner);
                    $obj[$key] = array_map(fn(string $s) => coerceScalar(trim($s)), $parts);
                }
            } else {
                $obj[$key] = coerceScalar($rest);
            }
        }
        return $obj;
    };

    /**
     * @return list<mixed>
     */
    $parseArray = function (int $indent) use (&$lines, &$i): array {
        $arr = [];
        while ($i < count($lines)) {
            $raw = $lines[$i];
            if (trim($raw) === '' || str_starts_with(trim($raw), '#')) {
                $i++;
                continue;
            }
            preg_match('/^ */', $raw, $m);
            $currentIndent = strlen($m[0]);
            if ($currentIndent < $indent) {
                return $arr;
            }
            $line = substr($raw, $indent);
            if (!str_starts_with($line, '- ')) {
                return $arr;
            }
            $item = trim(substr($line, 2));
            $arr[] = coerceScalar($item);
            $i++;
        }
        return $arr;
    };

    while ($i < count($lines)) {
        $raw = $lines[$i];
        if (trim($raw) === '' || str_starts_with(trim($raw), '#')) {
            $i++;
            continue;
        }
        preg_match('/^ */', $raw, $m);
        $indent = strlen($m[0]);
        $root = array_merge($root, $parseBlock($indent));
        break;
    }
    while ($i < count($lines)) {
        $before = $i;
        $root = array_merge($root, $parseBlock(0));
        if ($i === $before) {
            $i++;
        }
    }
    return [$root, $i];
}

/**
 * Location READMEs carry ONE leading YAML frontmatter block at the very top of
 * the file (opening `---` on line 1, so GitHub renders it as real frontmatter),
 * with every operation keyed under `operations:`. Extract that single block:
 * scan to the first `---`, parse through the matching close. A leading `# title`
 * before the block (if any) is skipped; markdown thematic-break `---` separators
 * later in the body are not part of the block.
 *
 * @return array{startLine: int, meta: array<string, mixed>}|null
 */
function extractLeadingFrontmatter(string $content, string $file): ?array
{
    /** @var list<string> $lines */
    $lines = explode("\n", $content);
    $i = 0;
    while ($i < count($lines) && trim($lines[$i]) !== '---') {
        $i++;
    }
    if ($i >= count($lines)) {
        return null;
    }
    $startLine = $i + 1;
    $j = $i + 1;
    while ($j < count($lines) && trim($lines[$j]) !== '---') {
        $j++;
    }
    if ($j >= count($lines)) {
        throw new RuntimeException(sprintf('%s: unterminated frontmatter block starting at line %d', $file, $startLine));
    }
    $yamlText = implode("\n", array_slice($lines, $i + 1, $j - $i - 1));
    [$meta, ] = parseFrontmatter($yamlText);
    return ['startLine' => $startLine, 'meta' => $meta];
}

// --- schema validation (mirrors schemas/connector-readme-schema.yaml) ---
const AUTH_METHODS = [
    'api-key-header',
    'api-key-query',
    'api-key-form',
    'bearer',
    'arcgis-token',
    'oauth2-client-credentials',
    'none',
];
const TOKEN_LIFECYCLES = ['static', 'rotating', 'refreshable', 'none'];
const OPERATIONS = ['routing', 'matrix', 'geocoding', 'isochrone'];
// Required at the top level of the single leading frontmatter block.
const REQUIRED_TOP = ['providerId', 'operations'];
// Required inside each operation object (the value under operations.<op>).
const REQUIRED_OP = [
    'auth',
    'endpoint',
    'versioning',
    'selfHostable',
    'notes_passthrough',
];

/**
 * Validate one operation's metadata — the object under `operations.<op>`.
 *
 * @param array<string, mixed> $meta
 * @return list<string>
 */
function validateOperation(array $meta, string $file, int $blockLine, string $op): array
{
    $errors = [];
    $prefix = sprintf("%s:%d (operation '%s')", $file, $blockLine, $op);
    foreach (REQUIRED_OP as $key) {
        if (!array_key_exists($key, $meta) || $meta[$key] === null) {
            $errors[] = sprintf("%s: missing required key '%s'", $prefix, $key);
        }
    }
    if (isset($meta['auth']) && is_array($meta['auth'])) {
        foreach (['method', 'tokenLifecycle'] as $k) {
            if (!array_key_exists($k, $meta['auth'])) {
                $errors[] = sprintf('%s: auth.%s is required', $prefix, $k);
            }
        }
        if (isset($meta['auth']['method']) && is_string($meta['auth']['method'])) {
            if (!in_array($meta['auth']['method'], AUTH_METHODS, true)) {
                $errors[] = sprintf("%s: auth.method '%s' must be one of %s", $prefix, $meta['auth']['method'], implode(', ', AUTH_METHODS));
            }
        }
        if (isset($meta['auth']['tokenLifecycle']) && is_string($meta['auth']['tokenLifecycle'])) {
            if (!in_array($meta['auth']['tokenLifecycle'], TOKEN_LIFECYCLES, true)) {
                $errors[] = sprintf("%s: auth.tokenLifecycle '%s' must be one of %s", $prefix, $meta['auth']['tokenLifecycle'], implode(', ', TOKEN_LIFECYCLES));
            }
        }
    }
    if (isset($meta['endpoint']) && is_array($meta['endpoint'])) {
        if (!isset($meta['endpoint']['default'])) {
            $errors[] = sprintf('%s: endpoint.default is required', $prefix);
        } else {
            $default = $meta['endpoint']['default'];
            if (!is_string($default) || preg_match('#^https?://#', $default) !== 1) {
                $errors[] = sprintf("%s: endpoint.default must be an http(s) URL (got '%s')", $prefix, is_scalar($default) ? (string) $default : gettype($default));
            }
        }
    }
    if (isset($meta['versioning']) && is_array($meta['versioning'])) {
        if (!isset($meta['versioning']['vendorApiVersion'])) {
            $errors[] = sprintf('%s: versioning.vendorApiVersion is required', $prefix);
        }
        if (!isset($meta['versioning']['lastVerified'])) {
            $errors[] = sprintf('%s: versioning.lastVerified is required', $prefix);
        } else {
            $lv = (string) $meta['versioning']['lastVerified'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lv) !== 1) {
                $errors[] = sprintf("%s: versioning.lastVerified '%s' must be ISO date YYYY-MM-DD", $prefix, $lv);
            }
        }
    }
    if (array_key_exists('selfHostable', $meta) && !is_bool($meta['selfHostable'])) {
        $errors[] = sprintf('%s: selfHostable must be a boolean', $prefix);
    }
    if (array_key_exists('retryAfterSurfaced', $meta) && !is_bool($meta['retryAfterSurfaced'])) {
        $errors[] = sprintf('%s: retryAfterSurfaced must be a boolean', $prefix);
    }
    if (array_key_exists('notes_passthrough', $meta) && $meta['notes_passthrough'] !== null && !is_string($meta['notes_passthrough'])) {
        $errors[] = sprintf('%s: notes_passthrough must be a string', $prefix);
    }
    return $errors;
}

/**
 * @return list<array{id: string, path: string, missing?: bool}>
 */
function listProviderReadmes(string $providersDir): array
{
    $readmes = [];
    if (!is_dir($providersDir)) {
        return $readmes;
    }
    $entries = scandir($providersDir);
    if ($entries === false) {
        return $readmes;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $providersDir . '/' . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $readme = $full . '/README.md';
        // providerId in YAML is lowercase — derive from the PascalCase dir name.
        $id = strtolower($entry);
        if (is_file($readme)) {
            $readmes[] = ['id' => $id, 'path' => $readme];
        } else {
            $readmes[] = ['id' => $id, 'path' => $readme, 'missing' => true];
        }
    }
    return $readmes;
}

$readmes = listProviderReadmes($providersDir);
$errors = [];

$present = array_values(array_filter($readmes, fn(array $r) => !($r['missing'] ?? false)));
$missing = array_values(array_filter($readmes, fn(array $r) => $r['missing'] ?? false));
foreach ($missing as $m) {
    $errors[] = $m['path'] . ': missing per-connector README';
}

$totalBlocks = 0;
foreach ($present as $r) {
    $content = file_get_contents($r['path']);
    if ($content === false) {
        $errors[] = $r['path'] . ': could not read file';
        continue;
    }
    try {
        $block = extractLeadingFrontmatter($content, $r['path']);
        if ($block === null) {
            $errors[] = $r['path'] . ': contains no YAML frontmatter block';
            continue;
        }
        $line = $block['startLine'];
        $meta = $block['meta'];
        $prefix = $r['path'] . ':' . $line;

        foreach (REQUIRED_TOP as $key) {
            if (!array_key_exists($key, $meta) || $meta[$key] === null) {
                $errors[] = sprintf("%s: missing required key '%s'", $prefix, $key);
            }
        }
        if (isset($meta['providerId']) && is_string($meta['providerId'])) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', $meta['providerId']) !== 1) {
                $errors[] = sprintf("%s: providerId '%s' must match /^[a-z][a-z0-9-]*$/", $prefix, $meta['providerId']);
            }
            if ($meta['providerId'] !== $r['id']) {
                $errors[] = sprintf("%s: providerId '%s' does not match directory name '%s'", $prefix, $meta['providerId'], $r['id']);
            }
        }
        if (isset($meta['operations']) && is_array($meta['operations']) && $meta['operations'] !== []) {
            foreach ($meta['operations'] as $op => $opMeta) {
                $op = (string) $op;
                if (!in_array($op, OPERATIONS, true)) {
                    $errors[] = sprintf("%s: operation '%s' must be one of %s", $prefix, $op, implode(', ', OPERATIONS));
                }
                if (!is_array($opMeta)) {
                    $errors[] = sprintf("%s: operations.%s must be a mapping", $prefix, $op);
                    continue;
                }
                $totalBlocks++;
                $errors = array_merge($errors, validateOperation($opMeta, $r['path'], $line, $op));
            }
        } elseif (array_key_exists('operations', $meta)) {
            $errors[] = sprintf('%s: operations must be a non-empty mapping of operation → metadata', $prefix);
        }
    } catch (Throwable $e) {
        $errors[] = $r['path'] . ': ' . $e->getMessage();
    }
}

$totalProviders = count($present);
if ($totalProviders !== $EXPECTED_PROVIDERS) {
    $errors[] = sprintf('coverage: found %d per-connector README(s); expected %d', $totalProviders, $EXPECTED_PROVIDERS);
}
if ($totalBlocks !== $EXPECTED_BLOCKS) {
    $errors[] = sprintf('coverage: found %d frontmatter block(s) across all READMEs; expected %d', $totalBlocks, $EXPECTED_BLOCKS);
}

if (count($errors) > 0) {
    foreach ($errors as $e) {
        fwrite(STDERR, $e . "\n");
    }
    fwrite(STDERR, sprintf("\n%d error(s) — frontmatter validation failed.\n", count($errors)));
    exit(1);
}

printf(
    "OK — %d per-connector README(s) / %d frontmatter block(s) validated against schemas/connector-readme-schema.yaml\n",
    $totalProviders,
    $totalBlocks,
);
