# SmartUno

Multi-channel customer messaging SaaS. Laravel 12 + React 19 / Inertia 2 + MySQL, forked from
a purchased product called **WhatsMine**. Being turned into a commercial product sold to small
Romanian companies.

**Read [`docs/product-vision.md`](docs/product-vision.md) before making any product decision.**
Short version: our customer is a Romanian firm with under 30 employees — an online shop, a
dental clinic, an estate agency, a restaurant, a plumbing or electrical firm. They want
something that helps them, *not a big heavy CRM*. Simplicity is a requirement, not a
preference.

**Talk to the user in Romanian.** Code, comments, commit messages, and these docs are English.

---

## The four rules

### 1. Reuse before you write

[`docs/reuse-catalogue.md`](docs/reuse-catalogue.md) lists every service, trait, helper,
middleware and React component worth reusing, verified against the working tree. Read it
first. If you write a new class or component, be able to name the catalogue entry you rejected
and why it did not fit. There is already a `Modal`, a toast system, a `Pagination`, an
`EmptyState`, a `MediaUpload`, a `DatePicker`, a `ContactService`, a `ChannelManager`. Do not
add second versions of them.

### 2. Agree on the plan before writing code

Before any code change beyond a typo or a copy fix, put this to the user **in Romanian** and
wait for confirmation:

- **Ce am înțeles** — the requirement restated, including what you are *not* doing.
- **Ce refolosesc** — the existing files, services and components you will build on.
- **Ce creez nou și de ce** — each new file, with the reason the existing thing did not fit.
- **Ce ating în DB / securitate / facturare** — migrations, tenancy, auth, payments, external calls.
- **Cum verific** — the tests and commands that will prove it works.

Do not start writing until they say yes.

### 3. Tenant isolation is manual, so it is on you

There is **no global scope, no tenant trait, no ORM safety net**. One forgotten `where` clause
is a cross-tenant data leak. The rules:

- Every query on a tenant-owned table filters by `workspace_id`. Every single one.
- Resolve the tenant with `WorkspaceScopedController::workspaceId($request)` in API controllers.
  In web controllers the existing idiom is
  `$request->user()->current_workspace_id ?? $request->user()->workspace_id` — **`current_workspace_id`
  does not exist as a column**, so it always falls through. Do not copy it into new code; use
  `$request->user()->workspace_id`.
- After `findOrFail` on a UUID-bound model, assert ownership:
  `abort_unless((int) $model->workspace_id === $wid, 403)`.
- New tenant-owned table → `workspace_id` column **plus an index**, and it must appear in
  `tests/Feature/MarketingSuite/MultiTenantScopingTest.php`.
- A cross-workspace id must return 403 or 404, never data. Prove it with a test.

### 4. Verify your own work, then say what you actually ran

```bash
./vendor/bin/pint --test          # composer lint      — PHP formatting
./vendor/bin/phpstan analyse      # composer analyse   — level 6, 1218 baselined errors
php artisan test                  # NOT `composer test` — that one is broken (no paratest)
npm run lint                      # eslint resources/js
npm run test                      # vitest
npm run build                     # verify the frontend compiles
```

Never add to `phpstan-baseline.neon`. If your change produces a PHPStan error, fix the code.

Report results honestly. If a test fails, say so and show the output. If you skipped a step,
say which. "Done" means the commands above pass.

---

## Where things go

Full detail in [`docs/architecture.md`](docs/architecture.md). The short form:

- **Domain code lives in `app/Modules/{Name}/`** — AI, Automation, Broadcasting, Ecommerce,
  Inbox, Integrations, Leads, Shared, Social, Whatsapp. Each module owns its models,
  migrations, routes, services and jobs, and loads them from its own service provider.
  **Module routes are not registered in `bootstrap/app.php`.**
- `app/Models/` is platform only — tenancy, billing, admin RBAC, settings.
- `app/Services/` is cross-cutting only. Module logic goes in the module.
- React pages are `resources/js/Pages/{Module}/*.jsx`, rendered with `Inertia::render()`.
  Import a layout and wrap your JSX — no page uses `Page.layout`.
- UI primitives come from `@/Components/ui` (15 of them, barrel-exported).
- Credentials come from `CredentialResolver`, never `config()` or `env()` in a driver.
- Schedules go in `routes/console.php`. There is no `app/Console/Kernel.php`.
- Broadcast channels are registered in `BroadcastChannelsServiceProvider`, not `routes/channels.php`.

## Non-negotiables

- **Never weaken a security control to make something work.** If a control blocks you, say so
  and stop.
- **Validate every URL fetched from user input** with the SSRF guard in
  `app/Modules/Ecommerce/Services/StoreUrlGuard.php` (currently the only one, and it is called
  from only two places — outbound webhooks, automation webhook nodes and AI URL ingestion are
  all unguarded today).
- **Never `dangerouslySetInnerHTML`.** Use `MarkdownLite` for model- or user-generated text.
- **Never broadcast a whole model.** Hand-write `broadcastWith()` with scalars.
- **Never put a secret in a Laravel prop, a log line, or an error message.** `HandleInertiaRequests`
  currently shares the raw `User` model — see C1 in the debt register.
- **Every user-facing string goes through `t('namespace.key')`**, added to **both**
  `resources/js/locales/en.json` and `ro.json`. Never run `php artisan i18n:scan --sync` or
  `TranslationSeeder` — both rewrite those hand-authored files.
- **Never commit `.env`.** Never edit `public/build/`.
- **Never push to `main`.** Feature branch → PR into `staging` → PR `staging` → `main` (see README).

## The agents

| Agent | When |
|---|---|
| `smartuno-dev` | Any feature, fix, refactor or investigation. It agrees a written plan with you in Romanian before it writes code. |
| `smartuno-security` | After any change touching auth, tenancy, permissions, webhooks, uploads, outbound HTTP, payments, or user input reaching a query or a URL. Read-only. |
| `smartuno-review` | After any non-trivial change — reuse, conventions, scale, tests, Romanian, product fit. Read-only. |

`smartuno-dev` cannot invoke the other two itself. When it reports that a change is ready, run
the reviewers from the main session before merging.

## Read these before touching the codebase

| Document | What it is for |
|---|---|
| [`docs/traps.md`](docs/traps.md) | Things that are broken or misleadingly named. **Read this first.** `EnsureClientScope` enforces nothing, `UsageMeter` does not accumulate, the WhatsApp 24h window never closes, `composer test` fails. |
| [`docs/reuse-catalogue.md`](docs/reuse-catalogue.md) | What already exists. Read before writing anything new. |
| [`docs/architecture.md`](docs/architecture.md) | Request lifecycles, directory map, where new code goes. |
| [`docs/debt-register.md`](docs/debt-register.md) | 8 critical, 18 high, 24 medium inherited defects, with file evidence. |
| [`docs/product-vision.md`](docs/product-vision.md) | Who we sell to, and what that rules in and out. |

## Assume nothing works until you have read it

This is a purchased codebase written to be sold as a script, not operated as a SaaS. Things
that look implemented are frequently inert: plan limits do not enforce, the license system is a
pass-through, three automation triggers are dead, `/admin/search` throws a 500. Before you build
on top of an existing feature, open it and confirm it does what its name says.
