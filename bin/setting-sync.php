#!/usr/bin/env php
<?php

declare(strict_types=1);

// setting-sync keeps two texts in step across the stack: the Setting resolver
// (src/Support/Setting.php in Playbook) and the shared WSU-values section
// (between the markers of docs/guides/wsu-values.md). Both are embedded in
// this file, so the copy each consumer carries is self-contained: same bytes
// in every stack repo, copy it, don't fork it (the Squire agent-ready pattern).
//
// In Playbook, where the sources sit next to the tool:
//   php bin/setting-sync.php --embed           re-embed the sources in this file
//   php bin/setting-sync.php --embed --check   exit 1 when the embedding drifted
//   php bin/setting-sync.php --sync-to=<dir>   copy this tool to <dir>/bin/
//
// In a consumer package (helmet, sesame, rollcall, ...):
//   php bin/setting-sync.php --write           write Setting.php, the wsu-values
//                                             section and setting-sync.json
//   php bin/setting-sync.php --check           exit 1 when any of them drifted
// Both read the namespace and the environment-only list from setting-sync.json,
// so a script never quotes a backslash path. The first run (no manifest yet)
// gives the options, and options always win over the manifest and rewrite it:
//   php bin/setting-sync.php --write  --namespace=<Vendor>\<Package>\Support
//                              [--environment-only=KEY[,KEY]] [--root=<dir>]
//   php bin/setting-sync.php --check --namespace=<Vendor>\<Package>\Support
//                              [--environment-only=KEY[,KEY]] [--root=<dir>]
//
// Exit codes: 0 clean, 1 drift, 2 usage or tool error.

const BEGIN_MARKER = '<!-- playbook:wsu-values:begin -->';
const END_MARKER = '<!-- playbook:wsu-values:end -->';

// A consumer package's Setting namespace: <Vendor>\<Package>\Support.
const NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9]*\\\\[A-Z][A-Za-z0-9]*\\\\Support$/';

// The exact bytes of Playbook's src/Support/Setting.php. The blank line
// before the closing marker is load-bearing: PHP drops the newline before
// the marker, so the constant keeps the file's trailing newline.
const SETTING_SOURCE = <<<'SETTING_SOURCE'
<?php

declare(strict_types=1);

namespace Waynestate\Playbook\Support;

/**
 * One resolver for a shared value: the environment first, Playbook as the
 * fallback.
 *
 * The copy that helmet, sesame and rollcall each carry today, kept here so
 * one owner changes the order once. Playbook is optional by design: the
 * class works when it is not installed, because the consumer packages only
 * suggest it, never require it.
 */
final class Setting
{
    /** @var list<string> */
    public const ENVIRONMENT_ONLY = [];

    /**
     * A value from the environment only.
     *
     * getenv() wins over $_ENV over $_SERVER; the first one that holds a
     * non-empty string counts. An empty string means unset, so a key set to
     * nothing falls through. Playbook is never consulted here.
     */
    public static function env(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A shared value: the environment first, then Playbook.
     *
     * A key in ENVIRONMENT_ONLY never falls back to Playbook, so a shared
     * package can never supply a secret the app keeps in its environment.
     * When Playbook is not installed, or has no value, get() returns null.
     */
    public static function get(string $key): ?string
    {
        $value = self::env($key);
        if ($value !== null) {
            return $value;
        }

        if (self::isEnvironmentOnly($key, self::ENVIRONMENT_ONLY)) {
            return null;
        }

        if (!class_exists(\Waynestate\Playbook\Playbook::class)) {
            return null;
        }

        $fallback = \Waynestate\Playbook\Playbook::get($key);

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * @param list<string> $keys
     */
    private static function isEnvironmentOnly(string $key, array $keys): bool
    {
        return in_array($key, $keys, true);
    }
}

SETTING_SOURCE;

const SETTING_SHA256 = '40b5c56a1574dac9ba68a567b3c3be4b177434d36ba43e20a129fa2770135240';

// The exact text between the two marker lines of docs/guides/wsu-values.md,
// not including the newline after the begin marker or the one before the end
// marker, so a round trip through --write is byte-stable.
const SHARED_SECTION = <<<'SHARED_SECTION'
## Get Playbook with your own GitLab token

The repository is private, so every path below authenticates with a personal
access token of your own. Pick the one that fits:

### Composer

Point Composer at the instance in your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.wayne.edu/enterprise-apps/playbook.git"
        }
    ]
}
```

Then require the package:

```sh
composer require waynestate/playbook
```

The credential goes in `auth.json` (a `gitlab-token` entry for git.wayne.edu),
never in the repository.

### Fetch the values file directly

```sh
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" \
    "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" \
    -o .env.playbook
```

```powershell
Invoke-WebRequest `
    -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } `
    -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" `
    -OutFile .env.playbook
```

A fetched `.env.playbook` is plain `KEY=value` lines to copy into the app's
`.env`.

### Clone

```sh
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

The token is your own: `read_api` for curl, `read_repository` for clone and
Composer; keep it out of the repo. Playbook is private, so on a 404 ask the
Enterprise Applications team for read access.
SHARED_SECTION;

const SECTION_SHA256 = 'f565bee6921391c658cd552575f11cef7c47c772d2f8a5975099891d0982642a';

$rawArgs = $GLOBALS['argv'] ?? [];
$args = [];
if (is_array($rawArgs)) {
    foreach (array_slice($rawArgs, 1) as $arg) {
        if (is_string($arg)) {
            $args[] = $arg;
        }
    }
}

exit(main($args));

/**
 * @param list<string> $args
 */
function main(array $args): int
{
    $opts = parseOptions($args);

    if ($opts['sync_to'] !== null) {
        if ($opts['embed'] || $opts['write'] || $opts['check'] || $opts['namespace'] !== null || $opts['environment_only_given']) {
            fail('--sync-to stands alone');
        }

        return runSyncTo($opts['sync_to']);
    }

    if ($opts['write']) {
        if ($opts['check']) {
            fail('--write and --check are different modes');
        }

        [$namespace, $keys] = resolveOptions($opts, 'write');
        $namespace = validatedNamespace($namespace);

        return runWrite($namespace, $keys, $opts['root']);
    }

    if ($opts['embed']) {
        if ($opts['namespace'] !== null || $opts['environment_only_given']) {
            fail('--embed takes no --namespace or --environment-only');
        }

        return runEmbed($opts['check']);
    }

    if ($opts['check']) {
        [$namespace, $keys] = resolveOptions($opts, 'check');

        return runCheck($namespace, $keys, $opts['root']);
    }

    fail('nothing to do; ' . usage());
}

/**
 * @param list<string> $args
 *
 * @return array{embed: bool, check: bool, write: bool, sync_to: ?string, namespace: ?string, environment_only: list<string>, environment_only_given: bool, root: string}
 */
function parseOptions(array $args): array
{
    $cwd = getcwd();
    $opts = [
        'embed' => false,
        'check' => false,
        'write' => false,
        'sync_to' => null,
        'namespace' => null,
        'environment_only' => [],
        'environment_only_given' => false,
        'root' => $cwd === false ? '.' : $cwd,
    ];

    foreach ($args as $arg) {
        if ($arg === '--embed') {
            $opts['embed'] = true;
            continue;
        }
        if ($arg === '--check') {
            $opts['check'] = true;
            continue;
        }
        if ($arg === '--write') {
            $opts['write'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            fail("unknown argument \"$arg\"; " . usage());
        }

        $eq = strpos($arg, '=');
        $name = $eq === false ? $arg : substr($arg, 0, $eq);
        $value = $eq === false ? null : substr($arg, $eq + 1);

        switch ($name) {
            case '--sync-to':
                $opts['sync_to'] = requiredValue($name, $value);
                break;
            case '--namespace':
                // Kept raw: --write validates it (it seeds the manifest), while
                // --check compares it against the manifest and reports a
                // difference as drift, exit 1.
                $opts['namespace'] = requiredValue($name, $value);
                break;
            case '--environment-only':
                $opts['environment_only'] = validatedKeys($name, $value);
                $opts['environment_only_given'] = true;
                break;
            case '--root':
                $opts['root'] = requiredValue($name, $value);
                break;
            default:
                fail("unknown option \"$name\"; " . usage());
        }
    }

    return $opts;
}

/**
 * @param array{namespace: ?string, environment_only: list<string>, environment_only_given: bool, root: string} $opts
 *
 * @return array{0: string, 1: list<string>} the namespace and keys to use
 */
function resolveOptions(array $opts, string $mode): array
{
    $manifestFile = $opts['root'] . '/setting-sync.json';
    if ($opts['namespace'] === null) {
        if (!is_file($manifestFile)) {
            if ($mode === 'write') {
                fail('no setting-sync.json and no --namespace: first run needs --namespace');
            }
            fwrite(STDERR, "setting-sync: drift: missing {$manifestFile}\n");
            exit(1);
        }
        $recorded = readManifest($manifestFile);
        $opts['namespace'] = $recorded['namespace'];
        if (!$opts['environment_only_given']) {
            $opts['environment_only'] = $recorded['environment_only'];
        }
    }
    if (!$opts['environment_only_given'] && $opts['environment_only'] === [] && is_file($manifestFile)) {
        // A manifest with an empty list is authoritative too: it says the
        // package has no secrets, so do not fall back to one.
        $recorded = readManifest($manifestFile);
        $opts['environment_only'] = $recorded['environment_only'];
    }

    return [$opts['namespace'], $opts['environment_only']];
}

/**
 * @return array{namespace: string, environment_only: list<string>}
 */
function readManifest(string $manifestFile): array
{
    try {
        $data = json_decode(readBytes($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail("{$manifestFile} is not valid JSON");
    }
    if (!is_array($data)) {
        fail("{$manifestFile} must hold a JSON object");
    }
    if (!isset($data['namespace']) || !is_string($data['namespace'])) {
        fail("{$manifestFile} has no namespace string");
    }
    if (preg_match(NAMESPACE_PATTERN, $data['namespace']) !== 1) {
        fail("{$manifestFile} has an invalid namespace \"{$data['namespace']}\" (want <Vendor>\\<Package>\\Support)");
    }
    $namespace = $data['namespace'];
    $keys = [];
    if (isset($data['environment_only'])) {
        if (!is_array($data['environment_only'])) {
            fail("{$manifestFile} has no environment_only list of strings");
        }
        foreach ($data['environment_only'] as $key) {
            if (!is_string($key) || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                fail("{$manifestFile} has an invalid environment_only key");
            }
            $keys[] = $key;
        }
    }

    return ['namespace' => $namespace, 'environment_only' => $keys];
}

function requiredValue(string $name, ?string $value): string
{
    if ($value === null) {
        fail("$name needs a value (--name=value)");
    }

    return $value;
}

function validatedNamespace(string $namespace): string
{
    if (preg_match(NAMESPACE_PATTERN, $namespace) !== 1) {
        fail("invalid --namespace \"$namespace\" (want <Vendor>\\<Package>\\Support)");
    }

    return $namespace;
}

/**
 * @return list<string>
 */
function validatedKeys(string $name, ?string $value): array
{
    $value = requiredValue($name, $value);
    if ($value === '') {
        return [];
    }
    $keys = explode(',', $value);
    foreach ($keys as $key) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            fail("invalid --environment-only key \"$key\" (want UPPER_SNAKE)");
        }
    }

    return $keys;
}

function usage(): string
{
    return 'usage: php bin/setting-sync.php (--embed [--check] | --sync-to=<dir> | --write | --check [options]); --write and --check take the namespace and the environment-only list from setting-sync.json, or from --namespace=<Vendor>\\<Package>\\Support [--environment-only=KEY[,KEY]] [--root=<dir>]';
}

function runEmbed(bool $check): int
{
    [$settingFile, $pageFile] = requireSources();
    $source = readBytes($settingFile);
    $section = extractSection(readBytes($pageFile));

    if ($check) {
        $problems = [];
        if (SETTING_SOURCE !== $source) {
            $problems[] = "embedded Setting.php differs from {$settingFile}";
        }
        if (SETTING_SHA256 !== hash('sha256', $source)) {
            $problems[] = "SETTING_SHA256 does not match {$settingFile}";
        }
        if (SHARED_SECTION !== $section) {
            $problems[] = "embedded shared section differs from {$pageFile}";
        }
        if (SECTION_SHA256 !== hash('sha256', $section)) {
            $problems[] = "SECTION_SHA256 does not match {$pageFile}";
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                fwrite(STDERR, "setting-sync: {$problem}\n");
            }

            return 1;
        }

        echo "setting-sync: the embedded texts are in step with the sources.\n";

        return 0;
    }

    atomicWrite(__FILE__, replaceEmbedded(readBytes(__FILE__), $source, $section));
    echo "setting-sync: embedded {$settingFile} and the shared section of {$pageFile}.\n";

    return 0;
}

function runSyncTo(string $dir): int
{
    requireSources();
    if (!is_dir($dir)) {
        fail("--sync-to: {$dir} is not a directory");
    }
    $target = $dir . '/bin/setting-sync.php';
    $bytes = str_replace("\r\n", "\n", readBytes(__FILE__));
    if (is_file($target) && readBytes($target) === $bytes) {
        echo "setting-sync: unchanged: {$target}\n";

        return 0;
    }
    atomicWrite($target, $bytes);
    echo "setting-sync: wrote {$target}\n";

    return 0;
}

/**
 * @param list<string> $keys
 */
function runWrite(string $namespace, array $keys, string $root): int
{
    verifyTool();
    $setting = renderSetting($namespace, $keys);
    $pageFile = $root . '/docs/guides/wsu-values.md';
    if (!is_file($pageFile)) {
        // First run in a fresh package: seed the page around the markers.
        $newPage = renderNewPage();
    } else {
        $newPage = replaceSection(readBytes($pageFile));
    }
    $manifest = renderManifest($namespace, $keys, $setting);

    atomicWrite($root . '/src/Support/Setting.php', $setting);
    atomicWrite($pageFile, $newPage);
    atomicWrite($root . '/setting-sync.json', $manifest);
    echo "setting-sync: wrote Setting.php, the wsu-values section and setting-sync.json under {$root}\n";

    return 0;
}

/**
 * @param list<string> $keys
 */
function runCheck(string $namespace, array $keys, string $root): int
{
    verifyTool();
    $settingFile = $root . '/src/Support/Setting.php';
    $pageFile = $root . '/docs/guides/wsu-values.md';
    $manifestFile = $root . '/setting-sync.json';

    $problems = [];
    foreach ([$settingFile, $pageFile, $manifestFile] as $file) {
        if (!is_file($file)) {
            $problems[] = "drift: missing {$file}";
        }
    }
    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDERR, "setting-sync: {$problem}\n");
        }

        return 1;
    }

    $expectedSetting = renderSetting($namespace, $keys);
    $expectedPage = replaceSection(readBytes($pageFile));
    $expectedManifest = renderManifest($namespace, $keys, $expectedSetting);
    if (readBytes($settingFile) !== $expectedSetting) {
        $problems[] = "drift: {$settingFile}" . manifestDifference($manifestFile, $namespace, $keys);
    }
    if (readBytes($pageFile) !== $expectedPage) {
        $problems[] = "drift: {$pageFile}";
    }
    $manifestBytes = readBytes($manifestFile);
    if ($manifestBytes !== $expectedManifest) {
        $problems[] = "drift: {$manifestFile}" . manifestDifference($manifestFile, $namespace, $keys);
    }
    try {
        $manifestData = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $manifestData = null;
    }
    if (is_array($manifestData)) {
        $recordedToolSha = $manifestData['tool_sha256'] ?? null;
        if (!is_string($recordedToolSha) || $recordedToolSha !== hash('sha256', readBytes(__FILE__))) {
            $problems[] = "drift: " . __FILE__ . " (tool_sha256 in {$manifestFile})";
        }
    }

    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDERR, "setting-sync: {$problem}\n");
        }

        return 1;
    }

    echo "setting-sync: {$root} is in step with this tool.\n";

    return 0;
}

/**
 * @param list<string> $keys
 */
function manifestDifference(string $manifestFile, string $namespace, array $keys): string
{
    // Only when the call said something different from the manifest: name both.
    try {
        $data = json_decode(readBytes($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return '';
    }
    if (!is_array($data)) {
        return '';
    }
    $notes = [];
    if (isset($data['namespace']) && is_string($data['namespace']) && $data['namespace'] !== $namespace) {
        $notes[] = "{$manifestFile} says \"{$data['namespace']}\", the call says \"$namespace\"";
    }
    if (isset($data['environment_only']) && is_array($data['environment_only'])) {
        $recorded = array_values(array_filter($data['environment_only'], 'is_string'));
        if ($recorded !== $keys) {
            $notes[] = "{$manifestFile} says [" . implode(', ', $recorded) . "], the call says [" . implode(', ', $keys) . ']';
        }
    }
    if ($notes === []) {
        return '';
    }

    return ': ' . implode('; ', $notes);
}

function verifyTool(): void
{
    if (hash('sha256', SETTING_SOURCE) !== SETTING_SHA256 || hash('sha256', SHARED_SECTION) !== SECTION_SHA256) {
        fail('tool file was edited: the embedded texts no longer hash to their constants; re-copy it from Playbook');
    }
}

/**
 * @return array{0: string, 1: string}
 */
function requireSources(): array
{
    $base = dirname(__DIR__);
    $settingFile = $base . '/src/Support/Setting.php';
    $pageFile = $base . '/docs/guides/wsu-values.md';
    if (!is_file($settingFile) || !is_file($pageFile)) {
        fail("--embed and --sync-to run only in Playbook, where {$settingFile} and {$pageFile} sit next to the tool");
    }

    return [$settingFile, $pageFile];
}

/**
 * @return array{0: int, 1: int}
 */
function markerOffsets(string $page): array
{
    if (substr_count($page, BEGIN_MARKER) !== 1 || substr_count($page, END_MARKER) !== 1) {
        fail('the wsu-values page must carry the begin and end markers exactly once');
    }
    $begin = strpos($page, BEGIN_MARKER);
    $end = strpos($page, END_MARKER);
    if ($begin === false || $end === false || $begin >= $end) {
        fail('the wsu-values page is missing its markers');
    }
    if (($begin > 0 && $page[$begin - 1] !== "\n") || ($page[$begin + strlen(BEGIN_MARKER)] ?? '') !== "\n") {
        fail('the begin marker must sit on its own line');
    }
    if (($page[$end - 1] ?? '') !== "\n") {
        fail('the end marker must sit on its own line');
    }
    $afterEnd = $page[$end + strlen(END_MARKER)] ?? '';
    if ($afterEnd !== "\n" && $afterEnd !== '') {
        fail('the end marker must sit on its own line');
    }

    return [$begin, $end];
}

function extractSection(string $page): string
{
    [$begin, $end] = markerOffsets($page);
    $start = $begin + strlen(BEGIN_MARKER) + 1; // past the newline after the begin marker
    $stop = $end - 1;                           // before the newline that precedes the end marker

    return substr($page, $start, $stop - $start);
}

function replaceSection(string $page): string
{
    [$begin, $end] = markerOffsets($page);

    return substr($page, 0, $begin + strlen(BEGIN_MARKER)) . "\n" . SHARED_SECTION . "\n" . substr($page, $end);
}

function renderNewPage(): string
{
    // A minimal values page for a package that has none yet: the shared
    // section between the markers, nothing else to drift.
    return "# WSU values\n"
        . "\n"
        . "The section between the markers is shared by every stack package;\n"
        . "bin/setting-sync.php keeps it in step with Playbook.\n"
        . "\n"
        . BEGIN_MARKER . "\n"
        . SHARED_SECTION . "\n"
        . END_MARKER . "\n";
}

/**
 * @param list<string> $keys
 */
function renderSetting(string $namespace, array $keys): string
{
    $out = [];
    $didDeclare = false;
    $didNamespace = false;
    $didGuard = false;

    foreach (explode("\n", SETTING_SOURCE) as $line) {
        if ($line === 'declare(strict_types=1);') {
            if ($didDeclare) {
                fail('the embedded Setting.php holds the declare line twice');
            }
            $didDeclare = true;
            $out[] = $line;
            $out[] = '';
            $out[] = '// Generated by Playbook bin/setting-sync.php. Do not edit; run it again (php bin/setting-sync.php --write ...).';
            continue;
        }
        if ($line === 'namespace Waynestate\Playbook\Support;') {
            if ($didNamespace) {
                fail('the embedded Setting.php holds the namespace line twice');
            }
            $didNamespace = true;
            $out[] = 'namespace ' . $namespace . ';';
            continue;
        }
        if ($line === '    public const ENVIRONMENT_ONLY = [];') {
            if ($didGuard) {
                fail('the embedded Setting.php holds the ENVIRONMENT_ONLY line twice');
            }
            $didGuard = true;
            if ($keys !== []) {
                $quoted = array_map(static fn (string $key): string => "'" . $key . "'", $keys);
                $out[] = '    public const ENVIRONMENT_ONLY = [' . implode(', ', $quoted) . '];';
            } else {
                $out[] = $line;
            }
            continue;
        }
        $out[] = $line;
    }

    if (!$didDeclare || !$didNamespace || !$didGuard) {
        fail('the embedded Setting.php lost a line the generator replaces (declare, namespace or ENVIRONMENT_ONLY)');
    }

    return implode("\n", $out);
}

/**
 * @param list<string> $keys
 */
function renderManifest(string $namespace, array $keys, string $setting): string
{
    $data = [
        'environment_only' => $keys,
        'namespace' => $namespace,
        'section_sha256' => hash('sha256', SHARED_SECTION),
        'setting_sha256' => hash('sha256', $setting),
        'tool_sha256' => hash('sha256', readBytes(__FILE__)),
    ];
    ksort($data);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail('cannot encode setting-sync.json');
    }

    return $json . "\n";
}

function replaceEmbedded(string $bytes, string $settingSource, string $sharedSection): string
{
    $bytes = replaceNowdoc($bytes, 'SETTING_SOURCE', $settingSource);
    $bytes = replaceNowdoc($bytes, 'SHARED_SECTION', $sharedSection);
    $bytes = replaceHashLine($bytes, 'SETTING_SHA256', hash('sha256', $settingSource));
    $bytes = replaceHashLine($bytes, 'SECTION_SHA256', hash('sha256', $sharedSection));

    return $bytes;
}

function replaceNowdoc(string $bytes, string $name, string $content): string
{
    nowdocSafe($name, $content);
    $open = "const {$name} = <<<'{$name}'\n";
    $close = "{$name};\n";
    $openPos = strpos($bytes, $open);
    if ($openPos === false) {
        fail("cannot find the {$name} nowdoc in the tool file; it was edited by hand");
    }
    $start = $openPos + strlen($open);
    $closePos = strpos($bytes, $close, $start);
    if ($closePos === false) {
        fail("cannot find the end of the {$name} nowdoc in the tool file");
    }

    // PHP drops the newline before the closing marker, so the file always
    // carries the content plus one newline; content that already ends with a
    // newline therefore keeps a visible blank line before the marker.
    $raw = $content . "\n";

    return substr($bytes, 0, $start) . $raw . substr($bytes, $closePos);
}

function nowdocSafe(string $name, string $content): void
{
    // Since PHP 7.3 an indented line holding only the identifier (with or
    // without the semicolon) ends the nowdoc, so such content cannot embed.
    foreach (explode("\n", $content) as $line) {
        $trimmed = trim($line);
        if ($trimmed === $name || $trimmed === $name . ';') {
            fail("the content holds a line that would end the {$name} nowdoc; cannot embed it");
        }
    }
}

function replaceHashLine(string $bytes, string $name, string $hash): string
{
    $count = 0;
    $out = preg_replace("/const {$name} = '[0-9a-f]{64}';/", "const {$name} = '{$hash}';", $bytes, 1, $count);
    if ($out === null || $count !== 1) {
        fail("cannot find the {$name} hash line in the tool file");
    }

    return $out;
}

function atomicWrite(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
        fail("cannot create {$dir}");
    }
    $tmp = $path . '.tmp-' . getmypid();
    if (file_put_contents($tmp, $contents) === false) {
        fail("cannot write {$tmp}");
    }
    if (!rename($tmp, $path)) {
        // rename() cannot replace an existing file on Windows; drop it first.
        if (is_file($path) && unlink($path) && rename($tmp, $path)) {
            return;
        }
        @unlink($tmp);
        fail("cannot replace {$path}");
    }
}

function readBytes(string $path): string
{
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        fail("cannot read {$path}");
    }

    return $bytes;
}

function fail(string $message): never
{
    fwrite(STDERR, 'setting-sync: ' . $message . "\n");
    exit(2);
}
