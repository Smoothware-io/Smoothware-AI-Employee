# Smoothware AI Employee

A CRM built to be operated **jointly by human sales reps and an AI agent**. The
AI answers inbound calls, qualifies leads, analyses prospect companies, and
(later) makes outbound calls — always grounded in a company knowledge base, and
always with humans in control of judgment calls.

Built for **Smoothware**, a web/software agency (websites, apps, SEO, hosting,
maintenance).

> **Current status: Phase 2 (Knowledge Base + RAG) complete.** See
> [`ARCHITECTURE.md`](ARCHITECTURE.md) for the design and roadmap.

## Stack

Laravel 13 · Filament 5 · PostgreSQL 17 · PHP 8.5 · Pest 4 · spatie/laravel-permission
+ Filament Shield · Anthropic Claude (Phase 3) · Voyage AI embeddings (RAG).
One Laravel app.

## Prerequisites

- PHP **8.3+** (developed on 8.5), Composer 2
- Node 20 + npm (panel assets)
- Docker + Docker Compose (PostgreSQL)

## Local setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate            # if APP_KEY is empty

docker compose up -d                # PostgreSQL on host port 5434

php artisan migrate --seed          # schema + roles + admin user
php artisan db:seed --class=DemoSeeder        # optional: sample CRM data
php artisan db:seed --class=KnowledgeSeeder   # optional: starter KB + prompt rules

npm run build                       # or `npm run dev`
php artisan queue:work              # REQUIRED for RAG embeddings (see below)
php artisan serve
```

Open **http://localhost:8000/admin** — `admin@smoothware.test` / `password`.

> ⚠️ Local-only development credentials. Change them before any deployment.

## Background jobs (RAG embeddings)

Knowledge-base entries are chunked and embedded by a **queued job** when they're
published or edited. Run a worker so entries become retrievable:

```bash
php artisan queue:work                 # long-running worker
# or, one-off after seeding/editing:
php artisan queue:work --stop-when-empty
```

Embeddings use an offline **fake** provider by default (no API key). For
production semantic quality, set `EMBEDDINGS_DRIVER=voyage` and `VOYAGE_API_KEY`
in `.env` (Anthropic's API has no embeddings endpoint, hence a separate provider).

## Database

PostgreSQL 17 in Docker (`docker-compose.yml`), host port **5434** (avoids other local
PostgreSQL containers). `docker compose down -v` wipes the data volume.

## Tests

```bash
php artisan test                    # 60 tests
```

Run on in-memory SQLite for speed and isolation; the app targets PostgreSQL. Covers
the audit log, AI + task state machines, call erasure, RAG retrieval, prompt-rule
versioning, RBAC, and a UI render smoke test.

## Code style

```bash
php vendor/bin/pint
```

## Project conventions

See [`ARCHITECTURE.md`](ARCHITECTURE.md). In short:

- **Nothing is hard-deleted** (except GDPR erasure) — soft delete into `archived_at`.
- **Every mutation is audited** to the append-only `events` table; PII values are
  never written there.
- **AI never writes directly** — AI records go through `AiActionService`
  (propose → approve/reject → apply) with confidence, context version, and model id.

## Compliance note

Recording/placing calls on a **Dutch number → EU/GDPR** rules apply. Call
recording, retention, and outbound calling require explicit product/legal
decisions before implementation — flagged in `ARCHITECTURE.md §8`.
