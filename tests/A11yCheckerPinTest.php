<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The magic words for the checker itself: the local a11y scripts run
 * Preflight's own checker, vendored in tools/a11y/check.mjs, at the same tag
 * as CI. This test fails when the copy drifts from the pinned tag, so a page
 * that passes locally passes the pipeline. Open the door, but only to the
 * right bytes.
 */
final class A11yCheckerPinTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The Preflight tag the a11y component runs at in .gitlab-ci.yml. */
    private const PREFLIGHT_TAG = '0.9.0';

    /** SHA-256 of templates/a11y/check.mjs at that tag, LF line endings. */
    private const CHECKER_SHA256 = '8f11379ac03cee8b52e39835639430abe1c749eeb5ffb2abb43aeaa300f1c663';

    /** The image default of the a11y component at that tag, digest included. */
    private const IMAGE = 'mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d';

    /** The template defaults the checker packages must pin. */
    private const AXE_PLAYWRIGHT_VERSION = '4.14.0';

    private const AXE_CORE_VERSION = '4.14.0';

    /** How to bring a drifted pin back, in one commit. */
    private const UPDATE = 'To update the a11y pin, in one commit: bump the preflight/a11y tag in .gitlab-ci.yml, '
        . 'recopy the checker byte for byte with git -C <a Preflight clone> show <tag>:templates/a11y/check.mjs '
        . '> tools/a11y/check.mjs (LF line endings), move the image string in scripts/a11y.sh and scripts/a11y.ps1 '
        . 'and the pins in tools/a11y/package.json to the new tag, and update the constants in this test.';

    public function testTheVendoredCheckerMatchesThePinnedTagByteForByte(): void
    {
        self::assertFileExists(self::ROOT . '/tools/a11y/check.mjs', 'tools/a11y/check.mjs is missing; ' . self::UPDATE);
        $raw = (string) file_get_contents(self::ROOT . '/tools/a11y/check.mjs');

        self::assertSame(
            self::CHECKER_SHA256,
            hash('sha256', str_replace("\r\n", "\n", $raw)),
            'tools/a11y/check.mjs does not match Preflight ' . self::PREFLIGHT_TAG . ' byte for byte. ' . self::UPDATE,
        );
    }

    public function testEveryCiIncludeOfTheA11yComponentNamesThePinnedTag(): void
    {
        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');
        preg_match_all('/preflight\/a11y@([0-9]+\.[0-9]+\.[0-9]+)/', $ci, $matches);
        self::assertNotEmpty($matches[1], '.gitlab-ci.yml includes no preflight/a11y component');

        foreach (array_unique($matches[1]) as $tag) {
            self::assertSame(
                self::PREFLIGHT_TAG,
                $tag,
                '.gitlab-ci.yml pins the a11y component to ' . $tag . ', not ' . self::PREFLIGHT_TAG . '. ' . self::UPDATE,
            );
        }
    }

    public function testTheLocalScriptsUseThePinnedImage(): void
    {
        $scripts = [
            'scripts/a11y.sh',
            'scripts/a11y.ps1',
        ];
        foreach ($scripts as $script) {
            $code = (string) file_get_contents(self::ROOT . '/' . $script);
            preg_match_all('/mcr\.microsoft\.com\/playwright:[0-9A-Za-z.:@_-]+/', $code, $matches);
            self::assertNotEmpty($matches[0], $script . ' names no Playwright image');

            foreach (array_unique($matches[0]) as $image) {
                self::assertSame(
                    self::IMAGE,
                    $image,
                    $script . ' uses ' . $image . ', not the pinned image. ' . self::UPDATE,
                );
            }
        }
    }

    public function testTheCheckerPackagesPinTheTemplateAxeVersions(): void
    {
        $package = json_decode((string) file_get_contents(self::ROOT . '/tools/a11y/package.json'), true);
        self::assertIsArray($package, 'tools/a11y/package.json is not valid JSON');
        $dependencies = $package['dependencies'] ?? [];

        self::assertSame(
            self::AXE_PLAYWRIGHT_VERSION,
            $dependencies['@axe-core/playwright'] ?? null,
            '@axe-core/playwright must pin the template axe_playwright_version default. ' . self::UPDATE,
        );
        self::assertSame(
            self::AXE_CORE_VERSION,
            $dependencies['axe-core'] ?? null,
            'axe-core must pin the template axe_core_version default. ' . self::UPDATE,
        );
    }
}
