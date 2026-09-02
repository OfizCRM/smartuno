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

## Notes

- `.env` is not committed. Use `.env.example` as the template.
- Frontend source lives in `resources/js/` (React/JSX). Do not edit `public/build/`.
- This repository is private. The product license is proprietary.
