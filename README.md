# SmartUno

Multi-channel customer messaging SaaS (Laravel 12 + React/Inertia). Originally based on WhatsMine.

This README is the install and operations guide. Almost every misconfiguration in this
application fails **silently** — a feature is simply absent, or a queue fills up with nothing in
any log — so the sections below say what breaks as well as what to set.

## Requirements

- **PHP 8.2+** with: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, `bcmath`,
  `fileinfo`, `curl`, `zip`, `iconv`, `dom`, `libxml`, `simplexml`, `xml`, `xmlwriter`, `zlib`
- Composer
- **Node.js 20.19+** (or 22.12+, or 24+) and npm
- MySQL 8+ (the storage figure in Documents uses `JSON_TABLE`)
- Optional: Docker, for the document editing service — see below

On Debian and Ubuntu the XML extensions are **not** part of `php-cli`; they come from a separate
package. Install it before anything else:

```bash
sudo apt install php8.3-xml
```

Without them `composer install` refuses to run at all — dompdf, PHPUnit, Pint, the AWS SDK and
the IMAP client each declare one as a platform requirement. The installer's own requirements
screen does not check for them, so nothing tells you why. They are used directly too: every SVG
logo upload is sanitised through `DOMDocument`.

Nothing in the repository pins a Node version, so an older Node installs with only a warning and
then fails partway through `npm run build`. `@vitejs/plugin-react` sets the floor above; `jsdom`,
used by `npm run test`, sets the same one.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create a MySQL database and set `DB_*` in `.env`. Set `APP_ENV=local` while you work on your own
machine — the template ships `production`, which forces the https scheme on generated URLs.

Then start the stack:

```bash
composer dev
```

This runs `php artisan serve`, a queue listener, logs, and Vite.

Open `http://localhost:8000/install` and complete the wizard — **database, then admin account**.
There is no licence step on this build: `config/license.php` sets `verify => false`, so it is
hidden and no key is needed. Until `APP_INSTALLED=true`, all traffic redirects to `/install`; the
installer sets it itself when it finishes.

If `/install` sends you to the login screen instead of running, or the very first request returns
a 500 complaining about a `sessions` table, `APP_INSTALLED` is `true` in a `.env` that has never
actually been installed. Set it to `false` — the wizard is the only thing that runs
`php artisan migrate`, so nothing else will have created the schema.

Then, once, in the project root:

```bash
php artisan storage:link
```

Uploaded logos, favicons, avatars and media go to `storage/app/public` and are served from
`APP_URL/storage`. The symlink is gitignored and neither the wizard nor `saas:install` creates
it, so without this every uploaded image returns 404 — silently, with nothing in the log.

### Installing from the command line instead

The wizard runs all migrations and the seeders inside a single web request. That is several
minutes on a small server; nginx's default `fastcgi_read_timeout` is 60 seconds, so the browser
can show a 504 while the install is still running. Either raise that timeout for the install, or
skip the wizard:

```bash
php artisan saas:install    # migrate, seed core data, create the super-admin
```

**Do not** use a bare `php artisan db:seed`. That runs `DatabaseSeeder`, which pulls in the
inherited demo dataset. The core seeders are all required — without the role seeder the
super-admin is created with no role attached, logs in successfully, and is then refused on every
admin page. Module migrations under `app/Modules/*/database/migrations` are picked up by a plain
`migrate`; there is no extra step for those.

### After any install: check the locale files

Both the wizard and `saas:install` run `i18n:seed-defaults`, which rewrites
`resources/js/locales/en.json` and `ro.json` in place. Both files are hand-authored and tracked
in git, and the command appends machine-generated English to them. Run `git status` afterwards
and revert those two files.

Never run `php artisan i18n:scan --sync` or the translation seeder for the same reason.

## After the install

Sign in at `/login`.

The account the wizard created is a **platform super-admin**. It owns `/admin` — plans, clients,
billing, settings, cron and queue diagnostics — and nothing else. The product itself (Inbox,
contacts, campaigns, automations) lives under `/app` and belongs to a *client*: a customer
organisation with its own users. So the first thing to do is create one under
Admin → Clients, add a user to it, and sign in as that user to look around.

Two settings worth changing immediately:

- **Platform default language.** The seeder marks English as default, so everyone who has not
  picked a language sees an English interface. Switch it to Romanian in the admin panel.
- **`HEALTHZ_TOKEN`.** It is in neither `.env.example` nor the config defaults, and while it is
  blank `/healthz/db`, `/healthz/redis` and `/healthz/queue` answer anyone — not customer data,
  but whether your database is reachable and how deep your queue is.

## Serving it on a server

`php artisan serve` is for your own machine. On a server:

- Point the vhost's document root at **`public/`**, never at the project root — the project root
  would serve `.env`, `vendor/` and `storage/` straight over HTTP. `public/.htaccess` covers
  Apache; on nginx you need `root /path/to/smartuno/public;`,
  `try_files $uri $uri/ /index.php?$query_string;` and a `fastcgi_pass` to PHP-FPM.
- Give the application its own A record and its own certificate
  (`certbot --nginx -d app.example.ro`).

### Permissions

The wizard writes your database credentials into `.env` **from the browser**, so PHP-FPM — not
just your deploy user — must be able to write it. It checks all three paths on the first screen
and refuses to continue if any is read-only:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache .env
```

Its final step writes `APP_INSTALLED=true` back into `.env`. If that write fails, the wizard
stays open to anyone who finds it.

### `APP_URL` must be exact

Same scheme, same host, `www` or not as the site really answers, no trailing slash. This is not
cosmetic:

- The `public` disk builds every uploaded file's URL from it, so a wrong value 404s every logo,
  avatar and media file with nothing in the logs.
- Campaign tracking, unsubscribe and email-verification links are signed inside queue jobs, where
  there is no request to learn the address from. A mismatch makes every one of them return 403.
- It is also the only host allowed in the CSP's `connect-src`, the Stripe and PayPal return
  address, the Meta Embedded Signup `redirect_uri`, Sanctum's stateful-domain list (there is no
  `SANCTUM_STATEFUL_DOMAINS` in the template, so it is derived from `APP_URL`), and the fallback
  for `ONLYOFFICE_APP_URL`.

### `APP_KEY` belongs to the database, not to the machine

WhatsApp tokens, mailbox and SMTP passwords, Stripe and PayPal secrets, AI provider keys, social
OAuth tokens, webhook secrets and two-factor secrets are all stored encrypted under it.

Run `key:generate` once, on a fresh install with an empty database. **If you clone an instance or
restore a dump onto another server, copy the existing `APP_KEY` across.** A new key does not clear
those rows — it makes them undecryptable, and every read throws rather than reporting "not
configured". If you must rotate it, put the old key in `APP_PREVIOUS_KEYS` (comma-separated) so
existing rows can still be read while they are re-encrypted.

### Upload limits

The app accepts media up to 50 MB and documents up to 25 MB, while stock PHP allows 2 MB and
stock nginx 1 MB:

```ini
; php.ini
upload_max_filesize = 60M
post_max_size = 70M
```

```nginx
client_max_body_size 70m;
```

Left at the defaults, an oversized upload arrives with no file attached at all and the user is
told the file field is required — a message that points nowhere near the cause.

### Behind a proxy or load balancer

```env
TRUSTED_PROXIES=10.0.0.4,10.0.0.5
SESSION_SECURE_COOKIE=true
```

Neither is in `.env.example`. `TRUSTED_PROXIES` **defaults to `*`**, and the trusted headers
include `X-Forwarded-Host` — so with the default, any client can send `X-Forwarded-For` to bypass
every IP-keyed rate limiter, or `X-Forwarded-Host` to make the app mint password-reset links
pointing at their own domain.

The proxy must pass `proxy_set_header X-Forwarded-Proto $scheme;`, and `APP_URL` must start with
`https://`. The app only forces the https scheme on URLs built outside a request when `APP_ENV`
is **exactly** `production` — so on a staging box, or with the duplicate `APP_ENV` above left in
place, campaign tracking links and anything a queued job generates come out `http://`.

### Do not cache the config

`php artisan config:cache` (and `optimize`) stops Laravel reading `.env` altogether — and this
codebase calls `env()` outside `config/`. `HandleInertiaRequests` gets the Pusher key that way,
so the server keeps broadcasting while the browser is handed an empty key and real-time goes dark
after the deploy, with nothing in any log. `TRUSTED_PROXIES` falls back to `*` the same way.

`route:cache` and `view:cache` are fine. If you do cache the config, set those values in the
server environment too (a `fastcgi_param`, or systemd `Environment=`). After changing any
`ONLYOFFICE_*` value, run `php artisan config:clear`.

### Build the frontend

On any machine not running `composer dev` — staging, production, a CI box:

```bash
npm ci && npm run build
```

`public/build/` is not in the repository, so a fresh clone has no Vite manifest and every page
throws "Vite manifest not found". If the site is blank and the page source points at
`127.0.0.1:5173`, delete `public/hot` — Vite writes it when `npm run dev` starts and Laravel
trusts it in every environment.

## Queue workers

Everything that is not a page render is a queued job, and jobs go onto **six named queues**:
`default`, `whatsapp`, `broadcast`, `social`, `ai`, `automation`.

A worker started without `--queue` drains only `default`, so the other five fill up in silence.
Inbound WhatsApp, Messenger and Instagram messages never reach the Inbox, campaigns never send,
scheduled posts never publish, knowledge-base documents never index, and every automation run
stays "queued". **`composer dev` covers only `default`** — it is a development convenience, not a
model for a server.

```bash
php artisan queue:work --queue=default,whatsapp,broadcast,social,ai,automation --tries=3 --max-time=3600
```

Keep it alive with supervisor or a systemd unit. Do not drop `default` from the list: broadcast
events, queued notifications and outbound webhooks all live there.

Two traps:

- `docker/supervisor/whatsmine.conf` and `docker-compose.queues.yml` are inherited examples, not
  a supported configuration. Both run `queue:work redis` while the app defaults to
  `QUEUE_CONNECTION=database`, so copying them gives you workers polling a Redis list nothing
  writes to, every process reported RUNNING, while jobs pile up in the MySQL `jobs` table.
- Do not set `QUEUE_CONNECTION=sync` to "just get it running". Automation Wait and Ask-question
  nodes resume by re-dispatching a delayed job, which the code deliberately skips on `sync` — so
  those runs sit at "waiting" for ever.

Redis is optional: queue, cache and sessions all default to `database`, and `/healthz/redis`
returning 503 without Redis is expected. If you do switch, you also need the `redis` PHP
extension — `REDIS_CLIENT` is `phpredis` and Predis is not installed as a fallback.

## Cron

There is no `app/Console/Kernel.php` — the whole schedule lives in `routes/console.php`, and none
of it runs without one crontab line, as the user PHP runs as:

```cron
* * * * * cd /path/to/smartuno && php artisan schedule:run >> /dev/null 2>&1
```

Without it, fifteen scheduled tasks never run: scheduled campaigns and social posts, social OAuth
token refresh (access tokens live one to two hours, so the UI shows "Token expired" most of the
day), mailbox polling, WhatsApp template sync, document expiry reminders, the document bin purge
(the disk grows for ever while the storage figure on screen says otherwise), subscription sync
and trial expiry. None of it logs an error.

Admin → Cron Setup reads a heartbeat the scheduler writes every minute and will tell you whether
your entry is firing.

**The scheduler runs in UTC and there is no setting for it** — `config/app.php` sets the timezone
as a literal, so `APP_TIMEZONE` does nothing. Fixed-time tasks land two to three hours late in
Romanian local time: the document-expiry reminder set for 07:10 arrives at 10:10 in summer.

## Mail

Mail is configured in **two** places, and both start out non-functional.

1. **`.env`** ships `MAIL_MAILER=log`, which writes messages to `storage/logs` instead of sending
   them, and the wizard never asks for mail settings. Set real `MAIL_*` credentials.
2. **Admin → Email System → SMTP.** Password resets, email verification, team invitations and
   support-ticket mail do not use the `.env` mailer at all — they look up an active SMTP row in
   the database, which the seeders do not create. With no row, the mail service logs a warning and
   returns false, and the invitation screen ignores that and still says "Invitation sent".

Set `MAIL_FROM_ADDRESS` to an address on a domain you control and have SPF and DKIM records for,
then use the test-send button on that admin page and confirm the message actually arrives.

## Real-time (Pusher)

Real-time is off until you turn it on. `.env.example` ships `BROADCAST_CONNECTION=log`, so on a
fresh install the Inbox does not update when a message arrives, typing indicators never appear,
and assignment toasts never fire. There is no polling fallback — the only signal is a
`console.warn` in the browser.

Set `BROADCAST_CONNECTION=pusher` and fill in `PUSHER_APP_ID`, `PUSHER_APP_KEY`,
`PUSHER_APP_SECRET` and `PUSHER_APP_CLUSTER`.

**Ignore the Reverb block in `.env.example`.** `resources/js/echo.js` hard-codes the Pusher
broadcaster with `forceTLS` and no `wsHost`/`wsPort`, so the browser always dials
`ws-<cluster>.pusher.com` and a self-hosted Reverb server can never be reached.

## What `.env.example` promises but does not do

Three blocks in the template are dead. They are worth knowing about because filling them in looks
like progress and changes nothing.

| Block | Reality |
|---|---|
| **AI** (`AI_PROVIDER`, `OPENAI_API_KEY`, `OPENAI_MODEL`, …) | Read by nothing in the product. AI keys are stored encrypted per workspace and are set from inside the app. Until one is saved there, every AI call fails with "No AI provider configured for workspace". |
| **`AWS_*` / S3** | Dead for file storage — the `s3`, `do_spaces` and `wasabi` disks are filled in at runtime from the encrypted integrations table. Configure cloud storage in Admin → Integrations → Storage. Still live for SES mail and the SQS driver, which is why it is confusing rather than merely unused. |
| **`FILESYSTEM_DISK`** | No effect on uploads. Everything user-uploaded goes through `StorageManager`, which never consults the framework default. |

Two more that are absent from the template but real:

- `VITE_HANDSONTABLE_LICENSE_KEY` — read by the contact bulk-import grid. Unset, it falls back to
  Handsontable's `non-commercial-and-evaluation` key, which is not valid for a product you sell.
  It is baked in at build time, so set it **before** `npm run build`.
- `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` — web push stays off until you run
  `php artisan webpush:vapid` and put the pair in `.env`.

## Know what is public

Media, avatars, logos and chat attachments go to the `public` disk, which is symlinked into the
web root and served **directly by nginx** — the request never reaches Laravel, so there is no
workspace check and no login. Filenames are UUIDs, so nobody can walk the directory, but anyone
holding a URL keeps access for ever.

Only the document library (`storage/app/private`) is served through a controller that checks the
workspace first. If you switch storage to DigitalOcean Spaces, objects are written `public-read`,
which is the same trade.

## Inbound messages need a public address

WhatsApp, Messenger, Instagram, the SMS providers and the e-commerce stores all deliver by calling
us back on `/webhooks/…`. Meta registers that URL from `APP_URL` and will only accept an `https://`
address with a valid public certificate, which it verifies by calling it.

So behind NAT, HTTP basic auth, an IP allowlist or a Cloudflare bot challenge, inbound messages
never arrive — the channel still shows as connected and the Inbox simply stays empty. Leave
`/webhooks/` open to the internet; the controllers verify each provider's signature themselves.
Use a tunnel such as ngrok to test inbound locally.

## Optional services

### Document editing (ONLYOFFICE Docs)

Word, Excel and PowerPoint files are edited in the browser, as they are on a
desktop. The engine is [ONLYOFFICE Docs Community
Edition](https://github.com/ONLYOFFICE/DocumentServer), running as its own
container.

**Read this before deciding to deploy it.** The Community Edition is AGPL v3 and
**its branding must stay visible** — hiding or removing the ONLYOFFICE logo
breaches the licence we use it under. White-labelling requires their paid
Developer Edition. This was a deliberate product decision, not an oversight.

Leave `ONLYOFFICE_URL` blank and document editing is simply absent — the button is not shown and
nothing else changes.

#### 1. A secret, shared by both sides

```bash
openssl rand -hex 32
```

Put the same value in `.env` as `ONLYOFFICE_SECRET`. `docker-compose.yml` reads
it from there and passes it to the container as `JWT_SECRET`.

**JWT is not optional.** Without it, anything that can reach the container can
ask it to open any document — and ONLYOFFICE additionally refuses to fetch files
from private addresses when JWT is off, so it would not work anyway.

Changing the secret later needs the container **recreated**, not restarted — Compose reads the
value at creation and the image writes it into its own config:

```bash
docker exec onlyoffice env | grep JWT_SECRET   # what the container actually has
docker compose up -d onlyoffice                # recreate it after any change
```

#### 2. Start it

```bash
docker compose up -d onlyoffice
curl -s http://localhost:8080/healthcheck    # expect: true
```

The first start takes a couple of minutes. The container wants about **2 GB of RAM and 5 GB of
disk** — a ~1.4 GB pull that unpacks to ~5 GB, before the three named volumes hold anything. On a
small VPS `docker compose up` dies partway through the pull with `no space left on device`.

It is bound to `127.0.0.1`: reached by the browser on the same host, and by nothing else.
`restart: unless-stopped` brings it back after a reboot on its own, as long as the Docker daemon
starts at boot.

Two things in `docker-compose.yml` to change deliberately: the image is `:latest`, so pin a
version instead — two servers provisioned a month apart otherwise run different ones. And
`container_name: onlyoffice` plus the fixed `127.0.0.1:8080` publish collide with any second
instance on the same box.

#### 3. Two addresses, and they are different

This is where the integration usually fails.

```env
ONLYOFFICE_URL=http://localhost:8080
ONLYOFFICE_APP_URL=http://host.docker.internal:8000
```

- `ONLYOFFICE_URL` — where the **browser** loads the editor from.
- `ONLYOFFICE_APP_URL` — how the **container** reaches this application back, to
  collect the file and to report a save. A container's `localhost` is the
  container itself, so this is never the same address as the one in your address
  bar. On macOS and Windows use `host.docker.internal`; on a Linux server use the
  host's address on the docker bridge (usually `172.17.0.1`).

Get this wrong and the editor opens to a blank page with no error in the browser
console — the failure is on the container's side, in `docker logs onlyoffice`.

**`ONLYOFFICE_URL` is not browser-only.** When the editor reports a save it hands us a download
link on the container's own origin; the application rewrites that link onto `ONLYOFFICE_URL` and
fetches the file *itself*. So the PHP process makes an outbound request to that address. Test it
from the server, not from your laptop:

```bash
curl -sI https://docs.example.ro/healthcheck    # expect: 200
```

If that fails, the editor still opens and still says the document saved — the fetch is caught and
logged, and the edit is lost.

#### 4. On a real server

**Both must be HTTPS.** If the application is served over HTTPS and the Document Server over
plain HTTP, the browser silently refuses to load the editor as mixed content. Give it a
subdomain of its own:

```nginx
server {
    listen 443 ssl;
    server_name docs.example.ro;

    ssl_certificate     /etc/letsencrypt/live/docs.example.ro/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/docs.example.ro/privkey.pem;

    client_max_body_size 100m;    # nginx defaults to 1m — inserting an image exceeds it
    proxy_read_timeout   3600s;   # the editor's websocket idles far longer than the 60s default
    proxy_send_timeout   3600s;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;      # the editor uses websockets
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

The certificate has to cover **that name**. One issued for `example.ro` alone does not — use a
wildcard, a SAN that lists the subdomain, or `certbot --nginx -d docs.example.ro`. A self-signed
certificate is worse than a broken one: the browser can be told to accept it, but the
application's own fetch of the saved file cannot, so editing appears to work and silently loses
every save.

**The internal address needs a server block too.** `ONLYOFFICE_APP_URL` must answer over plain
HTTP without redirecting. A normal TLS vhost carries `listen 80; return 301 https://…` and no
`server_name` matching an IP, so the container's request gets bounced to a certificate that cannot
match:

```nginx
server {
    listen 172.17.0.1:80;
    server_name 172.17.0.1;
    root /var/www/smartuno/public;
    # ... the same index / fastcgi_pass configuration as the public vhost ...
}
```

**Let the Docker bridge through the firewall.** `ufw` denies incoming by default, and
`docker0 → host` counts as incoming. This is the most common cause of the blank editor:

```bash
sudo ufw allow in on docker0 to any port 80 proto tcp
```

Confirm it from inside the container before blaming anything else:

```bash
docker exec onlyoffice curl -sI http://172.17.0.1/    # expect 200 or 302
```

**Do not add a second Content-Security-Policy.** The application sets its own and builds the
editor's origin into `script-src`, `style-src`, `font-src`, `img-src`, `connect-src` (plus
`ws:`/`wss:`) and `frame-src` straight from `ONLYOFFICE_URL` — there is nothing to edit by hand,
but the value must be the exact origin the browser sees, down to the port. The app only sets its
policy when the response does not already carry one, and it cannot see a header added at nginx or
in a Cloudflare Transform Rule, so the browser enforces both as an intersection and a routine
`default-src 'self'` kills the editor.

**Do not put the docs host behind a bot challenge or SSO proxy** — a challenge answers with an
HTML page instead of the document. A subdomain on a Cloudflare-managed domain is proxied by
default; grey-cloud it.

#### 5. Check it end to end

Open any `.docx` in Documents and press the editor button. If the page stays
blank:

```bash
docker logs --tail 50 onlyoffice     # almost always says why
```

The usual causes, in order: a firewall blocking `docker0`, an `ONLYOFFICE_APP_URL` the container
cannot reach, a secret that differs between `.env` and the container, and mixed content on HTTPS.

A **403** on `/documents/office/…` is a different failure. Those URLs are signed *relative* —
Laravel validates the signature against the path only and ignores the host, which is exactly why
the container may use a different address than the browser. So a 403 is never an `APP_URL`,
`TRUSTED_PROXIES` or `X-Forwarded-*` problem. It means either the path was rewritten between the
container and the application, or `APP_KEY` changed. Two application instances behind a load
balancer must share both `APP_KEY` and `storage/app/private`.

## Deploying an update

Upgrades are `git pull` — the in-app updater is disabled on this build. After the pull, in this
order:

```bash
php artisan down
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:clear
php artisan queue:restart
php artisan up
```

The workers are long-lived and hold the old code in memory, so without `queue:restart` they keep
running the previous release against the new schema for up to an hour, quietly.

Back up the database before you migrate. Rolling back is not symmetrical — reverting the code does
not revert a migration.

## Backups

A complete backup is four things, and the database alone is not enough:

- the MySQL database;
- **`storage/app/private`** — the document library, and nothing else holds it (the `onlyoffice-*`
  Docker volumes hold only the Document Server's own state and can be rebuilt);
- `storage/app/public` — media, logos, avatars, favicons;
- **`APP_KEY` from `.env`** — restore a database without it and every stored token, password and
  payment secret in it is undecryptable.

```bash
php artisan db:backup
```

Two things to know about it: it shells out to `mysqldump`, which is a separate package from the
MySQL server, and it writes onto the same disk it is protecting. Copy the result somewhere else.

Logs go to a single unrotated file (`LOG_STACK=single`) and nothing trims it. Set
`LOG_STACK=daily` or add a logrotate entry — on a small VPS a log file nobody watches is a
plausible way to fill the disk and take the application down.

## Checking the install worked

Every common misconfiguration here fails silently, so check deliberately:

1. `curl -sI https://your-domain/up` — 200 means PHP, the framework and the database booted.
2. Admin → Cron Setup shows a heartbeat from within the last minute. "Inactive" means your
   crontab entry is not firing.
3. Queue something (send a campaign to yourself) and confirm it completes. If nothing ever
   completes and Failed Jobs is empty, your worker is not covering the named queues.
4. Admin → Email System → send a test message, and confirm it **arrives**, not just that the page
   says it sent.
5. Upload a logo under Admin → Settings and check the image renders — a broken image means
   `storage:link` was not run.
6. Open a `.docx` in Documents, type something, close it, and reopen it. That exercises the whole
   ONLYOFFICE round trip including the save the application fetches itself.

Every page also loads a webfont from `fonts.bunny.net`. On a network with restricted outbound
access that request fails and the interface falls back to system fonts — nothing else breaks, but
it explains a site that looks wrong and works fine.

## Tests

```bash
mysql -e 'CREATE DATABASE wm3_test;'
php artisan test
npm run test
```

The suite runs against its **own** MySQL schema, never the one in `.env` — most test files call
`migrate:fresh`, which would drop every table in your working install. `phpunit.xml` pins them to
`wm3_test`, but nothing creates it, so the first run dies on an unknown database until you do.
SQLite is not an option: the migrations use MySQL-specific syntax.

Use `php artisan test`, **not** `composer test` — that script is `artisan test --parallel` and
paratest is not installed.

## Git workflow (2 developers)

Do not push features to `main`. Default branch on GitHub is `staging`.

| Branch | Role | Server |
|---|---|---|
| `feature-…` / `fix-…` | Your task. One branch per task. | Local only |
| `staging` | Shared test. Both of you merge here. | Staging instance |
| `main` | Production. Only after staging looks good. | Production instance |

### Daily work

```bash
git checkout staging
git pull origin staging
git checkout -b feature-task1
```

Commit and push the feature branch (not `main`):

```bash
git add -A
git commit -m "Explain why this change exists."
git push -u origin feature-task1
```

Open a Pull Request **into `staging`**. After review, merge it. Staging server pulls `staging`.

When staging is verified, open a Pull Request **`staging` → `main`**. Production server pulls `main`.

Hotfix for production: branch from `main`, PR into `main`, then merge or cherry-pick the same fix into `staging`.

## Notes

- `.env` is not committed. Use `.env.example` as the template. Staging and production each have
  their own `.env`.
- Every optional service in `.env.example` (ONLYOFFICE, Sentry, OneSignal, social login) is
  silently absent until it is configured, rather than failing loudly.
- Frontend source lives in `resources/js/` (React/JSX). Do not edit `public/build/`.
- This repository is private. The product license is proprietary.
