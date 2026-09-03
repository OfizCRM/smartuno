# SmartUno

Multi-channel customer messaging SaaS (Laravel 12 + React/Inertia). Originally based on WhatsMine.

## Requirements

- PHP 8.2+ (pdo_mysql, mbstring, openssl, tokenizer, ctype, json, bcmath, fileinfo, curl)
- Composer
- Node.js + npm
- MySQL

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create a MySQL database and set `DB_*` in `.env`. Then start the stack:

```bash
composer dev
```

This runs `php artisan serve`, a queue listener, logs, and Vite.

Open `http://localhost:8000/install` and complete the wizard (database, license, admin). Until `APP_INSTALLED=true`, all traffic redirects to `/install`.

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

- `.env` is not committed. Use `.env.example` as the template. Staging and production each have their own `.env`.
- Frontend source lives in `resources/js/` (React/JSX). Do not edit `public/build/`.
- This repository is private. The product license is proprietary.
