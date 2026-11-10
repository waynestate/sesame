<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Metrics\LoginMetrics;

/**
 * The front page's door count: a hand tally under the shop hours that stands
 * for the opt-in sign-in counter. The wheels are decorative, so these pin
 * what a keyboard or screen reader user gets instead: a real button with a
 * name, a spoken count that matches the wheels, and a link that resolves.
 * Nobody signs in on the front page; the plunger only clicks.
 */
final class FrontPageTallyTest extends TestCase
{
    private const string SITE = __DIR__ . '/../site';

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

    private static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    public function testThePlungerIsANamedButton(): void
    {
        $xpath = $this->xpath();
        $tally = $this->one($xpath, '//aside[@data-tally]');
        $button = $this->one($xpath, '//aside[@data-tally]//button[@data-tally-click]');

        self::assertSame('button', $button->getAttribute('type'), 'a plunger never submits anything');
        self::assertSame('Count one more sign-in', trim($button->textContent));

        $title = $this->one($xpath, '//*[@id="' . $tally->getAttribute('aria-labelledby') . '"]');
        self::assertSame('Door count', trim($title->textContent));
    }

    public function testTheSpokenCountMatchesTheWheels(): void
    {
        $xpath = $this->xpath();

        $window = $this->one($xpath, '//aside[@data-tally]//*[' . self::hasClass('tally__window') . ']');
        self::assertSame('true', $window->getAttribute('aria-hidden'), 'the wheels are decorative');

        $strips = $xpath->query('//aside[@data-tally]//*[' . self::hasClass('tally__strip') . ']');
        self::assertNotFalse($strips);
        self::assertSame(4, $strips->length, 'four wheels, like the real thing');

        $digits = '';
        foreach ($strips as $strip) {
            self::assertInstanceOf(DOMElement::class, $strip);
            self::assertSame('0123456789', $strip->textContent);
            $style = trim($strip->getAttribute('style'));
            self::assertSame(1, preg_match('/^--d:\s*[0-9]$/', $style), "a wheel sets one digit: {$style}");
            $digits .= substr($style, -1);
        }

        $said = $this->one($xpath, '//aside[@data-tally]//*[@data-tally-said]');
        self::assertStringContainsString('wsu-sr-only', $said->getAttribute('class'));
        self::assertSame('The counter reads ' . (int) $digits . '.', trim($said->textContent));

        $button = $this->one($xpath, '//button[@data-tally-click]');
        self::assertSame($said->getAttribute('id'), $button->getAttribute('aria-describedby'));
    }

    public function testTheTagsAreTheOutcomesSesameCounts(): void
    {
        $tags = $this->xpath()->query('//aside[@data-tally]//li[' . self::hasClass('tally__tag') . ']');
        self::assertNotFalse($tags);

        $names = [];
        foreach ($tags as $tag) {
            self::assertInstanceOf(DOMElement::class, $tag);
            $names[] = trim($tag->textContent);
        }

        self::assertSame(LoginMetrics::OUTCOMES, $names);
    }

    public function testTheNoteLinksTheMetricsGuide(): void
    {
        $link = $this->one($this->xpath(), '//aside[@data-tally]//a');

        self::assertSame('docs/guides/metrics.html', $link->getAttribute('href'));
        self::assertFileExists(self::SITE . '/docs/guides/metrics.html');
    }

    public function testTheScriptAndStylesKnowTheTally(): void
    {
        $js = file_get_contents(self::SITE . '/assets/sesame.js');
        $css = file_get_contents(self::SITE . '/assets/sesame.css');
        self::assertNotFalse($js);
        self::assertNotFalse($css);

        self::assertStringContainsString('"[data-tally]"', $js);
        self::assertStringContainsString('".tally"', $js, 'the card reveals with the rest of the page');
        self::assertStringContainsString('.motion .tally.reveal:not(.is-seen) .tally__strip', $css, 'the roll is motion only');
    }
}
