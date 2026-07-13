# Maisha — Master Engineering Guidelines

Read this file completely before doing anything else. Then read
`PROJECT_STATE.md` for current known issues and priorities — that file
changes often, this one doesn't.

Do not modify any files until you have summarized your understanding of the
task and I have approved a plan.

---

## 0. What Maisha is

```
Frontend (React/TS/Vite)
    ↓
Laravel API (backend/) — Sanctum auth, REST, business logic, primary DB
    ↓
Flask AI Engine (ai-engine/) — meal planning, intent detection, health classification
    ↓
MySQL/PostgreSQL
```

Three services, three disciplines. Don't apply Laravel conventions to the
Flask engine, or Python patterns to Laravel. When a task spans services,
say so explicitly and sequence the work service-by-service.

---

## 1. Service architecture (stable reference)

### backend/ — Laravel
- **Auth**: Sanctum, token-based via `auth:sanctum` middleware (not sessions).
  Login rotates tokens (old ones deleted on new login). Registration
  auto-creates a `HealthProfile` via model boot event. Google OAuth is
  stateless Socialite. Password reset uses Laravel's built-in broker.
- **Structure**: Controllers → Service classes (`UtakulaaService`,
  `WhatsAppService`, `WaterService`, `HabitService`,
  `MedicationDefaultsService`) → Models. Controllers stay thin; business
  logic lives in Services.
- **Validation**: Form Requests, not inline controller validation.
- **Async work**: queue-backed jobs (queue driver: `database`, run under
  Supervisor/Systemd — see `QUEUE_WORKER_SETUP.md`).

### ai-engine/ — Flask
- **Layers**: `resources/` (REST endpoint glue) → `engine/` (business
  logic/orchestration) → `providers/` (AI-specific calls) → `clients/`
  (outbound HTTP back to Laravel, e.g. fetching ingredients). Keep these
  boundaries — don't put orchestration logic in a resource file or AI
  calls in the engine layer.
- **Auth**: all endpoints behind `X-Maisha-Internal-Token` header, read
  from `config.py`/`.env`.
- **AI providers**: provider-agnostic by design — new integrations follow
  the pattern of the existing reference implementation
  (`providers/claude_provider.py`), with graceful degradation if a
  provider's key/package isn't present.
- **Core logic pattern**: multi-filter pipelines for recommendation logic
  (see `engine/utakulaa_algorithm.py`) — sequential filters, each with a
  single clear responsibility (budget, health, diet, nutrition,
  availability), not one monolithic function.

### frontend/ — React/TS/Vite
- **Lives in its own separate git repository** — not tracked in this repo
  at all (`frontend/` is in `.gitignore` here). Any frontend work happens
  in that repo, not this one. If a task needs frontend context, it must
  be provided separately — Cline has no visibility into frontend/ from
  within this repo's working tree, unless the frontend repo happens to be
  checked out locally alongside it (as it currently is on this dev
  machine — don't assume that's always true).
- Component/hook structure conventions TBD — treat as provisional until a
  proper frontend pass has been done (check `PROJECT_STATE.md`).
- API calls belong in a dedicated layer, not scattered in components.
- Type everything; no implicit `any` without justification.

---

## 2. Operating rules (every task, every mode)

1. **Never modify files before analysis.** Plan mode = read + explain only.
   Act mode only after I approve a scoped plan.
2. **Explain every non-trivial recommendation** — what changes, why,
   tradeoffs.
3. **Never delete files or drop data without explicit permission.**
4. **Maintain backward compatibility** on existing API contracts unless
   I approve a breaking change.
5. **Smallest safe increment.** One module/feature/fix per Act-mode pass,
   not sweeping rewrites, unless explicitly requested.
6. **Flag risk before acting** on anything touching auth, payment logic,
   rate limiting, public/unauthenticated endpoints, or migrations.
7. **Don't invent a new pattern silently.** Follow the existing convention
   in the surrounding code, or explicitly flag why you're deviating.
8. **Check `PROJECT_STATE.md` before diagnosing something as a new bug.**
   If it's already logged, reference it instead of re-deriving it from
   scratch.
9. **Before adding a new field/table for something that might already be
   tracked**, check `PROJECT_STATE.md`'s data-ownership notes first —
   don't create a third place to store the same concept.
10. **This repo does not contain the frontend.** Don't attempt to read,
    grep, or reference `frontend/` files when investigating an issue as
    if they're guaranteed to exist in this working tree. If a task
    appears to need frontend context, flag that explicitly rather than
    assuming absence of a caller means a feature is unused frontend-side
    — confirm the frontend repo is actually available to check first.

---

## 3. The Engineering Team

This is not decorative framing — treat every non-trivial task as if it
would actually be reviewed by this team before merge. For any task beyond
a one-line fix, your Plan-mode response should implicitly pass through
each *relevant* role below before you present the plan to me. You don't
need to narrate "as the Architect, I think..." — but if a role would have
flagged a concern, that concern needs to show up in your plan or your
open questions, not get silently skipped.

| Role | Owns | Reviews every plan for |
|---|---|---|
| 🏗️ **Architect** | Service boundaries, cross-cutting design | Is this the right service to own this logic? Does it introduce coupling that will hurt later? Does it match the layer boundaries in Section 1? |
| 👨‍💻 **Laravel Engineer** | `backend/` | Controller stays thin, logic goes in a Service class, validation uses Form Requests, follows existing model/route conventions |
| 🐍 **AI Engineer** | `ai-engine/` | Layer boundaries respected (`resources`/`engine`/`providers`/`clients`), any config value added is actually wired in somewhere, provider fallback pattern followed |
| 🎨 **Frontend Engineer** | `frontend/` (separate repo) | Typed, API calls isolated from components, consistent with whatever frontend conventions exist |
| 🗄️ **Database Engineer** | Migrations, schema | Index/FK implications, and critically: does this duplicate a concept already tracked elsewhere (check `PROJECT_STATE.md`) |
| 🔒 **Security Engineer** | Auth, public endpoints, secrets, rate limits | No plaintext secrets in logs, no new unauthenticated surface without a reason, token/session handling is sound |
| 🧪 **QA Engineer** | `testing/` | Does this need a new test or update an existing one; is test data idempotent; does the fix actually address the root cause |
| 🚀 **DevOps** | Deploy, background jobs, production readiness | Logging is structured (not `print()`), queue/async implications, does this need Supervisor/Systemd/env changes |

**For cross-service tasks**, explicitly state in your plan which roles you
consulted and what each one changed about the plan. E.g.: *"Security
flagged that this new endpoint needs rate limiting before it ships — added
as a required step, not a follow-up."*

**For single-file, low-risk tasks**, you can skip the full multi-role
pass and just note "single-service, low-risk — proceeding directly."
Don't perform ceremony where it isn't earning its cost.

---

## 4. How to start any task

Respond first with:
1. **What you understand the task to be** (1–3 sentences)
2. **Which service(s) it touches**
3. **Whether it relates to an item in `PROJECT_STATE.md`** (reference it)
4. **Which roles from Section 3 are relevant, and what each flagged**
   (skip if single-service/low-risk — say so explicitly)
5. **A short numbered plan**
6. **Risks or open questions**

Wait for my approval before switching to Act mode.

---

## 5. Definition of done

- [ ] Follows existing conventions in the surrounding file/module
- [ ] Relevant test in `testing/` passes or was added
- [ ] No secrets/PII in logs or commits
- [ ] Plain-English summary of what changed and why
- [ ] If it closed or changed anything in `PROJECT_STATE.md`, propose the
      exact edit for my approval

---

## 6. Session handoff protocol

Long conversations cost more per message because full history is resent
each time. When a session wraps up a deliverable, end with a **handoff
summary** in this shape:

```
## Session Complete: [what was delivered]
**Baseline status:** [test pass rate / relevant state]

### Completed
- [item]: [what changed, files touched, test impact]

### Recommended next item
[which PROJECT_STATE.md item to tackle next, and why]

### Suggested PROJECT_STATE.md edits
[exact diff/lines to update]

**Recommendation:** Start a new conversation for the next item, using
this summary as opening context.
```

When I start the next conversation, I'll paste that handoff summary in
directly — you don't need to re-read the full prior conversation, just
`AI_GUIDELINES.md`, `PROJECT_STATE.md`, and the handoff summary I give you.

Use **New Task** (same conversation) when continuing the same feature
across small steps. Use a **New Conversation** with a handoff summary when
a deliverable is complete or the conversation has grown long/unfocused.

---

## 7. Maintenance of this system

This file describes **how to work**, not **what's currently true about the
project's state**. If you find yourself wanting to add "currently X is
broken" or "as of today, Y hasn't been built yet" to this file — that
belongs in `PROJECT_STATE.md` instead. Keeping that separation is what
keeps this file accurate without needing constant edits.





