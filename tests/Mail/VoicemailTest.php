<?php

declare(strict_types=1);

namespace VoicemailAi\Tests\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoicemailAi\Mail\Voicemail;

final class VoicemailTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null, string|null}>
     */
    public static function callers(): iterable
    {
        yield 'name and number' => ['Marie Martin', '0612345678', 'Marie Martin <0612345678>'];
        yield 'number only' => [null, '0612345678', '0612345678'];
        yield 'name only' => ['Marie Martin', null, 'Marie Martin'];
        yield 'name equal to number' => ['0612345678', '0612345678', '0612345678'];
        yield 'unknown' => [null, null, null];
    }

    #[DataProvider('callers')]
    public function testCaller(?string $callerName, ?string $callerId, ?string $expected): void
    {
        $voicemail = new Voicemail(null, [], '', '', callerId: $callerId, callerName: $callerName);

        self::assertSame($expected, $voicemail->caller());
    }
}
