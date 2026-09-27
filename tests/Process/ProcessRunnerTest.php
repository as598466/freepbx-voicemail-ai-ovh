<?php

declare(strict_types=1);

namespace VoicemailAi\Tests\Process;

use PHPUnit\Framework\TestCase;
use VoicemailAi\Process\ProcessRunner;

final class ProcessRunnerTest extends TestCase
{
    public function testWritesLargeStdinCompletely(): void
    {
        // Larger than the 64 KiB write chunks and than a pipe buffer.
        $stdin = str_repeat('0123456789abcdef', 128 * 1024);

        $result = (new ProcessRunner())->run(['/bin/sh', '-c', 'wc -c >&2'], $stdin);

        self::assertTrue($result->isSuccessful());
        self::assertSame((string) strlen($stdin), $result->stderr);
    }

    public function testReportsExitCodeAndStderr(): void
    {
        $result = (new ProcessRunner())->run(['/bin/sh', '-c', 'echo "  failure " >&2; exit 3']);

        self::assertFalse($result->isSuccessful());
        self::assertSame(3, $result->exitCode);
        self::assertSame('failure', $result->stderr);
    }

    public function testDoesNotUseShell(): void
    {
        $result = (new ProcessRunner())->run(['/bin/echo', '$(exit 1); false']);

        self::assertTrue($result->isSuccessful());
    }

    public function testMissingBinaryFails(): void
    {
        $result = (new ProcessRunner())->run(['/nonexistent/sendmail', '-t'], 'Subject: test');

        self::assertFalse($result->isSuccessful());
    }
}
