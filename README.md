# Smoothware AI Employee

A CRM built to be operated **jointly by human sales reps and an AI agent**. The
AI answers inbound calls, qualifies leads, analyses prospect companies, and
(later) makes outbound calls — always grounded in a company knowledge base, and
always with humans in control of judgment calls.

Built for **Smoothware**, a web/software agency (websites, apps, SEO, hosting,
maintenance).

> **Current status: Phase 0 (Foundation) complete.** See
> [`ARCHITECTURE.md`](ARCHITECTURE.md) for the design and roadmap.

## Stack

Laravel 13 · Filament 5 · MySQL 8 · PHP 8.5 · Pest 4 · spatie/laravel-permission
+ Filament Shield. Anthropic Claude (from Phase 3). Everything runs in one
Laravel app.

## Prerequisites

- PHP **8.3+** (developed on 8.5), Composer 2
- Node 20 + npm (for building panel assets)
- Docker + Docker Compose (for MySQL)

## Local setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate           # only if APP_KEY is empty

# 3. Start MySQL (host port 3308 — see docker-compose.yml)
docker compose up -d

# 4. Create the schema and seed roles + an admin user
php artisan migrate --seed

# 5. Build front-end assets and serve
npm run build                      # or `npm run dev` for hot reload
php artisan serve
```

Then open **http://localhost:8000/admin** and log in:

| Field | Value |
|---|---|
| Email | `admin@smoothware.test` |
| Password | `password` |

> ⚠️ These are **local-only** development credentials. Change them before any
> real deployment.

## Database

MySQL 8 runs in Docker (`docker-compose.yml`), mapped to **host port 3308** to
avoid clashing with other local MySQL containers. Connection settings live in
`.env` (`DB_*`). Data persists in the `smoothware-mysql-data` volume.

```bash
docker compose up -d      # start
docker compose down       # stop (keeps data)
docker compose down -v    # stop and wipe the database volume
```

## Tests

```bash
php artisan test
```

Tests run on an **in-memory SQLite** database for speed and isolation (the app
itself targets MySQL). The foundation suite covers the append-only event log,
the AI action approval flow, and RBAC panel access.

## Code style

```bash
php vendor/bin/pint        # format (Laravel preset)
```

## Project conventions

See [`ARCHITECTURE.md`](ARCHITECTURE.md). In short:

- **Nothing is hard-deleted** (except GDPR erasure) — models soft-delete into
  `archived_at`.
- **Every mutation is audited** to the append-only `events` table via the
  `LogsEvents` trait / `EventLogger` service.
- **AI never writes directly.** AI-originated records go through
  `AiActionService` (propose → human approve/reject → apply) and carry a
  confidence score, the context version used, and the model id.

## Compliance note

This product records and (later) places phone calls on a **Dutch number → EU /
GDPR** rules apply. Call recording, retention, and outbound-calling features
require explicit product/legal decisions before implementation — these are
flagged in `ARCHITECTURE.md §6`.
