<?php

declare(strict_types=1);

namespace Rewloy\Tests\Support;

use Closure;

/**
 * A sleep that only records how long it was asked to wait.
 */
final class Sleeps
{
    /** @var list<float> Seconds, in order. */
    public array $waits = [];

    /** @return Closure(float): void */
    public function sleeper(): Closure
    {
        return function (float $seconds): void {
            $this->waits[] = $seconds;
        };
    }
}
