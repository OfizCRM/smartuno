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

## Switching to Redis

Redis is provisioned and its connection variables are wired, but the queue, cache and session
drivers are on `database` for now — that needs no PHP extension, which removes a failure mode
from the first deploy. To switch:

1. Add `RAILPACK_PHP_EXTENSIONS=redis` (predis is not in `composer.json`, so phpredis is
   required).
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
