#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# MAISHA DAYS 6-9 IMPLEMENTATION AUDIT
# Run from: ~/Development/code/Wu-Tang/flask/January/maisha/backend/maisha-api
# ═══════════════════════════════════════════════════════════════════

LARAVEL="$HOME/Development/code/Wu-Tang/flask/January/maisha/backend/maisha-api"
FLASK="$HOME/Development/code/Wu-Tang/flask/January/maisha/ai-engine"
FRONTEND="$HOME/Development/code/Wu-Tang/flask/January/maisha/frontend/maisha-foundation-main/src"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  MAISHA DAYS 6-9 IMPLEMENTATION AUDIT"
echo "═══════════════════════════════════════════════════════════"

# ─── SECTION 1: LARAVEL BACKEND ─────────────────────────────────
echo ""
echo "── SECTION 1: LARAVEL BACKEND ──────────────────────────────"

echo ""
echo "[1.1] User.medications() relation"
grep -n "function medications" "$LARAVEL/app/Models/User.php" \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING"

echo ""
echo "[1.2] PanelSetupController exists"
ls "$LARAVEL/app/Http/Controllers/PanelSetupController.php" 2>/dev/null \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING"

echo ""
echo "[1.3] PanelSetupController — setupState method"
grep -n "function setupState\|classification_pending\|dietReady\|budgetReady\|medicineReady\|medication_count" \
  "$LARAVEL/app/Http/Controllers/PanelSetupController.php" 2>/dev/null \
  || echo "  ✗ METHOD BODY MISSING OR FILE NOT FOUND"

echo ""
echo "[1.4] setup-state route registered"
cd "$LARAVEL" && php artisan route:list --path=dashboard 2>/dev/null \
  || echo "  ✗ ROUTE NOT FOUND"

echo ""
echo "[1.5] UtakulaaService sends estimated_cost_kes to Flask"
grep -n "estimated_cost_kes" "$LARAVEL/app/Services/UtakulaaService.php" \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 9 collision will silently never fire"

echo ""
echo "[1.6] User model — onboarding_step + budget_is_custom in casts"
grep -n "onboarding_step\|budget_is_custom\|income_pattern" \
  "$LARAVEL/app/Models/User.php" \
  || echo "  ✗ MISSING CASTS"

echo ""
echo "[1.7] HealthProfile model — Day 4 columns in fillable + casts"
grep -n "mapped_condition_tags\|has_unmapped_condition\|condition_classification_status" \
  "$LARAVEL/app/Models/HealthProfile.php" \
  || echo "  ✗ MISSING — Day 4 columns not in model"

echo ""
echo "[1.8] ClassifyHealthConditionText job exists"
ls "$LARAVEL/app/Jobs/ClassifyHealthConditionText.php" 2>/dev/null \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 4 classification job"

echo ""
echo "[1.9] MedicationDefaultsService — all three methods present"
grep -n "function inferMealPeriods\|function inferTimes\|function requiresFood" \
  "$LARAVEL/app/Services/MedicationDefaultsService.php" \
  || echo "  ✗ MISSING METHODS"

echo ""
echo "[1.10] OnboardingController — step2 has Day 4+5 fields"
grep -n "other_condition_text\|medications\.\*\.name\|ClassifyHealthConditionText\|added_during_onboarding\|medDefaults" \
  "$LARAVEL/app/Http/Controllers/OnboardingController.php" \
  || echo "  ✗ MISSING — Day 4/5 step2 not fully implemented"

echo ""
echo "[1.11] OnboardingController — progress() method exists (Day 1)"
grep -n "function progress\|resume_at_step" \
  "$LARAVEL/app/Http/Controllers/OnboardingController.php" \
  || echo "  ✗ MISSING — Day 1 resume endpoint"

echo ""
echo "[1.12] OnboardingController — complete() uses is_null guard (bonus flag)"
grep -n "is_null.*daily_budget_kes\|daily_budget_kes.*is_null" \
  "$LARAVEL/app/Http/Controllers/OnboardingController.php" \
  || echo "  ✗ MISSING — still uses !user->daily_budget_kes (zero-budget bug)"

echo ""
echo "[1.13] MedicationController — estimated_cost_kes in validation + create"
grep -n "estimated_cost_kes" \
  "$LARAVEL/app/Http/Controllers/MedicationController.php" \
  || echo "  ✗ MISSING — Day 5 cost field"

echo ""
echo "[1.14] UserMedication model — Day 5 fields in fillable + casts"
grep -n "estimated_cost_kes\|added_during_onboarding" \
  "$LARAVEL/app/Models/UserMedication.php" \
  || echo "  ✗ MISSING"

echo ""
echo "[1.15] Database — confirm all migrations ran"
cd "$LARAVEL" && mysql -u root -p maisha -e "
SELECT migration FROM migrations
WHERE migration LIKE '%onboarding%'
   OR migration LIKE '%unmapped%'
   OR migration LIKE '%estimated_cost%'
ORDER BY id DESC;" 2>/dev/null \
  || echo "  ✗ Could not query migrations — check DB credentials"

echo ""
echo "[1.16] Database — users table has Day 1-2 columns"
cd "$LARAVEL" && mysql -u root -p maisha -e "
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA='maisha' AND TABLE_NAME='users'
  AND COLUMN_NAME IN ('onboarding_step','income_pattern','budget_range','budget_is_custom')
ORDER BY COLUMN_NAME;" 2>/dev/null \
  || echo "  ✗ DB check failed"

echo ""
echo "[1.17] Database — health_profiles has Day 4 columns"
cd "$LARAVEL" && mysql -u root -p maisha -e "
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA='maisha' AND TABLE_NAME='health_profiles'
  AND COLUMN_NAME IN ('mapped_condition_tags','has_unmapped_condition','condition_classification_status')
ORDER BY COLUMN_NAME;" 2>/dev/null \
  || echo "  ✗ DB check failed"

echo ""
echo "[1.18] Database — user_medications has Day 5 columns"
cd "$LARAVEL" && mysql -u root -p maisha -e "
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA='maisha' AND TABLE_NAME='user_medications'
  AND COLUMN_NAME IN ('estimated_cost_kes','added_during_onboarding')
ORDER BY COLUMN_NAME;" 2>/dev/null \
  || echo "  ✗ DB check failed"

echo ""
echo "[1.19] All onboarding routes registered"
cd "$LARAVEL" && php artisan route:list --path=onboarding 2>/dev/null \
  || echo "  ✗ Could not list routes"

echo ""
echo "[1.20] Config — Flask URL and internal secret resolvable"
cd "$LARAVEL" && php artisan tinker --execute="
echo 'flask.url: ' . config('services.flask.url') . PHP_EOL;
echo 'flask.secret: ' . (config('services.flask.secret') ? 'SET' : 'MISSING') . PHP_EOL;
echo 'maisha.internal_secret: ' . (config('services.maisha.internal_secret') ? 'SET' : 'MISSING') . PHP_EOL;
" 2>/dev/null || echo "  ✗ Tinker failed"

# ─── SECTION 2: FLASK AI ENGINE ─────────────────────────────────
echo ""
echo "── SECTION 2: FLASK AI ENGINE ──────────────────────────────"

echo ""
echo "[2.1] portion_engine.py exists"
ls "$FLASK/engine/portion_engine.py" 2>/dev/null \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 8"

echo ""
echo "[2.2] portion_engine — CATEGORY_PORTION_GRAMS and describe_portion"
grep -n "CATEGORY_PORTION_GRAMS\|def describe_portion\|cupped hand\|palm\|fist\|thumb" \
  "$FLASK/engine/portion_engine.py" 2>/dev/null \
  || echo "  ✗ MISSING CONTENT"

echo ""
echo "[2.3] utakulaa_algorithm.py — imports portion_engine"
grep -n "from engine.portion_engine\|import describe_portion\|CATEGORY_PORTION_GRAMS_BASIS" \
  "$FLASK/engine/utakulaa_algorithm.py" \
  || echo "  ✗ MISSING — portion_engine not imported into algorithm"

echo ""
echo "[2.4] utakulaa_algorithm.py — portion_label in options.append"
grep -n "portion_label" "$FLASK/engine/utakulaa_algorithm.py" \
  && echo "  ✓ WIRED INTO BUILD" || echo "  ✗ MISSING — Day 8 not wired"

echo ""
echo "[2.5] utakulaa_algorithm.py — pantry_only_mode (Day 9 zero-budget)"
grep -n "pantry_only_mode\|budget <= 0\|budget_remaining_kes.*0" \
  "$FLASK/engine/utakulaa_algorithm.py" \
  || echo "  ✗ MISSING — Day 9 zero-budget fallback not implemented"

echo ""
echo "[2.6] utakulaa_algorithm.py — _check_medication_budget_collision function"
grep -n "def _check_medication_budget_collision\|medication_budget_warnings\|estimated_cost_kes" \
  "$FLASK/engine/utakulaa_algorithm.py" \
  || echo "  ✗ MISSING — Day 9 collision function not implemented"

echo ""
echo "[2.7] utakulaa_algorithm.py — collision result in return dict"
grep -n "medication_budget_warnings" "$FLASK/engine/utakulaa_algorithm.py" \
  | grep -v "def \|#" \
  || echo "  ✗ MISSING — medication_budget_warnings not in return dict"

echo ""
echo "[2.8] classify_condition resource exists"
ls "$FLASK/resources/classify_condition.py" 2>/dev/null \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 4 Flask endpoint"

echo ""
echo "[2.9] classify_health_condition in claude_provider.py"
grep -n "def classify_health_condition" \
  "$FLASK/providers/claude_provider.py" \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 4 Claude function"

echo ""
echo "[2.10] classify_health_condition in router.py"
grep -n "def classify_health_condition\|classify_health_condition" \
  "$FLASK/providers/router.py" \
  || echo "  ✗ MISSING — Day 4 router function"

echo ""
echo "[2.11] app.py — ClassifyConditionResource registered"
grep -n "ClassifyConditionResource\|classify-condition" \
  "$FLASK/app.py" \
  || echo "  ✗ MISSING — /api/classify-condition route not in app.py"

echo ""
echo "[2.12] app.py — all resources registered"
grep -n "add_resource" "$FLASK/app.py"

echo ""
echo "[2.13] portion_engine Python syntax check"
cd "$FLASK" && python3 -c "from engine.portion_engine import describe_portion; \
  result = describe_portion({'category':'staple'}, 120); \
  print('  portion_label test (staple 120g):', result)" 2>/dev/null \
  || echo "  ✗ IMPORT FAILED — syntax error or missing file"

echo ""
echo "[2.14] utakulaa_algorithm Python import check"
cd "$FLASK" && python3 -c "from engine.utakulaa_algorithm import run_utakulaa; \
  print('  ✓ run_utakulaa imports cleanly')" 2>/dev/null \
  || echo "  ✗ IMPORT FAILED — check for syntax errors"

echo ""
echo "[2.15] All engine files present"
ls "$FLASK/engine/"

# ─── SECTION 3: FRONTEND ────────────────────────────────────────
echo ""
echo "── SECTION 3: FRONTEND ─────────────────────────────────────"

echo ""
echo "[3.1] usePanelSetup hook exists"
ls "$FRONTEND/hooks/usePanelSetup.ts" 2>/dev/null \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 7"

echo ""
echo "[3.2] usePanelSetup — PanelState interface + hook body"
grep -n "PanelState\|SetupStateResponse\|setup-state\|usePanelSetup" \
  "$FRONTEND/hooks/usePanelSetup.ts" 2>/dev/null \
  || echo "  ✗ MISSING CONTENT"

echo ""
echo "[3.3] PanelOnboarding component exists"
ls "$FRONTEND/components/dashboard/PanelOnboarding.tsx" 2>/dev/null \
  && echo "  ✓ PRESENT" || echo "  ✗ MISSING — Day 7"

echo ""
echo "[3.4] PanelOnboarding — renders CTA button and navigate"
grep -n "navigate\|ctaPath\|ctaLabel\|ChevronRight" \
  "$FRONTEND/components/dashboard/PanelOnboarding.tsx" 2>/dev/null \
  || echo "  ✗ MISSING CONTENT"

echo ""
echo "[3.5] Dashboard — imports usePanelSetup and PanelOnboarding"
grep -n "usePanelSetup\|PanelOnboarding" \
  "$FRONTEND/pages/Dashboard.tsx" \
  || echo "  ✗ MISSING — Day 7 hooks not imported into Dashboard"

echo ""
echo "[3.6] Dashboard — hook calls for all 3 panels"
grep -n "usePanelSetup('diet')\|usePanelSetup('medicine')\|usePanelSetup('budget')" \
  "$FRONTEND/pages/Dashboard.tsx" \
  || echo "  ✗ MISSING — panel hooks not called in Dashboard"

echo ""
echo "[3.7] Dashboard — classification_pending notice in JSX"
grep -n "classification_pending\|Still reviewing\|processing_health" \
  "$FRONTEND/pages/Dashboard.tsx" \
  || echo "  ✗ MISSING — Day 6 pending state not handled in UI"

echo ""
echo "[3.8] api.ts — setupStateApi defined"
grep -n "setupStateApi\|setup-state" \
  "$FRONTEND/lib/api.ts" \
  || echo "  ✗ MISSING — setupStateApi not in api.ts"

echo ""
echo "[3.9] api.ts — medication_budget_warnings in UtakulaaResult"
grep -n "medication_budget_warnings" \
  "$FRONTEND/lib/api.ts" \
  || echo "  ✗ MISSING — Day 9 type not added to UtakulaaResult"

echo ""
echo "[3.10] api.ts — onboardingApi.progress() exists (Day 1)"
grep -n "progress.*GET.*onboarding\|onboarding.*progress\|resume_at_step" \
  "$FRONTEND/lib/api.ts" \
  || echo "  ✗ MISSING — Day 1 progress endpoint"

echo ""
echo "[3.11] api.ts — step2 accepts medications array (Day 5)"
grep -n "medications.*food_condition\|step2.*medications\|other_condition_text" \
  "$FRONTEND/lib/api.ts" \
  || echo "  ✗ MISSING — Day 5 step2 type not updated"

echo ""
echo "[3.12] api.ts — step3 accepts income_pattern + custom_amount (Day 2+3)"
grep -n "income_pattern\|custom_amount" \
  "$FRONTEND/lib/api.ts" \
  || echo "  ✗ MISSING — Day 2/3 step3 type not updated"

echo ""
echo "[3.13] Onboarding.tsx — useEffect resume block (Day 1)"
grep -n "onboardingApi.progress\|resume_at_step\|setStep.*resume" \
  "$FRONTEND/pages/Onboarding.tsx" \
  || echo "  ✗ MISSING — Day 1 resume logic not in Onboarding.tsx"

echo ""
echo "[3.14] Onboarding.tsx — incomePattern + customAmount in state (Day 2+3)"
grep -n "incomePattern\|customAmount\|income_pattern\|custom_amount" \
  "$FRONTEND/pages/Onboarding.tsx" \
  || echo "  ✗ MISSING — Day 2/3 fields not in Onboarding.tsx"

echo ""
echo "[3.15] Onboarding.tsx — otherConditionText + medications[] in state (Day 4+5)"
grep -n "otherConditionText\|medications.*name.*foodCondition\|addMedicationRow" \
  "$FRONTEND/pages/Onboarding.tsx" \
  || echo "  ✗ MISSING — Day 4/5 fields not in Onboarding.tsx"

echo ""
echo "[3.16] All pages present"
ls "$FRONTEND/pages/"

echo ""
echo "[3.17] All hooks present"
ls "$FRONTEND/hooks/"

echo ""
echo "[3.18] Dashboard components directory"
ls "$FRONTEND/components/dashboard/" 2>/dev/null \
  || echo "  ✗ components/dashboard/ directory does not exist"

# ─── SECTION 4: ENVIRONMENT ─────────────────────────────────────
echo ""
echo "── SECTION 4: ENVIRONMENT ──────────────────────────────────"

echo ""
echo "[4.1] Laravel .env — key vars set"
cd "$LARAVEL" && grep -E "QUEUE_CONNECTION|FLASK_AI_URL|MAISHA_INTERNAL_SECRET|DB_DATABASE" .env \
  | sed 's/=.*/=<SET>/' \
  || echo "  ✗ .env read failed"

echo ""
echo "[4.2] Flask .env — MAISHA_INTERNAL_SECRET set"
grep -E "MAISHA_INTERNAL_SECRET|ANTHROPIC_API_KEY" \
  "$FLASK/.env" 2>/dev/null \
  | sed 's/=.*/=<SET>/' \
  || echo "  ✗ Flask .env missing or unreadable"

echo ""
echo "[4.3] QUEUE_CONNECTION value"
cd "$LARAVEL" && grep "QUEUE_CONNECTION" .env \
  || echo "  ✗ QUEUE_CONNECTION not set"

echo ""
echo "[4.4] Flask process check"
lsof -i :5000 2>/dev/null | grep LISTEN \
  && echo "  ✓ Flask running on :5000" \
  || echo "  ✗ Nothing listening on :5000 — Flask may not be running"

echo ""
echo "[4.5] Laravel process check"
lsof -i :8000 2>/dev/null | grep LISTEN \
  && echo "  ✓ Laravel running on :8000" \
  || echo "  ✗ Nothing listening on :8000 — run: php artisan serve"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  AUDIT COMPLETE — review any ✗ lines above before testing"
echo "═══════════════════════════════════════════════════════════"
echo ""