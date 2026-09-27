<?php

declare(strict_types=1);

namespace VoicemailAi;

use VoicemailAi\Exception\ConfigurationException;
use VoicemailAi\Mail\MailProfile;
use VoicemailAi\Mail\MailProfiles;

final readonly class Config
{
    public const DEFAULT_SENDMAIL = '/usr/sbin/sendmail';

    private const DEFAULT_BASE_URL = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';

    private const DEFAULT_MODEL = 'whisper-large-v3';

    /**
     * Options of the "mail" section that can be overridden per voicemail box.
     */
    private const MAILBOX_OPTIONS = [
        'from_address',
        'from_name',
        'subject_prefix',
        'attach_audio',
        'header_title',
        'header_color',
    ];

    public function __construct(
        public string $transcriptionUrl,
        public ?string $apiToken,
        public string $model,
        public ?string $language,
        public ?string $prompt,
        public float $temperature,
        public int $timeout,
        public int $maxRetries,
        public ?string $ffmpegPath,
        public string $mp3Bitrate,
        public string $sendmailPath,
        public ?string $envelopeSender,
        public MailProfiles $mailProfiles,
        public string $logIdent,
        public bool $debug,
    ) {}

    /**
     * @throws ConfigurationException
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException(sprintf('Configuration file "%s" is not readable.', $path));
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new ConfigurationException(sprintf('Configuration file "%s" must return an array.', $path));
        }

        return self::fromArray($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $ovh = self::section($config, 'ovh');
        $audio = self::section($config, 'audio');
        $mail = self::section($config, 'mail');
        $log = self::section($config, 'log');

        $baseUrl = self::string($ovh['base_url'] ?? null) ?? self::DEFAULT_BASE_URL;

        return new self(
            transcriptionUrl: rtrim($baseUrl, '/') . '/audio/transcriptions',
            apiToken: self::string($ovh['token'] ?? null),
            model: self::string($ovh['model'] ?? null) ?? self::DEFAULT_MODEL,
            language: self::string($ovh['language'] ?? null),
            prompt: self::string($ovh['prompt'] ?? null),
            temperature: (float) ($ovh['temperature'] ?? 0.0),
            timeout: max(1, (int) ($ovh['timeout'] ?? 120)),
            maxRetries: max(0, (int) ($ovh['max_retries'] ?? 2)),
            ffmpegPath: self::string($audio['ffmpeg'] ?? null),
            mp3Bitrate: self::string($audio['mp3_bitrate'] ?? null) ?? '32k',
            sendmailPath: self::string($mail['sendmail'] ?? null) ?? self::DEFAULT_SENDMAIL,
            envelopeSender: self::string($mail['envelope_sender'] ?? null),
            mailProfiles: self::mailProfiles($mail),
            logIdent: self::string($log['ident'] ?? null) ?? 'voicemail-ai',
            debug: (bool) ($log['debug'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $mail
     *
     * @throws ConfigurationException
     */
    private static function mailProfiles(array $mail): MailProfiles
    {
        $mailboxes = self::section($mail, 'mailboxes');
        $profiles = [];

        foreach ($mailboxes as $mailbox => $overrides) {
            if (!is_array($overrides)) {
                throw new ConfigurationException(sprintf('Configuration of mailbox "%s" must be an array.', $mailbox));
            }

            $unknown = array_diff(array_keys($overrides), self::MAILBOX_OPTIONS);

            if ($unknown !== []) {
                throw new ConfigurationException(sprintf(
                    'Unknown option(s) "%s" for mailbox "%s", allowed: %s.',
                    implode('", "', $unknown),
                    $mailbox,
                    implode(', ', self::MAILBOX_OPTIONS),
                ));
            }

            $profiles[(string) $mailbox] = self::mailProfile(array_replace($mail, $overrides));
        }

        return new MailProfiles(self::mailProfile($mail), $profiles);
    }

    /**
     * @param array<string, mixed> $mail
     *
     * @throws ConfigurationException
     */
    private static function mailProfile(array $mail): MailProfile
    {
        $headerColor = self::string($mail['header_color'] ?? null) ?? MailProfile::DEFAULT_HEADER_COLOR;

        // The color is inserted in an inline CSS style: only accept hexadecimal notations.
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $headerColor) !== 1) {
            throw new ConfigurationException(sprintf(
                'Invalid mail header color "%s", expected a hexadecimal color such as "#1e3a5f".',
                $headerColor,
            ));
        }

        return new MailProfile(
            fromAddress: self::string($mail['from_address'] ?? null),
            fromName: self::string($mail['from_name'] ?? null),
            subjectPrefix: (string) ($mail['subject_prefix'] ?? ''),
            attachAudio: (bool) ($mail['attach_audio'] ?? true),
            headerTitle: self::string($mail['header_title'] ?? null) ?? MailProfile::DEFAULT_HEADER_TITLE,
            headerColor: $headerColor,
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException
     */
    private static function section(array $config, string $name): array
    {
        $section = $config[$name] ?? [];

        if (!is_array($section)) {
            throw new ConfigurationException(sprintf('Configuration section "%s" must be an array.', $name));
        }

        return $section;
    }

    private static function string(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
