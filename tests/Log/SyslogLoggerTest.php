<?php

declare(strict_types=1);

namespace VoicemailAi\Tests\Log;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VoicemailAi\Log\SyslogLogger;

final class SyslogLoggerTest extends TestCase
{
    public function testInterpolatesContextOnStderr(): void
    {
        $output = $this->log(debug: false, callback: static function (SyslogLogger $logger): void {
            $logger->error('{exception} ({flag}, {none}, {count}, {list})', [
                'exception' => new RuntimeException('HTTP 503'),
                'flag' => true,
                'none' => null,
                'count' => 3,
                'list' => [1, 2],
            ]);
        });

        self::assertSame('[error] RuntimeException: HTTP 503 (true, null, 3, array)' . PHP_EOL, $output);
    }

    public function testSkipsDebugMessagesUnlessEnabled(): void
    {
        $log = static fn(SyslogLogger $logger) => $logger->debug('Details');

        self::assertSame('', $this->log(debug: false, callback: $log));
        self::assertSame('[debug] Details' . PHP_EOL, $this->log(debug: true, callback: $log));
    }

    /**
     * @param callable(SyslogLogger): void $callback
     */
    private function log(bool $debug, callable $callback): string
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);

        $callback(new SyslogLogger('voicemail-ai-test', $debug, $stderr));

        rewind($stderr);

        return (string) stream_get_contents($stderr);
    }
}
