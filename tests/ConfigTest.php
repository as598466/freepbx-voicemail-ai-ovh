<?php

declare(strict_types=1);

namespace VoicemailAi\Tests;

use PHPUnit\Framework\TestCase;
use VoicemailAi\Config;
use VoicemailAi\Exception\ConfigurationException;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromArray([]);

        self::assertSame('https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/audio/transcriptions', $config->transcriptionUrl);
        self::assertNull($config->apiToken);
        self::assertSame('whisper-large-v3', $config->model);
        self::assertNull($config->language);
        self::assertNull($config->prompt);
        self::assertSame(0.0, $config->temperature);
        self::assertSame(120, $config->timeout);
        self::assertSame(2, $config->maxRetries);
        self::assertNull($config->ffmpegPath);
        self::assertSame('32k', $config->mp3Bitrate);
        self::assertSame(Config::DEFAULT_SENDMAIL, $config->sendmailPath);
        self::assertNull($config->mailProfiles->default->fromAddress);
        self::assertSame('', $config->mailProfiles->default->subjectPrefix);
        self::assertTrue($config->mailProfiles->default->attachAudio);
        self::assertSame('Messagerie vocale', $config->mailProfiles->default->headerTitle);
        self::assertSame('#1e3a5f', $config->mailProfiles->default->headerColor);
        self::assertSame([], $config->mailProfiles->mailboxes);
        self::assertSame('voicemail-ai', $config->logIdent);
        self::assertFalse($config->debug);
    }

    public function testNormalizesValues(): void
    {
        $config = Config::fromArray([
            'ovh' => [
                'base_url' => 'https://example.com/v1/',
                'token' => '  secret  ',
                'language' => '',
                'prompt' => ['not', 'a', 'string'],
                'timeout' => 0,
                'max_retries' => -3,
            ],
            'mail' => [
                'from_address' => 'vm@example.com',
                'subject_prefix' => '[Répondeur] ',
                'attach_audio' => false,
            ],
        ]);

        self::assertSame('https://example.com/v1/audio/transcriptions', $config->transcriptionUrl);
        self::assertSame('secret', $config->apiToken);
        self::assertNull($config->language);
        self::assertNull($config->prompt);
        self::assertSame(1, $config->timeout);
        self::assertSame(0, $config->maxRetries);
        self::assertSame('vm@example.com', $config->mailProfiles->default->fromAddress);
        self::assertSame('[Répondeur] ', $config->mailProfiles->default->subjectPrefix);
        self::assertFalse($config->mailProfiles->default->attachAudio);
    }

    public function testMailboxOverridesInheritGlobalMailSettings(): void
    {
        $config = Config::fromArray([
            'mail' => [
                'from_address' => 'vm@example.com',
                'subject_prefix' => '[Répondeur] ',
                'from_name' => 'Répondeur',
                'mailboxes' => [
                    '1001' => ['subject_prefix' => '[SAV] ', 'attach_audio' => false],
                ],
            ],
        ]);

        $profile = $config->mailProfiles->for('1001');

        self::assertSame('[SAV] ', $profile->subjectPrefix);
        self::assertFalse($profile->attachAudio);
        self::assertSame('vm@example.com', $profile->fromAddress);
        self::assertSame('Répondeur', $profile->fromName);
        self::assertSame($config->mailProfiles->default, $config->mailProfiles->for('2000'));
        self::assertSame($config->mailProfiles->default, $config->mailProfiles->for(null));
    }

    public function testRejectsUnknownMailboxOption(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown option(s) "html_template" for mailbox "1001"');

        Config::fromArray(['mail' => ['mailboxes' => ['1001' => ['html_template' => '/tmp/x.php']]]]);
    }

    public function testMailboxCanOverrideHeader(): void
    {
        $config = Config::fromArray([
            'mail' => [
                'header_title' => 'Répondeur',
                'mailboxes' => ['1001' => ['header_color' => '#B91C1C']],
            ],
        ]);

        $profile = $config->mailProfiles->for('1001');

        self::assertSame('Répondeur', $profile->headerTitle);
        self::assertSame('#B91C1C', $profile->headerColor);
    }

    public function testRejectsInvalidHeaderColor(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid mail header color "red;display:none"');

        Config::fromArray(['mail' => ['header_color' => 'red;display:none']]);
    }

    public function testRejectsMailboxThatIsNotAnArray(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::fromArray(['mail' => ['mailboxes' => ['1001' => '[SAV] ']]]);
    }

    public function testDistributedFileIsValid(): void
    {
        $config = Config::fromFile(dirname(__DIR__) . '/config/config.dist.php');

        self::assertNull($config->apiToken);
        self::assertSame('fr', $config->language);
        self::assertSame('/usr/bin/ffmpeg', $config->ffmpegPath);
    }

    public function testRejectsSectionThatIsNotAnArray(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::fromArray(['ovh' => 'token']);
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::fromFile('/nonexistent/config.php');
    }

    public function testRejectsFileNotReturningAnArray(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'vmai-test-');
        file_put_contents($file, '<?php return 42;');

        try {
            $this->expectException(ConfigurationException::class);

            Config::fromFile($file);
        } finally {
            unlink($file);
        }
    }
}
