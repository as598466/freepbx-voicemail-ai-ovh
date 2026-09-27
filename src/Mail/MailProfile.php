<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

/**
 * Presentation settings of the enriched email, global or specific to a voicemail box.
 */
final readonly class MailProfile
{
    public const DEFAULT_HEADER_TITLE = 'Messagerie vocale';

    public const DEFAULT_HEADER_COLOR = '#1e3a5f';

    public function __construct(
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public string $subjectPrefix = '',
        public bool $attachAudio = true,
        public string $headerTitle = self::DEFAULT_HEADER_TITLE,
        public string $headerColor = self::DEFAULT_HEADER_COLOR,
    ) {}
}
