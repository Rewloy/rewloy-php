<?php

declare(strict_types=1);

namespace Rewloy\Tests\Support;

use RuntimeException;

/**
 * PHP's built-in web server on 127.0.0.1, running tests/Support/router.php:
 * a real HTTP peer for the curl transport, with no network beyond loopback.
 *
 * One process, no workers: a forked worker would outlive the process this
 * stops (it is stopped by its own handle, never by name).
 */
final class LocalServer
{
    /** @var resource */
    private $process;

    private function __construct(public readonly string $url, mixed $process)
    {
        if (!is_resource($process)) {
            throw new RuntimeException('the local server did not start');
        }
        $this->process = $process;
    }

    public static function start(): self
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new RuntimeException('no free port: ' . $error);
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
        );
        $server = new self('http://127.0.0.1:' . $port, $process);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return $server;
            }
            usleep(20000);
        }
        $server->stop();
        throw new RuntimeException('the local server did not answer on port ' . $port);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
