<?php

/**
 * Plain text alternative of the voicemail email.
 *
 * @var VoicemailAi\Mail\Voicemail $voicemail
 * @var VoicemailAi\Transcription\Transcript|null $transcript
 * @var VoicemailAi\Audio\AudioFile|null $attachment
 * @var VoicemailAi\Mail\MailProfile $profile
 */

declare(strict_types=1);

$caller = $voicemail->caller();

echo $caller !== null ? 'Nouveau message de ' . $caller : 'Nouveau message vocal', "\n\n";

echo "TRANSCRIPTION\n-------------\n";

if ($transcript === null) {
    echo "La transcription automatique n'a pas pu être réalisée. Écoutez le message joint.\n";
} elseif ($transcript->isEmpty()) {
    echo "Aucune parole détectée dans ce message.\n";
} else {
    echo wordwrap($transcript->text, 76), "\n";
}

if ($voicemail->body !== '') {
    echo "\nDÉTAILS\n-------\n", $voicemail->body, "\n";
}

echo "\n--\nTranscription générée automatiquement par IA (OVHcloud AI Endpoints), elle peut contenir des erreurs.\n";
