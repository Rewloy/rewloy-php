<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Two jobs at the end of the live run: tidy up what the tests created (whatever happened), and
 * print a short summary, passed and failed per area.
 */
final class LiveExtension implements Extension
{
    /** @var array<string, array{passed: int, failed: int, skipped: int}> */
    public static array $areas = [];

    /** @var list<string> */
    public static array $skipReasons = [];

    public static function count(string $class, string $outcome, ?string $reason = null): void
    {
        $area = defined($class . '::AREA') && is_string(constant($class . '::AREA')) ? constant($class . '::AREA') : $class;
        self::$areas[$area] ??= ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        self::$areas[$area][$outcome]++;
        if ($outcome === 'skipped' && $reason !== null && !in_array($reason, self::$skipReasons, true)) {
            self::$skipReasons[] = $reason;
        }
    }

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements PassedSubscriber {
                public function notify(Passed $event): void
                {
                    LiveExtension::count(self::classOf($event->test()), 'passed');
                }

                private static function classOf(\PHPUnit\Event\Code\Test $test): string
                {
                    return $test instanceof \PHPUnit\Event\Code\TestMethod ? $test->className() : '';
                }
            },
            new class implements FailedSubscriber {
                public function notify(Failed $event): void
                {
                    $t = $event->test();
                    LiveExtension::count($t instanceof \PHPUnit\Event\Code\TestMethod ? $t->className() : '', 'failed');
                }
            },
            new class implements ErroredSubscriber {
                public function notify(Errored $event): void
                {
                    $t = $event->test();
                    LiveExtension::count($t instanceof \PHPUnit\Event\Code\TestMethod ? $t->className() : '', 'failed');
                }
            },
            new class implements SkippedSubscriber {
                public function notify(Skipped $event): void
                {
                    $t = $event->test();
                    LiveExtension::count($t instanceof \PHPUnit\Event\Code\TestMethod ? $t->className() : '', 'skipped', $event->message());
                }
            },
            new class implements MarkedIncompleteSubscriber {
                public function notify(MarkedIncomplete $event): void
                {
                    $t = $event->test();
                    LiveExtension::count($t instanceof \PHPUnit\Event\Code\TestMethod ? $t->className() : '', 'skipped', $event->throwable()->message());
                }
            },
            new class implements ExecutionFinishedSubscriber {
                public function notify(ExecutionFinished $event): void
                {
                    LiveExtension::finish();
                }
            },
        );
    }

    public static function finish(): void
    {
        Fixture::cleanup();

        $lines = ["\nRewloy live tests (" . (Guard::$baseUrl !== '' ? Guard::$baseUrl : 'not run') . ")"];
        $failed = 0;
        foreach (self::$areas as $area => $n) {
            $failed += $n['failed'];
            $lines[] = sprintf('  %-22s %s  %d passed, %d failed%s', $area, $n['failed'] > 0 ? 'FAIL' : ($n['passed'] > 0 ? 'ok  ' : 'skip'), $n['passed'], $n['failed'], $n['skipped'] > 0 ? ", {$n['skipped']} skipped" : '');
        }
        foreach (self::$skipReasons as $reason) {
            $lines[] = '  skipped: ' . $reason;
        }
        if (($skippedReset = self::$areas[ZResetTest::AREA]['skipped'] ?? 0) > 0) {
            $lines[] = '  NOTE: the test reset did not run, so the test business still holds this run\'s customers, cards and messages.';
        }
        $lines[] = $failed > 0 ? "  RESULT: {$failed} failed" : '  RESULT: ok';
        fwrite(STDOUT, implode("\n", $lines) . "\n");
    }
}
