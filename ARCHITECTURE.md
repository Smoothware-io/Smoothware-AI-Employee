# Smoothware AI Employee — Architecture

A CRM built to be operated jointly by human sales reps and an AI agent. This is
a **living document**: it is updated at the end of every phase so the project
stays legible as it grows.

> **Status:** Phase 0 + hardening, Phase 1 (Core CRM), Phase 2 (Knowledge Base +
> RAG), and **Phase 3 (AI Receptionist — post-call shadow mode) complete.**
> Phase 4 (Company Analysis) next.

---

## 1. Tech stack & why

| Layer | Choice | Rationale |
|---|---|---|
| Framework | **Laravel 13** (PHP 8.5) | Mature, relational, batteries-included. |
| Admin/UI | **Filament 5** (TALL: Tailwind, Alpine, Livewire) | A CRM/admin-panel engine. Server-rendered; no separate SPA. Badge theming makes "AI data looks different from human data" straightforward. |
| Database | **PostgreSQL 17** (via Docker Compose) | Fits the relational model; jsonb for KB/analysis JSON; pgvector available but deferred (see §9). |
| Auth & RBAC | **spatie/laravel-permission** + **Filament Shield** | Roles + per-resource policies. |
| AI reasoning | **Anthropic Claude API** — Opus 4.8 / Haiku 4.5 | Generation only (no embeddings). Structured outputs for the receptionist. |
| Embeddings | **Voyage AI** (prod) / offline fake (dev/test) | Separate provider because Claude has no embeddings API. Swappable via `EmbeddingClient`. |
| Telephony | **Sonetel** (Dutch number) | Phase 3, **POST-CALL**. Recording API (download after the call) — **no live media streaming** (§9). |
| Background jobs | **Laravel Queue** (database driver → Redis/Horizon later) | Embeddings, transcription, receptionist analysis, retention purge, follow-ups. Run `php artisan queue:work`. |
| Tests | **Pest 4** | Risky/stateful logic (state machines, RAG, grounding, RBAC, PII, UI render). |
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
  hard-delete — except for GDPR erasure (§9).

### 2.4 AI action framework ("AI proposes → human approves → executes")
- `ai_actions` + `AiActionService`. Lifecycle: `draft → approved → (applied)`,
  `draft → rejected`, or `auto_applied`. Every AI record carries
  `confidence_score`, `source_context_version` (§4), `model_id`, `ai_run_id`.
- Illegal transitions throw. The Phase-3 review queue is a Filament Resource with
  table polling — near-real-time with no custom Livewire.

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
- **Call** — metadata + Phase-3 recording/transcript columns.
  `transcript`/`summary` **encrypted at rest**; `CallContentEraser` destroys
  personal content but keeps metadata (GDPR right-to-erasure).

**Timeline anchor:** `events.company_id` set at write time → a company's feed is
one indexed query (`Event::forCompanyTimeline`). **Human vs. AI (principle #2):**
`RecordSource` and `ActorType` implement Filament `HasColor`/`HasLabel` → AI rows
badge amber; `ai_action_id` links any AI row to its approval record.

---

## 4. Phase 2 — Knowledge Base + RAG

The grounding layer for every AI feature.

- **`knowledge_entries`** — one flexible table for all six content types
  (`KnowledgeType`) + a JSON `data` column. Only **`published`** entries feed
  RAG. `last_verified_at` flags staleness (Phase 8 AI-ops).
- **Prompt Rules — versioned** — `prompt_rule_sets` (exactly one active) +
  `prompt_rules`. `PromptRuleSetService.activate()` archives the prior version in
  one audited transaction.
- **RAG pipeline:** `EmbeddingClient` (`FakeEmbeddingClient` offline /
  `VoyageEmbeddingClient` prod) → `KnowledgeChunker` → **`EmbedKnowledgeEntry`**
  queued job → `KnowledgeRetriever` (**brute-force cosine** over published
  chunks, fine for a small KB, §9; returns top-K + scores).
- **`source_context_version`** — `ContextVersion::current()` returns
  `rules:v{N}|kb:{timestamp}`, the stamp every AI action records so it's
  traceable to the ruleset + KB state that produced it.

> **Note:** embeddings run on the queue, so `php artisan queue:work` must be
> running for entries to become retrievable after seeding/editing.

---

## 5. Phase 3 — AI Receptionist (post-call shadow mode)

The AI processes a **completed** call and drafts CRM records for one-click human
approval — **nothing is auto-created**. It reuses Phase 0 (`ai_actions`) and
Phase 2 (RAG + `source_context_version`) wholesale; Phase 3 is orchestration.

**Post-call, not live (confirmed against Sonetel's API, 2026-07).** Sonetel has
**no** real-time media-streaming API (no Twilio-style live audio). Its Telephony
API is numbers / IVR / forwarding + a **Call Recording API** that lists/downloads
recordings *after* the call. So the flow is: a call is handled live by Sonetel
IVR / voicemail / a human → recorded → we pull the recording, transcribe, and
draft. **"Live AI answering the call" is out of scope on Sonetel — see §9.**

**Everything external is an adapter with an offline fake** (built + tested with
no vendor account; concrete wiring is a localized swap later):
- `TelephonyProvider` → `SonetelProvider` (payload field shapes marked
  **UNVERIFIED** pending hands-on access) / `FakeTelephonyProvider`.
- `TranscriptionClient` → `FakeTranscriptionClient` (real STT TBD — Sonetel may
  not transcribe, so Whisper/Deepgram is a likely add).
- `ReceptionistLlm` → `ClaudeReceptionistLlm` (Anthropic Messages API, Opus 4.8,
  structured outputs) / `FakeReceptionistLlm` (deterministic; no API calls in CI).

**Flow (queued):** inbound webhook / recording import → `Call` (factual) →
`ProcessInboundCall` → `ReceptionistPipeline`:
1. RAG-retrieve grounding chunks;
2. LLM analyses using ONLY those chunks + the active prompt rules;
3. **grounding enforcement (system-level, not prompt):** below-threshold
   retrieval OR foreign/uncited citations ⇒ `fallback_to_human` — the AI never
   improvises an answer;
4. record an **`AiRun`** (ops metrics only — no PII);
5. propose ONE draft `ai_action` (`receptionist_intake`) carrying the
   transcript-derived PII in its (erasable) payload.

**Review queue** (`AiActionResource`): a polling Filament table with Approve /
Reject. Approve runs `ReceptionistActionApplier`, which creates the Company (via
`CompanyMatcher` fuzzy dedup — shared with Phase 5) / Contact / Note / Task, tags
them `source=Ai` + `ai_action_id`, and links the call — atomically.
`AiRunResource` (read-only) surfaces grounding / fallback / latency / tokens for
Phase 8.

**GDPR:** recording consent + retention are config-driven
(`config/receptionist.php`, 90-day placeholder) with a daily
`PurgeExpiredCallContent` job (`CallContentEraser`). The real retention period +
disclosure wording need legal sign-off before go-live (§9).

---

## 6. Cross-cutting conventions (every new table/model)

- `id`, `created_at`, `updated_at`, `archived_at` unless append-only.
- `created_by` / `owner_id` on user-authored data.
- Emit an event on create/update/archive (`LogsEvents`); list PII/large fields
  in `$auditRedacted`.
- AI-generated records flow through `AiActionService` and carry the auditability
  trio — never write AI data directly.
- **Everything external is behind an interface with an offline fake**
  (`EmbeddingClient`, `TelephonyProvider`, `TranscriptionClient`,
  `ReceptionistLlm`) — the app builds and tests with no vendor account.

## 7. Key paths

```
app/
  Concerns/{LogsEvents,HasProvenance}.php
  Contracts/{EmbeddingClient,TelephonyProvider,TranscriptionClient,ReceptionistLlm}.php
  Enums/                    # actor/ai-action/company/task/note/call{,intent}/appointment/record + knowledge/publish/rule-set
  Exceptions/{InvalidAiActionTransition,InvalidTaskTransition}.php
  Http/Controllers/InboundCallWebhookController.php
  Jobs/{EmbedKnowledgeEntry,ProcessInboundCall,PurgeExpiredCallContent}.php
  Models/                   # + KnowledgeEntry/Chunk, PromptRuleSet/Rule, AiRun
  Services/                 # EventLogger, AiActionService, CallContentEraser, Knowledge*, ContextVersion,
                            #   Embeddings/*, Telephony/*, Receptionist/{ReceptionistPipeline,CompanyMatcher,
                            #   ReceptionistActionApplier,FakeReceptionistLlm,ClaudeReceptionistLlm}
  Filament/Resources/       # CRM resources [Knowledge Base nav] + AiAction(review queue)/AiRun [AI Receptionist nav]
config/receptionist.php     # grounding threshold, retention (placeholder), driver switches
database/{migrations,seeders,factories}/   # seeders: Role, AdminUser, Demo, Knowledge
tests/Feature/              # + ReceptionistPipeline, ReceptionistApproval, ReceptionistReviewUi,
                           #   InboundCallWebhook, CallRetentionPurge, ...
docker-compose.yml          # PostgreSQL 17 on host port 5434
```

## 8. Testing
- Pest, `php artisan test` (**73 tests**). In-memory SQLite for speed (queue =
  sync, so jobs run inline); app targets PostgreSQL; CI runs a Postgres migrate
  smoke.
- Covered: audit log + append-only, PII redaction, AI-action & Task state
  machines, call erasure + retention purge, timeline anchoring, RBAC, chunking,
  RAG ranking, ruleset activation, context version, **receptionist grounding +
  fallback + citation validation**, inbound webhook, approve/reject (Livewire),
  and a UI render smoke across every resource page.
- **CI:** `.github/workflows/ci.yml` — Pint + Pest + Postgres migrate.

---

## 9. Open decisions & compliance flags

- **Jurisdiction = NL / EU (GDPR).**
  - **Right to erasure** — *done:* event log never stores PII; `CallContentEraser`
    + `PurgeExpiredCallContent` destroy call content, keep metadata. *Remaining:*
    subject-level erasure spanning a contact + all their calls (a small service).
  - **Call recording consent + retention** (Phase 3): mechanism is built + config-
    driven (`config/receptionist.php`, 90-day placeholder + daily purge). The
    **retention period and disclosure wording need legal sign-off before
    go-live** — not defaulted.
  - **Outbound** (Phase 6): Dutch/EU telemarketing rules → compliance gate first.
- **Sonetel is post-call only (confirmed).** No real-time media-streaming API;
  the receptionist pulls recordings via the Call Recording API after the call. We
  still need hands-on access to verify the exact recording/callback payload shapes
  (`SonetelProvider` mappings are marked UNVERIFIED) and whether a call-completed
  callback exists (else a scheduled recording-poll job).
- **Live AI *answering* the call — separate future decision.** Not buildable on
  Sonetel. Options: confirm an undocumented Sonetel real-time tier; build a SIP
  media server (FreeSWITCH / Asterisk / Pipecat) behind Sonetel forwarding; or add
  a second vendor (Twilio / Telnyx) for just that leg. Deferred until there's a
  real answer.
- **Database engine (decided, pre-Phase 3):** switched MySQL → PostgreSQL while
  the cost was near-zero (no MySQL-specific SQL, no data, tests engine-agnostic on
  SQLite). Banks jsonb indexing for Phase 4/8; keeps pgvector a cheap future option.
- **RAG storage, pgvector deferred:** embeddings live in `jsonb`; retrieval is
  brute-force cosine in PHP. The DB image ships pgvector but it is unused — adopt
  it as a localized swap of `KnowledgeRetriever` + the embedding column when chunk
  volume/latency justifies it (needs a Postgres-based retrieval test then, since
  pgvector is not in SQLite).
- **Fake embeddings are lexical** (bag-of-words) — semantic quality arrives with
  Voyage (`EMBEDDINGS_DRIVER=voyage` + `VOYAGE_API_KEY`).
- **Transcript search vs. encryption** (Phase 3): encryption blocks SQL search;
  transcript search will use the object store / a dedicated index.

---

## 10. Roadmap status

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation + hardening (RBAC, event log, soft delete, AI action framework, GDPR-safe audit, CI) | ✅ Done |
| 1 | Core CRM (Companies, Contacts, Notes, Tasks, Appointments, Calls) | ✅ Done |
| 2 | Knowledge Base + RAG pipeline | ✅ Done |
| 3 | AI Receptionist (post-call shadow mode; approve → apply) | ✅ Done |
| 4 | Company Analysis (manual vs. AI, disagreement flags) | ⬜ Next |
| 5 | CSV Import | ⬜ |
| 6 | AI Sales Representative (outbound) | ⬜ |
| 7 | Follow-up Automation | ⬜ |
| 8 | Reporting (business + AI ops) | ⬜ |
