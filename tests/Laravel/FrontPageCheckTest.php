<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Artisan;

/**
 * The front page's lock inspection: a switch plate and a printed slip that
 * show what `php artisan sesame:check` says about a bad session setting.
 *
 * The slip is only worth printing if it tells the truth, so these tests run
 * the real command for every switch on the plate and hold the page to its
 * words: every line the script can print, and every line the page ships
 * with, must be a line the command prints for that setting. A front page
 * that makes up its own diagnosis is a horoscope with a cookie banner.
 */
final class FrontPageCheckTest extends LaravelTestCase
{
    private const string SITE = __DIR__ . '/../../site';

    /**
     * The plate's switches and the config each position stands for, on top
     * of the sound production app the slip describes.
     *
     * @var array<string, array<int|string, array{string, bool|int|string|null}>>
     */
    private const array SWITCHES = [
        'same_site' => [
            'lax' => ['session.same_site', 'lax'],
            'strict' => ['session.same_site', 'strict'],
            'none' => ['session.same_site', 'none'],
        ],
        'secure' => [
            'true' => ['session.secure', true],
            'null' => ['session.secure', null],
            'false' => ['session.secure', false],
        ],
        'driver' => [
            'file' => ['session.driver', 'file'],
            'cookie' => ['session.driver', 'cookie'],
            'array' => ['session.driver', 'array'],
        ],
        'lifetime' => [
            '120' => ['session.lifetime', 120],
            '30' => ['session.lifetime', 30],
        ],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];

        // The slip's app: production, on the cas driver, with a sound session.
        $app['env'] = 'production';
        $config->set('sesame.driver', 'cas');
        $config->set('sesame.cas.base_url', 'https://login.example.edu/cas');
        $config->set('sesame.cas.service_url', 'https://app.example.edu/login');
        $config->set('session.driver', 'file');
        $config->set('session.http_only', true);
        $config->set('session.same_site', 'lax');
        $config->set('session.secure', true);
        $config->set('session.lifetime', 120);
        $config->set('session.expire_on_close', false);
    }

    private function xpath(): DOMXPath
    {
        $html = file_get_contents(self::SITE . '/index.html');
        self::assertNotFalse($html, 'site/index.html must be readable');

        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($doc);
    }

    private function one(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, "exactly one match for {$query}");
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    /**
     * Run the command with one config value changed (or none) and return its
     * lines, blank ones dropped.
     *
     * @param array{string, bool|int|string|null}|null $change
     * @return list<string>
     */
    private function inspect(?array $change = null): array
    {
        if ($change !== null) {
            $app = $this->app;
            self::assertNotNull($app);

            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $app->make('config');
            $config->set($change[0], $change[1]);
        }

        Artisan::call('sesame:check');
        $lines = preg_split('/\R/', trim(Artisan::output()));
        self::assertIsArray($lines);

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    public function testThePrintedSlipIsWhatTheCommandSaysForASoundApp(): void
    {
        $xpath = $this->xpath();
        $items = $xpath->query('//*[@data-inspect-lines]/li');
        self::assertNotFalse($items);

        $page = [];
        foreach ($items as $item) {
            self::assertInstanceOf(DOMElement::class, $item);
            $text = (string) preg_replace('/\s+/', ' ', trim($item->textContent));
            self::assertSame('ok', $item->getAttribute('data-v'), "a sound app's slip is all OK: {$text}");
            $page[] = $text;
        }

        $said = $this->inspect();
        $summary = array_pop($said);

        self::assertSame($said, $page, 'the slip the page ships with is the command, line for line');
        self::assertSame($summary, trim($this->one($xpath, '//*[@data-inspect-sum]')->textContent));
        self::assertSame('exit 0', trim($this->one($xpath, '//*[@data-inspect-exit]')->textContent));
        self::assertSame('Cleared to open', trim($this->one($xpath, '//*[@data-inspect-stamp]')->textContent));
    }

    public function testEveryLineTheScriptCanPrintIsALineTheCommandPrints(): void
    {
        $js = file_get_contents(self::SITE . '/assets/sesame.js');
        self::assertNotFalse($js);

        $start = strpos($js, '9. the lock inspection');
        self::assertNotFalse($start, 'sesame.js has the lock inspection section');
        preg_match_all('/\["(ok|warn|fail)",\s*"([^"]+)"\]/', substr($js, $start), $pairs, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(11, count($pairs), 'the script carries every verdict the switches can reach');

        $printed = $this->inspect();
        foreach (self::SWITCHES as $positions) {
            foreach ($positions as $change) {
                $this->refreshApplication();
                $printed = [...$printed, ...$this->inspect($change)];
            }
        }

        foreach ($pairs as [, $verdict, $text]) {
            self::assertContains('[' . strtoupper($verdict) . '] ' . $text, $printed, 'sesame.js prints a line the command never does');
        }
    }

    public function testThePlateMatchesTheSwitchesTheTestFlips(): void
    {
        $xpath = $this->xpath();

        $plate = $this->one($xpath, '//form[@data-inspect-plate]');
        self::assertTrue($plate->hasAttribute('hidden'), 'without the script there is nothing to flip, so the plate stays hidden');

        $groups = [];
        $fieldsets = $xpath->query('//form[@data-inspect-plate]//fieldset');
        self::assertNotFalse($fieldsets);
        foreach ($fieldsets as $fieldset) {
            self::assertInstanceOf(DOMElement::class, $fieldset);
            $legends = $xpath->query('./legend', $fieldset);
            self::assertNotFalse($legends);
            self::assertSame(1, $legends->length, 'every switch is named by its legend');

            $radios = $xpath->query('.//input[@type="radio"]', $fieldset);
            self::assertNotFalse($radios);
            $name = '';
            $values = [];
            $checked = 0;
            foreach ($radios as $radio) {
                self::assertInstanceOf(DOMElement::class, $radio);
                $name = $name === '' ? $radio->getAttribute('name') : $name;
                self::assertSame($name, $radio->getAttribute('name'));
                $values[] = $radio->getAttribute('value');
                $checked += $radio->hasAttribute('checked') ? 1 : 0;
                self::assertInstanceOf(DOMElement::class, $radio->parentNode, 'each radio sits in its label');
                self::assertSame('label', $radio->parentNode->nodeName);
            }

            self::assertNotSame('', $name, 'every switch has positions');
            self::assertSame(1, $checked, "the {$name} switch starts on one position");
            $groups[$name] = $values;
        }

        $expected = array_map(static fn(array $positions): array => array_map('strval', array_keys($positions)), self::SWITCHES);
        self::assertSame($expected, $groups);
    }

    public function testTheReprintIsSpokenAndTheGuideIsThere(): void
    {
        $xpath = $this->xpath();

        $said = $this->one($xpath, '//*[@data-inspect-said]');
        self::assertSame('status', $said->getAttribute('role'));
        self::assertStringContainsString('wsu-sr-only', $said->getAttribute('class'));

        $stamp = $this->one($xpath, '//*[@data-inspect-stamp]');
        self::assertSame('true', $stamp->getAttribute('aria-hidden'), 'the stamp is said by the status line instead');

        $link = $this->one($xpath, '//section[@data-inspect]//a[contains(@href, "laravel.html")]');
        self::assertSame('docs/guides/laravel.html#check-before-a-deploy', $link->getAttribute('href'));

        $guide = file_get_contents(self::SITE . '/docs/guides/laravel.html');
        self::assertNotFalse($guide, 'the Laravel guide is built into site/docs');
        self::assertStringContainsString('id="check-before-a-deploy"', $guide);
    }

    public function testTheSlipMovesOnlyWithMotion(): void
    {
        $css = file_get_contents(self::SITE . '/assets/sesame.css');
        self::assertNotFalse($css);

        foreach (['.motion .slip.is-waiting', '.motion .slip.is-printing'] as $rule) {
            self::assertStringContainsString($rule, $css, "{$rule} is scoped to motion");
        }
        self::assertDoesNotMatchRegularExpression('/(?<!\.motion )\.slip\.is-(waiting|printing)[^{]*\{[^}]*animation/', $css);
    }
}
