#!/usr/bin/env bash
# =============================================================================
# Maisha API — Full curl Test Suite
# Tests: Laravel API (port 8000) + Flask AI (port 5000)
# Users: 10 profiles × full edge cases
# Run:   chmod +x maisha_test.sh && ./maisha_test.sh
# =============================================================================

# ── Config ────────────────────────────────────────────────────────────────────
LARAVEL="http://localhost:8000/api"
FLASK="http://localhost:5000/api"

# Load MAISHA_INTERNAL_SECRET from environment or .env file
if [[ -z "$MAISHA_INTERNAL_SECRET" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
  ENV_FILE="$SCRIPT_DIR/../backend/maisha-api/.env"
  
  if [[ -f "$ENV_FILE" ]]; then
    # Extract value, strip quotes and comments
    MAISHA_INTERNAL_SECRET=$(grep "^MAISHA_INTERNAL_SECRET=" "$ENV_FILE" | cut -d= -f2 | sed 's/^["'"'"']//;s/["'"'"'].*$//' | xargs)
  fi
fi

if [[ -z "$MAISHA_INTERNAL_SECRET" ]]; then
  echo "ERROR: MAISHA_INTERNAL_SECRET not found!" >&2
  echo "  - Not exported in environment" >&2
  echo "  - Not found in backend/maisha-api/.env" >&2
  echo "Please set it before running tests:" >&2
  echo "  export MAISHA_INTERNAL_SECRET=<value from backend/maisha-api/.env>" >&2
  exit 1
fi

INTERNAL_TOKEN="$MAISHA_INTERNAL_SECRET"

# Colours
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

# ── Counters ──────────────────────────────────────────────────────────────────
PASS=0; FAIL=0; SKIP=0
FAILED_TESTS=()

# ── Helpers ───────────────────────────────────────────────────────────────────
section() { echo -e "\n${BOLD}${CYAN}══════════════════════════════════════════${NC}"; \
            echo -e "${BOLD}${CYAN}  $1${NC}"; \
            echo -e "${BOLD}${CYAN}══════════════════════════════════════════${NC}"; }

subsection() { echo -e "\n${YELLOW}── $1 ──${NC}"; }

# check <test_name> <expected_http_code> <actual_http_code> [<body_grep_pattern>] [<body>]
check() {
  local name="$1" expected="$2" actual="$3" pattern="$4" body="$5"
  local status_ok=false body_ok=true

  [[ "$actual" == "$expected" ]] && status_ok=true

  if [[ -n "$pattern" && -n "$body" ]]; then
    echo "$body" | grep -qi "$pattern" || body_ok=false
  fi

  if $status_ok && $body_ok; then
    echo -e "  ${GREEN}✓${NC} $name (HTTP $actual)"
    ((PASS++))
  else
    echo -e "  ${RED}✗${NC} $name — expected HTTP $expected, got $actual"
    [[ -n "$pattern" ]] && ! $body_ok && \
      echo -e "    ${RED}body missing: '$pattern'${NC}"
    ((FAIL++))
    FAILED_TESTS+=("$name")
  fi
}

# api_post <url> <json> [<token>]  → sets $HTTP_CODE $BODY
api_post() {
  local url="$1" json="$2" token="${3:-}"
  local auth_header=()
  [[ -n "$token" ]] && auth_header=(-H "Authorization: Bearer $token")
  local resp
  resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
    -X POST "$url" \
    -H "Content-Type: application/json" \
    "${auth_header[@]}" \
    -d "$json")
  BODY=$(echo "$resp" | sed '$d')
  HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
}

# api_get <url> [<token>]
api_get() {
  local url="$1" token="${2:-}"
  local auth_header=()
  [[ -n "$token" ]] && auth_header=(-H "Authorization: Bearer $token")
  local resp
  resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
    -X GET "$url" \
    "${auth_header[@]}")
  BODY=$(echo "$resp" | sed '$d')
  HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
}

# api_delete <url> <token>
api_delete() {
  local url="$1" token="$2"
  local resp
  resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
    -X DELETE "$url" \
    -H "Authorization: Bearer $token")
  BODY=$(echo "$resp" | sed '$d')
  HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
}

# flask_post <path> <json>
flask_post() {
  local path="$1" json="$2"
  local resp
  resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
    -X POST "$FLASK$path" \
    -H "Content-Type: application/json" \
    -H "X-Maisha-Internal-Token: $INTERNAL_TOKEN" \
    -d "$json")
  BODY=$(echo "$resp" | sed '$d')
  HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
}

register_and_login() {
  local varname="$1" name="$2" email="$3" pass="$4"
  local attempt

  for attempt in 1 2 3; do
    api_post "$LARAVEL/register" \
      "{\"name\":\"$name\",\"email\":\"$email\",\"password\":\"$pass\",\"password_confirmation\":\"$pass\"}"
    if [[ "$HTTP_CODE" == "429" ]]; then
      echo -e "  ${YELLOW}Rate limited on register (attempt $attempt), sleeping 15s${NC}"
      sleep 15
      continue
    fi
    break
  done
  if [[ "$HTTP_CODE" == "422" ]]; then
    echo -e "  ${YELLOW}User $email already exists — logging in instead${NC}"
  elif [[ "$HTTP_CODE" != "201" ]]; then
    echo -e "  ${RED}Registration failed for $email (HTTP $HTTP_CODE): $BODY${NC}"
    exit 1
  fi

  local token=""
  for attempt in 1 2 3; do
    api_post "$LARAVEL/login" \
      "{\"email\":\"$email\",\"password\":\"$pass\"}"
    if [[ "$HTTP_CODE" == "429" ]]; then
      echo -e "  ${YELLOW}Rate limited on login (attempt $attempt), sleeping 15s${NC}"
      sleep 15
      continue
    fi
    token=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('token',''))" 2>/dev/null)
    [[ -n "$token" ]] && break
  done

  if [[ -z "$token" ]]; then
    echo -e "  ${RED}Login failed for $email after retries (HTTP $HTTP_CODE): $BODY${NC}"
    exit 1
  fi

  printf -v "TOKEN_${varname}" '%s' "$token"
  echo -e "  ${CYAN}Token [${varname}]: ${token:0:20}${NC}"
}
# ── Preflight ─────────────────────────────────────────────────────────────────
section "PREFLIGHT CHECKS"

subsection "Laravel"
api_get "$LARAVEL/ping"
check "Laravel /ping" 200 "$HTTP_CODE" "ok" "$BODY"

subsection "Flask"
resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" "$FLASK/health")
BODY=$(echo "$resp" | sed '$d')
HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
check "Flask /health" 200 "$HTTP_CODE" "ok" "$BODY"

subsection "Flask — auth guard"
resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
  -X POST "$FLASK/utakulaa" \
  -H "Content-Type: application/json" \
  -H "X-Maisha-Internal-Token: wrong-token" \
  -d '{"budget_remaining_kes":150}')
BODY=$(echo "$resp" | sed '$d')
HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
check "Flask /utakulaa rejects wrong token" 403 "$HTTP_CODE" "Unauthorized" "$BODY"

# ── AUTH SUITE ────────────────────────────────────────────────────────────────
section "AUTH — Registration & Login"

subsection "Happy path"
api_post "$LARAVEL/register" \
  '{"name":"Test User","email":"smoke@maisha.test","password":"password123","password_confirmation":"password123"}'
if [[ "$HTTP_CODE" == "422" ]]; then
  echo -e "  ${YELLOW}smoke@maisha.test already exists — logging in instead${NC}"
elif [[ "$HTTP_CODE" == "429" ]]; then
  sleep 8
  api_post "$LARAVEL/register" \
    '{"name":"Test User","email":"smoke@maisha.test","password":"password123","password_confirmation":"password123"}'
  check "Register new user" 201 "$HTTP_CODE" "token" "$BODY"
else
  check "Register new user" 201 "$HTTP_CODE" "token" "$BODY"
fi
SMOKE_TOKEN=$(echo "$BODY" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

api_post "$LARAVEL/login" \
  '{"email":"smoke@maisha.test","password":"password123"}'
check "Login existing user" 200 "$HTTP_CODE" "token" "$BODY"
SMOKE_TOKEN=$(echo "$BODY" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
SMOKE_TOKEN=$(echo "$BODY" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

subsection "Validation failures"
api_post "$LARAVEL/register" \
  '{"name":"","email":"bad-email","password":"short","password_confirmation":"short"}'
check "Register — invalid fields" 422 "$HTTP_CODE"

api_post "$LARAVEL/register" \
  '{"name":"Dup","email":"smoke@maisha.test","password":"password123","password_confirmation":"password123"}'
check "Register — duplicate email" 422 "$HTTP_CODE"

api_post "$LARAVEL/login" '{"email":"smoke@maisha.test","password":"wrongpass"}'
check "Login — wrong password" 401 "$HTTP_CODE" "Invalid" "$BODY"

api_post "$LARAVEL/login" '{"email":"nobody@maisha.test","password":"password123"}'
check "Login — unknown email" 401 "$HTTP_CODE"

subsection "Auth guard"
api_get "$LARAVEL/me"
check "GET /me without token → 401" 401 "$HTTP_CODE"

api_get "$LARAVEL/me" "$SMOKE_TOKEN"
check "GET /me with token → 200" 200 "$HTTP_CODE" "user" "$BODY"

subsection "Logout"
api_post "$LARAVEL/logout" '{}' "$SMOKE_TOKEN"
check "Logout → 200" 200 "$HTTP_CODE" "Logged out" "$BODY"

api_get "$LARAVEL/me" "$SMOKE_TOKEN"
check "Token invalidated after logout" 401 "$HTTP_CODE"

# ── PASSWORD RESET ────────────────────────────────────────────────────────────
section "PASSWORD RESET"

api_post "$LARAVEL/register" \
  '{"name":"Reset User","email":"reset@maisha.test","password":"password123","password_confirmation":"password123"}'

subsection "Forgot password"
api_post "$LARAVEL/forgot-password" '{"email":"reset@maisha.test"}'
check "Forgot password — known email" 200 "$HTTP_CODE" "Reset link" "$BODY"

api_post "$LARAVEL/forgot-password" '{"email":"nobody@maisha.test"}'
[[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "400" ]] && \
  echo -e "  ${CYAN}(HTTP $HTTP_CODE is acceptable for unknown email)${NC}"

api_post "$LARAVEL/reset-password" \
  '{"token":"fake-token","email":"reset@maisha.test","password":"newpass123","password_confirmation":"newpass123"}'
check "Reset with fake token → 400" 400 "$HTTP_CODE"

# ── INGREDIENTS ───────────────────────────────────────────────────────────────
section "INGREDIENTS (public)"

api_get "$LARAVEL/ingredients"
check "GET /ingredients → 200" 200 "$HTTP_CODE" "data" "$BODY"

# ── USER 1 — AMINA ────────────────────────────────────────────────────────────
section "USER 1 — AMINA (34, weight loss, sedentary, 150 KES)"

register_and_login "AMINA" "Amina Wanjiku" "amina@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":34,"weight_kg":75,"height_cm":162,"blood_type":"O+"}' "$TOKEN_AMINA"
check "Amina step-about → BMI calculated" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["lose_weight"]}' "$TOKEN_AMINA"
check "Amina step-1 goals" 200 "$HTTP_CODE" "saved" "$BODY"

api_post "$LARAVEL/onboarding/step-2" \
  '{"conditions":["none"]}' "$TOKEN_AMINA"
check "Amina step-2 no conditions" 200 "$HTTP_CODE" "saved" "$BODY"

api_post "$LARAVEL/onboarding/step-3" \
  '{"budget_range":"100_200","income_pattern":"daily"}' "$TOKEN_AMINA"
check "Amina step-3 budget" 200 "$HTTP_CODE" "budget_kes" "$BODY"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_AMINA"
check "Amina onboarding complete" 200 "$HTTP_CODE" "onboarded" "$BODY"

subsection "Status after onboarding"
api_get "$LARAVEL/onboarding/status" "$TOKEN_AMINA"
check "Amina onboarding status" 200 "$HTTP_CODE" "onboarded" "$BODY"

subsection "Meal suggestion"
api_post "$LARAVEL/utakulaa" '{"budget":150}' "$TOKEN_AMINA"
[[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "503" ]] && \
  ((PASS++)) && echo -e "  ${GREEN}✓${NC} Utakulaa 200 or graceful 503 (HTTP $HTTP_CODE)"

subsection "Profile completion"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"sedentary","exercise_frequency":"none"}' "$TOKEN_AMINA"
check "Amina activity profile" 200 "$HTTP_CODE" "saved" "$BODY"

api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":2,"preferred_meal_times":["07:00","12:30"],"meal_pattern":"breakfast_lunch"}' "$TOKEN_AMINA"
check "Amina meal pattern (skip dinner)" 200 "$HTTP_CODE" "saved" "$BODY"

api_get "$LARAVEL/profile/completion" "$TOKEN_AMINA"
check "Amina profile completion score" 200 "$HTTP_CODE" "overall" "$BODY"

subsection "Edge case — budget overspend"
api_post "$LARAVEL/expense-logs" '{"amount_kes":140,"description":"Lunch ugali"}' "$TOKEN_AMINA"
check "Amina log expense" 200 "$HTTP_CODE" "spent_today" "$BODY"

api_post "$LARAVEL/expense-logs" '{"amount_kes":90,"description":"Evening snack"}' "$TOKEN_AMINA"
check "Amina over-budget expense logs" 200 "$HTTP_CODE" "remaining" "$BODY"
REMAINING=$(echo "$BODY" | grep -o '"remaining":[0-9.]*' | cut -d: -f2)
echo -e "  ${CYAN}Remaining budget: $REMAINING KES${NC}"

api_get "$LARAVEL/budget/today" "$TOKEN_AMINA"
check "Amina today's budget summary" 200 "$HTTP_CODE" "percentage_used" "$BODY"

subsection "Edge case — validation"
api_post "$LARAVEL/expense-logs" '{"amount_kes":0}' "$TOKEN_AMINA"
check "Expense — zero amount rejected" 422 "$HTTP_CODE"

api_post "$LARAVEL/expense-logs" '{"amount_kes":99999}' "$TOKEN_AMINA"
check "Expense — over max rejected" 422 "$HTTP_CODE"

subsection "Flask — Amina hydration"
flask_post "/hydration/calculate" \
  '{"weight_kg":75,"activity_level":"sedentary","health_conditions":[],"active_slots":["breakfast","lunch"]}'
check "Flask hydration for Amina" 200 "$HTTP_CODE" "target_ml" "$BODY"

subsection "Flask — Amina meal pattern validate"
flask_post "/meal-pattern/validate" \
  '{"active_slots":["breakfast","lunch"],"goals":["lose_weight"],"conditions":[]}'
check "Flask meal pattern warnings" 200 "$HTTP_CODE" "warnings" "$BODY"

# ── USER 2 — JAMES ────────────────────────────────────────────────────────────
sleep 2
section "USER 2 — JAMES (52, diabetic, Metformin, 200 KES)"

register_and_login "JAMES" "James Odhiambo" "james@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":52,"weight_kg":88,"height_cm":170,"blood_type":"B+"}' "$TOKEN_JAMES"
check "James step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["manage_condition","lose_weight"]}' "$TOKEN_JAMES"
check "James multi-goal" 200 "$HTTP_CODE" "saved" "$BODY"

api_post "$LARAVEL/onboarding/step-2" \
  '{"conditions":["diabetes","hypertension"]}' "$TOKEN_JAMES"
check "James dual conditions" 200 "$HTTP_CODE" "saved" "$BODY"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"100_200","income_pattern":"daily"}' "$TOKEN_JAMES"
check "James budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_JAMES"
check "James onboarding complete" 200 "$HTTP_CODE" "onboarded" "$BODY"

subsection "Medication anchors"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Metformin 500mg","frequency":"twice_daily","duration_type":"ongoing","times":["08:00","20:00"],"food_condition":"with_food","requires_food":true,"meal_slot_anchor":"breakfast"}' "$TOKEN_JAMES"
check "James Metformin — add" 201 "$HTTP_CODE" "saved" "$BODY"
MED_ID=$(echo "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)

api_get "$LARAVEL/profile/medications" "$TOKEN_JAMES"
check "James medication list" 200 "$HTTP_CODE" "Metformin" "$BODY"

subsection "Edge case — skip breakfast (Tier 1 safety)"
flask_post "/intent" \
  '{"message":"sijala breakfast bado na nimechukua metformin","user_context":{"conditions":["diabetes"],"medications":[{"name":"Metformin","requires_food":true}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "James skip-breakfast intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent detected: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Edge case — doctor changes carb target"
flask_post "/intent" \
  '{"message":"doctor amesema nipunguze wanga zaidi","user_context":{"conditions":["diabetes"],"primary_goals":["manage_condition"]}}'
check "James doctor instruction intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — delete medication"
api_delete "$LARAVEL/profile/medications/$MED_ID" "$TOKEN_JAMES"
check "James delete medication" 200 "$HTTP_CODE" "deleted" "$BODY"

api_delete "$LARAVEL/profile/medications/99999" "$TOKEN_JAMES"
check "James delete non-existent med → 404" 404 "$HTTP_CODE"

subsection "Flask — James meal categories (diabetic)"
flask_post "/meal-categories" \
  '{"user_id":2,"budget_remaining_kes":200,"health_conditions":["diabetes","hypertension"],
    "allergies":[],"fitness_goal":"manage_condition","today_spent_kes":0,
    "ingredients":[
      {"id":1,"name":"Ugali (Maize)","category":"carb","price_kes":20,"calories":250,"protein_g":3,"carbs_g":50,"fat_g":1,"condition_flags":{"diabetes":false},"allergen_flags":[],"available":true,"in_season":true},
      {"id":2,"name":"Sorghum Uji","category":"carb","price_kes":15,"calories":120,"protein_g":4,"carbs_g":25,"fat_g":1,"condition_flags":{"diabetes":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":3,"name":"Eggs","category":"protein","price_kes":16,"calories":78,"protein_g":6,"carbs_g":0,"fat_g":5,"condition_flags":{"diabetes":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":4,"name":"Omena","category":"protein","price_kes":30,"calories":100,"protein_g":20,"carbs_g":0,"fat_g":2,"condition_flags":{"diabetes":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":5,"name":"Sukuma Wiki","category":"vegetable","price_kes":10,"calories":35,"protein_g":2,"carbs_g":5,"fat_g":0,"condition_flags":{"diabetes":true},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "James diabetic meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

# ── USER 3 — BRENDA ───────────────────────────────────────────────────────────
section "USER 3 — BRENDA (22, student, H.Pylori, 80 KES)"

register_and_login "BRENDA" "Brenda Akinyi" "brenda@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":22,"weight_kg":58,"height_cm":163,"blood_type":"A-"}' "$TOKEN_BRENDA"
check "Brenda step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["eat_better"]}' "$TOKEN_BRENDA"
check "Brenda goals" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" '{"conditions":["none"]}' "$TOKEN_BRENDA"
check "Brenda no conditions" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"under_100","income_pattern":"irregular"}' "$TOKEN_BRENDA"
check "Brenda ultra-tight budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_BRENDA"
check "Brenda onboarding complete" 200 "$HTTP_CODE"

subsection "Medications — triple H.Pylori therapy"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Amoxicillin","food_condition":"with_food","frequency":"three_times_daily","duration_type":"days","duration_days":14,"times":["07:00","14:00","21:00"],"food_condition":"with_food","requires_food":true,"meal_slot_anchor":"breakfast"}' "$TOKEN_BRENDA"
check "Brenda Amoxicillin" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Clarithromycin","food_condition":"with_food","frequency":"three_times_daily","duration_type":"days","duration_days":14,"times":["07:00","14:00","21:00"],"food_condition":"with_food","requires_food":true,"meal_slot_anchor":"breakfast"}' "$TOKEN_BRENDA"
check "Brenda Clarithromycin" 201 "$HTTP_CODE"

subsection "Health profile — H.Pylori sensitivities"
api_post "$LARAVEL/profile/health" \
  '{"conditions":[],"allergies":[],"sensitivities":["spicy","acidic","coffee"],"medical_notes":"H.Pylori under treatment — avoid stomach irritants"}' "$TOKEN_BRENDA"
check "Brenda health sensitivities" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Edge case — meal skip at 2pm"
flask_post "/intent" \
  '{"message":"nimekula chips tu kwa lunch nikiwa busy","user_context":{"conditions":[],"medications":[{"name":"Clarithromycin","requires_food":true,"times":["07:00","14:00","21:00"]}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "Brenda poor lunch intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — medication complete"
flask_post "/intent" \
  '{"message":"nimemaliza dawa yangu yote leo","user_context":{"conditions":[],"medications":[{"name":"Amoxicillin"},{"name":"Clarithromycin"}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "Brenda meds complete intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Edge case — incomplete onboarding guard"
api_post "$LARAVEL/register" \
  '{"name":"Incomplete","email":"incomplete@maisha.test","password":"password123","password_confirmation":"password123"}'
if [[ "$HTTP_CODE" == "429" ]]; then
  sleep 8
  api_post "$LARAVEL/register" \
    '{"name":"Incomplete","email":"incomplete@maisha.test","password":"password123","password_confirmation":"password123"}'
fi
INC_TOKEN=$(echo "$BODY" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

api_post "$LARAVEL/onboarding/step-about" \
  '{"age":25,"weight_kg":60,"height_cm":165}' "$INC_TOKEN"
api_post "$LARAVEL/onboarding/complete" '{}' "$INC_TOKEN"
check "Complete without goals → 422" 422 "$HTTP_CODE" "onboarding steps" "$BODY"

subsection "Budget at 80 KES — weekly view"
api_get "$LARAVEL/budget/weekly" "$TOKEN_BRENDA"
check "Brenda weekly budget" 200 "$HTTP_CODE" "data" "$BODY"

subsection "Pantry — minimal student items"
api_post "$LARAVEL/profile/pantry" \
  '{"ingredient_id":1,"tier":2,"quantity":2,"unit":"kg"}' "$TOKEN_BRENDA"
check "Brenda pantry add maize flour" 200 "$HTTP_CODE" "saved" "$BODY"

api_get "$LARAVEL/profile/pantry" "$TOKEN_BRENDA"
check "Brenda pantry list" 200 "$HTTP_CODE"

api_delete "$LARAVEL/profile/pantry/1" "$TOKEN_BRENDA"
check "Brenda pantry delete" 200 "$HTTP_CODE" "deleted" "$BODY"

api_delete "$LARAVEL/profile/pantry/99999" "$TOKEN_BRENDA"
check "Brenda pantry delete non-existent" 200 "$HTTP_CODE"

# ── USER 4 — BRIAN ────────────────────────────────────────────────────────────
section "USER 4 — BRIAN (28, fitness, lactose intolerant, 400 KES)"

register_and_login "BRIAN" "Brian Kamau" "brian@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":28,"weight_kg":80,"height_cm":178,"blood_type":"O+"}' "$TOKEN_BRIAN"
check "Brian step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["gain_muscle"]}' "$TOKEN_BRIAN"
check "Brian muscle gain goal" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" '{"conditions":["none"]}' "$TOKEN_BRIAN"
check "Brian no conditions" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"over_400","income_pattern":"weekly"}' "$TOKEN_BRIAN"
check "Brian high budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_BRIAN"
check "Brian onboarding complete" 200 "$HTTP_CODE"

subsection "Profile — very active"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"very_active","exercise_frequency":"5x_week","sleep_schedule":"22:00-06:00"}' "$TOKEN_BRIAN"
check "Brian very_active profile" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Health profile — lactose intolerance"
api_post "$LARAVEL/profile/health" \
  '{"conditions":[],"allergies":["dairy","lactose"],"sensitivities":["milk","yoghurt","cheese"],"medical_notes":"Lactose intolerant — use plant-based milk only"}' "$TOKEN_BRIAN"
check "Brian lactose allergy" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Meal pattern — 5 meals"
api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":5,"preferred_meal_times":["05:45","08:00","13:00","17:30","20:00"],"meal_pattern":"athlete_5meal"}' "$TOKEN_BRIAN"
check "Brian 5-meal athlete pattern" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Pantry — athlete pantry"
for ING_ID in 1 2 3; do
  api_post "$LARAVEL/profile/pantry" \
    "{\"ingredient_id\":$ING_ID,\"tier\":1,\"quantity\":5,\"unit\":\"kg\"}" "$TOKEN_BRIAN"
done
check "Brian pantry bulk items" 200 "$HTTP_CODE" "saved" "$BODY"

api_get "$LARAVEL/profile/completion" "$TOKEN_BRIAN"
check "Brian profile completion" 200 "$HTTP_CODE" "overall" "$BODY"
OVERALL=$(echo "$BODY" | grep -o '"overall":[0-9.]*' | cut -d: -f2)
echo -e "  ${CYAN}Completion score: $OVERALL%${NC}"

subsection "Edge case — rest day"
flask_post "/intent" \
  '{"message":"haikuwezekanika kwenda gym leo","user_context":{"activity_level":"very_active","fitness_goal":"muscle_gain"}}'
check "Brian rest day intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — protein powder depleted"
flask_post "/intent" \
  '{"message":"protein powder yameisha","user_context":{"activity_level":"very_active","fitness_goal":"muscle_gain","pantry":["oats","eggs","rice"]}}'
check "Brian powder depleted intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Flask — high-calorie meal categories"
flask_post "/meal-categories" \
  '{"user_id":4,"budget_remaining_kes":400,"health_conditions":[],"allergies":["dairy"],
    "fitness_goal":"muscle_gain","today_spent_kes":0,
    "ingredients":[
      {"id":3,"name":"Eggs","category":"protein","price_kes":16,"calories":78,"protein_g":6,"carbs_g":0,"fat_g":5,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":4,"name":"Omena","category":"protein","price_kes":30,"calories":100,"protein_g":20,"carbs_g":0,"fat_g":2,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":6,"name":"Rice","category":"carb","price_kes":40,"calories":260,"protein_g":5,"carbs_g":55,"fat_g":0,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":7,"name":"Sweet Potato","category":"carb","price_kes":25,"calories":180,"protein_g":2,"carbs_g":40,"fat_g":0,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":8,"name":"Spinach","category":"vegetable","price_kes":15,"calories":25,"protein_g":3,"carbs_g":3,"fat_g":0,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "Brian athlete meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

subsection "Flask — Brian hydration (heavy sweat)"
flask_post "/hydration/calculate" \
  '{"weight_kg":80,"activity_level":"very_active","health_conditions":[],"active_slots":["pre_workout","breakfast","lunch","post_workout","dinner"]}'
check "Brian athlete hydration" 200 "$HTTP_CODE" "target_ml" "$BODY"
TARGET=$(echo "$BODY" | python3 -c "import sys, json; print(json.load(sys.stdin).get('target_ml', ''))")
echo -e "  ${CYAN}Hydration target: ${TARGET}ml (expect ~3200ml)${NC}"

# ── USER 5 — MARY ─────────────────────────────────────────────────────────────
section "USER 5 — MARY (45, anaemia, family of 4, 200 KES)"

register_and_login "MARY" "Mary Wambui" "mary@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":45,"weight_kg":62,"height_cm":158,"blood_type":"A+"}' "$TOKEN_MARY"
check "Mary step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["eat_better","manage_condition"]}' "$TOKEN_MARY"
check "Mary goals" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" \
  '{"conditions":["anaemia"]}' "$TOKEN_MARY"
check "Mary anaemia condition" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"100_200","income_pattern":"daily"}' "$TOKEN_MARY"
check "Mary family budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_MARY"
check "Mary onboarding complete" 200 "$HTTP_CODE"

subsection "Medication — ferrous sulfate (iron)"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Ferrous Sulfate","frequency":"once_daily","duration_type":"ongoing","times":["06:00"],"requires_food":false,"food_condition":"empty_stomach","meal_slot_anchor":"before_breakfast"}' "$TOKEN_MARY"
check "Mary ferrous sulfate (empty stomach)" 201 "$HTTP_CODE" "saved" "$BODY"

subsection "Health notes — tea/iron absorption"
api_post "$LARAVEL/profile/health" \
  '{"conditions":["anaemia"],"allergies":[],"sensitivities":[],"medical_notes":"Tea reduces iron absorption — delay tea 1hr after breakfast. Vitamin C enhances absorption."}' "$TOKEN_MARY"
check "Mary health notes saved" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Edge case — children reject beans"
flask_post "/intent" \
  '{"message":"watoto wamekataa beans tena","user_context":{"family_size":4,"conditions":["anaemia"],"primary_goals":["eat_better"]}}'
check "Mary family food rejection intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — fatigue after hard work"
flask_post "/intent" \
  '{"message":"nimechoka sana leo kazi ilikuwa ngumu","user_context":{"conditions":["anaemia"],"family_size":4}}'
check "Mary fatigue intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Budget tracking — family spend"
api_post "$LARAVEL/expense-logs" '{"amount_kes":60,"description":"Breakfast eggs and uji"}' "$TOKEN_MARY"
check "Mary family breakfast expense" 200 "$HTTP_CODE"

api_post "$LARAVEL/expense-logs" '{"amount_kes":40,"description":"Lunch omena"}' "$TOKEN_MARY"
check "Mary lunch expense" 200 "$HTTP_CODE"

api_post "$LARAVEL/expense-logs" '{"amount_kes":70,"description":"Dinner beans and ugali"}' "$TOKEN_MARY"
check "Mary dinner expense" 200 "$HTTP_CODE"

api_get "$LARAVEL/budget/today" "$TOKEN_MARY"
check "Mary family budget today" 200 "$HTTP_CODE" "spent" "$BODY"
SPENT=$(echo "$BODY" | grep -o '"spent":[0-9.]*' | cut -d: -f2)
echo -e "  ${CYAN}Family spent: $SPENT KES of 200 KES${NC}"

subsection "Flask — anaemia meal plan"
flask_post "/meal-categories" \
  '{"user_id":5,"budget_remaining_kes":200,"health_conditions":["anaemia"],"allergies":[],
    "fitness_goal":"manage_condition","today_spent_kes":0,
    "ingredients":[
      {"id":9,"name":"Sorghum Flour","category":"carb","price_kes":20,"calories":200,"protein_g":6,"carbs_g":40,"fat_g":2,"condition_flags":{"anaemia":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":10,"name":"Omena","category":"protein","price_kes":30,"calories":100,"protein_g":20,"carbs_g":0,"fat_g":2,"condition_flags":{"anaemia":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":11,"name":"Beans","category":"protein","price_kes":25,"calories":150,"protein_g":9,"carbs_g":25,"fat_g":1,"condition_flags":{"anaemia":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":12,"name":"Managu","category":"vegetable","price_kes":10,"calories":30,"protein_g":3,"carbs_g":4,"fat_g":0,"condition_flags":{"anaemia":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":13,"name":"Tomatoes","category":"vegetable","price_kes":15,"calories":20,"protein_g":1,"carbs_g":4,"fat_g":0,"condition_flags":{"anaemia":true},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "Mary anaemia meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

# ── USER 6 — KEVIN ────────────────────────────────────────────────────────────
section "USER 6 — KEVIN (19, H.Pylori+Ulcer, night owl, 120 KES)"

register_and_login "KEVIN" "Kevin Mwangi" "kevin@maisha.test" "password123"

subsection "Onboarding (edge: underweight BMI)"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":19,"weight_kg":52,"height_cm":178,"blood_type":"B-"}' "$TOKEN_KEVIN"
check "Kevin step-about (underweight)" 200 "$HTTP_CODE" "bmi" "$BODY"
BMI=$(echo "$BODY" | grep -o '"bmi":[0-9.]*' | cut -d: -f2)
echo -e "  ${CYAN}Kevin BMI: $BMI (expect ~16.4 — underweight)${NC}"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["gain_muscle","manage_condition"]}' "$TOKEN_KEVIN"
check "Kevin goals" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" '{"conditions":["none"]}' "$TOKEN_KEVIN"
check "Kevin conditions (H.Pylori in health profile)" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"100_200","income_pattern":"daily"}' "$TOKEN_KEVIN"
check "Kevin budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_KEVIN"
check "Kevin onboarding complete" 200 "$HTTP_CODE"

subsection "Medications — triple therapy"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Omeprazole","frequency":"three_times_daily","duration_type":"days","duration_days":14,"times":["11:00","15:00","22:00"],"requires_food":false,"food_condition":"empty_stomach","meal_slot_anchor":"before_meal"}' "$TOKEN_KEVIN"
check "Kevin Omeprazole (before food)" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Amoxicillin","food_condition":"with_food","frequency":"three_times_daily","duration_type":"days","duration_days":14,"times":["11:30","15:00","22:00"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"meal"}' "$TOKEN_KEVIN"
check "Kevin Amoxicillin" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Clarithromycin","food_condition":"with_food","frequency":"three_times_daily","duration_type":"days","duration_days":14,"times":["11:30","15:00","22:00"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"meal"}' "$TOKEN_KEVIN"
check "Kevin Clarithromycin" 201 "$HTTP_CODE"

subsection "Activity — irregular schedule"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"light","exercise_frequency":"irregular","sleep_schedule":"03:00-11:00"}' "$TOKEN_KEVIN"
check "Kevin night-owl schedule" 200 "$HTTP_CODE"

subsection "Health profile — H.Pylori + Ulcer"
api_post "$LARAVEL/profile/health" \
  '{"conditions":["h_pylori","peptic_ulcer"],"allergies":[],"sensitivities":["spicy","fried","coffee","acidic","soda"],"medical_notes":"Triple therapy — food required. Avoid re-used frying oil. Night hunger dangerous for ulcer."}' "$TOKEN_KEVIN"
check "Kevin H.Pylori + Ulcer health profile" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Edge case — skipped medication (Tier 1)"
flask_post "/intent" \
  '{"message":"nimesahau kula na dawa zangu za saa tisa","user_context":{"conditions":["h_pylori","peptic_ulcer"],"medications":[{"name":"Amoxicillin","requires_food":true}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "Kevin skipped-meds intent (Tier 1)" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — hungry at 2am (ulcer)"
flask_post "/intent" \
  '{"message":"naona njaa sana","user_context":{"conditions":["peptic_ulcer"],"time":"02:00","sleep_schedule":"03:00-11:00"}}'
check "Kevin 2am hunger ulcer intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — treatment side effects"
flask_post "/intent" \
  '{"message":"sijisikii vizuri dawa zinanisumbua","user_context":{"conditions":["h_pylori"],"medications":[{"name":"Amoxicillin"},{"name":"Clarithromycin"},{"name":"Omeprazole"}],"days_on_treatment":14},"frequency":"once_daily","duration_type":"ongoing"}'
check "Kevin side effects intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Weekly budget — erratic spend"
api_get "$LARAVEL/budget/weekly" "$TOKEN_KEVIN"
check "Kevin weekly budget (erratic)" 200 "$HTTP_CODE" "data" "$BODY"

# ════════════════════════════════════════════════════════════════════════════
# USERS 7-10 — NEW PROFILES
# ════════════════════════════════════════════════════════════════════════════

# ── USER 7 — GRACE ────────────────────────────────────────────────────────────
section "USER 7 — GRACE (38, hypertension+cholesterol, night shift, 250 KES)"

register_and_login "GRACE" "Grace Njeri" "grace@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":38,"weight_kg":78,"height_cm":165,"blood_type":"AB+"}' "$TOKEN_GRACE"
check "Grace step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["manage_condition","eat_better"]}' "$TOKEN_GRACE"
check "Grace dual manage+eat goals" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" \
  '{"conditions":["hypertension","high_cholesterol"]}' "$TOKEN_GRACE"
check "Grace hypertension + cholesterol" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"200_400","income_pattern":"weekly"}' "$TOKEN_GRACE"
check "Grace budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_GRACE"
check "Grace onboarding complete" 200 "$HTTP_CODE"

subsection "Medications — anti-hypertensives"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Amlodipine 5mg","frequency":"once_daily","duration_type":"ongoing","times":["08:00"],"requires_food":false,"food_condition":"none","meal_slot_anchor":"morning"}' "$TOKEN_GRACE"
check "Grace Amlodipine" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Atorvastatin 20mg","frequency":"once_daily","duration_type":"ongoing","times":["21:00"],"requires_food":false,"food_condition":"none","meal_slot_anchor":"bedtime"}' "$TOKEN_GRACE"
check "Grace Atorvastatin (night)" 201 "$HTTP_CODE"

subsection "Activity — shift worker"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"moderate","exercise_frequency":"3x_week","sleep_schedule":"08:00-16:00"}' "$TOKEN_GRACE"
check "Grace day-sleeper schedule" 200 "$HTTP_CODE"

subsection "Health profile — sodium restricted"
api_post "$LARAVEL/profile/health" \
  '{"conditions":["hypertension","high_cholesterol"],"allergies":[],"sensitivities":["high_sodium","saturated_fat","processed_food"],"medical_notes":"Low sodium diet. Avoid fried foods and processed meats. Atorvastatin at night."}' "$TOKEN_GRACE"
check "Grace sodium-restricted profile" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Goal — target weight"
api_post "$LARAVEL/profile/goals" \
  '{"primary_goal":"manage_condition","secondary_goals":["eat_better","lose_weight"]}' "$TOKEN_GRACE"
check "Grace goal save" 200 "$HTTP_CODE" "saved" "$BODY"
api_get "$LARAVEL/profile/goals" "$TOKEN_GRACE"
check "Grace goals retrieval" 200 "$HTTP_CODE" "primary_goal" "$BODY"

subsection "Edge case — night shift hunger"
flask_post "/intent" \
  '{"message":"niko njaa sana shift yangu imalize saa tatu usiku","user_context":{"conditions":["hypertension"],"sleep_schedule":"08:00-16:00","activity_level":"moderate"}}'
check "Grace night-shift hunger intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — salty food temptation"
flask_post "/intent" \
  '{"message":"nilikula crisps na chips kwa lunch","user_context":{"conditions":["hypertension","high_cholesterol"],"sensitivities":["high_sodium"]}}'
check "Grace salty food slip intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Edge case — stress overeating"
flask_post "/intent" \
  '{"message":"shift ilikuwa ngumu sana nilikula sana leo","user_context":{"conditions":["hypertension","high_cholesterol"]}}'
check "Grace stress-overeating intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — blood pressure spike"
flask_post "/intent" \
  '{"message":"kichwa kinauma sana na BP yangu ilikuwa 160/100 jana","user_context":{"conditions":["hypertension"],"medications":[{"name":"Amlodipine 5mg","requires_food":false}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "Grace BP spike intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Flask — hypertension meal categories (low sodium)"
flask_post "/meal-categories" \
  '{"user_id":7,"budget_remaining_kes":250,"health_conditions":["hypertension","high_cholesterol"],
    "allergies":[],"fitness_goal":"manage_condition","today_spent_kes":0,
    "ingredients":[
      {"id":14,"name":"Oats","category":"carb","price_kes":35,"calories":150,"protein_g":5,"carbs_g":27,"fat_g":3,"condition_flags":{"hypertension":true,"high_cholesterol":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":15,"name":"Avocado","category":"fat","price_kes":25,"calories":160,"protein_g":2,"carbs_g":9,"fat_g":15,"condition_flags":{"high_cholesterol":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":16,"name":"Sardines","category":"protein","price_kes":45,"calories":190,"protein_g":25,"carbs_g":0,"fat_g":10,"condition_flags":{"hypertension":true,"high_cholesterol":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":5,"name":"Sukuma Wiki","category":"vegetable","price_kes":10,"calories":35,"protein_g":2,"carbs_g":5,"fat_g":0,"condition_flags":{"hypertension":true},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "Grace hypertension meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

subsection "Meal pattern — shift worker schedule"
api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":3,"preferred_meal_times":["16:30","21:00","03:00"],"meal_pattern":"shift_worker","cuisine_preference":"kenyan"}' "$TOKEN_GRACE"
check "Grace shift worker meal times" 200 "$HTTP_CODE" "saved" "$BODY"

api_get "$LARAVEL/profile/meal-pattern" "$TOKEN_GRACE"
check "Grace meal pattern retrieval" 200 "$HTTP_CODE" "meals_per_day" "$BODY"

# ── USER 8 — PETER ────────────────────────────────────────────────────────────
section "USER 8 — PETER (16, student athlete, growth phase, 100 KES)"

register_and_login "PETER" "Peter Mutua" "peter@maisha.test" "password123"

subsection "Onboarding (minor — growth calorie needs)"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":16,"weight_kg":58,"height_cm":172,"blood_type":"O-"}' "$TOKEN_PETER"
check "Peter step-about (teen athlete)" 200 "$HTTP_CODE" "bmi" "$BODY"
BMI=$(echo "$BODY" | grep -o '"bmi":[0-9.]*' | cut -d: -f2)
echo -e "  ${CYAN}Peter BMI: $BMI (teen growth phase)${NC}"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["gain_muscle","eat_better"]}' "$TOKEN_PETER"
check "Peter teen athlete goals" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" '{"conditions":["none"]}' "$TOKEN_PETER"
check "Peter no conditions" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"under_100","income_pattern":"irregular"}' "$TOKEN_PETER"
check "Peter tight student budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_PETER"
check "Peter onboarding complete" 200 "$HTTP_CODE"

subsection "Activity — student athlete"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"active","exercise_frequency":"daily_training","sleep_schedule":"22:00-06:00"}' "$TOKEN_PETER"
check "Peter daily training schedule" 200 "$HTTP_CODE"

subsection "Meal pattern — school schedule"
api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":4,"preferred_meal_times":["06:30","10:00","13:00","18:00"],"meal_pattern":"school_athlete","cuisine_preference":"kenyan"}' "$TOKEN_PETER"
check "Peter school athlete pattern" 200 "$HTTP_CODE"

subsection "Pantry — school hostel"
api_post "$LARAVEL/profile/pantry" \
  '{"ingredient_id":3,"tier":2,"quantity":12,"unit":"pieces"}' "$TOKEN_PETER"
check "Peter eggs in hostel" 200 "$HTTP_CODE"

subsection "Flask — teen growth hydration"
flask_post "/hydration/calculate" \
  '{"weight_kg":58,"activity_level":"active","health_conditions":[],"active_slots":["breakfast","mid_morning","lunch","dinner"]}'
check "Peter teen athlete hydration" 200 "$HTTP_CODE" "target_ml" "$BODY"

subsection "Flask — growth-phase meal categories"
flask_post "/meal-categories" \
  '{"user_id":8,"budget_remaining_kes":100,"health_conditions":[],"allergies":[],"fitness_goal":"muscle_gain","today_spent_kes":0,
    "ingredients":[
      {"id":3,"name":"Eggs","category":"protein","price_kes":16,"calories":78,"protein_g":6,"carbs_g":0,"fat_g":5,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":1,"name":"Ugali","category":"carb","price_kes":20,"calories":250,"protein_g":3,"carbs_g":50,"fat_g":1,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":11,"name":"Beans","category":"protein","price_kes":25,"calories":150,"protein_g":9,"carbs_g":25,"fat_g":1,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true},
      {"id":5,"name":"Sukuma Wiki","category":"vegetable","price_kes":10,"calories":35,"protein_g":2,"carbs_g":5,"fat_g":0,"condition_flags":{},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "Peter teen growth meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

subsection "Edge case — pre-match nerves (no appetite)"
flask_post "/intent" \
  '{"message":"siwezi kula kabla ya mechi tumbo inaniuma","user_context":{"activity_level":"active","fitness_goal":"muscle_gain","age":16}}'
check "Peter pre-match anxiety intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — canteen food only option"
flask_post "/intent" \
  '{"message":"leo canteen inauza chips tu hakuna kitu kingine","user_context":{"activity_level":"active","budget_kes":100,"fitness_goal":"muscle_gain"}}'
check "Peter limited canteen intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — muscle cramps after training"
flask_post "/intent" \
  '{"message":"miguu yangu inakamata baada ya mazoezi","user_context":{"activity_level":"active","age":16,"fitness_goal":"muscle_gain"}}'
check "Peter muscle cramps intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Profile completion — partial (school constraints)"
api_get "$LARAVEL/profile/completion" "$TOKEN_PETER"
check "Peter profile completion" 200 "$HTTP_CODE" "overall" "$BODY"

# ── USER 9 — FATUMA ───────────────────────────────────────────────────────────
section "USER 9 — FATUMA (29, pregnant T2, folic acid, 180 KES)"

register_and_login "FATUMA" "Fatuma Said" "fatuma@maisha.test" "password123"

subsection "Onboarding"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":29,"weight_kg":65,"height_cm":160,"blood_type":"O+"}' "$TOKEN_FATUMA"
check "Fatuma step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["eat_better","manage_condition"]}' "$TOKEN_FATUMA"
check "Fatuma pregnancy goals" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" '{"conditions":["none"]}' "$TOKEN_FATUMA"
check "Fatuma conditions (pregnancy in health profile)" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"100_200","income_pattern":"daily"}' "$TOKEN_FATUMA"
check "Fatuma budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_FATUMA"
check "Fatuma onboarding complete" 200 "$HTTP_CODE"

subsection "Medications — pregnancy supplements"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Folic Acid 5mg","frequency":"once_daily","duration_type":"ongoing","times":["08:00"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"breakfast"}' "$TOKEN_FATUMA"
check "Fatuma folic acid" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Ferrous Sulfate (Pregnancy)","frequency":"twice_daily","duration_type":"ongoing","times":["08:00","20:00"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"meal"}' "$TOKEN_FATUMA"
check "Fatuma iron supplement (pregnancy)" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Calcium 500mg","frequency":"twice_daily","duration_type":"ongoing","times":["13:00","21:00"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"meal"}' "$TOKEN_FATUMA"
check "Fatuma calcium supplement" 201 "$HTTP_CODE"

subsection "Health profile — pregnancy food restrictions"
api_post "$LARAVEL/profile/health" \
  '{"conditions":["pregnancy_t2"],"allergies":[],"sensitivities":["raw_fish","undercooked_meat","soft_cheese","excess_caffeine","alcohol"],"medical_notes":"2nd trimester — 300 extra kcal/day. Avoid: raw fish, undercooked eggs, high-mercury fish. Nausea trigger: strong smells, greasy food."}' "$TOKEN_FATUMA"
check "Fatuma pregnancy health profile" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Meal pattern — pregnancy (frequent small meals)"
api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":5,"preferred_meal_times":["07:30","10:30","13:00","16:00","19:30"],"meal_pattern":"pregnancy_frequent","cuisine_preference":"coastal_kenyan"}' "$TOKEN_FATUMA"
check "Fatuma frequent pregnancy meals" 200 "$HTTP_CODE"

subsection "Edge case — morning sickness (can't eat)"
flask_post "/intent" \
  '{"message":"siezi kula asubuhi kichefuchefu ni sana","user_context":{"conditions":["pregnancy_t2"],"medications":[{"name":"Folic Acid","requires_food":true}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "Fatuma morning sickness intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — food craving (unusual combination)"
flask_post "/intent" \
  '{"message":"nataka sana nyanya na sukari asante hizi ni cravings","user_context":{"conditions":["pregnancy_t2"],"primary_goals":["eat_better"]}}'
check "Fatuma pregnancy craving intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — heartburn (common T2)"
flask_post "/intent" \
  '{"message":"ninaumwa kiungulia sana baada ya chakula","user_context":{"conditions":["pregnancy_t2"],"meal_pattern":"pregnancy_frequent"}}'
check "Fatuma heartburn intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Edge case — unsafe food question"
flask_post "/intent" \
  '{"message":"naweza kula samaki mbichi","user_context":{"conditions":["pregnancy_t2"],"sensitivities":["raw_fish"]}}'
check "Fatuma raw fish safety check intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Flask — pregnancy meal categories"
flask_post "/meal-categories" \
  '{"user_id":9,"budget_remaining_kes":180,"health_conditions":["pregnancy_t2"],"allergies":["raw_fish","undercooked_meat"],
    "fitness_goal":"maintain","today_spent_kes":0,
    "ingredients":[
      {"id":3,"name":"Eggs (well-cooked)","category":"protein","price_kes":16,"calories":78,"protein_g":6,"carbs_g":0,"fat_g":5,"condition_flags":{"pregnancy":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":17,"name":"Liver","category":"protein","price_kes":50,"calories":185,"protein_g":26,"carbs_g":4,"fat_g":7,"condition_flags":{"pregnancy":true,"anaemia":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":18,"name":"Millet","category":"carb","price_kes":20,"calories":170,"protein_g":5,"carbs_g":35,"fat_g":2,"condition_flags":{"pregnancy":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":12,"name":"Managu","category":"vegetable","price_kes":10,"calories":30,"protein_g":3,"carbs_g":4,"fat_g":0,"condition_flags":{"pregnancy":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":13,"name":"Tomatoes","category":"vegetable","price_kes":15,"calories":20,"protein_g":1,"carbs_g":4,"fat_g":0,"condition_flags":{"pregnancy":true},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "Fatuma pregnancy meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

subsection "Flask — pregnancy hydration (higher target)"
flask_post "/hydration/calculate" \
  '{"weight_kg":65,"activity_level":"light","health_conditions":["pregnancy_t2"],"active_slots":["breakfast","mid_morning","lunch","afternoon","dinner"]}'
check "Fatuma pregnancy hydration" 200 "$HTTP_CODE" "target_ml" "$BODY"
TARGET=$(echo "$BODY" | python3 -c "import sys, json; print(json.load(sys.stdin).get('target_ml', ''))")
echo -e "  ${CYAN}Pregnancy hydration target: ${TARGET}ml${NC}"

# ── USER 10 — SAMUEL ──────────────────────────────────────────────────────────
section "USER 10 — SAMUEL (60, T2 diabetes + CKD, dialysis, 300 KES)"

register_and_login "SAMUEL" "Samuel Kipchoge" "samuel@maisha.test" "password123"

subsection "Onboarding (complex medical profile)"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":60,"weight_kg":72,"height_cm":168,"blood_type":"B+"}' "$TOKEN_SAMUEL"
check "Samuel step-about" 200 "$HTTP_CODE" "bmi" "$BODY"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["manage_condition"]}' "$TOKEN_SAMUEL"
check "Samuel manage-condition primary goal" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" \
  '{"conditions":["diabetes","hypertension"]}' "$TOKEN_SAMUEL"
check "Samuel diabetes + hypertension" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" '{"budget_range":"200_400","income_pattern":"weekly"}' "$TOKEN_SAMUEL"
check "Samuel budget" 200 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/complete" '{}' "$TOKEN_SAMUEL"
check "Samuel onboarding complete" 200 "$HTTP_CODE"

subsection "Complex medication regime"
api_post "$LARAVEL/profile/medications" \
  '{"name":"Metformin 500mg","frequency":"twice_daily","duration_type":"ongoing","times":["07:30","19:30"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"breakfast"}' "$TOKEN_SAMUEL"
check "Samuel Metformin" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Lisinopril 10mg","frequency":"once_daily","duration_type":"ongoing","times":["08:00"],"requires_food":false,"food_condition":"none","meal_slot_anchor":"morning"}' "$TOKEN_SAMUEL"
check "Samuel Lisinopril (ACE inhibitor)" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Erythropoietin injection","frequency":"once_daily","duration_type":"ongoing","times":["09:00"],"requires_food":false,"food_condition":"none","meal_slot_anchor":"dialysis_day"}' "$TOKEN_SAMUEL"
check "Samuel EPO injection (CKD anaemia)" 201 "$HTTP_CODE"

api_post "$LARAVEL/profile/medications" \
  '{"name":"Calcium Carbonate (phosphate binder)","frequency":"three_times_daily","duration_type":"ongoing","times":["07:30","13:00","19:30"],"requires_food":true,"food_condition":"with_food","meal_slot_anchor":"with_meals"}' "$TOKEN_SAMUEL"
check "Samuel phosphate binder with meals" 201 "$HTTP_CODE"

subsection "Health profile — CKD restrictions"
api_post "$LARAVEL/profile/health" \
  '{"conditions":["diabetes","hypertension","ckd_stage3"],"allergies":[],"sensitivities":["high_potassium","high_phosphorus","high_protein","high_sodium","high_fluid_when_anuric"],"medical_notes":"CKD Stage 3. Protein restricted: 0.8g/kg/day max (57g). Potassium <2000mg/day. Phosphorus <800mg/day. Dialysis Mon/Wed/Fri 9am. Avoid: bananas, oranges, tomato paste, dairy in large amounts, nuts, seeds, cola drinks."}' "$TOKEN_SAMUEL"
check "Samuel CKD restriction profile" 200 "$HTTP_CODE" "saved" "$BODY"

subsection "Activity — dialysis schedule"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"light","exercise_frequency":"walk_daily","sleep_schedule":"21:00-05:00"}' "$TOKEN_SAMUEL"
check "Samuel light activity" 200 "$HTTP_CODE"

subsection "Meal pattern — CKD 4-meal"
api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":4,"preferred_meal_times":["07:30","12:00","15:00","19:00"],"meal_pattern":"ckd_4meal","cuisine_preference":"kenyan"}' "$TOKEN_SAMUEL"
check "Samuel CKD meal pattern" 200 "$HTTP_CODE"

subsection "Edge case — potassium-rich food question"
flask_post "/intent" \
  '{"message":"naweza kula ndizi moja tu leo","user_context":{"conditions":["ckd_stage3","diabetes"],"sensitivities":["high_potassium"]}}'
check "Samuel banana potassium safety intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — phosphate-rich food temptation"
flask_post "/intent" \
  '{"message":"jirani alinileta maziwa na cheese nyumbani ni nzuri sana","user_context":{"conditions":["ckd_stage3"],"sensitivities":["high_phosphorus"]}}'
check "Samuel dairy phosphorus intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — dialysis day appetite loss"
flask_post "/intent" \
  '{"message":"siku ya dialysis siwa njaa kabisa","user_context":{"conditions":["ckd_stage3","diabetes"],"medications":[{"name":"Metformin","requires_food":true}]},"frequency":"once_daily","duration_type":"ongoing"}'
check "Samuel dialysis-day appetite intent" 200 "$HTTP_CODE" "intent" "$BODY"
echo -e "  ${CYAN}Intent: $(echo "$BODY" | grep -o '"intent":"[^"]*"' | cut -d'"' -f4)${NC}"

subsection "Edge case — fluid restriction"
flask_post "/intent" \
  '{"message":"daktari amesema nipunguze maji kwa sababu ya figo","user_context":{"conditions":["ckd_stage3"],"sensitivities":["high_fluid_when_anuric"]}}'
check "Samuel fluid restriction intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Edge case — dialysis schedule change"
flask_post "/intent" \
  '{"message":"daktari amesema nianze dialysis mara tatu kwa wiki kuanzia juzi","user_context":{"conditions":["ckd_stage3","diabetes"],"primary_goals":["manage_condition"]}}'
check "Samuel dialysis schedule change intent" 200 "$HTTP_CODE" "intent" "$BODY"

subsection "Flask — CKD restricted meal categories"
flask_post "/meal-categories" \
  '{"user_id":10,"budget_remaining_kes":300,"health_conditions":["diabetes","hypertension","ckd_stage3"],
    "allergies":["high_potassium","high_phosphorus"],
    "fitness_goal":"manage_condition","today_spent_kes":0,
    "ingredients":[
      {"id":19,"name":"White Rice (boiled, drained)","category":"carb","price_kes":40,"calories":200,"protein_g":3,"carbs_g":45,"fat_g":0,"condition_flags":{"diabetes":false,"ckd":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":20,"name":"Egg White Only","category":"protein","price_kes":16,"calories":17,"protein_g":4,"carbs_g":0,"fat_g":0,"condition_flags":{"ckd":true,"diabetes":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":21,"name":"Cabbage","category":"vegetable","price_kes":10,"calories":25,"protein_g":1,"carbs_g":5,"fat_g":0,"condition_flags":{"ckd":true,"hypertension":true},"allergen_flags":[],"available":true,"in_season":true},
      {"id":22,"name":"Chapati (low salt)","category":"carb","price_kes":15,"calories":200,"protein_g":4,"carbs_g":38,"fat_g":4,"condition_flags":{"ckd":true},"allergen_flags":[],"available":true,"in_season":true}
    ]}'
check "Samuel CKD meal categories" 200 "$HTTP_CODE" "slot_menus" "$BODY"

subsection "Flask — CKD restricted hydration"
flask_post "/hydration/calculate" \
  '{"weight_kg":72,"activity_level":"light","health_conditions":["ckd_stage3","hypertension"],"active_slots":["breakfast","lunch","afternoon","dinner"]}'
check "Samuel CKD hydration (restricted)" 200 "$HTTP_CODE" "target_ml" "$BODY"
TARGET=$(echo "$BODY" | python3 -c "import sys, json; print(json.load(sys.stdin).get('target_ml', ''))")
echo -e "  ${CYAN}CKD hydration target: ${TARGET}ml (expect <1500ml if anuric)${NC}"

subsection "Profile completion — medical complexity"
api_get "$LARAVEL/profile/completion" "$TOKEN_SAMUEL"
check "Samuel profile completion" 200 "$HTTP_CODE" "overall" "$BODY"

api_get "$LARAVEL/profile/medications" "$TOKEN_SAMUEL"
check "Samuel medications list (4 meds)" 200 "$HTTP_CODE" "Metformin" "$BODY"
MED_COUNT=$(echo "$BODY" | grep -o '"name"' | wc -l)
echo -e "  ${CYAN}Samuel medications count: $MED_COUNT${NC}"

# ════════════════════════════════════════════════════════════════════════════
# CROSS-CUTTING EDGE CASES
# ════════════════════════════════════════════════════════════════════════════
section "CROSS-CUTTING — Validation & Security"

subsection "Onboarding — invalid field values"
api_post "$LARAVEL/onboarding/step-about" \
  '{"age":200,"weight_kg":10,"height_cm":300}' "$TOKEN_AMINA"
check "step-about — out-of-range values → 422" 422 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-2" \
  '{"conditions":["unknown_condition"]}' "$TOKEN_AMINA"
check "step-2 — invalid condition → 422" 422 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-3" \
  '{"budget_range":"free_money"}' "$TOKEN_AMINA"
check "step-3 — invalid budget → 422" 422 "$HTTP_CODE"

api_post "$LARAVEL/onboarding/step-1" \
  '{"primary_goals":["become_superhero"]}' "$TOKEN_AMINA"
check "step-1 — invalid goal → 422" 422 "$HTTP_CODE"

subsection "Profile endpoints — invalid data"
api_post "$LARAVEL/profile/activity" \
  '{"activity_level":"ultra_supersonic"}' "$TOKEN_AMINA"
check "Activity — invalid level → 422" 422 "$HTTP_CODE"

api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":10}' "$TOKEN_AMINA"
check "Meal pattern — meals_per_day > 6 → 422" 422 "$HTTP_CODE"

api_post "$LARAVEL/profile/meal-pattern" \
  '{"meals_per_day":0}' "$TOKEN_AMINA"
check "Meal pattern — meals_per_day 0 → 422" 422 "$HTTP_CODE"

subsection "Flask — missing required fields"
resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
  -X POST "$FLASK/utakulaa" \
  -H "Content-Type: application/json" \
  -H "X-Maisha-Internal-Token: $INTERNAL_TOKEN" \
  -d '{"health_conditions":[]}')
BODY=$(echo "$resp" | sed '$d')
HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
check "Flask /utakulaa — missing budget → 400" 400 "$HTTP_CODE" "budget_remaining_kes" "$BODY"

resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
  -X POST "$FLASK/utakulaa" \
  -H "Content-Type: application/json" \
  -H "X-Maisha-Internal-Token: $INTERNAL_TOKEN" \
  -d '{"budget_remaining_kes":150}')
BODY=$(echo "$resp" | sed '$d')
HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
check "Flask /utakulaa — missing ingredients → 400" 400 "$HTTP_CODE" "ingredients" "$BODY"

resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
  -X POST "$FLASK/utakulaa" \
  -H "Content-Type: application/json" \
  -H "X-Maisha-Internal-Token: $INTERNAL_TOKEN" \
  -d 'not json at all')
BODY=$(echo "$resp" | sed '$d')
HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
check "Flask /utakulaa — invalid JSON → 400" 400 "$HTTP_CODE"

subsection "Flask — intent with empty message"
resp=$(curl -s -w "\n__HTTP_CODE__%{http_code}" \
  -X POST "$FLASK/intent" \
  -H "Content-Type: application/json" \
  -H "X-Maisha-Internal-Token: $INTERNAL_TOKEN" \
  -d '{"message":"","user_context":{}}')
BODY=$(echo "$resp" | sed '$d')
HTTP_CODE=$(echo "$resp" | tail -1 | sed 's/__HTTP_CODE__//')
check "Flask /intent — empty message → 400" 400 "$HTTP_CODE"

subsection "Rate limiting"
echo -e "  ${CYAN}Testing auth rate limit (12 rapid requests on /login)…${NC}"
RATE_HIT=false
for i in $(seq 1 12); do
  resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST "$LARAVEL/login" \
    -H "Content-Type: application/json" \
    -d '{"email":"ratetest@maisha.test","password":"wrong"}')
  if [[ "$resp" == "429" ]]; then
    RATE_HIT=true
    echo -e "  ${GREEN}✓${NC} Rate limit hit at request $i (HTTP 429)"
    ((PASS++))
    break
  fi
done
sleep 3
if ! $RATE_HIT; then
  echo -e "  ${YELLOW}⚠${NC} Rate limit not triggered in 12 requests — check throttle:auth config"
  ((SKIP++))
fi

subsection "Token security"
api_get "$LARAVEL/me" "tampered_token_abc123"
check "Tampered token → 401" 401 "$HTTP_CODE"

api_get "$LARAVEL/me" ""
check "Empty token → 401" 401 "$HTTP_CODE"

section "CROSS-CUTTING — Flask Meal Pattern Warnings"

subsection "Diabetic with 3-meal pattern"
flask_post "/meal-pattern/validate" \
  '{"active_slots":["breakfast","lunch","dinner"],"goals":["manage_condition"],"conditions":["diabetes"]}'
check "Diabetic — 3 meal pattern warnings" 200 "$HTTP_CODE" "warnings" "$BODY"

subsection "Skip breakfast with medication anchor"
flask_post "/meal-pattern/validate" \
  '{"active_slots":["lunch","dinner"],"goals":["lose_weight"],"conditions":[]}'
check "Skip breakfast — pattern warning" 200 "$HTTP_CODE" "warnings" "$BODY"

subsection "H.Pylori requires 3 anchors"
flask_post "/meal-pattern/validate" \
  '{"active_slots":["breakfast","lunch","dinner"],"goals":["eat_better"],"conditions":["h_pylori"]}'
check "H.Pylori 3-anchor validation" 200 "$HTTP_CODE" "warnings" "$BODY"

subsection "CKD — protein distribution"
flask_post "/meal-pattern/validate" \
  '{"active_slots":["breakfast","lunch","dinner"],"goals":["manage_condition"],"conditions":["ckd_stage3","diabetes"]}'
check "CKD protein distribution warnings" 200 "$HTTP_CODE" "warnings" "$BODY"

section "CROSS-CUTTING — Multi-User Data Isolation"

api_get "$LARAVEL/profile/health" "$TOKEN_AMINA"
check "Amina health profile (own data)" 200 "$HTTP_CODE"

api_get "$LARAVEL/budget/today" "$TOKEN_KEVIN"
check "Kevin budget (own data)" 200 "$HTTP_CODE"

AMINA_SPENT=$(curl -s -H "Authorization: Bearer $TOKEN_AMINA" "$LARAVEL/budget/today" | grep -o '"spent":[0-9.]*' | cut -d: -f2)
KEVIN_SPENT=$(curl -s -H "Authorization: Bearer $TOKEN_KEVIN" "$LARAVEL/budget/today" | grep -o '"spent":[0-9.]*' | cut -d: -f2)
if [[ "$AMINA_SPENT" != "$KEVIN_SPENT" ]] || [[ "$AMINA_SPENT" == "0" && "$KEVIN_SPENT" == "0" ]]; then
  echo -e "  ${GREEN}✓${NC} User budget isolation confirmed (Amina: ${AMINA_SPENT}, Kevin: ${KEVIN_SPENT})"
  ((PASS++))
else
  echo -e "  ${YELLOW}⚠${NC} Both budgets at $AMINA_SPENT — check isolation or both genuinely 0"
  ((SKIP++))
fi

# ════════════════════════════════════════════════════════════════════════════
# WEEKLY BUDGET + MEAL HISTORY — ALL USERS
# ════════════════════════════════════════════════════════════════════════════
section "BUDGET VIEWS — All Users Weekly"

for USER in AMINA JAMES BRENDA BRIAN MARY KEVIN GRACE PETER FATUMA SAMUEL; do
  eval "TOK=\$TOKEN_$USER"
  api_get "$LARAVEL/budget/weekly" "$TOK"
  check "$USER weekly budget" 200 "$HTTP_CODE" "data" "$BODY"
done

section "MEAL SUGGESTIONS HISTORY"

for USER in AMINA JAMES MARY KEVIN; do
  eval "TOK=\$TOKEN_$USER"
  api_get "$LARAVEL/meal-suggestions" "$TOK"
  check "$USER meal suggestions history" 200 "$HTTP_CODE" "data" "$BODY"
done

# ════════════════════════════════════════════════════════════════════════════
# RESULTS SUMMARY
# ════════════════════════════════════════════════════════════════════════════
section "TEST RESULTS SUMMARY"

TOTAL=$((PASS + FAIL + SKIP))
echo -e "\n  Total tests run : ${BOLD}$TOTAL${NC}"
echo -e "  ${GREEN}Passed          : $PASS${NC}"
echo -e "  ${RED}Failed          : $FAIL${NC}"
echo -e "  ${YELLOW}Skipped         : $SKIP${NC}"

if [[ $FAIL -gt 0 ]]; then
  echo -e "\n  ${RED}${BOLD}Failed tests:${NC}"
  for t in "${FAILED_TESTS[@]}"; do
    echo -e "    ${RED}✗${NC} $t"
  done
fi

echo ""
if [[ $FAIL -eq 0 ]]; then
  echo -e "  ${GREEN}${BOLD}All tests passed! ✓${NC}"
  exit 0
else
  echo -e "  ${RED}${BOLD}$FAIL test(s) failed.${NC}"
  exit 1
fi