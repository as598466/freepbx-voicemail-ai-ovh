# FreePBX Voicemail AI OVH

Transcription automatique des messages vocaux FreePBX 17 / Asterisk avec
[OVHcloud AI Endpoints](https://endpoints.ai.cloud.ovh.net/) (Whisper), puis envoi d'un e-mail
HTML via PHPMailer et Postfix.

Le script remplace la commande d'envoi d'e-mail de la messagerie vocale (`mailcmd`, par défaut
`/usr/sbin/sendmail -t`).

## Fonctionnement

```
Asterisk app_voicemail
   │  e-mail complet (MIME + pièce jointe audio) sur stdin
   ▼
bin/voicemail-ai
   ├─ VoicemailParser       lecture de l'e-mail (destinataires, sujet, corps, audio)
   ├─ OvhTranscriber        POST /v1/audio/transcriptions du WAV d'origine (whisper-large-v3)
   ├─ AudioConverter        WAV → MP3 pour la pièce jointe (ffmpeg)
   ├─ VoicemailMailer       e-mail HTML + texte + MP3 (PHPMailer)
   └─ /usr/sbin/sendmail    Postfix
```

Asterisk lance la commande en arrière-plan (`( mailcmd < fichier ; rm -f fichier ) &`) : la
durée de la transcription ne bloque pas l'appel.

**Un message vocal n'est jamais perdu :**

| Situation                                           | Résultat                                          |
|-----------------------------------------------------|---------------------------------------------------|
| Transcription OK                                    | E-mail enrichi avec transcription + MP3           |
| Échec de la transcription (réseau, quota, HTTP 5xx) | E-mail enrichi avec un avertissement + MP3 (joint même si `attach_audio` vaut `false`) |
| Échec de la conversion MP3 (ffmpeg absent...)       | WAV d'origine joint à la place du MP3             |
| Pas de pièce jointe (e-mail pager, `attach=no`)     | E-mail d'origine transmis tel quel                |
| E-mail illisible, erreur PHPMailer, config invalide | E-mail d'origine transmis tel quel à sendmail     |
| Erreur fatale PHP (`vendor/` absent, mémoire...)    | E-mail d'origine transmis tel quel à sendmail     |
| Option inconnue dans **Mail Command**               | Option ignorée (avertissement dans les journaux)  |

Les erreurs réseau, HTTP 429 et 5xx sont retentées (`max_retries`, attente exponentielle).

## Prérequis

- FreePBX 17 sur Debian 12 (PHP 8.2, Postfix déjà fonctionnel)
- Extensions PHP `curl` et `mbstring`
- `ffmpeg` avec `libmp3lame` (paquet Debian standard) pour la pièce jointe MP3
- Un jeton d'accès OVHcloud AI Endpoints (sans jeton : accès anonyme limité à 60 s / 10 Mo)

## Installation

```bash
apt install git php8.2-curl php8.2-mbstring ffmpeg composer
```

```bash
git clone https://github.com/as598466/freepbx-voicemail-ai-ovh.git /opt/freepbx-voicemail-ai-ovh
```

```bash
cd /opt/freepbx-voicemail-ai-ovh && composer install --no-dev --optimize-autoloader
```

```bash
cp /opt/freepbx-voicemail-ai-ovh/config/config.dist.php /opt/freepbx-voicemail-ai-ovh/config/config.php
```

Renseigner au minimum `ovh.token` dans `config/config.php`, puis protéger le fichier (il contient
le jeton) :

```bash
chown -R root:asterisk /opt/freepbx-voicemail-ai-ovh && chmod 640 /opt/freepbx-voicemail-ai-ovh/config/config.php
```

## Test avant mise en production

L'option `--dry-run` effectue la vraie transcription mais affiche l'e-mail produit au lieu de
l'envoyer. Le lancer en tant qu'utilisateur `asterisk`, comme le fera Asterisk :

```bash
sudo -u asterisk /usr/bin/php /opt/freepbx-voicemail-ai-ovh/bin/voicemail-ai --dry-run --verbose < /opt/freepbx-voicemail-ai-ovh/tests/fixtures/voicemail.eml
```

Le fichier d'exemple contient un silence : la transcription attendue est vide (« Aucune parole
détectée »). Pour un test réaliste, remplacer la pièce jointe par un vrai enregistrement, ou
récupérer un e-mail réellement généré (voir *Dépannage*).

## Configuration FreePBX

1. **Paramètres → Voicemail Admin → Settings**, onglet de configuration des e-mails.
2. Champ **Mail Command** :
   ```
   /usr/bin/php /opt/freepbx-voicemail-ai-ovh/bin/voicemail-ai
   ```
3. Vérifier que les boîtes vocales ont **Email Attachment = yes** (sinon l'e-mail est transmis
   sans transcription).
4. **Submit** puis **Apply Config**.

Contrôle : `grep mailcmd /etc/asterisk/voicemail.conf` doit afficher la commande.

Le texte de l'e-mail configuré dans FreePBX (**Email Body**, variables `${VM_NAME}`,
`${VM_DUR}`, `${VM_CALLERID}`...) est conservé : il apparaît dans la section « Détails » de
l'e-mail, sous la transcription. Le sujet et l'expéditeur définis dans FreePBX sont aussi
repris : `mail.subject_prefix` ajoute un préfixe au sujet, et `mail.from_address` (avec
`mail.from_name`) remplace l'expéditeur.

## Configuration (`config/config.php`)

| Clé                     | Défaut                                              | Rôle                                           |
|-------------------------|-----------------------------------------------------|------------------------------------------------|
| `ovh.base_url`          | `https://oai.endpoints.kepler.ai.cloud.ovh.net/v1`  | API compatible OpenAI                          |
| `ovh.token`             | `null`                                              | Jeton AI Endpoints                             |
| `ovh.model`             | `whisper-large-v3`                                  | Modèle (voir le catalogue OVHcloud)            |
| `ovh.language`          | `null`                                              | Code ISO-639-1 (`'fr'` dans le fichier d'exemple), `null` = détection auto |
| `ovh.prompt`            | `null`                                              | Indice de contexte (noms propres, jargon...)   |
| `ovh.temperature`       | `0.0`                                               | Température d'échantillonnage de Whisper       |
| `ovh.timeout`           | `120`                                               | Délai max d'une requête (s)                    |
| `ovh.max_retries`       | `2`                                                 | Nouvelles tentatives sur erreur temporaire     |
| `audio.ffmpeg`          | `null`                                              | Binaire ffmpeg (`/usr/bin/ffmpeg` dans le fichier d'exemple), `null` = pièce jointe WAV d'origine |
| `audio.mp3_bitrate`     | `32k`                                               | Débit du MP3 joint                             |
| `mail.sendmail`         | `/usr/sbin/sendmail`                                | Binaire Postfix                                |
| `mail.from_address`     | `null`                                              | Remplace l'expéditeur généré par Asterisk      |
| `mail.from_name`        | `null`                                              | Nom de l'expéditeur, utilisé seulement avec `mail.from_address` |
| `mail.envelope_sender`  | `null`                                              | Expéditeur d'enveloppe (`sendmail -f`)         |
| `mail.subject_prefix`   | `''`                                                | Préfixe ajouté au sujet, ex. `'[Répondeur] '`  |
| `mail.attach_audio`     | `true`                                              | Joindre l'enregistrement (toujours joint si la transcription échoue) |
| `mail.header_title`     | `Messagerie vocale`                                 | Titre du bandeau en haut de l'e-mail HTML      |
| `mail.header_color`     | `#1e3a5f`                                           | Couleur du bandeau, en hexadécimal (`#rgb` ou `#rrggbb`) |
| `mail.mailboxes`        | `[]`                                                | Réglages propres à une boîte vocale (voir ci-dessous) |
| `log.ident`             | `voicemail-ai`                                      | Identifiant syslog (`journalctl -t`)           |
| `log.debug`             | `false`                                             | Journaux détaillés                             |

La colonne « Défaut » donne la valeur utilisée quand la clé est absente de `config/config.php`.

### Un rendu différent par boîte vocale

Si le standard gère plusieurs numéros, chacun routé vers sa propre boîte vocale, `mail.mailboxes`
permet d'adapter l'e-mail à chaque boîte. La clé est le numéro de la boîte (`${VM_MAILBOX}`,
lu dans le `Message-ID` généré par Asterisk). La valeur peut remplacer `from_address`,
`from_name`, `subject_prefix`, `attach_audio`, `header_title` et `header_color`. Les clés absentes reprennent les valeurs
globales de la section `mail` :

```php
'mailboxes' => [
    '1001' => [
        'from_address' => 'messagerie@societe-a.example',
        'from_name' => 'Messagerie Société A',
        'subject_prefix' => '[SAV] ',
        'header_title' => 'Société A',
        'header_color' => '#b91c1c',
    ],
    '2000' => [
        'from_address' => 'messagerie@societe-b.example',
        'from_name' => 'Société B',
        'attach_audio' => false,
    ],
],
```

Une option inconnue ou une couleur invalide fait échouer le chargement de la configuration : les messages sont alors
transmis sans enrichissement, jamais perdus.

Un autre fichier de configuration peut être passé avec `--config=/chemin/config.php` ou la
variable d'environnement `VOICEMAIL_AI_CONFIG`.

## Dépannage

Journaux (syslog, facility `mail`) :

```bash
journalctl -t voicemail-ai -f
```

Pour capturer un e-mail réellement produit par Asterisk, mettre temporairement dans
**Mail Command** : `/usr/bin/tee /tmp/vm.eml | /usr/sbin/sendmail -t`, laisser un message, puis le
rejouer avec `--dry-run`.

Les en-têtes `X-Voicemail-Transcription: ok|failed` des e-mails envoyés indiquent si la
transcription a abouti.

## Développement

Code conforme PSR-4 (`VoicemailAi\` → `src/`) et PER Coding Style 3.0.

```bash
composer install
```

```bash
composer test
```

```bash
composer cs-check
```

Arborescence :

```
bin/voicemail-ai                    point d'entrée (mailcmd)
config/config.dist.php              configuration d'exemple
src/Cli.php                         options, chargement config, secours si config invalide
src/Application.php                 orchestration et stratégie de repli
src/Config.php                      lecture et validation de config/config.php
src/Audio/                          AudioFile, AudioConverter (WAV → MP3, ffmpeg)
src/Transcription/                  TranscriberInterface, OvhTranscriber, Transcript
src/Mail/                           VoicemailParser, VoicemailMailer (PHPMailer), TemplateRenderer,
                                    MailProfile(s) (réglages par boîte), RawMailForwarder
src/Exception/                      exceptions de configuration, d'analyse et de processus
src/Log/SyslogLogger.php            logger PSR-3 vers syslog
src/Process/                        exécution de commandes sans shell
templates/                          gabarits HTML et texte de l'e-mail
tests/                              PHPUnit + e-mail Asterisk d'exemple
```
