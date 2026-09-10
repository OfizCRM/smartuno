import {
  bucket,
  defineRailway,
  mysql,
  preserve,
  project,
  redis,
  service,
  volume,
} from "railway/iac";

/**
 * Railway infrastructure for SmartUno.
 *
 * Config as Code (railway.toml / railway.json) is deprecated: it stops being
 * read on 2026-12-01, and a service created after 2026-08-28 cannot opt into it
 * at all. Every service in this project is newer than that, so a .toml file is
 * not an option here — this is the replacement.
 *
 * Apply with:
 *   railway config plan     # read-only diff, changes nothing
 *   railway config apply
 *
 * This is stage one: the web service only. The queue worker and the scheduler
 * are a deliberate follow-up — docs/deploy-railway.md lists what does not run
 * until they exist, and it is not a short list.
 */

// Amsterdam: closest Railway region to Romanian customers, and keeps personal
// data inside the EU.
const REGION = "europe-west4";

export default defineRailway(() => {
  const db = mysql("mysql");
  const cache = redis("redis");

  /**
   * Public media only — logos, favicons, WhatsApp previews.
   *
   * Its credentials are deliberately NOT wired in as environment variables.
   * StorageManager reads every storage credential from the encrypted
   * `integration_configs` table and states it has "zero fallback to the
   * environment", so AWS_* variables here would be read by nothing. The bucket
   * is connected from Admin > Integrations > Storage after the app is installed.
   */
  const media = bucket("media");

  /**
   * Private files: invoices, scanned IDs, signed contracts, e-mail attachments,
   * the document library.
   *
   * These cannot live in the bucket. PrivateFileStore is pinned to the `local`
   * disk on purpose — the cloud disks in config/filesystems.php are declared
   * public-read, and a signed contract must not have a public URL. Railway's
   * container filesystem is ephemeral, so without this volume every private
   * file is destroyed on each deploy.
   *
   * Mounted at storage/app, not storage/: framework caches and logs belong on
   * the disposable layer.
   */
  const privateFiles = volume("backend-storage", {
    region: REGION,
    sizeMB: 5120,
  });

  const backend = service("backend", {
    // No build or start command on purpose. Railpack detects `artisan` and
    // serves the app through FrankenPHP with public/ as the document root, and
    // detects package.json and runs `npm run build` for the Inertia bundle.
    // Overriding either replaces that whole pipeline rather than adding to it.

    // Migrations run here: once, between build and deploy, with the private
    // network available. Not at container start — see RAILPACK_SKIP_MIGRATIONS.
    preDeploy: "php artisan migrate --force",

    // /up is Laravel's own health route, registered in bootstrap/app.php. The
    // /healthz/* routes are not usable here: they expect an Authorization
    // bearer token that Railway's probe cannot send.
    healthcheck: "/up",
    healthcheckTimeout: 60,

    volumeMounts: {
      "/app/storage/app": privateFiles,
    },

    env: {
      // ── Set by hand in the dashboard; preserve() stops this file clearing them ──

      // Must be the https:// Railway domain. AppServiceProvider only calls
      // URL::forceScheme('https') when APP_URL already starts with https, so
      // getting this wrong emits every asset and signed URL as http.
      APP_URL: preserve(),
      // From `php artisan key:generate --show`.
      APP_KEY: preserve(),
      // Absent until /install has been run once, then set to true by hand. The
      // wizard's own markInstalled() writes the flag to .env, and .env does not
      // survive a deploy here.
      APP_INSTALLED: preserve(),
      // Guards /healthz/db, /healthz/redis and /healthz/queue for external
      // monitoring. Any random string.
      HEALTHZ_TOKEN: preserve(),

      // ── Application ──
      APP_NAME: "Smartuno",
      APP_ENV: "production",
      APP_DEBUG: "false",
      // Romanian customers, Romanian default. English stays as the fallback so
      // a missing key degrades to English rather than to the raw key.
      APP_LOCALE: "ro",
      APP_FALLBACK_LOCALE: "en",
      APP_DEMO_MODE: "false",

      // Railpack installs a fixed base list -- ctype curl dom fileinfo filter
      // hash mbstring openssl pcre pdo session tokenizer xml -- plus whatever
      // the root composer.json declares as ext-*. This composer.json declares
      // none, so anything else has to be named here. Additive, comma-separated.
      //
      //   zip        webklex/php-imap requires it. Without it `composer install`
      //              aborts and the build never reaches the app at all.
      //   pdo_mysql  the base list ships `pdo` but no driver. Nothing in
      //              composer.lock requires it, so composer never complains --
      //              it surfaces later as "could not find driver" at migrate.
      RAILPACK_PHP_EXTENSIONS: "zip,pdo_mysql",

      // Railpack runs `migrate` AND the seeders on every boot of every replica
      // unless this is set. Seeding production would run TranslationSeeder,
      // which rewrites the hand-authored resources/js/locales/*.json that
      // /i18n/{locale} serves at runtime. Migrations are in preDeploy instead.
      RAILPACK_SKIP_MIGRATIONS: "true",

      // ── Database ──
      DB_CONNECTION: "mysql",
      DB_HOST: db.env.MYSQLHOST,
      DB_PORT: db.env.MYSQLPORT,
      DB_DATABASE: db.env.MYSQLDATABASE,
      DB_USERNAME: db.env.MYSQLUSER,
      DB_PASSWORD: db.env.MYSQLPASSWORD,

      // ── Redis ──
      // Provisioned and wired now, but nothing uses it yet: the drivers below
      // stay on `database`, which needs no PHP extension and so removes a
      // failure mode from the first deploy. Switching them to redis is a
      // one-line change once the worker exists — it also needs
      // `redis` appended to RAILPACK_PHP_EXTENSIONS, because predis is not
      // in composer.json and phpredis is therefore the only client available.
      REDIS_CLIENT: "phpredis",
      REDIS_HOST: cache.env.REDISHOST,
      REDIS_PORT: cache.env.REDISPORT,
      REDIS_PASSWORD: cache.env.REDISPASSWORD,

      QUEUE_CONNECTION: "database",
      CACHE_STORE: "database",
      SESSION_DRIVER: "database",

      // ── Session / transport ──
      // Railway terminates TLS and the app is https-only, so the session cookie
      // has no reason to be offered over plain http.
      SESSION_SECURE_COOKIE: "true",
      SESSION_LIFETIME: "120",
      SESSION_SAME_SITE: "lax",

      // The container filesystem is wiped on every deploy, so a log file on
      // disk is a log nobody will ever read. stderr goes to `railway logs`.
      LOG_CHANNEL: "stderr",
      LOG_LEVEL: "warning",

      // Reverb is not deployed, so real-time broadcasts have nowhere to go.
      // `log` records them instead of failing the request that emits one.
      BROADCAST_CONNECTION: "log",

      // StorageManager falls back to the `public` local disk when no provider
      // is enabled in Admin > Integrations > Storage. That disk is inside the
      // volume mounted above, so uploads survive a deploy either way.
      FILESYSTEM_DISK: "local",
    },
  });

  return project("smartuno", {
    resources: [backend, db, cache, media, privateFiles],
  });
});
