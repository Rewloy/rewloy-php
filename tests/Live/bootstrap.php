<?php

declare(strict_types=1);

// The live suite's bootstrap (phpunit.live.xml): autoload, then the dev/test-key gate.

require __DIR__ . '/../../vendor/autoload.php';

\Rewloy\Tests\Live\Guard::boot();
