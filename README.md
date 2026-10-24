# pulse-laravel

Ton app Laravel surveillée par [Pulse](https://pulse-app.si), sur ton iPhone et ton Mac. Tu installes le paquet, Pulse trouve tout seul ta base, ton Redis, ta file et tes tâches planifiées, et te prévient dès que quelque chose cloche.

```
  ton app Laravel                                          ton iPhone, ton Mac
 ┌──────────────────────────┐        HTTPS        ┌──────────┐       ┌──────────────┐
 │ artisan pulse-app:work   │ ──────────────────▶ │  Pulse   │ ────▶ │  app Pulse   │
 │  ├ PostgreSQL  SELECT 1  │   état, tâches,     │  (API)   │ push  │  alertes,    │
 │  ├ Redis       PING      │   journaux          │          │ ◀──── │  journaux,   │
 │  ├ File        taille    │   (chiffrés si tu   │          │  SSE  │  en direct   │
 │  └ planning    passages  │    le veux)         └──────────┘       └──────────────┘
 └──────────────────────────┘
        ▲ storage/pulse/ : journaux et passages des tâches, écrits par tes autres processus
```

Une requête PHP est courte : le rapport ne part donc jamais d'une requête web. Un petit worker, `php artisan pulse-app:work`, tourne à côté de ton app (comme `queue:work`) et parle à Pulse à sa place.

## En deux minutes

```bash
composer require pulse-si/laravel
```

Le paquet est découvert tout seul par Laravel. Ajoute la clé que l'app Pulse t'affiche quand tu ajoutes une app :

```bash
# .env
PULSE_KEY=pk_live_…
```

Puis lance le worker :

```bash
php artisan pulse-app:work
```

Le premier signal arrive dans Pulse deux secondes plus tard. Pour un essai ponctuel, `php artisan pulse-app:report` envoie un seul rapport et affiche ce qui a été mesuré.

Si tu veux régler les options dans un fichier plutôt que dans `.env` :

```bash
php artisan vendor:publish --tag=pulse-app-config
```

`config/pulse-app.php` lit les mêmes variables, et `php artisan config:cache` reste juste.

## Lancer le worker

Le worker suit le rythme dicté par l'API, s'arrête proprement sur `SIGTERM` et `SIGINT` (avec l'extension `pcntl`) et ne fait jamais tomber ton app.

### Supervisor

```ini
[program:pulse]
command=php /var/www/helio/artisan pulse-app:work
autostart=true
autorestart=true
stopwaitsecs=15
user=www-data
```

### systemd

```ini
[Unit]
Description=Pulse pour Helio
After=network-online.target

[Service]
WorkingDirectory=/var/www/helio
ExecStart=/usr/bin/php artisan pulse-app:work
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

### Docker Compose

```yaml
services:
  pulse-app:
    image: helio-app
    command: php artisan pulse-app:work
    env_file: .env
    restart: unless-stopped
```

Le worker, `schedule:run` et tes requêtes partagent le dossier `storage/pulse/` : sur plusieurs conteneurs, monte `storage/` en volume commun, ou au moins ce dossier.

## Ce que Pulse surveille

| Dépendance trouvée | Ce que Pulse fait | Nom affiché |
|---|---|---|
| connexion de base par défaut (`pgsql`, `mysql`, `mariadb`, `sqlite`, `sqlsrv`, `mongodb`) | `SELECT 1`, `ping` pour MongoDB | PostgreSQL, MySQL, MariaDB… |
| Redis, seulement s'il sert : cache, file, session ou diffusion par défaut | `PING` | Redis |
| file par défaut (sauf `sync` et `null`) | lit le nombre de tâches en attente | File default, File emails… |
| planning de `routes/console.php` ou du Kernel | expression, dernier et prochain passage, réussite | le nom de la tâche |

Chaque contrôle a 5 secondes pour répondre et ne fait que lire. Une dépendance en échec, une tâche qui a planté ou qui a sauté son passage de plus de 5 minutes, et l'app passe en « dégradée » dans Pulse avec une notification qui dit quoi.

Le nom d'une tâche est celui que tu lui donnes avec `->name()`, sinon la commande Artisan (`helio:relance`). Les passages sont relevés par `schedule:run` lui-même : une commande qui sort avec un code non nul ou une closure qui lève comptent comme un échec.

Pour suivre un travail qui ne passe pas par le planning de Laravel (un cron système, un script), enveloppe-le :

```php
use Pulse\Laravel\Facades\Pulse;

Pulse::track('relance-abonnements', fn () => $abonnements->relancer());
```

En cas d'erreur, l'échec est noté puis l'erreur te revient telle quelle.

Une route à surveiller en plus :

```php
// config/pulse-app.php
'http_probes' => [
    'Route /health' => 'https://helio.fr/health',
],
```

## Choisir ce qui part

Tu décides de ce que ton app raconte à Pulse. Le signal de vie part toujours, le reste se règle :

```bash
# Seulement l'état des dépendances et les journaux, pas les tâches ni les versions
PULSE_SEND=checks,logs

# Ignorer certaines dépendances ou certaines tâches (* sert de joker)
PULSE_EXCLUDE=File emails,nettoyage-*

# Ou au contraire, ne suivre que celles-ci
PULSE_INCLUDE=PostgreSQL,Redis,relance-abonnements

# Journaux : seulement les avertissements et les erreurs
PULSE_LOG_LEVEL=WARN
```

## Journaux et mode direct (Pro et Team)

Pulse recopie ce que ton app écrit sur le canal de log par défaut (celui de `LOG_CHANNEL`, pile comprise), sans rien changer à ce que tu vois dans `laravel.log` ou ta sortie standard. Le niveau et le canal sont repris, les secrets masqués avant de partir (`token=***`, `Bearer ***`, identifiants dans les URL de connexion).

```
[2026-10-20 12:12:56] production.ERROR: Paiement refusé token=abc123
                                   │
                                   ▼  dans l'app Pulse
ERROR  production  Paiement refusé token=***
```

Le paquet ajoute un handler Monolog à la fin du canal par défaut : tes handlers écrivent d'abord, aucun n'est retiré, et une erreur de Pulse ne peut pas bloquer l'écriture. C'est plus sûr qu'un canal `pulse` à mettre soi-même dans la pile : rien à modifier dans `config/logging.php`, rien d'oublié le jour où la pile change. Le handler écrit chaque ligne nettoyée dans `storage/pulse/logs.ndjson` (1000 lignes au plus) et le worker vide ce fichier toutes les 10 secondes.

Tant que Pulse n'a pas confirmé que ton offre comprend les journaux, rien n'est écrit : sans journaux dans l'offre, ton app ne paie aucun surcoût. Si le worker s'arrête, la recopie se coupe d'elle-même quelques minutes plus tard.

Quand tu ouvres l'app dans Pulse, le serveur prévient le worker qu'on le regarde : l'état part toutes les 5 secondes et les journaux chaque seconde, puis tout revient au calme quand tu quittes l'écran. Les journaux sont gardés 3 jours en Pro et 7 jours en Team.

## Mode sécurisé : Pulse ne peut rien lire

C'est l'option pour qui ne veut confier ses données à personne, nous compris. Ton iPhone ou ton Mac fabrique une paire de clés. La clé publique va dans ton `.env`, la clé privée ne quitte jamais tes appareils.

```bash
PULSE_PUBLIC_KEY=pulse_pub_…   # copiée depuis l'app, Réglages de l'app > Mode sécurisé
```

```
 ton app ──── rapport chiffré (HPKE, X25519 + AES-256-GCM) ────▶ Pulse ──▶ ton iPhone
                                                                 │          │
                                                    garde un paquet         ouvre avec
                                                    qu'il ne sait pas       la clé privée
                                                    ouvrir                  du trousseau
```

Un serveur qui ne lit rien ne peut pas alerter. C'est pour ça qu'un petit résumé reste en clair par défaut : « tout va bien » ou « 2 soucis », sans nom ni message. Tu peux en laisser plus, ou rien du tout :

```bash
PULSE_CLEAR=status            # par défaut : un booléen et un nombre
PULSE_CLEAR=status,checks     # Pulse peut dire « Redis ne répond plus »
PULSE_CLEAR=none              # silence complet : Pulse ne sait plus que si l'app est en vie
```

Le mode sécurisé demande les extensions `sodium` et `openssl` de PHP (présentes dans la plupart des installations). Sans elles, le worker refuse de démarrer plutôt que d'envoyer en clair. Une fois le mode actif, Pulse refuse tout rapport en clair : un `.env` oublié ne fuit pas.

## Sécurité, en clair

- **Ton app d'abord.** Le paquet ne lève jamais d'erreur vers ton app (sauf `Pulse::track`, qui te rend l'erreur de ta tâche). Pulse injoignable : un seul avertissement, le worker continue. Clé refusée : les envois s'arrêtent.
- **Rien n'est écrit chez toi.** Les contrôles ne font que lire (`SELECT 1`, `PING`, taille de la file), avec 5 secondes au plus. Le paquet n'écrit que dans `storage/pulse/`.
- **Rien de superflu ne part.** Ni requête, ni donnée métier, ni variable d'environnement. Les messages d'erreur sont nettoyés et coupés à 300 caractères.
- **La clé ne voyage qu'en HTTPS**, dans un en-tête, jamais dans une URL. Elle ne sert qu'à écrire l'état de cette app : elle n'ouvre pas ton compte.
- **Une seule dépendance** en dehors de Laravel : `pulse-si/php`, qui n'utilise que des extensions courantes de PHP.

## Variables

| Variable | Défaut | Rôle |
|---|---|---|
| `PULSE_KEY` | (obligatoire) | clé de l'app : `pk_live_…` en production, `pk_test_…` en staging |
| `PULSE_SEND` | `checks,crons,logs,versions` | ce qui part en plus du signal de vie |
| `PULSE_INCLUDE` | (tout) | seuls ces contrôles et tâches, noms séparés par des virgules, `*` permis |
| `PULSE_EXCLUDE` | (rien) | contrôles et tâches ignorés |
| `PULSE_LOG_LEVEL` | `DEBUG` | niveau minimal des journaux envoyés |
| `PULSE_LOGS` | `true` | `false` coupe les journaux |
| `PULSE_PUBLIC_KEY` | (aucune) | active le mode sécurisé |
| `PULSE_CLEAR` | `status` | en mode sécurisé, ce qui reste lisible par Pulse |
| `PULSE_ENDPOINT` | `https://api.pulse-app.si` | ton Pulse Relay en mode VPS perso (Team), par exemple `https://relais.helio.fr` |
| `PULSE_RELAY_FINGERPRINT` | (aucune) | empreinte du certificat d'un relais sans domaine : rien ne part si elle ne correspond pas |
| `PULSE_APP_VERSION` | (aucune) | version affichée dans Pulse |
| `PULSE_DISABLED` | `false` | `true` coupe tout (tests, CI) |

## Si rien n'arrive

| Message | Ce qu'il faut faire |
|---|---|
| `PULSE_KEY absente` | la variable n'est pas chargée : vérifie `.env`, puis `php artisan config:clear` |
| `Clé Pulse refusée` | la clé a été remplacée dans l'app : recopie la nouvelle |
| `Mode sécurisé actif pour cette app` | ajoute `PULSE_PUBLIC_KEY` |
| `PULSE_PUBLIC_KEY ne correspond plus` | la clé a changé sur ton téléphone : recopie la clé publique |
| `Pulse injoignable` | réseau ou pare-feu : le worker continue, les envois reprennent seuls |
| aucune tâche planifiée | lance `schedule:run` (ou `schedule:work`) avec la même `PULSE_KEY` que le worker |
| aucun journal | `schedule:run`, la file et le web doivent voir la même `PULSE_KEY` et le même `storage/pulse/` |

Le rythme des rapports suit celui de l'app dans Pulse (30 secondes en Pro, 5 minutes en Gratuit).

Les commandes et la configuration portent le préfixe `pulse-app` : le paquet cohabite avec Laravel Pulse, l'outil officiel de Laravel, qui a déjà `pulse:work` et `config/pulse.php`.

## Licence

MIT
