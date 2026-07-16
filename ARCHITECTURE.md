# Smoothware AI Employee — Architecture

A CRM built to be operated jointly by human sales reps and an AI agent. This is
a **living document**: it is updated at the end of every phase so the project
stays legible as it grows.

> **Status:** Phases 0 (+ hardening), 1 (Core CRM), 2 (Knowledge Base + RAG),
> 3 (AI Receptionist — post-call shadow mode), 4 (Company Analysis), and
> **5 (CSV Import) complete.** Phase 6 (AI Sales Representative — outbound) next.

---

## 1. Tech stack & why

| Layer | Choice | Rationale |
|---|---|---|
| Framework | **Laravel 13** (PHP **8.4+** floor; dev + CI also on 8.5) | Mature, relational, batteries-included. |
| Admin/UI | **Filament 5** (TALL: Tailwind, Alpine, Livewire) | A CRM/admin-panel engine. Server-rendered; no separate SPA. Badge theming makes "AI data looks different from human data" straightforward. |
| Database | **PostgreSQL 17** (via Docker Compose) | Fits the relational model; jsonb for KB/analysis JSON; pgvector available but deferred (§11). |
| Auth & RBAC | **spatie/laravel-permission** + **Filament Shield** | Roles + per-resource policies. |
| AI reasoning | **Anthropic Claude API** — Opus 4.8 / Haiku 4.5 | Generation only (no embeddings). Structured outputs for receptionist + analysis. |
| Embeddings | **Voyage AI** (prod) / offline fake (dev/test) | Separate provider because Claude has no embeddings API. Swappable via `EmbeddingClient`. |
| Telephony | **Sonetel** (Dutch number) | Phase 3, **POST-CALL**. Recording API (download after the call) — **no live media streaming** (§11). |
| Background jobs | **Laravel Queue** (database driver → Redis/Horizon later) | Embeddings, transcription, receptionist analysis, company analysis, retention purge, CSV stage/commit. Run `php artisan queue:work`. |
| Tests | **Pest 4** | Risky/stateful logic (state machines, RAG, grounding, disagreement, import dedup, RBAC, PII, UI render). |
| Formatting | **Laravel Pint** | |

Everything lives in **one Laravel application** — Filament serves the UI.

---

## 2. Phase 0 — foundational architecture

Four pillars built **once as infrastructure** and reused by every later phase.

### 2.1 Users, Roles & Permissions
- Roles (spatie): **`super_admin`** ("Admin"), **`sales_manager`**, **`sales_rep`**.
- Shield generates per-resource permissions: **run
  `php artisan shield:generate --all --panel=admin` after adding resources** — it
  creates them and assigns them to `super_admin` (the admin "bypass" is
  permission-assignment, not a gate).
- `User::canAccessPanel()` = active user + at least one role.

### 2.2 Universal event log — the backbone
- `events`, **append-only** (the model throws on update/delete). Columns include
  `entity_type`/`entity_id`, **`company_id`** (timeline anchor, §3), `actor_type`
  (`user`|`ai_agent`|`system`), `action`, `payload`.
- Written only via `EventLogger`; models opt in with **`LogsEvents`**.
- **Reference logging for GDPR:** PII *values* are never written — a model lists
  PII/large fields in `$auditRedacted`; the log records that they changed, not
  their contents. `$hidden` always redacted.

### 2.3 Soft delete everywhere
- **`archived_at`** (not `deleted_at`) + `SoftDeletes`. Archive, never
  hard-delete — except for GDPR erasure (§11).

### 2.4 AI action framework ("AI proposes → human approves → executes")
- `ai_actions` + `AiActionService`. Lifecycle: `draft → approved → (applied)`,
  `draft → rejected`, or `auto_applied`. Every AI record carries
  `confidence_score`, `source_context_version` (§4), `model_id`, `ai_run_id`.
- Used for **externally-consequential** AI actions (receptionist drafts). Internal
  AI data (company analysis) carries the same provenance but doesn't need approval.

---

## 3. Phase 1 — Core CRM

Six entities, each with soft delete, `LogsEvents`, provenance (`HasProvenance`),
and per-model PII redaction: **Company** (hub; tabbed detail page), **Contact**,
**Note**, **Task**, **Appointment**, **Call**.

- **Task** — a real **state machine** (`TaskStatus`), guarded transitions
  (`InvalidTaskTransition`), one `task.status_changed` event each.
- **Appointment** — Google Calendar **link-out**.
- **Call** — metadata + Phase-3 recording/transcript columns.
  `transcript`/`summary` **encrypted at rest**; `CallContentEraser` destroys
  personal content but keeps metadata (GDPR right-to-erasure).

**Timeline anchor:** `events.company_id` → a company's feed is one indexed query.
**Human vs. AI (principle #2):** `RecordSource`/`ActorType` render as coloured
badges (AI = amber); `ai_action_id` links any AI row to its approval record.

---

## 4. Phase 2 — Knowledge Base + RAG

- **`knowledge_entries`** — one flexible table for all six content types + a JSON
  `data` column. Only **`published`** entries feed RAG. `last_verified_at` flags
  staleness.
- **Prompt Rules — versioned** — `prompt_rule_sets` (one active) + `prompt_rules`;
  `PromptRuleSetService.activate()` archives the prior version, audited.
- **RAG pipeline:** `EmbeddingClient` (`Fake` / `Voyage`) → `KnowledgeChunker` →
  **`EmbedKnowledgeEntry`** queued job → `KnowledgeRetriever` (brute-force cosine
  over published chunks, fine for a small KB, §11; top-K + scores).
- **`source_context_version`** — `ContextVersion::current()` →
  `rules:v{N}|kb:{timestamp}`, stamped on every AI record.

> **Note:** embeddings run on the queue — `php artisan queue:work` must run for
> entries to become retrievable after seeding/editing.

---

## 5. Phase 3 — AI Receptionist (post-call shadow mode)

The AI processes a **completed** call and drafts CRM records for one-click human
approval — **nothing is auto-created**. Reuses Phase 0 (`ai_actions`) + Phase 2
(RAG) wholesale.

**Post-call, not live (confirmed against Sonetel's API).** Sonetel has no
real-time media-streaming API; its Recording API downloads recordings *after* the
call. Flow: call handled live by Sonetel IVR/voicemail/a human → recorded → we
pull, transcribe, and draft. **"Live AI answering the call" is out of scope on
Sonetel — §11.**

**Adapters + offline fakes** (no vendor account, no API calls in CI):
`TelephonyProvider` (`Sonetel`, UNVERIFIED shapes / `Fake`), `TranscriptionClient`
(`Fake`), `ReceptionistLlm` (`Claude` / `Fake`).

**Flow (queued):** webhook/import → `Call` → `ProcessInboundCall` →
`ReceptionistPipeline`: retrieve → LLM on chunks-only → **grounding enforcement**
(below-threshold or foreign/uncited citations ⇒ `fallback_to_human`; never
improvises) → `AiRun` (ops only) → one draft `ai_action` (`receptionist_intake`,
PII in its erasable payload). **Review queue** (`AiActionResource`, polling) →
Approve runs `ReceptionistActionApplier` (creates AI-tagged Company via
`CompanyMatcher` dedup / Contact / Note / Task, links the call, atomic).

**GDPR:** consent + retention are config-driven (`config/receptionist.php`, 90-day
placeholder) + daily `PurgeExpiredCallContent`; real values need legal sign-off (§11).

---

## 6. Phase 4 — Company Analysis

Every company has two **physically separate** analyses (principle #2 — AI never
overwrites human input):

- **Manual analysis** (`company_manual_analyses`, 1:1, human-owned): pain points,
  opportunities, notes, priority. **AI code never writes here.** Edited inline in
  the company form.
- **AI analysis** (`company_ai_analyses`, regenerable history): `technical` (from
  the website scan — factual), `marketing` + `recommendations` (LLM, grounded in
  our KB), each finding with a **confidence**; plus an inferred priority and full
  provenance (`source_context_version`, `model_id`, `ai_run_id`). AI analysis is
  internal data — it carries provenance but **doesn't need approval** (principle #4
  is for external-facing actions).

**Adapters + fakes:** `WebsiteAnalyzer` (`Http` w/ PageSpeed / `Fake`),
`CompanyAnalysisLlm` (`Claude` structured outputs / `Fake`). `CompanyAnalyzer`
assembles signals + grounded LLM → a new `company_ai_analyses` row + an `AiRun`
(`kind=analysis`); `GenerateCompanyAnalysis` is the queued job (UI action).

**Disagreement flags (product requirement):** `DisagreementDetector` compares the
AI's inferred priority against the rep's manual priority and surfaces a
**"⚠ Disagreement"** badge on the AI-analysis panel — the point where the rep's
judgment visibly overrides the AI. Never shows both silently side-by-side.

---

## 7. Phase 5 — CSV Import

Bulk-load companies (and their primary contact) from a CSV — the fast path to
seeding the CRM — under the same **human-approval gate** as every other write:
nothing is created until a rep reviews the preview and commits.

- **Two-step, never blind.** Upload → **stage** (`CsvImporter`) parses, auto-maps
  headers to fields (or uses a stored mapping), validates, and **dedups against
  existing companies using the SAME `CompanyMatcher` as the Phase 3 receptionist**,
  writing one `import_rows` row per line with a **disposition** (`Create` / `Match`
  / `Skip` / `Invalid`) and rolled-up counts. Status → `Previewed`. **Nothing is
  created yet.**
- **Preview → commit.** The rep sees every row + its disposition + errors
  (read-only relation manager) and clicks **Commit** (`ImportCommitter` — atomic,
  and **idempotent**: guards on `status = Previewed`). `Create`/`Match` rows create
  a Company (`source = import`, applying the import's default owner/status/campaign/
  industry) or link the matched one; a Contact is created when names are present;
  **each NEW company queues `GenerateCompanyAnalysis`** (Phase 4). `Skip`/`Invalid`
  rows are never written.
- **Campaigns** (`campaigns`) — an optional grouping stamped on imported companies
  (`companies.campaign_id`) so a batch stays attributable.

Provenance holds throughout: imported companies are `RecordSource::Import`, visibly
distinct from `manual` / `ai` / `system`. Stage/commit are queued jobs
(`StageImport` / `CommitImport`); the Filament actions dispatch them synchronously
for immediate feedback in the panel.

---

## 8. Cross-cutting conventions (every new table/model)

- `id`, `created_at`, `updated_at`, `archived_at` unless append-only.
- `created_by` / `owner_id` on user-authored data.
- Emit an event on create/update/archive (`LogsEvents`); list PII/large fields in
  `$auditRedacted`.
- AI-generated records carry the auditability trio; externally-consequential ones
  flow through `AiActionService` (approval). Never write AI data over human data.
- **Everything external is behind an interface with an offline fake**
  (`EmbeddingClient`, `TelephonyProvider`, `TranscriptionClient`, `ReceptionistLlm`,
  `WebsiteAnalyzer`, `CompanyAnalysisLlm`) — build + test with no vendor account.

## 9. Key paths

```
app/
  Concerns/{LogsEvents,HasProvenance}.php
  Contracts/                # EmbeddingClient, TelephonyProvider, TranscriptionClient,
                            #   ReceptionistLlm, WebsiteAnalyzer, CompanyAnalysisLlm
  Enums/                    # + CallIntent, AnalysisPriority, ImportStatus, ImportRowDisposition
  Http/Controllers/InboundCallWebhookController.php
  Jobs/                     # EmbedKnowledgeEntry, ProcessInboundCall, PurgeExpiredCallContent,
                            #   GenerateCompanyAnalysis, StageImport, CommitImport
  Models/                   # + KnowledgeEntry/Chunk, PromptRuleSet/Rule, AiRun,
                            #   CompanyManualAnalysis, CompanyAiAnalysis, Campaign, Import, ImportRow
  Services/                 # EventLogger, AiActionService, CallContentEraser, Knowledge*, ContextVersion,
                            #   Embeddings/*, Telephony/*, Receptionist/*, Analysis/{CompanyAnalyzer,
                            #   DisagreementDetector, Fake/Http WebsiteAnalyzer, Fake/Claude AnalysisLlm},
                            #   Import/{CsvImporter, ImportCommitter}
  Filament/Resources/       # CRM [nav] + AiAction(review)/AiRun [AI Receptionist nav] +
                            #   Import/Campaign [Import nav]; Company form has an inline
                            #   Manual-analysis section + an AI-analysis RM; Import has a read-only preview RM
config/{receptionist,analysis}.php   # grounding, retention (placeholder), driver switches
database/{migrations,seeders,factories}/
tests/Feature/              # + Receptionist*, InboundCallWebhook, CallRetentionPurge,
                           #   CompanyAnalysis, DisagreementDetector, CsvImport, PanelSmoke, ...
docker-compose.yml          # PostgreSQL 17 on host port 5434
```

## 10. Testing
- Pest, `php artisan test` (**85 tests**). In-memory SQLite for speed (queue =
  sync, so jobs run inline); app targets PostgreSQL; CI runs a Postgres migrate
  smoke.
- Covered: audit log + append-only, PII redaction, AI-action & Task state
  machines, call erasure + retention purge, timeline anchoring, RBAC, RAG ranking,
  ruleset activation, receptionist grounding + fallback + citation validation,
  inbound webhook, approve/reject (Livewire), company analysis (provenance,
  AI-never-touches-manual, regenerate history) + disagreement detection,
  **CSV import (auto-map + dispositions, commit defaults/dedup/contacts/queued
  analysis, idempotency)**, and a UI render smoke across every resource page.
- **CI:** `.github/workflows/ci.yml` — Pint + Pest + Postgres migrate.

---

## 11. Open decisions & compliance flags

> **All legal/compliance items live in [`GO-LIVE-LEGAL.md`](GO-LIVE-LEGAL.md)** —
> one checklist, not scattered across phase notes. The flags below are summaries;
> that file is the source of truth.

- **Jurisdiction = NL / EU (GDPR).**
  - **Right to erasure** — *done:* event log never stores PII; `CallContentEraser`
    + `PurgeExpiredCallContent` destroy call content, keep metadata. *Remaining:*
    subject-level erasure spanning a contact + all their calls (a small service).
  - **Call recording consent + retention** (Phase 3): mechanism built + config-
    driven (90-day placeholder + daily purge). **Retention period + disclosure
    wording need legal sign-off before go-live** — not defaulted.
  - **Outbound** (Phase 6): Dutch/EU telemarketing rules → compliance gate first.
  - **Imported data provenance** (Phase 5): imports carry `source = import` and a
    campaign, but the *lawful basis* for cold-loaded B2B contact data (legitimate
    interest vs. consent) is a go-live legal check, not a code default.
- **Sonetel is post-call only (confirmed).** No real-time media-streaming API. Still
  need hands-on access to verify recording/callback payload shapes
  (`SonetelProvider` marked UNVERIFIED) and whether a call-completed callback exists
  (else a scheduled recording-poll job).
- **Live AI *answering* the call — separate future decision.** Not buildable on
  Sonetel. Options: confirm an undocumented Sonetel real-time tier; a SIP media
  server (FreeSWITCH/Asterisk/Pipecat) behind Sonetel forwarding; or a second vendor
  (Twilio/Telnyx) for that leg. Deferred until there's a real answer.
- **Database engine (decided):** MySQL → PostgreSQL while the cost was near-zero.
  Banks jsonb indexing (Phase 4/8); keeps pgvector a cheap future option.
- **RAG storage, pgvector deferred:** embeddings in `jsonb`, brute-force cosine in
  PHP; adopt pgvector as a localized swap of `KnowledgeRetriever` + the embedding
  column when volume/latency justifies it (needs a Postgres-based retrieval test).
- **Fake providers are simplified** (lexical embeddings; heuristic website signals)
  — real semantic/scan quality arrives with Voyage / the PageSpeed + Claude wiring.
- **Transcript search vs. encryption** (Phase 3): encryption blocks SQL search;
  transcript search will use the object store / a dedicated index.

---

## 12. Roadmap status

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation + hardening (RBAC, event log, soft delete, AI action framework, GDPR-safe audit, CI) | ✅ Done |
| 1 | Core CRM (Companies, Contacts, Notes, Tasks, Appointments, Calls) | ✅ Done |
| 2 | Knowledge Base + RAG pipeline | ✅ Done |
| 3 | AI Receptionist (post-call shadow mode; approve → apply) | ✅ Done |
| 4 | Company Analysis (manual vs. AI, disagreement flags) | ✅ Done |
| 5 | CSV Import (map → preview/dedup → create → queue analysis) | ✅ Done |
| 6 | AI Sales Representative (outbound) | ⬜ Next |
| 7 | Follow-up Automation | ⬜ |
| 8 | Reporting (business + AI ops) | ⬜ |
