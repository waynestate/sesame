<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Metrics\LoginMetrics;

/**
 * The sign-ins dashboard ships in docs/grafana/sesame.json, so a Grafana
 * with a Prometheus data source can graph sesame_logins_total as soon as an
 * app turns the counter on (ADR-0011: dashboards live as JSON in the repo).
 */
final class GrafanaDashboardTest extends TestCase
{
    private const string DASHBOARD = __DIR__ . '/../docs/grafana/sesame.json';

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        self::assertFileExists(self::DASHBOARD);

        $json = file_get_contents(self::DASHBOARD);
        self::assertNotFalse($json, 'the dashboard must be readable');

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'the dashboard must be valid JSON');

        return $decoded;
    }

    /**
     * The panels that query Prometheus (rows and text panels do not).
     *
     * @return list<array<string, mixed>>
     */
    private function queryPanels(): array
    {
        $panels = $this->dashboard()['panels'];
        self::assertIsArray($panels);

        return array_values(array_filter(
            $panels,
            static fn(array $panel): bool => ! in_array($panel['type'], ['row', 'text'], true),
        ));
    }

    /**
     * @return list<string>
     */
    private function expressions(): array
    {
        $expressions = [];

        foreach ($this->queryPanels() as $panel) {
            self::assertNotEmpty($panel['targets'], sprintf('panel "%s" has no targets', $panel['title']));

            foreach ($panel['targets'] as $target) {
                $expressions[(string) $panel['title'] . ': ' . count($expressions)] = (string) $target['expr'];
            }
        }

        return array_values($expressions);
    }

    public function testTheDashboardIsAnExportedDashboard(): void
    {
        $dashboard = $this->dashboard();

        self::assertSame(39, $dashboard['schemaVersion']);
        self::assertSame('SESAME sign-ins', $dashboard['title']);
        self::assertSame('sesame-signins', $dashboard['uid']);
        self::assertSame(['sesame', 'wsu'], $dashboard['tags']);
        self::assertNull($dashboard['id'], 'an exported dashboard carries no instance id');
    }

    /**
     * Grafana's import screen only asks for a data source when the file
     * declares it under __inputs; under any other key the placeholders stay
     * unresolved and every panel fails.
     */
    public function testTheDataSourceIsAnImportInput(): void
    {
        $dashboard = $this->dashboard();

        self::assertCount(1, $dashboard['__inputs']);
        self::assertSame('DS_PROMETHEUS', $dashboard['__inputs'][0]['name']);
        self::assertSame('datasource', $dashboard['__inputs'][0]['type']);
        self::assertSame('prometheus', $dashboard['__inputs'][0]['pluginId']);

        foreach ($this->queryPanels() as $panel) {
            self::assertSame('${DS_PROMETHEUS}', $panel['datasource']['uid'], sprintf('panel "%s"', $panel['title']));
            foreach ($panel['targets'] as $target) {
                self::assertSame('${DS_PROMETHEUS}', $target['datasource']['uid'], sprintf('panel "%s"', $panel['title']));
            }
        }
    }

    public function testTheDashboardHasTheExpectedPanels(): void
    {
        $titles = array_map(static fn(array $panel): string => (string) $panel['title'], $this->queryPanels());
        sort($titles);

        self::assertSame([
            'Failed share by driver',
            'Identity provider unavailable',
            'Identity provider unavailable, by app',
            'Refused by the app',
            'Sign-ins',
            'Sign-ins by app',
            'Sign-ins per minute by driver',
            'Sign-ins per minute by outcome',
            'Success rate',
        ], $titles);
    }

    public function testEveryQueryReadsOnlyTheSignInCounterAndHonorsTheVariables(): void
    {
        foreach ($this->expressions() as $expr) {
            preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\{/', $expr, $matches);

            self::assertNotEmpty($matches[1], 'a query selects no metric: ' . $expr);
            foreach ($matches[1] as $metric) {
                self::assertSame('sesame_logins_total', $metric, 'a query reads another metric: ' . $expr);
            }

            self::assertSame(
                substr_count($expr, 'sesame_logins_total{'),
                substr_count($expr, 'job=~"$job", instance=~"$instance"'),
                'every selector honors the job and instance variables: ' . $expr,
            );
        }
    }

    public function testEveryOutcomeTheDashboardNamesIsOneSesameCounts(): void
    {
        foreach ($this->expressions() as $expr) {
            preg_match_all('/outcome="([a-z]+)"/', $expr, $matches);

            foreach ($matches[1] as $outcome) {
                self::assertContains($outcome, LoginMetrics::OUTCOMES, 'unknown outcome in: ' . $expr);
            }
        }
    }

    public function testTheVariablesReadTheSignInCounter(): void
    {
        $names = [];

        foreach ($this->dashboard()['templating']['list'] as $variable) {
            $names[] = $variable['name'];
            self::assertStringContainsString('sesame_logins_total', (string) $variable['definition']);
            self::assertSame('${DS_PROMETHEUS}', $variable['datasource']['uid']);
        }

        self::assertSame(['job', 'instance'], $names);
    }
}
