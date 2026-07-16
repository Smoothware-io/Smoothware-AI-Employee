# Go-Live Legal & Compliance Checklist

**Single source of truth for every legal item that must be resolved before this
system touches real prospects, real calls, or real personal data in production.**

These are deliberately **not** resolved in code. Each one is a placeholder or a
built-but-unconfigured mechanism awaiting a decision from a qualified person.
Engineering has built the *mechanism*; the *values and lawful basis* are not
engineering decisions and have not been defaulted.

> **Jurisdiction: Netherlands / EU.** Governing regimes: **GDPR** (personal data),
> **ePrivacy / Telecommunicatiewet** (call recording, telemarketing),
> **Reclame Code / ACM rules** (Dutch telemarketing conduct).
>
> **Status: NOTHING ON THIS LIST IS CLEARED.** No item below has legal sign-off.
> Nothing here should be read as legal advice — it is an engineering-side register
> of open questions for a compliance professional.

---

## How to use this document

| Column | Meaning |
|---|---|
| **Built** | The mechanism exists in code and is configurable. |
| **Blocked on** | The specific decision needed, and from whom. |
| **Gate** | What must be true before the related feature is enabled in production. |

Update this file whenever a phase adds a legal surface. **Do not scatter legal
flags across phase notes** — ARCHITECTURE.md §13 links here.

---

## Named questions for counsel

Two questions are **specific enough to answer** and are the current blockers. Take
these to counsel as written — not "review our GDPR posture".

### Q1 — Art. 14 notice for imported contacts: timing and format
> We import B2B contact lists we did not collect from the data subject (purchased,
> partner, or event lists). **Under GDPR Art. 14, by when and in what form must we
> notify those individuals?** Specifically: (a) does the one-month outer limit
> apply, or does "first communication" pull it earlier when the import is
> immediately followed by outreach; (b) is a notice in our first outbound email
> sufficient, or is a separate standalone notice required; (c) does the Art. 14(5)
> **disproportionate-effort** exemption apply to lists of this size and type, and
> if we rely on it, what must we publish instead; (d) what exactly must the notice
> contain for our purposes (AI-assisted enrichment + CRM storage + outreach)?

**Why it blocks:** the obligation attaches the moment the CSV lands, and nothing in
our code prompts for it. → §2.

### Q2 — ZZP'er / sole-trader classification for Dutch cold-calling
> We sell to small Dutch businesses; a large share are **ZZP'ers / eenmanszaken**.
> **Which of our targets count as "consumers"** for the Dutch cold-calling regime
> (the consumer cold-calling prohibition and the *recht van verzet* / register
> rules)? Specifically: (a) is a sole trader called on a business number for
> business purposes a consumer for these rules; (b) does a KvK registration settle
> it, or does it turn on the nature of the call; (c) **which register(s) must we
> screen against, and how often**; (d) does the answer differ for the shapes we can
> actually build (AI-prepared script + human caller vs. AI post-call analysis)?

**Why it blocks:** it determines whether Phase 6 is "normal B2B outreach" or
"regulated consumer telemarketing", and that changes the build, not just the
paperwork. → §3.

---

## 1. Call recording — consent & retention (Phase 3)

**Built.** Retention is config-driven (`config/receptionist.php`), with a **90-day
placeholder** and a daily `PurgeExpiredCallContent` job. Transcript/summary are
encrypted at rest; `CallContentEraser` destroys personal content while keeping
call metadata (right-to-erasure).

**Blocked on (legal):**
- [ ] **Actual retention period.** 90 days is an engineering placeholder chosen to
      exercise the mechanism — it is **not** a recommendation and has no basis.
- [ ] **Disclosure wording + timing.** Currently assumed: a standard verbal
      disclosure at call start. Needs approved wording.
- [ ] **Consent model.** NL generally permits recording where one party consents +
      the other is informed, but *purpose matters* — recording for AI processing
      and CRM enrichment is a distinct purpose from "quality assurance" and may
      require its own notice and basis.
- [ ] **Data-processor posture for third parties.** Transcription and LLM providers
      process call content containing personal data → DPAs + transfer basis
      (US providers ⇒ adequacy / SCCs) required.

**Gate:** ⛔ Do not process real recordings until the period and wording are signed
off and set in config. The placeholder must not survive to production.

---

## 2. Imported contact data — lawful basis (Phase 5)

**Built.** CSV import stamps `source = import` and an optional campaign, so every
imported record is attributable to a batch. Import is preview-then-commit; nothing
is written without a human acting.

**Blocked on (legal):**
- [ ] **Documented lawful basis for cold-loaded B2B contact data.** Importing
      contacts we did not collect directly (bought, scraped, inherited, or
      event/partner lists) requires a documented basis. **Legitimate interest**
      (GDPR Art. 6(1)(f)) is often workable in a B2B context but is **conditional**,
      not automatic — it must be *necessary* and *proportionate*, and generally
      calls for a recorded **LIA (Legitimate Interest Assessment)** balancing our
      interest against the data subject's rights.
- [ ] **Art. 14 notice obligation** — **see Q1 (named question for counsel).**
      Where personal data was **not obtained from the data subject**, they must
      generally be informed — typically within one month, or at first
      communication. This is the one most easily missed on an import, and it
      applies the moment the CSV lands.
- [ ] **Right to object.** Must be honoured and must be easy — especially where
      legitimate interest is the basis, and absolutely for direct marketing
      (Art. 21(2), where objection is **absolute**, no balancing).
- [ ] **Provenance of each list.** Where did it come from, and was it lawfully
      obtainable? Per-source, not per-import.
- [ ] **Personal vs. corporate data.** `info@company.nl` is likely not personal
      data; `eva.bloom@company.nl` and a named contact **are** — the import creates
      both, and they are not equivalent under GDPR.

**Engineering follow-ups:**
- [x] **Record source/provenance + lawful basis per import batch.** *Built
      2026-07-16:* `imports.list_source`, `imports.lawful_basis` (a `LawfulBasis`
      enum of the Art. 6 bases), `imports.lawful_basis_notes`. Both are **required
      in the upload form, asked before the import runs**; the notes field becomes
      required when the chosen basis carries an assessment burden (legitimate
      interest / other). Columns are **nullable in the DB on purpose** — pre-existing
      imports have no answer, and backfilling a default would fabricate a lawful
      basis. A company traces back via `import_rows.company_id` → `imports`.
      An import whose basis needs an assessment but records no reasoning is
      **flagged in the UI** — that state looks answered but isn't.
- [ ] A **suppression / do-not-contact list** honoured by import and by outbound.
- [ ] **Subject-level erasure** spanning a contact + all their calls (already a
      known gap in ARCHITECTURE §13).

**Gate:** ⛔ Do not import real purchased/third-party lists until the basis is
documented and the Art. 14 notice path exists.

---

## 3. Outbound calling — telemarketing (Phase 6, not yet built)

**Nothing built.** Deliberately gated behind this checklist. See the telephony
constraint below — it changes what is even *possible*, which in turn changes what
must be cleared.

**Blocked on (legal):**
- [ ] **Which outbound shape are we doing?** The compliance surface is materially
      different for each, and Sonetel currently permits only the latter two:
      - *AI holds a live conversation* — **not buildable on Sonetel** (see below).
        Would additionally raise **AI disclosure** duties (telling a person they
        are speaking to a machine) under the **EU AI Act Art. 50** transparency
        obligations.
      - *AI drafts a script, a human calls* — human is the caller; standard
        telemarketing rules apply.
      - *AI analyses recordings of human-made calls post-hoc* — closest to the
        Phase 3 model already shipped.
- [ ] **B2B telemarketing rules (NL)** — **see Q2 (named question for counsel).**
      The Dutch **recht van verzet** / register regime and the ACM's rules on cold
      calling — including the significant tightening of consumer cold-calling — and
      precisely **which of our targets are legally "consumers"** (a sole trader /
      **ZZP'er** may be treated as one; this is a real trap for a B2B agency
      selling to small businesses).
- [ ] **Caller-ID and identification duties** — who we must say we are, and when.
- [ ] **Opt-out handling on the call** and its propagation back into the CRM.
- [ ] **Interaction with §2** — a prospect imported under legitimate interest and
      then *cold-called* is a different, higher-risk processing activity than one
      merely stored.

**Gate:** ⛔ **Phase 6 does not begin design until the outbound shape is chosen and
the telemarketing rules are cleared.** This is the highest-risk phase in the
project (real money, real reputation, regulator-visible conduct).

---

## 4. Telephony constraint (technical, but it drives §3)

**Verified against Sonetel's public API docs (2026-07):**

- Sonetel has **no real-time media-streaming / WebSocket audio API** (unlike Twilio
  Media Streams / Telnyx). **An AI cannot hold a live conversation on a Sonetel
  call — inbound or outbound.** This is why Phase 3 shipped post-call.
- Sonetel's **outbound** API is a **callback/bridge**
  (`POST /make-calls/call/call-back`): it dials `call1` (our rep's phone), then
  dials `call2` (the prospect), and connects the two legs. **Both legs are ordinary
  PSTN calls to real phones — there is no audio path an AI can occupy.**
  Consequently, "AI Sales Representative makes outbound calls" **as originally
  briefed is not buildable on the current telephony stack.** What *is* buildable:
  AI prepares the call and triggers the dial; a **human speaks**; AI analyses the
  recording afterwards.

**Also outstanding (technical gate, tracked here because it blocks go-live):**
- [ ] `SonetelProvider` field/webhook shapes are **UNVERIFIED assumptions** pending
      hands-on API access. **Do not set `TELEPHONY_DRIVER=sonetel` until verified.**
- [ ] Confirm whether a call-completed callback exists, else a scheduled
      recording-poll job is required.

**If live AI conversation is genuinely required**, it needs a deliberate decision:
a SIP media server (FreeSWITCH / Asterisk / Pipecat) behind Sonetel forwarding, or
a second vendor (Twilio / Telnyx) for that leg. That decision carries its own legal
weight (§3: AI disclosure) and is **not** a Phase 6 implementation detail.

**Sources:**
- [Make Calls | Sonetel Documentation](https://docs.sonetel.com/docs/sonetel-documentation/9795c7fd58a87-make-calls)
- [Start a callback call | Sonetel Documentation](https://docs.sonetel.com/docs/sonetel-documentation/90592dd24322e-start-a-callback-call)
- [Make Calls API — Sonetel](https://sonetel.com/en/developer/help/telephony-api/make-calls-api/)

---

## 5. Standing GDPR obligations (cross-phase)

- [x] **Audit log never stores PII** — reference logging (`$auditRedacted`).
- [x] **Call content erasure + retention purge** — mechanism built.
- [ ] **Subject-level erasure** — a contact + all their calls, in one operation.
- [ ] **Records of processing (Art. 30)** — an activity register.
- [ ] **DPIA** — likely required: systematic AI evaluation of individuals, call
      recording, and (if Phase 6 proceeds) large-scale outreach.
- [ ] **DPAs + transfer basis** for every external processor (LLM, embeddings,
      transcription, telephony).
- [ ] **Automated decision-making (Art. 22)** — currently safe: AI *proposes*, a
      human *approves*, and AI never overwrites human judgment. **Re-check this if
      autonomy is ever increased** (principle #4 — "earn autonomy") — that is the
      moment this stops being safe by construction.

---

## Summary — three blocking legal items

| # | Item | Phase | Status |
|---|---|---|---|
| 1 | Call recording consent wording + retention period | 3 | ⛔ Placeholder (90d) — needs sign-off |
| 2 | Lawful basis + Art. 14 notice for imported B2B contacts | 5 | ⛔ **Q1** with counsel. Recording mechanism built; the *answer* is outstanding |
| 3 | Outbound telemarketing rules + chosen outbound shape | 6 | ⛔ **Q2** with counsel. Blocks Phase 6 design |

**None of the three is cleared. Do not flip any of the related features to
production against real people until the corresponding row is signed off.**
