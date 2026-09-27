<?php

declare(strict_types=1);

namespace VoicemailAi\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VoicemailAi\Application;
use VoicemailAi\Audio\AudioConverter;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Mail\MailProfile;
use VoicemailAi\Mail\MailProfiles;
use VoicemailAi\Mail\RawMailForwarder;
use VoicemailAi\Mail\TemplateRenderer;
use VoicemailAi\Mail\VoicemailMailer;
use VoicemailAi\Mail\VoicemailParser;
use VoicemailAi\Process\ProcessRunner;
use VoicemailAi\Transcription\TranscriberInterface;
use VoicemailAi\Transcription\Transcript;
use VoicemailAi\Transcription\TranscriptionException;

final class ApplicationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }

    public function testSendsEnrichedEmailWithTranscription(): void
    {
        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture());

        self::assertStringContainsString('To: Jean Dupont <jean.dupont@example.com>', $output);
        self::assertStringContainsString('X-Voicemail-Transcription: ok', $output);
        self::assertStringContainsString('rappelez-moi', quoted_printable_decode($output));
        self::assertStringContainsString('filename=msg0000.wav', $output);
    }

    public function testSendsEmailEvenWhenTranscriptionFails(): void
    {
        $output = $this->runApplication($this->failingTranscriber(), $this->fixture());

        self::assertStringContainsString('X-Voicemail-Transcription: failed', $output);
        self::assertStringContainsString("n'a pas pu être réalisée", quoted_printable_decode($output));
    }

    public function testOmitsAudioWhenAttachmentIsDisabled(): void
    {
        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture(), attachAudio: false);

        self::assertStringContainsString('X-Voicemail-Transcription: ok', $output);
        self::assertStringNotContainsString('filename=msg0000.wav', $output);
    }

    public function testAttachesAudioWhenTranscriptionFailsEvenIfAttachmentIsDisabled(): void
    {
        $output = $this->runApplication($this->failingTranscriber(), $this->fixture(), attachAudio: false);

        self::assertStringContainsString('X-Voicemail-Transcription: failed', $output);
        self::assertStringContainsString('filename=msg0000.wav', $output);
    }

    public function testUsesMailSettingsOfTheMailbox(): void
    {
        $profiles = new MailProfiles(
            new MailProfile(subjectPrefix: '[Global] '),
            [
                '1001' => new MailProfile(
                    fromAddress: 'sav@example.com',
                    fromName: 'Service client',
                    subjectPrefix: '[SAV] ',
                    attachAudio: false,
                ),
            ],
        );

        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture(), mailProfiles: $profiles);

        self::assertStringContainsString('From: Service client <sav@example.com>', $output);
        self::assertStringContainsString('[SAV] ', iconv_mime_decode_headers($output, 0, 'UTF-8')['Subject'] ?? '');
        self::assertStringNotContainsString('filename=msg0000.wav', $output);
    }

    public function testRendersHeaderOfTheMailbox(): void
    {
        $profiles = new MailProfiles(
            new MailProfile(),
            ['1001' => new MailProfile(headerTitle: 'Société <A>', headerColor: '#b91c1c')],
        );

        $output = quoted_printable_decode(
            $this->runApplication($this->succeedingTranscriber(), $this->fixture(), mailProfiles: $profiles),
        );

        self::assertStringContainsString('background-color:#b91c1c;', $output);
        self::assertStringContainsString('Société &lt;A&gt;</div>', $output);
        self::assertStringNotContainsString('#1e3a5f', $output);
    }

    public function testUsesGlobalMailSettingsForOtherMailboxes(): void
    {
        $profiles = new MailProfiles(
            new MailProfile(subjectPrefix: '[Global] '),
            ['2000' => new MailProfile(subjectPrefix: '[Autre] ', attachAudio: false)],
        );

        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture(), mailProfiles: $profiles);

        self::assertStringContainsString('[Global] ', iconv_mime_decode_headers($output, 0, 'UTF-8')['Subject'] ?? '');
        self::assertStringContainsString('filename=msg0000.wav', $output);
    }

    public function testForwardsEmailWithoutAudioUnchanged(): void
    {
        $raw = "From: vm@pbx.example.com\nTo: pager@example.com\nSubject: Nouveau message\n\nMessage de 0612345678\n";

        $transcriber = new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                throw new LogicException('Must not be called.');
            }
        };

        self::assertSame($raw, $this->runApplication($transcriber, $raw));
    }

    public function testForwardsOriginalEmailWhenComposingFails(): void
    {
        $raw = $this->fixture();

        $output = $this->runApplication($this->succeedingTranscriber(), $raw, templateDirectory: '/nonexistent');

        self::assertSame($raw, $output);
    }

    public function testForwardsOriginalEmailWithoutRecipient(): void
    {
        $raw = (string) preg_replace('/^To: .*\n/m', '', $this->fixture());

        self::assertSame($raw, $this->runApplication($this->succeedingTranscriber(), $raw));
    }

    public function testShowsWhenNoSpeechIsDetected(): void
    {
        $output = quoted_printable_decode($this->runApplication($this->transcriberReturning(''), $this->fixture()));

        self::assertStringContainsString('X-Voicemail-Transcription: ok', $output);
        self::assertStringContainsString('Aucune parole détectée', $output);
    }

    public function testEscapesCallerAndTranscriptInHtml(): void
    {
        $raw = str_replace('X-Asterisk-CallerIDName: Marie Martin', 'X-Asterisk-CallerIDName: <b>Marie</b>', $this->fixture());

        $output = quoted_printable_decode(
            $this->runApplication($this->transcriberReturning('<script>alert(1)</script>'), $raw),
        );
        $html = (string) strstr((string) strstr($output, '<!DOCTYPE html>'), '</html>', true);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Nouveau message de &lt;b&gt;Marie&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testSendsEnrichedEmailWithSendmail(): void
    {
        $sendmail = $this->fakeSendmail();

        $this->runApplication(
            $this->succeedingTranscriber(),
            $this->fixture(),
            sendmail: $sendmail,
            envelopeSender: 'bounce@example.com',
        );

        $calls = $this->sendmailCalls($sendmail);
        self::assertCount(1, $calls);
        self::assertContains('-t', $calls[0]['arguments']);
        self::assertContains('-fbounce@example.com', $calls[0]['arguments']);
        self::assertStringContainsString('X-Voicemail-Transcription: ok', $calls[0]['stdin']);
        self::assertStringContainsString('To: Jean Dupont <jean.dupont@example.com>', $calls[0]['stdin']);
    }

    public function testForwardsOriginalEmailWhenSendmailRejectsEnrichedEmail(): void
    {
        $raw = $this->fixture();
        $sendmail = $this->fakeSendmail(failOn: 'X-Voicemail-Transcription');

        $this->runApplication($this->succeedingTranscriber(), $raw, sendmail: $sendmail);

        $calls = $this->sendmailCalls($sendmail);
        self::assertCount(2, $calls);
        self::assertSame(['-t', '-oi'], $calls[1]['arguments']);
        self::assertSame($raw, $calls[1]['stdin']);
    }

    public function testFailsWhenOriginalEmailCannotBeForwarded(): void
    {
        $sendmail = $this->fakeSendmail(failOn: 'From:');

        $this->runApplication(
            $this->succeedingTranscriber(),
            $this->fixture(),
            sendmail: $sendmail,
            expectedExitCode: Application::EXIT_FAILURE,
        );

        self::assertCount(2, $this->sendmailCalls($sendmail));
    }

    public function testFailsOnEmptyInput(): void
    {
        $output = $this->runApplication(
            $this->succeedingTranscriber(),
            " \n",
            expectedExitCode: Application::EXIT_FAILURE,
        );

        self::assertSame('', $output);
    }

    private function runApplication(
        TranscriberInterface $transcriber,
        string $raw,
        bool $attachAudio = true,
        ?string $templateDirectory = null,
        ?MailProfiles $mailProfiles = null,
        int $expectedExitCode = Application::EXIT_SUCCESS,
        ?string $sendmail = null,
        ?string $envelopeSender = null,
    ): string {
        // With a sendmail binary the email is really "sent", otherwise it is written to a dry-run output.
        $output = $sendmail === null ? fopen('php://memory', 'w+') : null;
        $sendmail ??= '/usr/sbin/sendmail';

        $processRunner = new ProcessRunner();

        $application = new Application(
            parser: new VoicemailParser(),
            converter: new AudioConverter($processRunner, null),
            transcriber: $transcriber,
            mailer: new VoicemailMailer(
                renderer: new TemplateRenderer(),
                sendmailPath: $sendmail,
                envelopeSender: $envelopeSender,
                templateDirectory: $templateDirectory ?? dirname(__DIR__) . '/templates',
            ),
            forwarder: new RawMailForwarder($processRunner, $sendmail, $output),
            logger: new NullLogger(),
            mailProfiles: $mailProfiles ?? new MailProfiles(new MailProfile(attachAudio: $attachAudio)),
            output: $output,
        );

        self::assertSame($expectedExitCode, $application->run($raw));

        if ($output === null) {
            return '';
        }

        rewind($output);

        return (string) stream_get_contents($output);
    }

    private function succeedingTranscriber(): TranscriberInterface
    {
        return new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                return new Transcript('Bonjour, rappelez-moi au sujet du devis.', 'fr', 3.2);
            }
        };
    }

    private function transcriberReturning(string $text): TranscriberInterface
    {
        return new class ($text) implements TranscriberInterface {
            public function __construct(private readonly string $text) {}

            public function transcribe(AudioFile $audio): Transcript
            {
                return new Transcript($this->text);
            }
        };
    }

    private function failingTranscriber(): TranscriberInterface
    {
        return new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                throw new TranscriptionException('HTTP 503');
            }
        };
    }

    /**
     * Fake sendmail recording each call in its directory, failing (exit 75) when stdin contains $failOn.
     */
    private function fakeSendmail(?string $failOn = null): string
    {
        $directory = sys_get_temp_dir() . '/vmai-test-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        $script = $directory . '/sendmail';
        file_put_contents($script, sprintf(<<<'SH'
            #!/bin/sh
            call="$(dirname "$0")/call-$(ls "$(dirname "$0")" | grep -c '^call-.*\.stdin$')"
            printf '%%s\n' "$@" > "$call.args"
            cat > "$call.stdin"
            if [ -n %1$s ] && grep -qF -- %1$s "$call.stdin"; then exit 75; fi
            SH, escapeshellarg($failOn ?? '')));
        chmod($script, 0o755);

        return $script;
    }

    /**
     * @return list<array{arguments: list<string>, stdin: string}>
     */
    private function sendmailCalls(string $sendmail): array
    {
        $calls = [];

        for ($call = 0; is_file($prefix = sprintf('%s/call-%d', dirname($sendmail), $call)) || is_file($prefix . '.stdin'); $call++) {
            $calls[] = [
                'arguments' => file($prefix . '.args', FILE_IGNORE_NEW_LINES) ?: [],
                'stdin' => (string) file_get_contents($prefix . '.stdin'),
            ];
        }

        return $calls;
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/voicemail.eml');
    }
}
