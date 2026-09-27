# CLAUDE.md

Ce fichier guide Claude Code (claude.ai/code) lorsqu'il travaille sur le code de ce dépôt.

## Présentation

CLI PHP 8.2 utilisée comme `mailcmd` de la messagerie vocale Asterisk/FreePBX. Asterisk transmet sur stdin l'e-mail complet du message vocal (MIME, avec pièce jointe WAV) à `bin/voicemail-ai`. Le script transcrit l'audio avec OVHcloud AI Endpoints (Whisper, `/v1/audio/transcriptions` compatible OpenAI), convertit le WAV en MP3 avec ffmpeg, puis envoie un e-mail enrichi HTML et texte via PHPMailer et `/usr/sbin/sendmail` (Postfix). Le README et tous les textes visibles par l'utilisateur (gabarits d'e-mail, messages) sont en français. Le code, les commentaires et les messages de journal sont en anglais.

## Commandes

```bash
composer install                                   # vendor/ n'est pas versionné
composer test                                      # PHPUnit 11 (tests/)
vendor/bin/phpunit --filter testSendsEmailEvenWhenTranscriptionFails   # un seul test
vendor/bin/phpunit tests/Mail/VoicemailParserTest.php                  # un seul fichier
composer cs-check                                  # php-cs-fixer, PER-CS 3.0 + strict_types + imports triés
composer cs-fix
```

Exécution de bout en bout sans envoi d'e-mail. Elle appelle la vraie API OVH, et le fichier `config/config.php` doit exister (le copier depuis `config/config.dist.php` ; il est ignoré par git car il contient le jeton d'API) :

```bash
php bin/voicemail-ai --dry-run --verbose < tests/fixtures/voicemail.eml
```

PHPUnit reste en `^11` : c'est la dernière version compatible PHP 8.2 (cible Debian 12 / FreePBX 17). `composer.lock` est versionné et `config.platform.php` est fixé à `8.2` dans `composer.json`, pour que `composer update` résolve toujours des versions compatibles avec la production, quelle que soit la version de PHP locale. Après toute modification de `composer.json`, mettre à jour et versionner `composer.lock`.

Composer est installé localement dans le projet (`composer.phar`, ignoré par git) : remplacer `composer` par `php composer.phar` dans les commandes ci-dessus.

`--config=FICHIER` ou la variable d'environnement `VOICEMAIL_AI_CONFIG` remplace le chemin de configuration. `--dry-run` écrit le message MIME produit sur stdout au lieu d'appeler sendmail. `--verbose` recopie sur stderr les journaux syslog (ident `voicemail-ai`, facility `mail`).

## Architecture

**Invariant central : un message vocal ne doit jamais être perdu.** Chaque chemin d'échec se termine par la livraison de *quelque chose*. Toute modification doit préserver cet invariant :

- `bin/voicemail-ai` lit stdin avant de charger l'autoloader et enregistre une fonction d'arrêt de dernier recours : en cas d'erreur fatale (`vendor/` absent, erreur de syntaxe, mémoire épuisée…), l'e-mail brut est transmis à `/usr/sbin/sendmail -t` (ou écrit sur stdout en `--dry-run`). Ce code ne doit dépendre d'aucune classe du projet ; c'est la seule exception à la règle `ProcessRunner`.
- `Cli` transforme les warnings PHP en `ErrorException`, pour que chaque échec atteigne un repli. Si le chargement de la configuration échoue, `Cli::rescue()` transmet l'entrée brute à `Config::DEFAULT_SENDMAIL` sans utiliser la configuration. Toute exception qui s'échappe d'`Application` passe aussi par `Cli::rescue()`, avec le sendmail configuré. Une option inconnue est ignorée avec un avertissement (stderr et syslog) : elle ne doit jamais empêcher la livraison.
- `Application::run()` est le pipeline : `VoicemailParser` → `TranscriberInterface` → `AudioConverter::toMp3()` → `VoicemailMailer::compose()` → envoi.
  - Si l'analyse échoue ou s'il n'y a pas de pièce jointe audio (e-mail pager, `attach=no`), l'e-mail d'origine est transmis tel quel par `RawMailForwarder` (`sendmail -t`).
  - Si la transcription échoue, `$transcript` vaut `null`. L'e-mail est quand même envoyé, avec un avertissement et l'en-tête `X-Voicemail-Transcription: failed`. L'audio est alors joint même si `mail.attach_audio` vaut `false`.
  - Si la conversion MP3 échoue, le WAV d'origine est joint à la place.
  - Si la composition ou l'envoi lève une exception, l'e-mail brut est transmis.
- Le flux de sortie du dry-run (`$output`) traverse `Application` et `Cli`, et il est transmis à `RawMailForwarder`. Lorsqu'il est défini, chaque « envoi » ou « transmission » écrit dans ce flux.

Autres points qui concernent plusieurs fichiers :

- `Application::create()` est le seul endroit où les dépendances sont construites à partir de `Config`. Les tests instancient `Application` directement avec des bouchons `TranscriberInterface` en classes anonymes et un flux de sortie dry-run, puis vérifient le texte MIME produit. `tests/ApplicationTest.php` montre le modèle, ainsi qu'un faux sendmail (`fakeSendmail()`) pour tester le vrai chemin d'envoi hors dry-run. `tests/CliTest.php` lance `bin/voicemail-ai` en sous-processus (toujours en `--dry-run`) pour les replis de bout en bout, et `tests/Transcription/OvhTranscriberTest.php` interroge un faux serveur AI Endpoints (serveur web intégré de PHP, `tests/fixtures/transcription-server.php`).
- `OvhTranscriber` envoie le **WAV d'origine**, pas le MP3. Il retente les erreurs réseau, HTTP 429 et 5xx avec une attente exponentielle (`max_retries`).
- `Config` est un objet valeur readonly construit à partir d'un fichier PHP qui retourne un tableau imbriqué (`ovh.*`, `audio.*`, `mail.*`, `log.*`). Une nouvelle option demande des modifications à trois endroits : le constructeur de `Config`, `Config::fromArray()` et `config/config.dist.php`. Le tableau de configuration du README doit aussi être mis à jour. Les options de présentation de la section `mail` (expéditeur, préfixe du sujet, `attach_audio`, titre et couleur du bandeau) forment un `Mail\MailProfile`. Elles peuvent être surchargées par boîte vocale via `mail.mailboxes` (clé = numéro de boîte, extrait du `Message-ID` par `VoicemailParser`, voir `Config::MAILBOX_OPTIONS`). `MailProfiles::for()` choisit le profil dans `Application::run()`.
- Les binaires externes (ffmpeg, sendmail) sont lancés uniquement via `Process\ProcessRunner`, qui utilise `proc_open` avec un tableau d'arguments, sans shell. Ne pas utiliser `exec`/`shell_exec` ni de commandes sous forme de chaîne.
- Les corps d'e-mail proviennent des gabarits PHP simples `templates/email.{html,txt}.php` (non configurables), rendus par `TemplateRenderer` avec `$voicemail`, `$transcript` (nullable), `$attachment` et `$profile` (`MailProfile` de la boîte, pour le titre et la couleur du bandeau) dans la portée. Le texte configuré dans FreePBX (`$voicemail->body`) est conservé et affiché dans la section « Détails ».

## Déploiement

Le chemin d'installation en production est `/opt/freepbx-voicemail-ai-ovh`. Le processus tourne sous l'utilisateur `asterisk`, en arrière-plan, lancé par Asterisk. `config/config.php` doit être en `root:asterisk 640`. Journaux de production : `journalctl -t voicemail-ai -f`.
