# Smoothware AI Employee — Architecture

A CRM built to be operated jointly by human sales reps and an AI agent. This is
a **living document**: it is updated at the end of every phase so the project
stays legible as it grows.

> **Status:** Phase 0 + hardening, Phase 1 (Core CRM), and **Phase 2 (Knowledge
> Base + RAG) complete.** Phase 3 (AI Receptionist) not started.

---

## 1. Tech stack & why

| Layer | Choice | Rationale |
|---|---|---|
| Framework | **Laravel 13** (PHP 8.5) | Mature, relational, batteries-included. |
| Admin/UI | **Filament 5** (TALL: Tailwind, Alpine, Livewire) | A CRM/admin-panel engine. Server-rendered; no separate SPA. Badge theming makes "AI data looks different from human data" straightforward. |
| Database | **MySQL 8** (via Docker Compose) | Fits the relational model. No native vector index — see §8. |
| Auth & RBAC | **spatie/laravel-permission** + **Filament Shield** | Roles + per-resource policies. |
| AI reasoning | **Anthropic Claude API** — Opus 4.8 / Haiku 4.5 | Used from Phase 3. Generation only (no embeddings). |
| Embeddings | **Voyage AI** (prod) / offline fake (dev/test) | Separate provider because Claude has no embeddings API. Swappable via `EmbeddingClient`. |
| Telephony | **Sonetel** (Dutch number) | Phase 3. REST API + webhooks; recordings pulled into our storage (§8). |
| Background jobs | **Laravel Queue** (database driver → Redis/Horizon later) | Embeddings, CSV import, analysis, transcription, follow-ups. Run `php artisan queue:work`. |
| Tests | **Pest 4** | Risky/stateful logic (state machines, RAG, RBAC, PII, UI render). |
| Formatting | **Laravel Pint** | |

Everything lives in **one Laravel application** — Filament serves the UI.

---

## 2. Phase 0 — foundational architecture

Four pillars built **once as infrastructure** and reused by every later phase.

### 2.1 Users, Roles & Permissions
- Roles (spatie): **`super_admin`** ("Admin"), **`sales_manager`**, **`sales_rep`**.
- Shield generates per-resource permissions: **run
  `php artisan shield:generate --all --panel=admin` after adding resources** — it
  creates the permissions and assigns them to `super_admin` (that is the admin
  "bypass": permission-assignment, not a gate).
- `User::canAccessPanel()` = active user + at least one role.

### 2.2 Universal event log — the backbone
- `events`, **append-only** (the model throws on update/delete). Columns include
  `entity_type`/`entity_id`, **`company_id`** (timeline anchor, §3), `actor_type`
  (`user`|`ai_agent`|`system`), `action`, `payload`.
- Written only via `EventLogger`; models opt in with the **`LogsEvents`** trait.
- **Reference logging for GDPR:** PII *values* are never written — a model lists
  PII fields in `$auditRedacted`; the log records that they changed, not their
  contents. `$hidden` always redacted.

### 2.3 Soft delete everywhere
- **`archived_at`** (not `deleted_at`) + `SoftDeletes`. Archive, never
  hard-delete — except for GDPR erasure (§8).

### 2.4 AI action framework ("AI proposes → human approves → executes")
- `ai_actions` + `AiActionService`. Lifecycle: `draft → approved → (applied)`,
  `draft → rejected`, or `auto_applied`. Every AI record carries
  `confidence_score`, `source_context_version` (§4), `model_id`, `ai_run_id`.
- Illegal transitions throw. Review queue (Phase 3) = Filament Resource with
  polling; only a live in-call transcript view needs Reverb.

---

## 3. Phase 1 — Core CRM

Six entities, each with soft delete, `LogsEvents`, provenance (`HasProvenance`:
`source` + `ai_action_id`), and per-model PII redaction: **Company** (hub;
tabbed detail page with relation managers + a read-only **Timeline**),
**Contact**, **Note**, **Task**, **Appointment**, **Call**.

- **Task** — a real **state machine** (`TaskStatus`: open · in_progress ·
  blocked · completed · cancelled, reopenable). Guarded transitions
  (`InvalidTaskTransition`), one `task.status_changed` event each, drives the UI
  buttons. Phase 7 automation depends on it.
- **Appointment** — Google Calendar **link-out** (`googleCalendarUrl()`).
- **Call** — metadata now; Phase-3 recording/transcript columns dormant.
  `transcript`/`summary` **encrypted at rest**; `CallContentEraser` destroys
  personal content but keeps metadata (GDPR right-to-erasure).

**Timeline anchor:** `events.company_id` set at write time → a company's feed is
one indexed query (`Event::forCompanyTimeline`). **Human vs. AI (principle #2):**
`RecordSource` and `ActorType` implement Filament `HasColor`/`HasLabel` → AI rows
badge amber; `ai_action_id` links any AI row to its approval record.

All resource tables + company-page relation managers share tidy, consistent
columns. Appointments "calendar" is a date-sorted list in v1 (full calendar =
plugin add). Task block/cancel reason is one-click.

---

## 4. Phase 2 — Knowledge Base + RAG

The grounding layer for every AI feature. Delivers content + a tested retrieval
pipeline (it does **not** call Claude — that's Phase 3).

- **`knowledge_entries`** — one flexible table for all six content types
  (`KnowledgeType`), with a JSON `data` column for type-specific structure
  (pricing factors/ranges, portfolio meta). Only **`published`** entries feed
  RAG. `last_verified_at` flags staleness (surfaces in Phase 8 AI-ops). Editor
  history is the entry's event timeline.
- **Prompt Rules — versioned** — `prompt_rule_sets` (v1, v2… exactly one active)
  + `prompt_rules`. `PromptRuleSetService.activate()` archives the prior version
  in one audited transaction. Editing published rules = publish a new version.
- **RAG pipeline:**
  - `EmbeddingClient` interface → **`FakeEmbeddingClient`** (deterministic
    bag-of-words, offline; dev/test/CI) or **`VoyageEmbeddingClient`** (prod).
    Bound in `AppServiceProvider` from `services.embeddings.driver`.
  - `KnowledgeChunker` (overlapping chunks) → **`EmbedKnowledgeEntry`** queued
    job (auto re-embed on content/status change; clears chunks when unpublished).
  - `KnowledgeRetriever` — embeds the query, **brute-force cosine** over
    published chunks in PHP (fine for a small KB, §8), returns top-K + scores.
    The score is the Phase-3 grounding signal (below threshold → defer to human).
- **`source_context_version`** — `ContextVersion::current()` returns
  `rules:v{N}|kb:{timestamp}`, the stamp every AI action records so it's
  traceable to the ruleset + KB state that produced it. Closes the Phase-0 loop.

> **Note:** embeddings run on the queue, so `php artisan queue:work` must be
> running (or run once with `--stop-when-empty`) for entries to become
> retrievable after seeding/editing.

---

## 5. Cross-cutting conventions (every new table/model)

- `id`, `created_at`, `updated_at`, `archived_at` unless append-only.
- `created_by` / `owner_id` on user-authored data.
- Emit an event on create/update/archive (`LogsEvents`); list PII/large fields
  in `$auditRedacted`.
- AI-generated records flow through `AiActionService` and carry the auditability
  trio — never write AI data directly.

## 6. Key paths

```
app/
  Concerns/{LogsEvents,HasProvenance}.php
  Contracts/EmbeddingClient.php
  Enums/                    # actor/ai-action/company/task/note/call/appointment/record + knowledge/publish/rule-set
  Exceptions/{InvalidAiActionTransition,InvalidTaskTransition}.php
  Jobs/EmbedKnowledgeEntry.php
  Models/                   # Event, AiAction, User, Company, Contact, Note, Task, Appointment, Call,
                            #   KnowledgeEntry, KnowledgeChunk, PromptRuleSet, PromptRule
  Services/                 # EventLogger, AiActionService, CallContentEraser, KnowledgeChunker,
                            #   KnowledgeRetriever, PromptRuleSetService, ContextVersion, Embeddings/*
  Filament/Resources/       # Company(+RMs+Timeline), Contact, Note, Task, Appointment, Call,
                            #   KnowledgeEntry, PromptRuleSet(+Rules RM)   [nav group: Knowledge Base]
database/{migrations,seeders,factories}/   # seeders: Role, AdminUser, Demo, Knowledge
tests/Feature/             # + TaskStateMachine, CallContentErasure, CompanyTimeline, Phase1Pii,
                           #   KnowledgeChunker, RagRetrieval, PromptRuleSetActivation, PanelSmoke
docker-compose.yml         # MySQL 8 on host port 3308
```

## 7. Testing
- Pest, `php artisan test` (60 tests). In-memory SQLite for speed (queue = sync,
  so embed jobs run inline in tests); app targets MySQL; CI runs a MySQL migrate
  smoke.
- Covered: audit log + append-only, PII redaction, AI-action & Task state
  machines, call erasure, timeline anchoring, RBAC, chunking, RAG ranking +
  published-only, ruleset activation, context version, and a UI render smoke
  across every resource page.
- **CI:** `.github/workflows/ci.yml` — Pint + Pest + MySQL migrate.

---

## 8. Open decisions & compliance flags

- **Jurisdiction = NL / EU (GDPR).**
  - **Right to erasure** — *done:* event log never stores PII; `CallContentEraser`
    destroys call content, keeps metadata. *Remaining:* subject-level erasure
    spanning a contact + their calls (Phase 3).
  - **Call recording** (Phase 3): consent/disclosure + a **retention period** —
    legal decisions to set before go-live, not defaulted.
  - **Outbound** (Phase 6): Dutch/EU telemarketing rules → compliance gate first.
  - **Sonetel**: need API/webhook docs before Phase 3; pull recordings into our
    own object storage (MinIO/S3) so we control retention/erasure.
- **RAG on MySQL:** no native vector index; small KB → brute-force cosine in-app
  is sufficient. The **fake embeddings are lexical** (bag-of-words) — semantic
  quality arrives with Voyage. Revisit vector storage only at scale.
- **Embeddings provider**: build-swappable now; wire Voyage (`EMBEDDINGS_DRIVER=voyage`
  + `VOYAGE_API_KEY`) when ready.
- **Transcript search vs. encryption** (Phase 3): encryption blocks SQL search;
  transcript search will use the object store / a dedicated index.

---

## 9. Roadmap status

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation + hardening (RBAC, event log, soft delete, AI action framework, GDPR-safe audit, CI) | ✅ Done |
| 1 | Core CRM (Companies, Contacts, Notes, Tasks, Appointments, Calls) | ✅ Done |
| 2 | Knowledge Base + RAG pipeline | ✅ Done |
| 3 | AI Receptionist (shadow mode → autonomous) | ⬜ Next |
| 4 | Company Analysis (manual vs. AI, disagreement flags) | ⬜ |
| 5 | CSV Import | ⬜ |
| 6 | AI Sales Representative (outbound) | ⬜ |
| 7 | Follow-up Automation | ⬜ |
| 8 | Reporting (business + AI ops) | ⬜ |
