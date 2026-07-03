# Maisha — Project State

Living document. Update this whenever something here gets fixed, changes,
or a new issue is discovered. `AI_GUIDELINES.md` tells Cline how to work;
this file tells it what's currently true about the project.

**Test baseline:** 205/206 passing (as of 2026-07-03 session — 1
pre-existing, unrelated failure; see Smaller known issues).

---

## Open items, in priority order

### 7. ⚪ Frontend architecture — not yet analyzed
Do a proper Plan-mode pass on `frontend/` before building major new UI.
Now the sole remaining open item — and the natural trigger point for
finally deciding `UserGoal`'s long-term fate (see Schema Consolidation
Phase 2, Goals sub-item, below).

---

## Resolved

### ✅ Schema Consolidation — Phase 2 (Resolved — 2026-07-03)
- [x] `UserHealthProfile`: confirmed dead (0 rows, only referenced by
      a one-off merge command whose job was already done). Deleted
      model, command, and table.
- [x] Medications: `HealthProfile.medications` confirmed unused (0
      populated rows, no frontend caller). Removed from
      `$fillable`/`$casts`, column left nullable rather than dropped.
      `UserMedication` (36 rows) is sole source of truth, unchanged.
- [x] Goals: found NOT dead despite existing deprecation comment —
      `GoalController`/`UserGoal` is live, writable code
      (`/profile/goals`, `auth:sanctum`). One real row found (user
      218, Grace Njeri) written outside `primary_goals`, causing a
      silent `lose_weight` goal invisible to `Dashboard.tsx`/Flask
      meal generation. No frontend caller exists currently (confirmed
      via full grep of `frontend/src`) — likely backend groundwork
      pre-dating the not-yet-built health-profile-edit screen (Item
      #7), since `UserGoal` carries `target_weight_kg`/`timeline_weeks`
      with no equivalent on `User`. Fixed `GoalController::update` to
      sync a flattened array into `User.primary_goals` on every write.
      Backfilled Grace's `primary_goals`. Verified live: a fresh write
      via the test suite correctly synced both tables end-to-end.
      Revisit whether `UserGoal` is still wanted once Item #7's
      frontend audit reaches the health-profile-edit screen.
- [x] Hydration: `WaterLog` and `HydrationLog` confirmed dead (0 rows
      each, no write path in code — `HydrationLog` had zero references
      anywhere outside its own model file). `WaterDailySummary` (33
      rows, written by `OnboardingController`) is sole source of
      truth. Deleted both dead models, the `User::waterLogs()`
      relation, and both tables.
- [x] Budget: confirmed NOT broken, just spread across purposes —
      `User.daily_budget_kes` (target), `BudgetLog` (daily
      spent/limit ledger), `ExpenseLog` (line items),
      `UserMealPattern.budget_split` (unrelated: intra-day
      distribution). No changes needed.

**Also found and fixed along the way:** `testing/maisha_test.sh` reads
`MAISHA_INTERNAL_SECRET` from the shell environment rather than
`.env` (line 12: `${MAISHA_INTERNAL_SECRET:-test-secret-change-me}`).
Running the script without first exporting the real secret silently
falls back to a mismatched placeholder, causing every Flask endpoint
that enforces the internal token (`/intent`, `/meal-categories`,
malformed-input checks on `/utakulaa`) to fail with 403, while
endpoints with no auth check pass regardless — a confusing
partial-failure pattern that looked like an application bug but
wasn't. Always `export MAISHA_INTERNAL_SECRET=<value from
backend/maisha-api/.env>` before running the suite. Script itself not
yet hardened to read `.env` directly — worth doing so this can't
silently recur (tracked below in Smaller known issues).

**Verification:** full suite re-run after every sub-step, final result
205/206 passing (1 pre-existing unrelated flake — Brenda's
"Complete without goals → 422" intermittent 401, logged separately),
zero regressions introduced across all five sub-items.

**Files touched:**
- Deleted: `app/Models/UserHealthProfile.php`,
  `app/Console/Commands/MergeHealthProfiles.php`,
  `app/Models/WaterLog.php`, `app/Models/HydrationLog.php`
- `app/Models/HealthProfile.php` — removed `medications` from
  fillable/casts
- `app/Models/UserGoal.php` — corrected stale deprecation comment
- `app/Models/User.php` — removed `waterLogs()` relation
- `app/Http/Controllers/GoalController.php` — syncs `primary_goals`
  on every goal write
- Migrations: `2026_07_03_135818_drop_user_health_profiles_table.php`,
  `2026_07_03_153320_drop_water_logs_and_hydration_logs_tables.php`
- Manual backfill: `users.primary_goals` for user 218 (Grace Njeri)

### ✅ WhatsApp webhook rate limiting (Resolved — 2026-07-02)
- [x] Global throttle: 60/min across all senders (`database` cache
      driver, persistent across processes — confirmed via cache table
      inspection, not just code review)
- [x] Per-sender throttle: 5/min + 30/hr linked, 2/min + 10/hr unlinked,
      keyed on normalized `WaId` (trustworthy since Twilio signature
      validation already runs first in the middleware chain — see
      signature validation entry below)
- [x] Breach response: silent `200`, not 4xx/5xx — avoids Twilio
      retry-storm amplification. Logged as `WARNING` with `wa_id` +
      `linked` status.
- [x] Verified live under real burst load: sent 14 messages in rapid
      succession — log confirmed 10 consecutive per-sender throttle
      rejections once the cap hit; phone confirmed only 1 of 14 replies
      received (silent-200 confirmed end-to-end, not just at the log
      level)
- [x] Verified recovery: ~8.5 minutes after the last rejection, the next
      message processed normally (real Twilio send confirmed,
      `message_sid: SM0643a7b496be2416a35876c83d97db15`) — confirms the
      window resets rather than sticking permanently
- [x] **Isolation test run to rule out Twilio duplicate delivery
      inflating the per-sender count:** temporarily raised the limit
      (50/min linked, 20/min unlinked), marked a clean log start, sent
      exactly 3 real taps ~10s apart. Result: 3 taps → 3 distinct
      `MessageSid`s, no duplicates, clean 1:1 delivery. This rules out a
      keying/dedup bug (Theory B dead) and shows Twilio isn't
      reflexively double-delivering under normal conditions (Theory A
      not a persistent pattern). The earlier anomaly — 3 webhook
      deliveries in 3 seconds at `15:01:08–15:01:11` that made every
      message look throttled from the first tap — is best explained as
      a one-off (possibly host RAM was at 94% during that session,
      which can slow webhook responses enough to trigger a genuine
      Twilio retry) rather than a systemic issue. Limit reverted back to
      5/2 + 30/10 after the test, `config:clear` run to confirm.
- Sits on top of Flask's existing per-user Claude-cost cap (Item #3) —
  this reduces junk traffic reaching that layer, doesn't replace it

### ✅ Twilio webhook signature validation (Resolved — 2026-07-02)
Investigation (prompted by planning the rate-limiting item above) found
the WhatsApp webhook had **no verification that inbound requests
genuinely came from Twilio** — `POST /api/whatsapp/webhook` trusted the
payload body completely. Anyone who found the URL could forge a payload
with a real linked user's `WaId` and the system would process it as
that user — reading their session, triggering
`UtakulaaService::getMealPlan()` against their account, consuming their
Flask rate-limit quota, and sending a reply to their real WhatsApp
number. This made per-sender rate limiting meaningless on its own,
since sender identity was just an unauthenticated string in the POST
body. Treated as a prerequisite to rate limiting, not a nice-to-have
alongside it.

- [x] Added `twilio/sdk` (^8.11) via Composer — provides
      `RequestValidator` rather than hand-rolling HMAC-SHA1 comparison
      logic
- [x] Built `VerifyTwilioSignature` middleware (`app/Http/Middleware/`)
      — validates `X-Twilio-Signature` header against the request's
      full URL + POST params using `TWILIO_AUTH_TOKEN` as the HMAC key;
      rejects with `403` + logs (IP, URL checked) on failure or missing
      signature
- [x] Registered as named middleware `twilio.signature` in
      `bootstrap/app.php`, applied only to `POST /whatsapp/webhook` in
      `routes/api.php` — the `GET /webhook` Meta hub-challenge route
      (already dormant/dead code) intentionally left unguarded, since
      Meta's verify flow doesn't send a Twilio signature at all
- [x] Added `$middleware->trustProxies(at: '*')` in `bootstrap/app.php`
      — required so `$request->fullUrl()` correctly resolves to the
      public ngrok HTTPS URL Twilio actually signed against, rather
      than `localhost:8000`. **TODO before production:** replace `'*'`
      with the actual reverse proxy/load balancer IP range once
      deployed, rather than trusting all proxies.
- [x] Verified both directions live:
  - Genuine Twilio traffic (valid signature) → passes, processes and
    replies normally, confirmed via real WhatsApp message round-trip
  - Forged request (no signature header), tested via local curl
    (`http://localhost:8000`, bypassing ngrok) → correctly rejected
    with `403 Forbidden`, logged as `Twilio webhook rejected: missing
    signature header`

**Incident during this work, unrelated to the middleware itself but
worth recording:** while checking whether `TWILIO_AUTH_TOKEN` was
already validated anywhere, the token value was inadvertently pasted
into a chat session. Treated as exposed out of caution and rotated
immediately via Twilio's secondary-token flow (old token fully retired
after confirming outbound sending worked on the new one). Surfaced a
separate, real bug in the process: after rotation, outbound sending
returned `401`/`20003 Authenticate` intermittently for ~30 minutes
because `php artisan config:clear` only clears the cached config
*file* — it does not refresh a config value already loaded into memory
by an already-running `php artisan serve` process. Fix was restarting
the serve process itself, not just clearing config cache. Worth
remembering generally: any `.env` credential change on this project
needs a full process restart, not just `config:clear`, to take effect
in an already-running server.

**Files touched:**
- `composer.json` / `composer.lock` — added `twilio/sdk`
- `app/Http/Middleware/VerifyTwilioSignature.php` — new
- `bootstrap/app.php` — added `trustProxies`, registered
  `twilio.signature` middleware alias (also required a full rewrite
  after an incomplete `nano` save left the file syntactically broken
  mid-session — resolved, no lasting issue)
- `routes/api.php` — applied `twilio.signature` middleware to
  `POST /whatsapp/webhook` only

### ✅ Queue worker persistent process manager (Resolved — 2026-07-02)
Discovered as a real, silent gap during the WhatsApp inbound flow work
— jobs had apparently been queuing with nothing consuming them, since
`QUEUE_CONNECTION=database` requires an active worker at all times, or
dispatched jobs (including every WhatsApp message) sit unprocessed
indefinitely with no error, no alert, no visible failure. A worker had
only ever been run manually (`php artisan queue:work`) in ad-hoc
terminals.

Original Supervisor/Systemd configs (`config/supervisor-maisha-queue.conf`,
`config/systemd-maisha-queue.service`, both from the async queue work)
could not be used as-is:
- [x] Both were written for a production deploy (`user=www-data`,
      `APP_ENV=production` hardcoded) — updated to `user=dan` and
      removed the forced env, for local dev use.
- [x] Systemd itself is non-functional in this dev environment: the
      machine is running **WSL1** (`4.4.0-19041-Microsoft` kernel, no
      `-WSL2` suffix), which has no real init system as PID 1
      (`System has not been booted with systemd as init system` —
      confirmed via `systemctl daemon-reload` failure). Not fixable
      without a WSL2 upgrade, which is blocked on this machine.
      Supervisor was not installed and has the same underlying
      init-system dependency, so it wasn't pursued either.
- [x] **Switched to `pm2`** (Node process manager, works in userspace,
      no init system required) instead. Node v22.12.0 already present
      via nvm; `npm install -g pm2` (no `sudo` — nvm-managed npm
      installs must not use sudo, which was confirmed as the cause of
      an initial `ELF: not found` failure from `sudo` falling back to a
      broken system-level `node` binary).

**Setup used:**
```bash
pm2 start php --name maisha-queue -- artisan queue:work database --queue=default --tries=3 --timeout=90 --max-jobs=1000 --max-time=3600 --sleep=3
pm2 save
```

**Verified (genuine live tests, not just config review):**
- [x] Worker picked up and processed a real backlogged job immediately
      on start (`ProcessIncomingWhatsAppMessage`, 3s completion) —
      proof jobs really had been silently queuing with nothing
      consuming them.
- [x] `jobs` table count confirmed `0` post-start (no backlog
      remaining)
- [x] `whatsapp_messages` id 10 confirmed `status: processed`,
      `processed_at` populated 5s after `received_at`
- [x] Crash-restart test: `kill -9` on worker PID → pm2 auto-restarted
      with a new PID, restart counter incremented, status still
      `online`
- [x] Full recovery test: `pm2 kill` (simulates a WSL restart — wipes
      daemon + process entirely) → new shell opened → `.bashrc` hook
      (`pm2 resurrect`) automatically restored `maisha-queue` to
      `online` with zero manual steps

**Known limitation (WSL1-specific, accepted for dev):** recovery only
fires when a new WSL shell is opened — a full Windows reboot with no
subsequent terminal opened leaves the worker down with no auto-launch,
since WSL1 has no boot-time init hook. Acceptable for local dev, but
**must be replaced with real Supervisor or Systemd (the original
configs, restored to `www-data`/production settings) before any actual
production deploy**, which would run on a real Linux host or WSL2 at
minimum.

**Note (2026-07-02, later in session):** the worker has since shown 3
restarts (`↺ 3` in `pm2 status`) during the rate-limiting isolation
test work. Not investigated — worth a quick check of `pm2 logs
maisha-queue --err` next session to see whether these were crashes or
routine `--max-jobs`/`--max-time` recycles (the start command sets
`--max-jobs=1000 --max-time=3600`, so periodic self-restarts are
expected behavior, not necessarily a bug).

**Files touched:**
- `config/supervisor-maisha-queue.conf` — user/env fixed for local
  reference only; not actually used on this machine — kept as-is for
  future production use, needs `user=www-data` and prod env restored
  before that deploy
- `config/systemd-maisha-queue.service` — same as above, not actually
  used on this machine — kept for future production use
- `~/.bashrc` (local machine, not in repo) — added `pm2 resurrect` on
  shell start, guarded with `command -v pm2` check

### ✅ WhatsApp inbound flow (Resolved — 2026-07-02, built not just verified)
Item was scoped as "verify full completeness" but investigation found it
was NOT functional — required a real build, similar to Item #2's
discovery pattern. Three separate, independent breaks found and fixed:

**1. Inbound parsing was Meta-only — Twilio (the actual active channel)
had zero support**
- [x] `ProcessIncomingWhatsAppMessage.php`: added Twilio flat-payload
      parsing (`WaId` for phone as bare E.164, `Body` for text). Meta's
      original nested-JSON parsing kept intact as a fallback, dormant.
- [x] Real Twilio payload shape captured live via ngrok + log tail
      before writing any parsing code (not guessed) — confirmed
      fields: `Body`, `From` (`whatsapp:+254...`), `WaId` (bare
      `254...`), `MessageSid`.

**2. User linking was fully stubbed — `WhatsappSession.user_id` was
created null and never set anywhere**
- [x] Added real lookup: `User::where('wa_number', $normalized)`,
      matching against `WaId` with defensive `+`-stripping.
- [x] On match: sets `session.user_id` + `session.linked_at`.
- [x] On no match: sends new bilingual (Swahili/English) "not
      recognized, please sign up" message — warm tone matching the
      Flask system prompt style, not literal translation. No such
      message existed before.
- [x] Found and fixed a silent secondary bug: `linked_at` was missing
      from `WhatsappSession::$fillable`, so every `update()` call
      silently dropped it (no error — Eloquent just ignores unfillable
      keys). Added to `$fillable` + `$casts`. Verified fix on a clean
      link: `linked_at` now correctly populates (`2026-07-02 10:20:35`
      confirmed).

**3. `handleUtakulaa()` sent a broken, incomplete Flask payload**
- [x] Was hand-building `{ingredients: [], pantry: [], ...}` with
      `// TODO` comments and no `user_id` — guaranteed 400 from Flask
      (missing ingredients, and missing user_id as of Item #3's new
      requirement). Now calls the existing, correct
      `UtakulaaService::getMealPlan($user, $budget)` instead, which
      already builds ingredients/pantry/medications/user_id properly.

**4. Message completion was never tracked**
- [x] Added `processed_at`/`status` updates ('processed' or 'failed')
      on `WhatsappMessage` at job completion — columns already existed
      in schema but were never written to.

**5. Error handling — Flask/AI failures should not trigger blind
retries**
- [x] `UtakulaaService` failures are now caught inside
      `handleUtakulaa()` specifically, returning a friendly fallback
      message immediately rather than letting the exception bubble up
      and trigger Laravel's default 3x job retry against a possibly-down
      or rate-limited backend. Outer catch still handles genuine infra
      failures.

**6. Discovered mid-testing, not part of original scope: outbound
sending was still 100% Meta Graph API**
- [x] `WhatsAppService.php` was calling `graph.facebook.com` with a
      bearer token — this token was stale/invalid (`401
      OAuthException: session invalid, user logged out`), meaning even
      once inbound worked, replies silently failed to send.
- [x] Rewritten entirely to use Twilio's Messages API (HTTP Basic Auth
      with Account SID/Auth Token, form-encoded body, `whatsapp:+`
      prefix on the `To` field). `config/services.php` already had the
      `twilio` block from earlier config work; wired it in here.

**7. Discovered mid-testing: no queue worker was running at all**
- [x] `QUEUE_CONNECTION=database` (set in an earlier session) requires
      an active worker to consume jobs. None was running — dispatched
      jobs (2 confirmed) sat in the `jobs` table indefinitely with zero
      errors or visible signal. This had likely been silently true
      since the async queue migration. See "Queue worker persistent
      process manager" above — this is now resolved with pm2 on this
      dev machine.

**Verification — genuine live end-to-end test, not just code review:**
- [x] Real WhatsApp message sent from phone → Twilio sandbox → ngrok →
      Laravel webhook → job queued → (worker started manually) → job
      processed → intent classified (`"help"` for "Hey" — correct,
      ambiguous input) → `WhatsAppService::sendText()` → Twilio API
      → real reply delivered and visible on phone
      (`message_sid: SM9eee5e1144f05681bf30bc2aa0d4f749` confirmed in
      log, no errors)
- [x] `WhatsappMessage.status` confirmed `'processed'` in DB for both
      test messages
- [x] `WhatsappSession.linked_at` confirmed populated on a clean link
- [x] **`utakulaa` intent path confirmed (2026-07-02, follow-up test):**
      three separate messages sent — "Nataka kula" (Swahili), "What
      should I eat" (English), "Rice beans" (bare ingredients) — all
      correctly classified as `utakulaa` intent, all routed through
      `UtakulaaService::getMealPlan()`, all received real replies via
      Twilio (291–407 char responses, message_sids confirmed in log, no
      errors). `jobs` table confirmed `0` after the batch — no backlog.
      This scope is now fully closed, including this previously
      untested path.

**Manual data step completed:** `amina@maisha.test` wa_number populated
(`254746604602`) for testing.
**Still outstanding, not blocking:** `james@maisha.test` and other test
users still have `wa_number=NULL`; populate as needed for further
testing.

### ✅ Flask Rate-Limit Enforcement (Resolved — 2026-07-02)
- [x] Built `engine/flask_response_cache.py` — in-memory per-user
      tracker, thread-safe, checks both
      `MAX_UTAKULAA_PER_USER_PER_HOUR` (3) and
      `MAX_UTAKULAA_PER_USER_PER_DAY` (10)
- [x] Wired into `resources/utakulaa.py` — returns 429 with `reason`
      and `retry_after_seconds` when exceeded
- [x] `user_id` made a required field on `POST /api/utakulaa` —
      already sent by Laravel's `UtakulaaService`
      (`'user_id' => $user->id`), so no Laravel changes were needed
- [x] Manually verified via curl: 3× HTTP 200, 4th request → HTTP 429
      `hourly_limit_exceeded`, `retry_after_seconds: 3585`
- [x] Full test suite re-run: 204/206 passing, **zero regressions**
      from this change
- **Known limitation:** in-memory, single-process only — resets on
  Flask restart, and will not work correctly if ever scaled to
  multiple workers or behind Redis. Acceptable for current dev/demo
  scale; revisit before any production deploy with >1 worker.

### ✅ Laravel Middleware + Scheduler Wiring (Completed)
- [x] Built `ApiRequestLogging.php` — request ID, metadata logging
      (method/path/user_id/status), security headers. Metadata only,
      no response body restructuring.
- [x] Registered as named middleware `api.logging` in
      `bootstrap/app.php` — applied to specific routes only, not
      global
- [x] Built `GenerateDailyMealPlans.php` — `--test-user=<id>` flag
      (required), uses `daily_budget_kes`, respects
      `MAX_UTAKULAA_PER_USER_PER_DAY`, logs failures via
      `Log::error()`, no retries against rate-limited backend
- [x] Scheduler entry added to `routes/console.php`, daily 06:00
      Africa/Nairobi — **registered but NOT YET ACTIVE**, awaiting
      Item #7 (frontend audit) — the last remaining blocker
- [x] `SCHEDULER_SETUP.md` created documenting manual test +
      activation criteria
- [x] Manual test passed: `php artisan maisha:generate-daily-meal-plans
      --test-user=221` → "✓ Generated meal plan for user 221"
      (samuel@maisha.test, budget 300 KES)

### ✅ Flask Provider Config Cleanup (Completed)
- [x] Confirmed `gemini.py` / `grok.py` are genuinely unused (zero
      references in codebase)
- [x] Removed unused config vars from `config.py`
      (`EXPLAIN_PROVIDER`, `HEALTH_PROVIDER`, `INTENT_PROVIDER`,
      `FALLBACK_PROVIDER`)
- [x] Added clarifying comment block to `router.py` explaining
      hardcoded Claude→OpenAI→fallback chain by design
- [x] Deleted unused provider stubs (`gemini.py`, `grok.py`)

### ✅ WhatsApp async queue (was: sync processing blocking webhook)
- `QUEUE_CONNECTION` changed `sync` → `database` in `.env`
- Supervisor config added: `backend/maisha-api/config/supervisor-maisha-queue.conf`
- Systemd service added: `backend/maisha-api/config/systemd-maisha-queue.service`
- Documented in `QUEUE_WORKER_SETUP.md`
- **Note (2026-07-02):** these configs were never activated as-written
  on this dev machine — see "Queue worker persistent process manager"
  above, now resolved via pm2 for local dev. The queue driver switch
  itself is correct and working; the process manager to consume it
  wasn't running until this session.

### ✅ Schema Consolidation — Phase 1 (deprecated fields made nullable)
- Migration: `2026_06_30_150000_make_deprecated_fields_nullable.php`
  - `User.activity_level` → nullable (superseded by
    `UserActivityProfile`)
  - `User.meals_per_day` → nullable (superseded by
    `UserMealPattern.meals_per_day`)
  - `HealthProfile.medications` → nullable (superseded by
    `UserMedication` table)
- Deprecation comments added to `User.php`, `HealthProfile.php`,
  `UserGoal.php`
- Note: this is Phase 1 only — Phase 2 (deciding source of truth and
  removing dead fields/tables) is now also resolved, see above

---

## Smaller known issues (non-blocking)

- `testing/maisha_test.sh` reads `MAISHA_INTERNAL_SECRET` from the
  shell environment with a silent placeholder fallback
  (`${MAISHA_INTERNAL_SECRET:-test-secret-change-me}`) rather than
  reading `.env` directly — must remember to `export
  MAISHA_INTERNAL_SECRET=<value from backend/maisha-api/.env>` before
  every run, or internal-token-protected endpoints silently 403 while
  others pass, producing a confusing partial-failure pattern. Not yet
  hardened to read `.env` directly — worth doing so this can't
  silently recur.
- `whatsapp_messages` has no `user_id` column — can't link messages to
  users without parsing payload (partially mitigated:
  `WhatsappSession.user_id` now works, but the message itself still
  isn't directly linked)
- `WhatsappMessage` model has no `belongsTo User` relationship
- `MealSuggestion.channel` field is premature abstraction (WhatsApp
  channel usage still forming)
- No schema validation on the WhatsApp webhook payload
- No API documentation exists anywhere in the project
- `openai_provider.py` exists but the package is commented out in
  `requirements.txt`
- Brenda's "Complete without goals → 422" test intermittently returns
  401 instead — likely token invalidation somewhere in her ~40-request
  test block, not yet isolated. Not related to rate-limiting work.
  Investigate if it recurs across multiple runs.
- WhatsApp `verify()` method (Meta hub-challenge) and Meta
  JSON-parsing fallback in `ProcessIncomingWhatsAppMessage` are now
  dead code paths in practice (Meta permanently blocked) — kept
  dormant rather than deleted in case Meta ever becomes viable. Low
  priority cleanup candidate if it ever causes confusion.
- Local dev machine is WSL1 and cannot run systemd or a real init
  system (confirmed, WSL2 upgrade currently blocked at the OS level)
  — `pm2` is used as the queue worker process manager here instead of
  the Supervisor/Systemd configs in `config/`. Those configs are
  correct for production but untested as-written on this machine.
  Anyone continuing this project on a different dev machine should
  check whether that machine can run systemd before assuming the pm2
  approach is still necessary.
- `pm2` worker (`maisha-queue`) showed 3 restarts during the 2026-07-02
  session — not yet investigated; likely the configured
  `--max-jobs=1000 --max-time=3600` self-recycle rather than a crash,
  but worth confirming via `pm2 logs maisha-queue --err` next session.
- Host machine ran at ~94% RAM usage during part of the 2026-07-02
  session — not confirmed to have caused any issue, but noted as a
  possible source of slow webhook responses (which is what triggers
  Twilio retries) if similar anomalies show up again.

---

## What's confirmed solid (no need to re-verify)

- Claude integration in ai-engine — active, working, defensive JSON
  parsing
- Laravel auth flow end-to-end — Sanctum, Google OAuth, password reset
- The 5-filter `utakulaa_algorithm.py` pipeline logic
- Health-condition classification intentionally has no OpenAI fallback
  (Claude-only, by design — "hallucinated tags are worse than no
  tags")
- Async queue infrastructure — driver + job dispatch logic, now with a
  verified persistent worker process (pm2, this dev machine — see
  Resolved section)
- Flask per-user rate limiting on `/api/utakulaa` (see Resolved —
  Flask Rate-Limit Enforcement)
- WhatsApp inbound-to-outbound round trip via Twilio, real device
  verified (see Resolved — WhatsApp inbound flow)
- Twilio webhook signature validation — forged requests rejected,
  genuine traffic passes (see Resolved — Twilio webhook signature
  validation)
- WhatsApp webhook rate limiting — global + per-sender throttle,
  load-tested and verified not to be inflated by duplicate Twilio
  delivery under normal conditions (see Resolved — WhatsApp webhook
  rate limiting)
- Schema source-of-truth for Budget, Goals, Medications, Hydration, and
  the dead `UserHealthProfile` table — all decided and confirmed via
  row counts + code grep, not assumption (see Resolved — Schema
  Consolidation Phase 2)

---

## Test Suite State (`testing/`)

**Baseline: 205/206 passing** (as of 2026-07-03 session, post Schema
Consolidation Phase 2 — full suite re-run after every sub-step).

- 1 failed: Brenda "Complete without goals → 422" — got 401 instead
  (pre-existing, unrelated; logged above)
- 1 skipped: known rate-limit quota-exhaustion pattern
  (`throttle:auth` 12-request test doesn't trigger)

**Note:** the test suite does not cover any WhatsApp inbound/outbound
flow — all WhatsApp verification (inbound flow, signature validation,
rate limiting, isolation test) was manual against a live Twilio
sandbox, not automated. Worth considering a lightweight automated
check in future (even just hitting the webhook route with a synthetic
Twilio payload and asserting a 200 + job dispatch) so this doesn't
silently regress again the way it apparently already had.

Previously fixed:
- Non-idempotent registration → timestamp-suffixed emails
- Fragile token acquisition → retry loop with explicit validation in
  `register_and_login`
- Unreliable response parsing → Python-based JSON parsing for
  `MED_ID`
- Aggressive rate limiting on auth endpoints → adjusted in
  `RouteServiceProvider`

---

## Changelog

- **2026-07-03** — Schema Consolidation Phase 2 fully closed, all five
  sub-items resolved. `UserHealthProfile` confirmed dead (0 rows) and
  deleted along with its one-off merge command. `HealthProfile.medications`
  confirmed unused, stripped from fillable/casts (column left nullable);
  `UserMedication` remains sole source of truth. Goals sub-item turned up
  a real bug, not just dead code: `UserGoal`/`GoalController` is live and
  writable, but writes weren't syncing to `User.primary_goals`, silently
  hiding one user's (Grace Njeri, id 218) goal from the dashboard and meal
  generation — fixed `GoalController::update` to sync on every write and
  backfilled the affected row. Hydration: `WaterLog`/`HydrationLog`
  confirmed fully dead (0 rows, `HydrationLog` had zero references
  anywhere), both dropped along with the `waterLogs()` relation;
  `WaterDailySummary` is sole source of truth. Budget confirmed already
  correct as spread across purposes — no changes needed. Also found (not
  originally in scope): `testing/maisha_test.sh` silently falls back to a
  placeholder internal-token secret if `MAISHA_INTERNAL_SECRET` isn't
  exported first, causing a confusing partial 403 pattern — documented,
  not yet hardened. Full suite re-run after each sub-step, final result
  205/206 (same single pre-existing flake, no new failures). Item #7
  (frontend architecture audit) is now the sole remaining open item.
- **2026-07-02** — WhatsApp webhook rate limiting (global + per-sender
  throttle) completed and verified under real load. Sent 14 rapid
  messages via live Twilio sandbox: log confirmed 10 consecutive
  per-sender throttle rejections once the 5/min cap hit, phone
  confirmed only 1 of 14 replies received (silent-200 working
  end-to-end, not just in logs). Recovery verified ~8.5 min later —
  next message processed and replied normally, confirming the window
  resets rather than sticking. Ran a follow-up isolation test
  (temporarily raised limit, 3 known real taps ~10s apart) to rule out
  Twilio duplicate delivery as a hidden cause of over-counting: result
  was a clean 3 taps → 3 distinct MessageSids, no duplicates — confirms
  the limiter's per-sender keying is correct and Twilio isn't
  reflexively double-delivering under normal conditions. Limit reverted
  to production values (5/2 + 30/10) and confirmed via `config:clear`.
  This closes the entire WhatsApp workstream for this session: inbound
  build, signature validation, persistent queue worker (pm2), and now
  rate limiting — all load-tested on real device traffic, not just
  reviewed in a diff.
- **2026-07-02** — Twilio webhook signature validation built and
  verified (prerequisite work for rate limiting, done ahead of rate
  limiting itself). Added `twilio/sdk`, built `VerifyTwilioSignature`
  middleware, added `trustProxies` config needed for correct URL
  validation behind ngrok. Verified real Twilio traffic still passes
  and forged requests get `403`. Along the way: Twilio Auth Token was
  inadvertently exposed in a chat session and rotated as a precaution;
  rotation surfaced a real bug where `config:clear` alone doesn't
  refresh an already-running server process's in-memory config —
  needs a full restart.
- **2026-07-02** — `utakulaa` intent path over WhatsApp confirmed
  working end-to-end — the last untested piece of the WhatsApp inbound
  flow work. Sent three test messages ("Nataka kula", "What should I
  eat", "Rice beans"); all classified correctly, all got real replies
  via Twilio, `jobs` table confirmed drained to `0` afterward.
- **2026-07-02** — Queue worker persistent process manager resolved on
  local dev machine. Original Supervisor/Systemd configs unusable
  as-is (written for production `www-data`/`APP_ENV=production`; dev
  machine is WSL1, which has no real init system — `systemctl`
  confirmed non-functional, WSL2 upgrade blocked at OS level).
  Switched to `pm2` instead: installed via `npm install -g pm2`
  (without `sudo`, after diagnosing that `sudo` was resolving to a
  broken system `node` binary instead of the nvm-managed one). Started
  `maisha-queue` under pm2, verified it immediately drained a real
  backlogged job, confirmed via `jobs` table count and
  `whatsapp_messages` status, passed a crash-kill auto-restart test,
  and passed a full `pm2 kill` + new-shell recovery test via a
  `.bashrc` hook (`pm2 resurrect`). Known limitation: recovery only
  triggers on new shell open, not on full Windows reboot alone (WSL1
  has no boot-time hook) — acceptable for dev, must switch to real
  Supervisor/Systemd before production.
- **2026-07-02** — WhatsApp inbound flow fully built and verified
  end-to-end via live Twilio sandbox test. Was NOT actually functional
  despite roadmap description ("verify only") — same discovery pattern
  as earlier middleware work. Found and fixed four independent breaks:
  (1) inbound parsing was Meta-only, no Twilio support; (2) user
  linking was fully stubbed, plus a silent `$fillable` bug dropping
  `linked_at`; (3) `handleUtakulaa()` sent a broken payload with TODOs
  instead of using `UtakulaaService`; (4) outbound `WhatsAppService`
  was still 100% Meta Graph API with a stale token, so even correct
  inbound processing failed silently on reply; (5) no queue worker was
  running at all, so jobs queued indefinitely (see queue worker entry
  above for resolution).
- **2026-07-02** — Flask Rate-Limit Enforcement completed and
  verified. Built `engine/flask_response_cache.py`, wired into
  `resources/utakulaa.py` with 429 + `retry_after_seconds`.
  Curl-verified. Full suite re-run: 204/206, zero regressions.
- **2026-07-01** — Laravel Middleware + Scheduler Wiring verified
  complete. Manual test passed for `--test-user=221`. Scheduler
  remains inactive pending Item #7.
- **2026-06-30** — WhatsApp async queue completed (sync→database,
  Supervisor/Systemd config, docs — later found inactive, see
  2026-07-02 entries). Schema Consolidation Phase 1 completed.
- *(add a new entry here every time an item is closed or a new one is
  found)*