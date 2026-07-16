# Vitals Capture — Step-by-Step Implementation Guide

This turns the planning doc and technical spec into an actual build order. It follows your existing workflow: you brief Cline in Plan mode with a scoped task, review the plan, approve, Cline executes in Act mode, you verify manually before moving to the next phase. Each phase below is sized to be one Plan→Act→Verify cycle — don't skip ahead to the next phase until the current one is verified for real (curl/DB check), not just narrated as done.

**Do not attempt this as one giant task.** Feed Cline one phase at a time.

---

## Before you start

Save the two reference docs at the project root so Cline can read them as context:

```bash
cd ~/Development/code/Wu-Tang/flask/January/maisha
# place GUIDED_VITALS_MEDICATION_CAPTURE.md and VITALS_CAPTURE_TECHNICAL_SPEC.md here
```

When you brief Cline for Phase 1 below, tell it to read both files first — the same way you already point it at `PROJECT_STATE.md` and `AI_GUIDELINES.md`.

---

## Phase 1 — Database foundation (no behavior yet, just the tables)

**What you're building:** the three migrations from the technical spec, nothing else. No controller logic, no WhatsApp changes. This phase exists purely so every later phase has something to write to.

**Brief to give Cline (Plan mode):**
> Read GUIDED_VITALS_MEDICATION_CAPTURE.md and VITALS_CAPTURE_TECHNICAL_SPEC.md for context. Create three Laravel migrations exactly as specified in VITALS_CAPTURE_TECHNICAL_SPEC.md §1: `vitals_readings`, `whatsapp_conversation_states`, `medication_extraction_reviews`. Also create the corresponding Eloquent models with `$fillable` and `$casts` set correctly (this app has had silent `$fillable` bugs before — WhatsappSession.user_id was a documented incident — so double check this specifically). Do not touch any controller or routing code in this pass.

**Verify yourself before moving on:**
```bash
php artisan migrate
php artisan tinker
>>> \App\Models\VitalsReading::create(['user_id' => 1, 'type' => 'bp', 'systolic' => 120, 'diastolic' => 80, 'recorded_at' => now()]);
>>> \App\Models\VitalsReading::first();
```
Confirm the row actually saved with the right fields — this is exactly the kind of `$fillable` check that's bitten this project before.

---

## Phase 2 — BP capture state machine (text-only, no WhatsApp yet)

**What you're building:** the flow logic from Technical Spec §2, but tested directly via a controller/route or tinker — not yet wired to Twilio. This isolates "does the state machine logic work" from "does Twilio deliver messages correctly," so a bug doesn't hide in the wrong layer.

**Brief to give Cline:**
> Implement the BP capture state machine described in VITALS_CAPTURE_TECHNICAL_SPEC.md §2 — the `continueBpCapture` logic, `tryParseBpPair`, `extractPlausibleNumber`, `isPlausible` with the ranges in §2.3, and the outlier confirmation flow. Put this in a dedicated class (e.g. `app/Services/BpCaptureFlow.php`) rather than directly in the Twilio controller, so it can be unit tested without any WhatsApp involvement. Write it so it takes a `WhatsappConversationState` and a string input, returns a reply string, and handles all edge cases from GUIDED_VITALS_MEDICATION_CAPTURE.md §2.3 (non-numeric input, outlier values, "120/80" shorthand, SKIP). Write PHPUnit tests covering each edge case in that table.

**Verify yourself:**
```bash
php artisan test --filter=BpCaptureFlow
```
Read the actual test output — don't just accept "tests pass," check the test names correspond to real edge cases (non-numeric, outlier, shorthand, skip) and not just the happy path.

---

## Phase 3 — Wire it into Twilio

**What you're building:** the actual `handleIncoming` routing logic from Technical Spec §2.1-2.2, connecting real WhatsApp messages to the Phase 2 service class.

**Brief to give Cline:**
> Wire BpCaptureFlow into TwilioWhatsAppController's inbound handler, following the state-check logic in VITALS_CAPTURE_TECHNICAL_SPEC.md §2.1-2.2. A message with no active WhatsappConversationState should fall through to the existing intent router unchanged — this must not break any existing WhatsApp functionality (utakulaa requests, medication reminders, etc.). An active state should route to continueFlow(). Expired states (expires_at in the past) should be deleted and treated as fresh, not resumed.

**Verify yourself, in this order:**

1. **Confirm nothing existing broke first** — run the full suite:
   ```bash
   bash testing/maisha_test.sh
   ```
   Expect 205/206 (your known baseline) — if this drops, something in the routing change broke an existing WhatsApp path. Don't proceed until this is clean.

2. **Then test the new flow live**, using a real test WhatsApp number if you have the Twilio sandbox set up, or by simulating the webhook payload directly with curl if not:
   ```bash
   curl -X POST http://localhost:8000/api/whatsapp/webhook \
     -d "From=whatsapp:+254700000000" \
     -d "Body=start bp"
   ```
   (adjust the trigger phrase to whatever intent starts the flow) then follow up with a second curl sending "120", then "80", and confirm a `vitals_readings` row appears.

---

## Phase 4 — Sugar capture (copy of Phase 2-3, faster this time)

Same pattern as BP but single-number, so this phase should be quick — mostly reusing Phase 2's plausibility-check and non-numeric-input logic, not rebuilding it.

**Brief to give Cline:**
> Implement sugar capture following the same pattern as BpCaptureFlow, but single-step (no systolic/diastolic split). Reuse extractPlausibleNumber and the outlier-confirmation pattern from BpCaptureFlow rather than duplicating it — consider extracting a shared base class if the duplication becomes obvious.

**Verify:** same two-step process as Phase 3 (full suite first, then live flow test).

---

## Phase 5 — Feature B: photo receipt + Claude vision extraction

This is the bigger, riskier phase — don't start it until Phases 1-4 are shipped and you've had real users (even just yourself, a few days) actually using the BP/sugar flow without issues.

**Brief to give Cline (split into two sub-phases — do NOT combine):**

**5a — media receipt and Claude extraction, no confirmation UX yet:**
> Add media detection (NumMedia > 0) to the Twilio webhook handler. On receipt, download the image from Twilio's MediaUrl using Basic Auth with Twilio credentials (flagged as an easy-to-miss detail in VITALS_CAPTURE_TECHNICAL_SPEC.md §3.3/4.4), downscale it server-side (cap longer edge ~1200-1500px), then send to Claude vision with a tightly-scoped extraction prompt (name, dosage, frequency, timing only). Implement prompt caching on the system prompt per §3.2. Save the raw extraction result to medication_extraction_reviews with status='pending'. Do NOT write to UserMedication in this sub-phase. Do NOT send any reply to the user summarizing what was found — just log/save it for now, so we can inspect extraction quality before building the user-facing confirmation flow.

**Verify 5a:** send yourself a few real (or realistic sample) prescription photos via WhatsApp, then check `medication_extraction_reviews` directly in the DB:
```bash
php artisan tinker
>>> \App\Models\MedicationExtractionReview::latest()->first();
```
Manually judge extraction accuracy across several real photos before writing a single line of confirmation-flow code. If accuracy is poor, that's a prompt/model problem to fix here — don't paper over it with a fancier confirmation UX.

**5b — confirmation flow (only after 5a's extraction quality looks solid):**
> Add the YES/NO confirmation reply after extraction, and the tappable name/dosage/timing correction flow for NO, per GUIDED_VITALS_MEDICATION_CAPTURE.md §3.3. On YES, promote the medication_extraction_reviews row into UserMedication and mark status='confirmed'. Add the Feature-B-specific Redis rate limiter per VITALS_CAPTURE_TECHNICAL_SPEC.md §3.6, following the exact check_and_record() pattern already in engine/flask_response_cache.py — but keep in mind this rate limiter needs to live wherever the vision call actually happens (Flask, if you're routing the vision call through ai-engine rather than calling Claude directly from Laravel — confirm which side owns this call before implementing the limiter).

**Verify 5b:** full manual walkthrough — send a photo, confirm YES flow writes to `UserMedication` correctly, confirm NO flow lets you correct one field without re-doing the whole thing, then run the full test suite once more to confirm no regressions.

---

## Phase 6 — Dashboard surfacing (optional, separate from the WhatsApp work)

Only after Phases 1-5 are stable. Wire `vitals_readings` into Dashboard.tsx / Medical.tsx. This is standard frontend work, not part of the WhatsApp complexity, and can be handed to Cline as its own isolated task whenever you're ready — it doesn't block or get blocked by anything above.

---

## General rules across every phase (worth pinning to your Cline prompt each time)

1. **One phase per Cline session.** Don't let it combine phases even if it offers to "save time."
2. **Always run the full `maisha_test.sh` suite after wiring anything into TwilioWhatsAppController** — that controller is shared infrastructure; a regression there is invisible until you check.
3. **Never trust a "✅ complete" summary without running the verification command yourself** — today's session had multiple cases where Cline's own narration didn't match reality (the multi-worker test that returned all-200s and was still called a pass). Same discipline applies here.
4. **For Phase 5 specifically**, resist the urge to build the confirmation UX before checking raw extraction quality. That's the step most likely to reveal you need prompt or model changes — better to find that before more code is built on top of it.