<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Transcription\Transcript;

/**
 * Builds the enriched voicemail email (HTML + text + audio) and hands it to Postfix via sendmail.
 */
final readonly class VoicemailMailer
{
    private const TEMPLATE_DIRECTORY = __DIR__ . '/../../templates';

    public function __construct(
        private TemplateRenderer $renderer,
        private string $sendmailPath,
        private ?string $envelopeSender = null,
        private string $templateDirectory = self::TEMPLATE_DIRECTORY,
    ) {}

    /**
     * @throws MailerException
     */
    public function compose(
        Voicemail $voicemail,
        ?Transcript $transcript,
        MailProfile $profile,
        ?AudioFile $attachment = null,
    ): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->isSendmail();
        $mail->Sendmail = $this->sendmailPath;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = 'freepbx-voicemail-ai-ovh';

        $from = $profile->fromAddress !== null
            ? new Address($profile->fromAddress, $profile->fromName ?? '')
            : $voicemail->from;

        if ($from === null) {
            throw new MailerException('No sender address available.');
        }

        $mail->setFrom($from->email, $from->name, false);

        if ($this->envelopeSender !== null) {
            $mail->Sender = $this->envelopeSender;
        }

        if ($voicemail->to === []) {
            throw new MailerException('No recipient address available.');
        }

        foreach ($voicemail->to as $recipient) {
            $mail->addAddress($recipient->email, $recipient->name);
        }

        $mail->Subject = $profile->subjectPrefix . $voicemail->subject;

        if ($voicemail->messageId !== null && preg_match('/^<[^<>\s]+@[^<>\s]+>$/', $voicemail->messageId) === 1) {
            $mail->MessageID = $voicemail->messageId;
        }

        if ($voicemail->date !== null) {
            $mail->MessageDate = $voicemail->date;
        }

        if ($voicemail->callerId !== null) {
            $mail->addCustomHeader('X-Asterisk-CallerID', $voicemail->callerId);
        }

        if ($voicemail->callerName !== null) {
            $mail->addCustomHeader('X-Asterisk-CallerIDName', $voicemail->callerName);
        }

        $mail->addCustomHeader('X-Voicemail-Transcription', $transcript !== null ? 'ok' : 'failed');

        $variables = [
            'voicemail' => $voicemail,
            'transcript' => $transcript,
            'attachment' => $attachment,
            'profile' => $profile,
        ];

        $mail->isHTML(true);
        $mail->Body = $this->renderer->render($this->templateDirectory . '/email.html.php', $variables);
        $mail->AltBody = $this->renderer->render($this->templateDirectory . '/email.txt.php', $variables);

        if ($attachment !== null) {
            $mail->addStringAttachment(
                $attachment->content,
                $attachment->filename,
                PHPMailer::ENCODING_BASE64,
                $attachment->mimeType,
            );
        }

        return $mail;
    }
}
