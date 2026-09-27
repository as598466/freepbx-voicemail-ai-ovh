<?php

/**
 * HTML body of the voicemail email.
 *
 * @var VoicemailAi\Mail\Voicemail $voicemail
 * @var VoicemailAi\Transcription\Transcript|null $transcript
 * @var VoicemailAi\Audio\AudioFile|null $attachment
 * @var VoicemailAi\Mail\MailProfile $profile
 * @var Closure(mixed): string $e HTML escaping helper
 */

declare(strict_types=1);

$caller = $voicemail->caller();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($voicemail->subject) ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;">
                <tr>
                    <td style="background-color:<?= $e($profile->headerColor) ?>;padding:20px 24px;color:#ffffff;">
                        <div style="font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:0.8;"><?= $e($profile->headerTitle) ?></div>
                        <div style="font-size:20px;font-weight:bold;margin-top:4px;">
                            <?= $caller !== null ? 'Nouveau message de ' . $e($caller) : 'Nouveau message vocal' ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;">
                        <div style="font-size:13px;font-weight:bold;text-transform:uppercase;color:#6b7280;margin-bottom:8px;">Transcription</div>
<?php if ($transcript === null) : ?>
                        <div style="background-color:#fef3c7;border-left:4px solid #d97706;padding:12px 16px;font-size:14px;">
                            La transcription automatique n'a pas pu être réalisée. Écoutez le message joint.
                        </div>
<?php elseif ($transcript->isEmpty()) : ?>
                        <div style="background-color:#f3f4f6;border-left:4px solid #9ca3af;padding:12px 16px;font-size:14px;font-style:italic;">
                            Aucune parole détectée dans ce message.
                        </div>
<?php else : ?>
                        <div style="background-color:#eff6ff;border-left:4px solid #2563eb;padding:12px 16px;font-size:15px;line-height:1.5;">
                            <?= nl2br($e($transcript->text)) ?>
                        </div>
<?php endif; ?>
<?php if ($voicemail->body !== '') : ?>
                        <div style="font-size:13px;font-weight:bold;text-transform:uppercase;color:#6b7280;margin:24px 0 8px;">Détails</div>
                        <div style="font-size:14px;line-height:1.5;color:#374151;">
                            <?= nl2br($e($voicemail->body)) ?>
                        </div>
<?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;">
                        Transcription générée automatiquement par IA (OVHcloud AI Endpoints), elle peut contenir des erreurs.
                        <?= $attachment !== null ? 'L\'enregistrement est joint à ce message.' : '' ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
