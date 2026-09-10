# Deploying SmartUno on Railway

Everything Railway needs is declared in [`railway.ts`](railway.ts). That file is the only place
deployment settings live.

> This runbook lives here rather than in `docs/` because `/docs` is in `.gitignore`, so nothing
> written there is committed or reaches the team.

## Why not `railway.toml`

Config as Code is deprecated. Existing `railway.toml` / `railway.json` files stop being read on
**2026-12-01**, and a service that has never used Config as Code **cannot opt into it** after
2026-08-28. Every service in this project was created after that date, so the `.toml` route is
closed. Infrastructure as Code (`.railway/railway.ts`) replaces it.

## Why there is no `frontend` service

SmartUno is a Laravel + Inertia monolith. The React code in `resources/js` is compiled by Vite
into `public/build` and served by Laravel itself — see `vite.config.js`, which declares a single
entry point, and `resources/views/app.blade.php`, the one page every route renders through.
There is no `npm start`, no Node server, and no build output that serves itself. A second
Railway service has nothing to run.

## What is deployed, and what is not yet

Declared today: **backend** (web), **MySQL**, **Redis**, a **bucket** for public media, and a
**volume** for private files.

Not yet declared, and nothing in this list works until they are:

| Missing service | What stops working |
|---|---|
| `worker` — `php artisan queue:work` | Every queued job: WhatsApp sends, campaign delivery, automation runs, AI indexing, social posting, lead imports. |
| `scheduler` — `php artisan schedule:work` | All 14 scheduled tasks in `routes/console.php` — inbound e-mail polling, scheduled campaigns and posts, social token refresh, trial expiry, billing sync, document reminders and purge. |

Five of those run every minute, so the scheduler has to be a long-running `schedule:work`
process, not a Railway cron.

## First deploy

1. **Apply the infrastructure.**

   ```bash
   railway link            # select the project
   railway config plan     # read-only: shows the diff, changes nothing
   railway config apply
   ```

2. **Set the four variables the config deliberately leaves empty.** They are marked
   `preserve()`, so re-applying never clears them.

   | Variable | Value |
   |---|---|
   | `APP_KEY` | output of `php artisan key:generate --show` |
   | `APP_URL` | the generated Railway domain, **with `https://`** |
   | `HEALTHZ_TOKEN` | any random string |
   | `APP_INSTALLED` | leave unset for now — step 4 sets it |

   `APP_URL` must start with `https://`: `AppServiceProvider` only calls
   `URL::forceScheme('https')` when it does, and without it every asset and signed URL is
   emitted as `http` and blocked by the browser.

3. **Run the install wizard once.** Open `/install` and complete it. The database credentials it
   asks for are the MySQL service's. The wizard writes them to `.env`, which is discarded —
   Railway's own variables are what the app reads. What matters is what it puts in MySQL: the
   schema, permissions, roles, plans and your admin account. Those persist.

   Leave **"import demo data" unchecked** unless you want the SpaGreen demo tenant in production.

4. **Set `APP_INSTALLED=true` in the dashboard and redeploy.** This is the step that closes the
   wizard for good. `InstallerService::markInstalled()` writes the flag to `.env`, and `.env`
   does not survive a deploy here — a real Railway variable does. Skipping this leaves
   `/install` reachable over a populated database.

   The redeploy also restores `resources/js/locales/*.json` from git. The wizard's `seedCore()`
   calls `i18n:seed-defaults`, which runs `TranslationSeeder` and rewrites those files inside
   the container; `/i18n/{locale}` serves them at runtime, so until this redeploy the app shows
   generated translations instead of the hand-authored ones.

5. **Connect the bucket** in Admin › Integrations › Storage, using the keys from the bucket's
   service variables. Do **not** add `AWS_*` environment variables — `StorageManager` reads
   storage credentials only from the encrypted `integration_configs` table and has, in its own
   words, "zero fallback to the environment".

## Production environment variables

Three groups: what the IaC sets for you, what you must set by hand, and what is optional per
feature. Anything not listed here can stay unset -- the config defaults are correct.

### Set by `railway config apply` -- do not set these by hand

`APP_NAME` `APP_ENV` `APP_DEBUG` `APP_LOCALE` `APP_FALLBACK_LOCALE` `APP_DEMO_MODE`
`RAILPACK_PHP_EXTENSIONS` `RAILPACK_SKIP_MIGRATIONS` `DB_CONNECTION` `DB_HOST` `DB_PORT`
`DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` `REDIS_CLIENT` `REDIS_HOST` `REDIS_PORT`
`REDIS_PASSWORD` `QUEUE_CONNECTION` `CACHE_STORE` `SESSION_DRIVER` `SESSION_SECURE_COOKIE`
`SESSION_LIFETIME` `SESSION_SAME_SITE` `LOG_CHANNEL` `LOG_LEVEL` `BROADCAST_CONNECTION`
`FILESYSTEM_DISK`

Setting one of these by hand as well means two sources of truth for the same value. If you are
not running the IaC, copy them out of `railway.ts`, and use Railway's reference syntax for the
database ones: `${{MySQL.MYSQLHOST}}`, `${{MySQL.MYSQLPORT}}`, `${{MySQL.MYSQLDATABASE}}`,
`${{MySQL.MYSQLUSER}}`, `${{MySQL.MYSQLPASSWORD}}`.

### Required, by hand

| Variable | Value | Why |
|---|---|---|
| `APP_KEY` | `php artisan key:generate --show` | Encryption key. Sessions, encrypted columns and integration credentials are unreadable without a stable one. Never rotate it after go-live. |
| `APP_URL` | the Railway domain, **with `https://`** | `AppServiceProvider` only forces the https scheme when this starts with `https`. It also seeds Sanctum's stateful domains and every callback URL. |
| `HEALTHZ_TOKEN` | any long random string | Guards `/healthz/db`, `/healthz/redis`, `/healthz/queue`. Unset means those endpoints answer to anyone. |
| `APP_INSTALLED` | `true`, **after** running `/install` once | The wizard writes this to `.env`, which does not survive a deploy. Only a real Railway variable closes the wizard for good. |

### Required for anything that sends e-mail

Password resets, user invitations, trial-ending notices and the weekly digest all go through
this. Left unset, mail is written to the log and silently never arrives.

| Variable | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | your provider's SMTP host |
| `MAIL_PORT` | `587` (or `465` with `MAIL_SCHEME=smtps`) |
| `MAIL_USERNAME` | provider username |
| `MAIL_PASSWORD` | provider password |
| `MAIL_FROM_ADDRESS` | an address on a domain you own and have SPF/DKIM for |
| `MAIL_FROM_NAME` | `Smartuno` |

### Optional, per feature

Each block is inert until you set it. Nothing breaks by leaving them out.

**Real-time updates** (live inbox, typing indicators). Without it the UI still works, it just
does not update on its own.
`BROADCAST_CONNECTION=pusher` plus `PUSHER_APP_ID` `PUSHER_APP_KEY` `PUSHER_APP_SECRET`
`PUSHER_APP_CLUSTER`, and `VITE_PUSHER_APP_KEY` / `VITE_PUSHER_APP_CLUSTER` -- see the build-time
note below. Reverb is the self-hosted alternative and needs its own always-on service.

**AI features**: `OPENAI_API_KEY`, optionally `OPENAI_MODEL` (default `gpt-4o-mini`),
`AI_CREDITS_PER_GENERATION`. For the knowledge base: `QDRANT_URL`, `QDRANT_API_KEY`.

**Billing**: `BILLING_STRIPE_ENABLED=true` with `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`;
`BILLING_PAYPAL_ENABLED=true` with `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`
and `PAYPAL_SANDBOX=false`. Publishable keys are configured in the admin panel, not here.

**Web push**: `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` from `php artisan webpush:vapid`, plus
`VITE_VAPID_PUBLIC_KEY`.

**Social login**: `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`, `MICROSOFT_CLIENT_ID` /
`MICROSOFT_CLIENT_SECRET`. The redirect URIs derive from `APP_URL`.

**Error monitoring**: `SENTRY_LARAVEL_DSN`, and `SENTRY_TRACES_SAMPLE_RATE` (keep it low).

**Document editing**: `ONLYOFFICE_URL`, `ONLYOFFICE_APP_URL`, `ONLYOFFICE_SECRET`. Needs a
separate ONLYOFFICE container with roughly 2 GB of RAM; leave blank and the feature is absent.

### Four things that will bite you

**`VITE_*` variables are compiled into the JavaScript bundle at build time.** They are not read
at runtime. Set them *before* the build that should contain them, and redeploy after changing
one. They are also shipped to every browser -- a `VITE_` variable is public by definition, so
never put a secret in one.

**Do not set `AWS_*`.** They look like they configure the bucket and they configure nothing.
`StorageManager` reads storage credentials only from the encrypted `integration_configs` table
and states it has "zero fallback to the environment". The bucket is connected in
Admin > Integrations > Storage.

**Do not set `ADMIN_SEED_*` or `CLIENT_SEED_*`.** They exist for seeders, which production never
runs, and `.env.example` ships them with the password `12345678`.

**Do not set `HTTP_CLIENT_VERIFY_SSL=false`.** It defaults to true and disabling it turns off
certificate verification on every outbound call -- Meta, Stripe, every webhook.

`LICENSE_*` is not needed: `config/license.php` has `'verify' => false`, so licensing is off and
the install wizard will not ask for a code.

## File storage: two mechanisms, not one

The bucket does not cover everything, and cannot.

- **Public media** — logos, favicons, WhatsApp previews — goes through `StorageManager` to the
  bucket, once step 5 is done.
- **Private files** — invoices, scanned IDs, signed contracts, e-mail attachments, the document
  library — goes through `PrivateFileStore`, which is pinned to the `local` disk *on purpose*:
  the cloud disks in `config/filesystems.php` are declared public-read, and a signed contract
  must not have a public URL. That is what the volume mounted at `/app/storage/app` is for.

A Railway volume attaches to exactly one service. When the worker is added it will **not** see
this volume, and `IndexDocumentJob` reads `Storage::disk('local')` directly — so document
indexing is the first thing that breaks at that point. It needs a code change, not a config
change; decide it then.

## Migrations

`preDeploy: "php artisan migrate --force"` runs them once, between build and deploy, with the
private network available. A failure there stops the deployment and the previous version keeps
serving.

`RAILPACK_SKIP_MIGRATIONS=true` is **not optional**. Railpack otherwise runs `migrate` *and the
seeders* on every boot of every replica. Seeding production means `TranslationSeeder`, which
rewrites the hand-authored locale files.

## PHP extensions

Railpack installs a fixed base list -- `ctype curl dom fileinfo filter hash mbstring openssl
pcre pdo session tokenizer xml` -- plus whatever the **root** `composer.json` declares as
`ext-*`. This `composer.json` declares none, so anything else has to be named in
`RAILPACK_PHP_EXTENSIONS`, which is additive to that base list.

Currently `zip,pdo_mysql`:

- **zip** -- `webklex/php-imap` requires it. Without it `composer install` aborts and the build
  fails before it reaches the app. This is what killed the first deploy.
- **pdo_mysql** -- the base list ships `pdo` but no driver. Nothing in `composer.lock` requires
  it, so composer never complains; it surfaces later as `could not find driver` at `migrate`.

The app code itself calls nothing from `gd`, `exif`, `bcmath` or `intl`, so those are
deliberately not installed. Add one only when something actually fails for want of it.

> Known gap, not yet addressed: Railpack runs `composer install` without `--no-dev`, so phpunit
> and pint ship in the production image. Fixing it means overriding the build command, which
> replaces Railpack's whole pipeline rather than adjusting it -- not worth doing while the
> pipeline is the thing that works.

## Do not put a `classmap` in composer.json

Railpack copies only `composer.json`, `composer.lock` and `artisan` into the layer where it runs
`composer install`, so that dependency installs cache independently of app code. The rest of the
application is not there yet.

Composer treats that difference asymmetrically: a **missing PSR-4 directory is skipped silently**,
a **missing classmap path is a fatal error**. That is why `"App\\": "app/"` survives even though
`app/` does not exist at that point, while a single classmap entry failed the whole build.

The entry that broke it pointed at `app/Modules/Integrations/database/seeders/`. It existed to
paper over a casing mismatch -- the class is namespaced `App\Modules\Integrations\Database\Seeders`
(capitals) but the directory is `database/seeders` (lowercase), which resolves on a
case-insensitive Windows filesystem and never on Linux. It is now an explicit PSR-4 prefix
mapping instead, which fixes the casing properly and builds cleanly.

Changing `autoload` does not invalidate `composer.lock`: its content hash covers only
dependency-relevant keys, not autoload configuration.

## Switching to Redis

Redis is provisioned and its connection variables are wired, but the queue, cache and session
drivers are on `database` for now — that needs no PHP extension, which removes a failure mode
from the first deploy. To switch:

1. Append `redis` to the existing `RAILPACK_PHP_EXTENSIONS` list, making it
   `zip,pdo_mysql,redis` (predis is not in `composer.json`, so phpredis is required).
2. Change `QUEUE_CONNECTION`, `CACHE_STORE` and `SESSION_DRIVER` to `redis` in `railway.ts`.
3. `railway config plan`, then apply.

Do this together with adding the worker, not before: switching the queue driver with no worker
running only moves the pile of unprocessed jobs from MySQL to Redis.

## Verifying a deploy

```bash
railway logs                                                   # per service
curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/up  # expect 200
curl -sS -H "Authorization: Bearer $HEALTHZ_TOKEN" https://<domain>/healthz/db
```
