# Smoothware AI Employee — Architecture

A CRM built to be operated jointly by human sales reps and an AI agent. This is
a **living document**: it is updated at the end of every phase so the project
stays legible as it grows.

> **Status:** Phases 0 (+ hardening), 1 (Core CRM), 2 (Knowledge Base + RAG),
> 3 (AI Receptionist — post-call shadow mode), 4 (Company Analysis), and
> 5 (CSV Import), and **7 (Follow-up Automation) complete.** **Phase 8 (Reporting)
> next** — Phase 6 (outbound) is deferred: not buildable as briefed on Sonetel, and
> gated on telemarketing sign-off. See [`GO-LIVE-LEGAL.md`](GO-LIVE-LEGAL.md).

---

## 1. Tech stack & why

| Layer | Choice | Rationale |
|---|---|---|
| Framework | **Laravel 13** (PHP **8.4+** floor; dev + CI also on 8.5) | Mature, relational, batteries-included. |
| Admin/UI | **Filament 5** (TALL: Tailwind, Alpine, Livewire) | A CRM/admin-panel engine. Server-rendered; no separate SPA. Badge theming makes "AI data looks different from human data" straightforward. |
| Database | **PostgreSQL 17** (via Docker Compose) | Fits the relational model; jsonb for KB/analysis JSON; pgvector available but deferred (§13). |
| Auth & RBAC | **spatie/laravel-permission** + **Filament Shield** | Roles + per-resource policies. |
| AI reasoning | **Anthropic Claude API** — Opus 4.8 / Haiku 4.5 | Generation only (no embeddings). Structured outputs for receptionist + analysis. |
| Embeddings | **Voyage AI** (prod) / offline fake (dev/test) | Separate provider because Claude has no embeddings API. Swappable via `EmbeddingClient`. |
| Telephony | **Sonetel** (Dutch number) | Phase 3, **POST-CALL**. Recording API (download after the call) — **no live media streaming** (§13). |
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
  permission-assignment, not a gate). What `sales_manager` / `sales_rep` may do is
  the **access-control matrix in §9** — then re-run `db:seed --class=RolePermissionSeeder`.
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
  hard-delete — except for GDPR erasure (§13).

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
- **`contacts.preferred_channel`** (`PreferredChannel`: phone / email / either,
  **nullable**) — how a person prefers to be reached. On Contact, not Company: a
  company has no preference, its people do, and a single company-level value would
  be a fiction whenever two contacts disagree. Editable by hand and settable from
  a CSV column during import (Phase 5); unrecognised source text normalises to
  **null** rather than a guess. Null means *never stated* — there is no "unknown"
  case and no default, so automation can't read "we never asked" as consent.

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
  over published chunks, fine for a small KB, §13; top-K + scores).
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
Sonetel — §13.**

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
placeholder) + daily `PurgeExpiredCallContent`; real values need legal sign-off (§13).

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

**Import provenance (GDPR).** Each batch records **where the list came from**
(`list_source`) and **under which Art. 6 lawful basis** we process it
(`lawful_basis`, a `LawfulBasis` enum, + `lawful_basis_notes` for the LIA
reference). Both are asked **in the upload form, before the import runs** — the
question is unavoidable at the moment someone loads other people's personal data.
Nullable in the DB on purpose: a default would fabricate a basis. A company traces
back via `import_rows.company_id` → `imports`. See
[`GO-LIVE-LEGAL.md`](GO-LIVE-LEGAL.md) item #2.

Provenance holds throughout: imported companies are `RecordSource::Import`, visibly
distinct from `manual` / `ai` / `system`. Stage/commit are queued jobs
(`StageImport` / `CommitImport`); the Filament actions dispatch them synchronously
for immediate feedback in the panel.

---

---

## 8. Phase 7 — Follow-up Automation

Nothing falls through the cracks: when something happens (or pointedly doesn't),
a task appears for a human. **Internal only** — rules create Phase-1 `tasks`;
nothing is ever sent to a prospect from here. Auto-sending outbound email is
deliberately out of scope: it would reopen the direct-marketing / ePrivacy
surface that Phase 7 was sequenced *ahead of* Phase 6 to avoid
([`GO-LIVE-LEGAL.md`](GO-LIVE-LEGAL.md)).

**The design turns on *who decided*:**

| Path | Whose judgment | Behaviour |
|---|---|---|
| A **rule** fired | A human wrote the rule | Task created immediately, `source = system`. **No approval queue.** |
| **AI** suggested | The AI invented it | Would go through `ai_actions` → review queue. **Off at launch** (`followups.ai_suggestions`); schema carries the provenance columns, the suggester is not built. |

Routing human-authored automation through the approval queue would train
reviewers to rubber-stamp it — and a rubber-stamped queue stops protecting the AI
proposals that actually need scrutiny. Keeping the paths structurally distinct is
what preserves principle #2 in practice, not just on paper.

**Schema.** `follow_up_rules` (trigger, jsonb `conditions`, delay, task template,
`assignee_strategy`, `is_active`) + **`follow_ups`** — a ledger with one row per
*decision*, including suppressed ones. Two columns carry the weight:

- **`dedup_key` (UNIQUE)** — idempotency enforced by the **database**, not by the
  code remembering. The daily sweep re-runs over the same quiet companies, and
  two workers can race; exactly one may win.
- **`rule_snapshot`** — the rule as it read *when it fired*. Without it, editing a
  rule silently rewrites history. Cheaper than versioning (cf. `prompt_rule_sets`)
  and sufficient, because nothing re-runs an old rule.

**Triggers read the event log** rather than bolting hooks onto models: everything
already emits `{model}.{verb}`, so the Phase-0 backbone *is* the trigger stream.
`EventLogger` now dispatches `EventLogged` and **stays ignorant of who listens** —
the append-only backbone gains no dependency on Phase 7.
`FollowUpTrigger::forEvent()` is the single mapping point. **NoActivity** fires on
the *absence* of events, so it can't be pushed — a daily `EvaluateTimeBasedFollowUps`
pulls it, measured against the Phase-1 timeline anchor (`events.company_id`), so
there is no `last_activity_at` column to drift.

**Channel routing (`contacts.preferred_channel`, §3).** Rules that decide *how* to
reach out should read the contact's stated preference — e.g. route to a
`send_email` task rather than a `call_back` task when they prefer email, and
either when they said either. Use `PreferredChannel::allowsPhone()/allowsEmail()`
rather than re-deriving the logic. Two constraints:

- **A null preference is not permission for anything** — it means nobody asked.
  Treat it as "no signal" and fall back to the rule's own `task_type`, never as
  "either".
- **The preference says HOW, never WHETHER.** Whether we may contact someone at
  all is lawful basis + the right to object ([GO-LIVE-LEGAL](GO-LIVE-LEGAL.md)),
  not this field. **Any future Phase 6 outbound work must read it the same way**:
  a routing hint, not a consent record.

Note this stays within the Phase 7 boundary — routing a task to "send email" tells
a *human* to write one. **Auto-sending is still out of scope** and still opens the
direct-marketing surface described above.

**Guards.** A per-company/day cap (`followups.max_per_company_per_day`) stops a
badly-written rule burying a rep; suppressed follow-ups are recorded as `skipped`,
never silently dropped. Assignees resolve **at fire time**, so a rule written
months ago still routes to whoever owns the company today.

**RBAC.** Authoring is `sales_manager` + `super_admin`; `sales_rep` is read-only —
a standing rule creates work for other people without their per-instance consent.
The restriction lives in **permission assignment**
(`FollowUpRulePermissionSeeder`), *not* in the policy: Shield regenerates policies
on `shield:generate --all`, so a hand-written role check there would be silently
clobbered — a security regression with no failing test. `FollowUpRuleRbacTest` is
the tripwire.

---

## 9. Access control — the permission matrix

Three roles: **`super_admin`** ("Admin"), **`sales_manager`**, **`sales_rep`**.
Shield generates 12 permissions per entity (`Verb:Entity`) across **15 entities**
= 180. `super_admin` gets everything from Shield and appears nowhere below.

**The matrix lives in `Database\Seeders\RolePermissionSeeder::MATRIX` as
executable data** — that class is the source of truth, this section is the
reasoning. `RolePermissionMatrixTest` iterates the matrix itself rather than
restating it, so doc, seeder and tests cannot drift: **adding a resource without
deciding its access fails the suite.**

### Why assignment, not policies

Filament Shield **generates** the policy files (`shield:generate --all`). Any role
logic written into a policy is silently overwritten the next time a resource is
added — a security regression with no failing test behind it. Permission
**assignment** survives regeneration. So policies stay exactly as Shield writes
them (pure `$user->can('Verb:Entity')`), and every access decision lives in the
seeder. The seeder is **authoritative, not additive**: it `syncPermissions()` per
role, so a permission removed from the matrix is actually revoked rather than
lingering.

> **`app/Policies/*` are GENERATED ARTIFACTS. Do not hand-edit them — comments
> included.** Verified empirically (2026-07-16): running `shield:generate --all`
> rewrote all 15 policies and **silently deleted the explanatory docblocks** that
> had been written into two of them. The `can()` bodies survived byte-identical
> and the RBAC suite still passed, which is exactly the point — the *logic* is
> safe there because it is Shield's own shape, but *anything else* is not. Had the
> role checks lived in the policy, they would have been deleted with no failing
> test. Put reasoning here or in the seeder; never in a policy file.
>
> `shield:generate --all` is otherwise idempotent **once Pint has run** — Shield's
> raw output differs from Pint's style, so the workflow after adding any resource
> is: `shield:generate --all --panel=admin` → `pint` → `db:seed --class=RolePermissionSeeder`.

### Bundles

| Bundle | Permissions | Who |
|---|---|---|
| **READ** | `ViewAny`, `View` | per matrix |
| **WRITE** | `Create`, `Update` | per matrix |
| **ARCHIVE** | `Delete`, `DeleteAny`, `Restore`, `RestoreAny` | per matrix — soft delete (`archived_at`), recoverable |
| **DESTROY** | `ForceDelete`, `ForceDeleteAny` | **`super_admin` only, every entity, no exceptions** |
| *unused* | `Replicate`, `Reorder` | nobody — nothing uses them |

DESTROY is universally withheld because `archived_at` is the convention and true
erasure runs through dedicated GDPR services (`CallContentEraser`), never a UI
button.

### The matrix

| Entity | `sales_rep` | `sales_manager` | Why |
|---|---|---|---|
| Company | READ + WRITE | + ARCHIVE | Reps add and work prospects; removing a record of substance from view is a manager call. |
| Contact | READ + WRITE | + ARCHIVE | " |
| Call | READ + WRITE | + ARCHIVE | " |
| Note | READ + WRITE + ARCHIVE | same | A rep's own workflow item — they may clean up their own mess. |
| Task | READ + WRITE + ARCHIVE | same | " |
| Appointment | READ + WRITE + ARCHIVE | same | " |
| AiAction | READ + **`Update`** | + ARCHIVE | **Nobody gets `Create`** — the AI authors these; a human writing one by hand would forge provenance. `Update` **is** approve/reject, the rep's core Phase 3 job. |
| AiRun | READ | READ | Ops log, system-written. |
| KnowledgeEntry | READ | + WRITE + ARCHIVE | Editing the KB re-aims the AI **for the whole team** — not an IC act. |
| PromptRuleSet | READ | + WRITE + ARCHIVE | Same, more so: this *is* the AI's instructions. |
| FollowUpRule | READ | + WRITE + ARCHIVE | A standing rule creates work for others without their per-instance consent (§8). |
| FollowUp | READ | READ | The ledger is recorded, never authored. |
| Campaign | READ | + WRITE + ARCHIVE | Grouping decisions travel with imports. |
| **Import** | READ | **WRITE, no ARCHIVE** | `Create` asserts a **GDPR lawful basis** — a legal determination, not data entry, so it is manager-only. ARCHIVE is withheld **even from managers**: an import row *is* the Art. 14 / lawful-basis audit trail ([GO-LIVE-LEGAL](GO-LIVE-LEGAL.md) item #2), and compliance evidence must not quietly vanish from view. |
| Role (Shield) | — | — | `super_admin` only. **Load-bearing:** a manager who could grant permissions could grant themselves anything, making every row above decorative. |

### Decided: no row-level scoping

**Permissions are entity-level. Every authenticated user sees every company** —
`owner_id` routes work, it does not restrict visibility. At agency scale, hiding
accounts by owner costs more than it protects: someone must be able to cover a
colleague's pipeline, and a manager should see everything without special-casing.

Note the constraint this implies: Spatie permissions **cannot** express "a rep may
only edit their own accounts". That would need query scoping in the policies and
resources — a different mechanism.

> **Phase 8 inherits this.** Reporting will show **all data to all authenticated
> users, filtered only by their entity permissions — no per-owner filtering.**
> Phase 8 should be designed against that assumption rather than re-deriving it.
> **If it turns out to be wrong, the fix is an additive query-scoping layer on top
> — not a rebuild:** the matrix stays as-is and scopes narrow what a permitted
> user sees. Nothing in the current design forecloses that.

## 10. Cross-cutting conventions (every new table/model)

- `id`, `created_at`, `updated_at`, `archived_at` unless append-only.
- `created_by` / `owner_id` on user-authored data.
- Emit an event on create/update/archive (`LogsEvents`); list PII/large fields in
  `$auditRedacted`.
- AI-generated records carry the auditability trio; externally-consequential ones
  flow through `AiActionService` (approval). Never write AI data over human data.
- **Everything external is behind an interface with an offline fake**
  (`EmbeddingClient`, `TelephonyProvider`, `TranscriptionClient`, `ReceptionistLlm`,
  `WebsiteAnalyzer`, `CompanyAnalysisLlm`) — build + test with no vendor account.

## 11. Key paths

```
app/
  Concerns/{LogsEvents,HasProvenance}.php
  Contracts/                # EmbeddingClient, TelephonyProvider, TranscriptionClient,
                            #   ReceptionistLlm, WebsiteAnalyzer, CompanyAnalysisLlm
  Enums/                    # + CallIntent, AnalysisPriority, PreferredChannel, ImportStatus, ImportRowDisposition,
                            #   LawfulBasis (GDPR Art. 6 basis per import), FollowUpTrigger,
                            #   FollowUpStatus, AssigneeStrategy
  Events/EventLogged.php    # the log announces; Phase 7 listens (backbone stays dumb)
  Listeners/QueueFollowUpEvaluation.php
  Http/Controllers/InboundCallWebhookController.php
  Jobs/                     # EmbedKnowledgeEntry, ProcessInboundCall, PurgeExpiredCallContent,
                            #   GenerateCompanyAnalysis, StageImport, CommitImport,
                            #   EvaluateFollowUpsForEvent, EvaluateTimeBasedFollowUps
  Models/                   # + KnowledgeEntry/Chunk, PromptRuleSet/Rule, AiRun,
                            #   CompanyManualAnalysis, CompanyAiAnalysis, Campaign, Import, ImportRow,
                            #   FollowUpRule, FollowUp
  Services/                 # EventLogger, AiActionService, CallContentEraser, Knowledge*, ContextVersion,
                            #   Embeddings/*, Telephony/*, Receptionist/*, Analysis/{CompanyAnalyzer,
                            #   DisagreementDetector, Fake/Http WebsiteAnalyzer, Fake/Claude AnalysisLlm},
                            #   Import/{CsvImporter, ImportCommitter}, FollowUps/FollowUpEvaluator
  Filament/Resources/       # CRM [nav] + AiAction(review)/AiRun [AI Receptionist nav] +
                            #   Import/Campaign [Import nav]; Company form has an inline
                            #   Manual-analysis section + an AI-analysis RM; Import has a read-only preview RM
config/{receptionist,analysis,followups}.php  # grounding, retention (placeholder),
                                             #   driver switches, follow-up window/cap
database/seeders/RolePermissionSeeder.php  # THE access-control matrix (§9), executable
database/{migrations,seeders,factories}/
tests/Feature/              # + Receptionist*, InboundCallWebhook, CallRetentionPurge,
                           #   CompanyAnalysis, DisagreementDetector, CsvImport,
                           #   ImportProvenance, FollowUpAutomation, FollowUpRuleRbac,
                           #   RolePermissionMatrix, PreferredChannel, PanelSmoke, ...
docker-compose.yml          # PostgreSQL 17 on host port 5434
```

## 12. Testing
- Pest, `php artisan test` (**195 tests**). In-memory SQLite for speed (queue =
  sync, so jobs run inline); app targets PostgreSQL; CI runs a Postgres migrate
  smoke.
- Covered: audit log + append-only, PII redaction, AI-action & Task state
  machines, call erasure + retention purge, timeline anchoring, RBAC, RAG ranking,
  ruleset activation, receptionist grounding + fallback + citation validation,
  inbound webhook, approve/reject (Livewire), company analysis (provenance,
  AI-never-touches-manual, regenerate history) + disagreement detection,
  CSV import (auto-map + dispositions, commit defaults/dedup/contacts/queued
  analysis, idempotency, optional-column omission), **import provenance (list
  source + lawful basis, company→import trace, unjustified-basis flag)**, and a UI
  render smoke across every resource page, **follow-up automation (trigger
  mapping, idempotency incl. the DB constraint, rule-snapshot freeze, conditions,
  assignee strategies, per-company cap, NoActivity window), and **the full
  access-control matrix (every entity x role asserted in both directions, policy
  registration proven non-vacuously, DESTROY withheld from all, Role locked to
  super_admin, re-seed revokes drift)**.
- **CI:** `.github/workflows/ci.yml` — Pint + Pest (PHP 8.4 + 8.5 matrix) +
  Postgres migrate smoke on the real `pgvector/pgvector:pg17` image.
- **Local engine verification (2026-07-16).** The suite runs on SQLite for speed,
  which is a good proxy but not a guarantee — constraint enforcement and query
  planning differ. Verified against a real PostgreSQL server: all migrations
  apply; jsonb columns (`column_mapping`, `rule_snapshot`, `conditions`)
  round-trip; **`follow_ups.dedup_key`'s UNIQUE constraint is enforced by the
  engine** (a raw `DB::table()->insert` bypassing all application code is rejected
  with `SQLSTATE[23505]`); the `NoActivity` `whereDoesntHave` sweep behaves; and
  `RolePermissionSeeder` is idempotent (180 permissions / 148 grants, stable
  across runs). CI remains the authority on exact image fidelity (pg17).
- **Running Postgres without Docker.** If Docker Desktop's WSL integration is
  unavailable, a userspace cluster needs no root and matches `.env` as-is:
  `initdb -D ~/pgdata-smoothware -U postgres --auth-local=trust --auth-host=trust`
  then `pg_ctl -D ~/pgdata-smoothware -o "-p 5434 -k /tmp" -l ~/pgdata-smoothware/server.log start`,
  and create the `smoothware` role + database. `docker compose up -d` stays the
  documented path.

---

## 13. Open decisions & compliance flags

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
- **WATCH — `Update:AiAction` is coarser than the intent (§9).** A rep needs
  `Update` because that *is* approve/reject, but the permission technically also
  covers editing an AI action's payload. **Not exploitable today**: the review
  queue only exposes approve/reject, so there is no edit surface. Filament/Shield
  permissions are per-verb, not per-field, so separating them would need a custom
  ability or a dedicated approve endpoint. **Revisit the moment the review queue
  grows any edit affordance** — at that point a rep could alter what the AI
  proposed and then approve it, which would silently forge provenance.
- **RBAC coverage is self-enforcing (§9).** `RolePermissionMatrixTest` compares
  the matrix against the policy files on disk, so a new resource without an
  explicit access decision fails CI rather than shipping as another
  "only super_admin has permissions" hole.

---

## 14. Roadmap status

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation + hardening (RBAC, event log, soft delete, AI action framework, GDPR-safe audit, CI) | ✅ Done |
| 1 | Core CRM (Companies, Contacts, Notes, Tasks, Appointments, Calls) | ✅ Done |
| 2 | Knowledge Base + RAG pipeline | ✅ Done |
| 3 | AI Receptionist (post-call shadow mode; approve → apply) | ✅ Done |
| 4 | Company Analysis (manual vs. AI, disagreement flags) | ✅ Done |
| 5 | CSV Import (map → preview/dedup → create → queue analysis) | ✅ Done |
| 7 | Follow-up Automation (rules → tasks; internal only) | ✅ Done |
| 8 | Reporting (business + AI ops) | ⬜ **Next** |
| 6 | AI Sales Representative (outbound) | ⛔ **Deferred** — blocked on the telephony constraint (§13) + telemarketing sign-off ([GO-LIVE-LEGAL](GO-LIVE-LEGAL.md) item #3). Phases 7–8 run first. |
