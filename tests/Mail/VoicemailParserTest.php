<?php

declare(strict_types=1);

namespace VoicemailAi\Tests\Mail;

use PHPUnit\Framework\TestCase;
use VoicemailAi\Mail\VoicemailParser;

final class VoicemailParserTest extends TestCase
{
    public function testParsesAsteriskVoicemailEmail(): void
    {
        $voicemail = (new VoicemailParser())->parse((string) file_get_contents(__DIR__ . '/../fixtures/voicemail.eml'));

        self::assertSame('vm@pbx.example.com', $voicemail->from?->email);
        self::assertSame('Messagerie vocale', $voicemail->from->name);
        self::assertCount(1, $voicemail->to);
        self::assertSame('jean.dupont@example.com', $voicemail->to[0]->email);
        self::assertSame('Nouveau message vocal 1 dans la boîte 1001', $voicemail->subject);
        self::assertStringContainsString('Vous avez reçu un nouveau message', $voicemail->body);
        self::assertSame('Marie Martin <0612345678>', $voicemail->caller());
        self::assertSame('<Asterisk-1-1727165732-1001-4242@pbx.example.com>', $voicemail->messageId);
        self::assertSame('1001', $voicemail->mailbox);

        self::assertNotNull($voicemail->audio);
        self::assertSame('msg0000.wav', $voicemail->audio->filename);
        self::assertSame('audio/x-wav', $voicemail->audio->mimeType);
        self::assertStringStartsWith('RIFF', $voicemail->audio->content);
    }

    public function testEmailWithoutAttachmentHasNoAudio(): void
    {
        $raw = "From: vm@pbx.example.com\nTo: pager@example.com\nSubject: Nouveau message\n\nMessage de 0612345678\n";

        $voicemail = (new VoicemailParser())->parse($raw);

        self::assertNull($voicemail->audio);
        self::assertNull($voicemail->mailbox);
        self::assertSame('Message de 0612345678', $voicemail->body);
    }

    public function testMailboxIsOnlyReadFromAsteriskMessageId(): void
    {
        $raw = "From: vm@pbx.example.com\nTo: pager@example.com\nMessage-ID: <1234-1001-42@example.com>\n\nTest\n";

        $voicemail = (new VoicemailParser())->parse($raw);

        self::assertSame('<1234-1001-42@example.com>', $voicemail->messageId);
        self::assertNull($voicemail->mailbox);
    }
}
