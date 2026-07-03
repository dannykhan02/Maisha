# engine/utakulaa_algorithm.py
#
# The Utakulaa Algorithm — Maisha Intelligence Layer
#
# 4 filters run sequentially per meal slot:
#   A: Budget (Tier 3 shopping cost only — pantry is free)
#   B: Health (conditions + allergies + sensitivities)
#   C: Diet identity (hard filters: halal, vegan, no_pork etc)
#   D: Nutrition scoring (goals + variety penalty + preference boosts)
#   E: Availability (in-stock, in-season)
#
# Output: category menus per active meal slot + hydration plan
# Claude is called once at the end to write the human explanation.

import json

from engine.ranker import score_ingredient
from engine.hydration_engine import calculate_water_target, distribute_hydration
from engine.meal_slots import get_slot_targets
from engine.meal_pattern_engine import validate_meal_pattern
from engine.pantry_engine import resolve_pantry_cost, mark_pantry_items
from providers.router import get_meal_explanation
from engine.slot_normaliser import normalise_slots
from engine.portion_engine import describe_portion  # Day 8

# ── All supported health conditions ───────────────────────────────────────────
KNOWN_CONDITIONS = {
    'diabetes', 'pre_diabetes', 'high_cholesterol', 'thyroid',
    'hypertension', 'heart_disease',
    'ulcer', 'h_pylori', 'ibs', 'acid_reflux', 'crohns', 'celiac',
    'anaemia', 'kidney_disease', 'gout',
    'obesity', 'osteoporosis', 'liver_disease',
    'lactose_intolerance', 'nut_allergy',
}

MEAL_CATEGORIES = ['staple', 'protein', 'vegetable', 'fruit', 'drink', 'legume']
OPTIONS_PER_CATEGORY = 3

# Gram basis for portion_label — category midpoint until per-ingredient
# portion_reference lands in the ingredients table seeder pass
CATEGORY_PORTION_GRAMS_BASIS = {
    'protein':   100,
    'legume':    120,
    'staple':    120,
    'vegetable':  80,
    'fruit':      80,
    'fat':        14,
    'drink':     250,
}

# ── Dietary identity → ingredient-level rules ─────────────────────────────────
# Maps dietary identity flags to allergen/category exclusion rules
# 'allergen' = must not appear in allergen_flags
# 'category_name' = ingredient name patterns to exclude (soft, name-based)
DIETARY_HARD_RULES = {
    'halal':       {'exclude_allergens': ['pork', 'alcohol']},
    'no_pork':     {'exclude_allergens': ['pork']},
    'no_beef':     {'exclude_allergens': ['beef']},
    'vegetarian':  {'exclude_allergens': ['meat', 'fish', 'pork', 'beef', 'chicken']},
    'vegan':       {'exclude_allergens': ['meat', 'fish', 'pork', 'beef', 'chicken', 'dairy', 'eggs']},
    'gluten_free': {'exclude_allergens': ['gluten', 'wheat']},
    'dairy_free':  {'exclude_allergens': ['dairy', 'lactose']},
}

# Protein preference → maps to allergen flags that should be KEPT
# Everything else in protein category is deprioritised (not hard excluded)
PROTEIN_PREFERENCE_MAP = {
    'chicken':    ['chicken', 'poultry'],
    'fish':       ['fish', 'seafood'],
    'beef':       ['beef', 'meat'],
    'eggs_dairy': ['eggs', 'dairy'],
    'plant_only': [],  # handled via vegetarian/vegan logic
    'any':        [],  # no filter
}


def run_utakulaa(payload: dict) -> dict:
    """
    Main entry point. Receives full context from Laravel.
    Returns structured day plan: slot menus + hydration + explanation.
    """
    budget           = float(payload.get('budget_remaining_kes', 0))
    pantry_only_mode = budget <= 0  # Day 9: zero-budget fallback

    conditions       = _normalise_conditions(payload.get('health_conditions', []))
    allergies        = payload.get('allergies', []) or []
    sensitivities    = payload.get('sensitivities', []) or []
    primary_goals    = payload.get('primary_goals', [])
    fitness_goal     = payload.get('fitness_goal', 'maintain')
    all_goals        = _merge_goals(primary_goals, fitness_goal)
    ingredients      = payload.get('ingredients', [])
    pantry           = payload.get('pantry', [])
    medications      = payload.get('medications', [])
    history          = payload.get('suggestion_history', [])

    # Day 9 — medicine-budget collision detection
    medication_budget_warnings = _check_medication_budget_collision(medications, budget)
    if medication_budget_warnings:
        pantry_only_mode = True

    # ── Diet profile fields (new) ──────────────────────────────────────
    dietary_identity   = payload.get('dietary_identity', []) or []
    food_dislikes      = [d.lower().strip() for d in (payload.get('food_dislikes', []) or [])]
    cooking_source     = payload.get('cooking_source', 'both') or 'both'
    protein_preference = payload.get('protein_preference', 'any') or 'any'
    staple_preference  = [s.lower().strip() for s in (payload.get('staple_preference', []) or [])]

    # ── Active slots — sent by Laravel, normalise defensively ─────────
    active_slots = normalise_slots(
        payload.get('active_slots') or ['breakfast', 'lunch', 'dinner']
    )

    # ── Pantry: mark zero-cost items ───────────────────────────────────
    ingredients = mark_pantry_items(ingredients, pantry)

    # ── Meal slot targets ──────────────────────────────────────────────
    slot_targets, pattern_notes = get_slot_targets(active_slots, all_goals, payload)

    # ── Validate meal pattern ──────────────────────────────────────────
    pattern_warnings = validate_meal_pattern(active_slots, all_goals, conditions)

    # ── Build dietary exclusion set from identity ──────────────────────
    # Compiled once, applied per ingredient
    hard_exclude_allergens = _build_dietary_exclusions(dietary_identity)

    all_health_notes = []
    slot_menus       = {}

    for slot, target in slot_targets.items():
        slot_budget = target.get('budget_kes', budget / max(len(slot_targets), 1))

        # Filter A: budget (Day 9 — pantry-only mode when budget is zero)
        if pantry_only_mode:
            affordable = [i for i in ingredients if i.get('_in_pantry')]
            if not affordable:
                # Zero budget AND empty pantry — degrade honestly, never crash
                all_health_notes.append(
                    f'No pantry items for {slot} and budget is KES 0. '
                    f'Showing lowest-cost options as a starting point.'
                )
                affordable = sorted(
                    ingredients, key=lambda x: resolve_pantry_cost(x)
                )[:8]
        else:
            affordable = [i for i in ingredients if resolve_pantry_cost(i) <= slot_budget]
            if not affordable:
                affordable = sorted(
                    ingredients, key=lambda x: resolve_pantry_cost(x)
                )[:8]

        # Filter B: health + allergies + sensitivities
        safe, health_notes = _health_filter(
            affordable, conditions, allergies, sensitivities
        )
        all_health_notes.extend(health_notes)
        if not safe:
            safe = affordable
            all_health_notes.append(
                f'Limited safe options for {slot}. Please consult your doctor.'
            )

        # Filter C: dietary identity (hard rules — halal, vegan, no_pork etc)
        diet_safe, diet_notes = _dietary_identity_filter(
            safe, hard_exclude_allergens, dietary_identity
        )
        all_health_notes.extend(diet_notes)
        if not diet_safe:
            diet_safe = safe  # fallback — never return empty

        # Filter D: nutrition score + variety penalty + preference boosts
        scored = _score_and_sort(
            diet_safe, all_goals, history,
            staple_preference=staple_preference,
            protein_preference=protein_preference,
            food_dislikes=food_dislikes,
        )

        # Filter E: availability
        available = [i for i in scored if i.get('available') and i.get('in_season')]
        if not available:
            available = scored

        # Build category menu for this slot
        slot_menus[slot] = _build_category_menu(
            available, slot_budget, target, all_goals,
            cooking_source=cooking_source,
            staple_preference=staple_preference,
        )

    # Deduplicate health notes
    seen_notes, unique_notes = set(), []
    for note in all_health_notes:
        if note not in seen_notes:
            seen_notes.add(note)
            unique_notes.append(note)

    # Hydration plan
    water_target_ml = calculate_water_target(payload)
    hydration_plan  = distribute_hydration(water_target_ml, active_slots, conditions)

    # Savings calculation
    est_total_cost = 0
    for slot, menu in slot_menus.items():
        slot_min = 0
        for category, options in menu.get('categories', {}).items():
            if options:
                best = min(options, key=lambda x: x.get('shopping_cost_kes', 0))
                slot_min += best.get('shopping_cost_kes', 0)
        est_total_cost += slot_min

    savings     = round(max(budget - est_total_cost, 0), 2)
    savings_msg = _savings_message(savings)

    # Build summary for AI explanation
    summary_items = []
    for slot, menu in slot_menus.items():
        cats = menu.get('categories', {})
        for cat in ['staple', 'protein', 'legume', 'vegetable']:
            opts = cats.get(cat, [])
            if opts:
                summary_items.append(opts[0].get('name', ''))
                break

    plan_summary = {
        'slots':           list(slot_menus.keys()),
        'top_items':       summary_items[:4],
        'est_cost_kes':    round(est_total_cost, 2),
        'savings_kes':     savings,
        'water_target_ml': water_target_ml,
    }

    explanation, provider_used = get_meal_explanation(plan_summary, payload)

    med_notes = _medication_notes(medications, active_slots)

    return {
        'slot_menus':                slot_menus,
        'slot_targets':              slot_targets,
        'explanation':               explanation,
        'health_notes':              unique_notes,
        'pattern_warnings':          pattern_warnings + pattern_notes,
        'medication_notes':          med_notes,
        'medication_budget_warnings': medication_budget_warnings,  # Day 9 — new
        'hydration': {
            'target_ml': water_target_ml,
            'plan':      hydration_plan,
        },
        'budget_summary': {
            'daily_budget_kes': budget,
            'est_shopping_kes': round(est_total_cost, 2),
            'savings_kes':      savings,
            'savings_message':  savings_msg,
        },
        'ai_provider_used': provider_used,
        'top_meal':         _build_legacy_top_meal(slot_menus, active_slots),
    }


# ── Dietary identity filter ────────────────────────────────────────────────────

def _build_dietary_exclusions(dietary_identity: list) -> set:
    """
    Compile a set of allergen flags to exclude from all identity rules combined.
    Example: ['halal', 'gluten_free'] → {'pork', 'alcohol', 'gluten', 'wheat'}
    """
    excluded = set()
    for identity in dietary_identity:
        rule = DIETARY_HARD_RULES.get(identity, {})
        excluded.update(rule.get('exclude_allergens', []))
    return excluded


def _dietary_identity_filter(
    ingredients: list,
    hard_exclude_allergens: set,
    dietary_identity: list,
) -> tuple:
    """
    Hard filter based on dietary identity.
    Excludes ingredients whose allergen_flags intersect with the exclusion set.

    Returns (safe_ingredients, notes)
    """
    if not hard_exclude_allergens:
        return ingredients, []

    safe, notes = [], []

    for ing in ingredients:
        allergens = ing.get('allergen_flags') or []
        if isinstance(allergens, str):
            try:
                allergens = json.loads(allergens)
            except Exception:
                allergens = []

        allergens_lower = [a.lower() for a in allergens]
        excluded_hit = hard_exclude_allergens.intersection(set(allergens_lower))

        if excluded_hit:
            identity_label = ', '.join(dietary_identity)
            notes.append(
                f"{ing.get('name', 'Ingredient')} excluded — "
                f"not suitable for {identity_label} diet"
            )
        else:
            safe.append(ing)

    return safe, notes


# ── Category menu builder ──────────────────────────────────────────────────────

def _build_category_menu(
    ingredients, slot_budget, target, goals,
    cooking_source='both',
    staple_preference=None,
):
    """
    Build the category menu for a single meal slot.
    cooking_source affects the price_range label shown to the user.
    staple_preference boosts matching staples to top of list.
    """
    staple_preference = staple_preference or []
    categories = {}

    for cat in MEAL_CATEGORIES:
        cat_items = [i for i in ingredients if i.get('category') == cat]
        if not cat_items:
            continue

        # Boost staple preference items to front of list
        if cat == 'staple' and staple_preference:
            preferred = [
                i for i in cat_items
                if any(s in i.get('name', '').lower() for s in staple_preference)
            ]
            others = [
                i for i in cat_items
                if not any(s in i.get('name', '').lower() for s in staple_preference)
            ]
            cat_items = preferred + others

        n = 2 if cat in ('fruit', 'drink') else OPTIONS_PER_CATEGORY
        options = []

        for ing in cat_items[:n * 2]:
            cost = resolve_pantry_cost(ing)
            if cost > slot_budget:
                continue

            is_pantry  = ing.get('_in_pantry', False)
            tier       = ing.get('_pantry_tier', 3)
            base_price = float(ing.get('price_kes', 0))

            # cooking_source changes the label shown to user
            if is_pantry:
                price_range = 'pantry ✓'
            elif cooking_source == 'food_stalls':
                price_range = f'~{round(base_price * 0.9)}–{round(base_price * 1.3)} KES (stall)'
            elif cooking_source == 'home':
                price_range = f'~{round(base_price * 0.8)}–{round(base_price * 1.1)} KES (market)'
            else:
                price_range = f'~{round(base_price * 0.8)}–{round(base_price * 1.2)} KES'

            goal_note = _goal_note(ing, goals)

            options.append({
                'id':                ing.get('id'),
                'name':              ing.get('name', ''),
                'name_sw':           ing.get('name_sw', ''),
                'category':          cat,
                'price_range':       price_range,
                'shopping_cost_kes': 0 if is_pantry else base_price,
                'calories':          float(ing.get('calories', 0) or 0),
                'protein_g':         float(ing.get('protein_g', 0) or 0),
                'carbs_g':           float(ing.get('carbs_g', 0) or 0),
                'fat_g':             float(ing.get('fat_g', 0) or 0),
                'fibre_g':           float(ing.get('fibre_g', 0) or 0),
                'in_pantry':         is_pantry,
                'pantry_tier':       tier,
                'score':             round(ing.get('_score', 0), 2),
                'is_best_match':     False,
                'goal_note':         goal_note,
                'cooking_source':    cooking_source,
                # Day 8 — category-typical portion language
                # Uses category midpoint as gram basis; per-ingredient precision is deferred
                'portion_label':     describe_portion(ing, CATEGORY_PORTION_GRAMS_BASIS.get(cat, 100)),
            })

            if len(options) >= n:
                break

        if options:
            best = max(options, key=lambda x: x['score'])
            best['is_best_match'] = True
            categories[cat] = options

    return {
        'slot_budget_kes':  round(slot_budget, 2),
        'target_kcal':      target.get('kcal', 0),
        'target_protein_g': target.get('protein_g', 0),
        'categories':       categories,
    }


# ── Scoring with preference boosts ────────────────────────────────────────────

def _score_and_sort(
    ingredients, goals, history,
    staple_preference=None,
    protein_preference='any',
    food_dislikes=None,
):
    """
    Score ingredients against goals, apply variety penalty,
    apply preference boosts and dislike penalties.
    """
    from engine.ranker import score_ingredient, variety_penalty

    staple_preference = staple_preference or []
    food_dislikes     = food_dislikes or []
    protein_pref_tags = PROTEIN_PREFERENCE_MAP.get(protein_preference, [])

    # Normalise history
    history_map = {}
    if isinstance(history, dict):
        for k, v in history.items():
            try:
                history_map[int(k)] = v
            except (ValueError, TypeError):
                continue
    elif isinstance(history, list):
        for h in history:
            if isinstance(h, dict) and 'ingredient_id' in h:
                history_map[h['ingredient_id']] = h

    for ing in ingredients:
        name_lower = ing.get('name', '').lower()
        category   = ing.get('category', '')
        allergens  = ing.get('allergen_flags') or []
        if isinstance(allergens, str):
            try:
                allergens = json.loads(allergens)
            except Exception:
                allergens = []
        allergens_lower = [a.lower() for a in allergens]

        # Base nutrition score across all goals
        nut_scores = [score_ingredient(ing, g) for g in goals]
        nut_score  = sum(nut_scores) / len(nut_scores) if nut_scores else 0

        # Variety penalty
        penalty = variety_penalty(ing.get('id'), history_map)

        # Staple preference boost — user's preferred staples score higher
        staple_boost = 0.0
        if category == 'staple' and staple_preference:
            if any(s in name_lower for s in staple_preference):
                staple_boost = 2.0

        # Protein preference boost — preferred protein type scores higher
        protein_boost = 0.0
        if category == 'protein' and protein_pref_tags:
            if any(tag in allergens_lower for tag in protein_pref_tags):
                protein_boost = 1.5
            elif protein_preference != 'any':
                # Non-preferred protein — mild penalty to push it lower
                protein_boost = -0.5

        # Food dislike penalty — soft, not hard excluded
        # User said they dislike it — deprioritise but keep as fallback
        dislike_penalty = 0.0
        if food_dislikes:
            if any(d in name_lower for d in food_dislikes):
                dislike_penalty = 3.0  # strong enough to push to bottom

        ing['_score'] = round(
            nut_score
            - penalty
            + staple_boost
            + protein_boost
            - dislike_penalty,
            4
        )

    return sorted(ingredients, key=lambda x: x.get('_score', 0), reverse=True)


# ── Health filter — updated to include sensitivities ──────────────────────────

def _health_filter(ingredients, conditions, allergies, sensitivities=None):
    """
    Filter B: Remove ingredients unsafe for conditions or allergies.
    Sensitivities treated as soft allergies — same hard exclude logic
    but note is worded differently.
    """
    sensitivities = sensitivities or []
    safe, notes   = [], []

    for ing in ingredients:
        flags     = ing.get('condition_flags') or {}
        allergens = ing.get('allergen_flags')  or []

        if isinstance(flags, str):
            try:
                flags = json.loads(flags)
            except Exception:
                flags = {}
        if isinstance(allergens, str):
            try:
                allergens = json.loads(allergens)
            except Exception:
                allergens = []

        allergens_lower = [a.lower() for a in allergens]

        # Condition flag check — False means "not suitable for this condition"
        unsafe = any(
            flags.get(cond) is False
            for cond in conditions
            if cond in KNOWN_CONDITIONS
        )

        # Allergy check — hard exclude
        allergic = any(a in allergies for a in allergens_lower)

        # Sensitivity check — same hard exclude, different note tone
        sensitive = any(s in allergens_lower for s in sensitivities)

        if unsafe:
            notes.append(
                f"{ing.get('name', 'Ingredient')} avoided — "
                f"not recommended for your condition"
            )
        elif allergic:
            notes.append(
                f"{ing.get('name', 'Ingredient')} avoided — allergen detected"
            )
        elif sensitive:
            notes.append(
                f"{ing.get('name', 'Ingredient')} avoided — "
                f"you marked sensitivity to this"
            )
        else:
            safe.append(ing)

    return safe, notes


# ── Medication notes — updated to use food_condition ──────────────────────────

def _medication_notes(medications: list, active_slots: list) -> list:
    """
    Build medication reminder notes from enriched medication data.
    Uses food_condition field. Skips as_needed and inactive medications.
    """
    notes = []

    food_condition_messages = {
        'with_food':     'must eat WITH this medication',
        'before_food':   'take 30 minutes BEFORE eating',
        'after_food':    'take AFTER finishing your meal',
        'empty_stomach': 'take on an EMPTY STOMACH — no food for 2 hours',
    }

    for med in medications:
        name           = med.get('name', 'medication')
        dosage         = med.get('dosage', '') or ''
        frequency      = med.get('frequency', 'once_daily') or 'once_daily'
        food_condition = med.get('food_condition', 'none') or 'none'
        meal_periods   = med.get('meal_periods', []) or []
        condition_src  = med.get('condition_source', '') or ''

        # as_needed never fires automatic notes
        if frequency == 'as_needed':
            continue

        dosage_str = f' ({dosage})' if dosage else ''

        if food_condition == 'none':
            # No food restriction — simple reminder, lower priority
            notes.append({
                'medication':     name,
                'dosage':         dosage,
                'food_condition': food_condition,
                'message':        f"Remember to take {name}{dosage_str}.",
                'priority':       'tier_2',
                'condition_for':  condition_src,
            })
            continue

        # Food-dependent medication
        food_msg = food_condition_messages.get(food_condition, '')
        periods  = meal_periods if meal_periods else ['morning']

        for period in periods:
            notes.append({
                'medication':     name,
                'dosage':         dosage,
                'food_condition': food_condition,
                'meal_period':    period,
                'message':        f"Take {name}{dosage_str} — {food_msg}.",
                'priority':       'tier_1',
                'condition_for':  condition_src,
            })

    return notes


# ── Day 9: Medication-budget collision detection ──────────────────────────────

def _check_medication_budget_collision(medications: list, daily_budget_kes: float) -> list:
    """
    Flags when a medication's estimated cost would eat >50% of today's food budget.
    Silence (estimated_cost_kes is None) means 'unknown' — never fires a false warning.
    Only fires when real cost data was captured during onboarding or via dashboard.
    """
    warnings = []
    for med in medications:
        cost = med.get('estimated_cost_kes')
        # None = unknown cost — do not warn, do not assume safe
        if cost is None or daily_budget_kes <= 0:
            continue
        if float(cost) >= daily_budget_kes * 0.5:
            warnings.append({
                'medication':        med.get('name', 'medication'),
                'estimated_cost_kes': float(cost),
                'message': (
                    f"{med.get('name')} costs about KES {cost:.0f} — "
                    f"that's a significant share of today's KES {daily_budget_kes:.0f} "
                    f"food budget. Today's plan leans toward pantry and lower-cost options."
                ),
            })
    return warnings


# ── Legacy top_meal builder ────────────────────────────────────────────────────

def _build_legacy_top_meal(slot_menus, active_slots):
    first_slot = active_slots[0] if active_slots else 'lunch'
    menu = slot_menus.get(first_slot, {})
    cats = menu.get('categories', {})
    meal_items = []
    total_cost = total_cal = total_prot = 0

    for cat in ['staple', 'protein', 'vegetable']:
        opts = cats.get(cat, [])
        best = next((o for o in opts if o.get('is_best_match')), opts[0] if opts else None)
        if best:
            meal_items.append(best)
            total_cost += best.get('shopping_cost_kes', 0)
            total_cal  += best.get('calories', 0)
            total_prot += best.get('protein_g', 0)

    if not meal_items:
        return {}

    return {
        'name':           ' + '.join(i['name'] for i in meal_items),
        'ingredients':    [i['name'] for i in meal_items],
        'ingredient_ids': [i.get('id') for i in meal_items],
        'total_cost_kes': round(total_cost, 2),
        'total_calories': round(total_cal, 1),
        'protein_g':      round(total_prot, 1),
        'score':          round(
            sum(i.get('score', 0) for i in meal_items) / max(len(meal_items), 1), 2
        ),
        'slot': first_slot,
    }


# ── Private helpers ────────────────────────────────────────────────────────────

def _normalise_conditions(conditions):
    if isinstance(conditions, str):
        try:
            conditions = json.loads(conditions)
        except Exception:
            conditions = [conditions]
    return [c for c in (conditions or []) if c in KNOWN_CONDITIONS]


def _merge_goals(primary_goals, fitness_goal):
    if isinstance(primary_goals, str):
        try:
            primary_goals = json.loads(primary_goals)
        except Exception:
            primary_goals = [primary_goals]
    goals = list(primary_goals or [])
    if fitness_goal and fitness_goal not in goals:
        goals.append(fitness_goal)
    return goals or ['maintain']


def _goal_note(ingredient, goals):
    p  = float(ingredient.get('protein_g', 0) or 0)
    fb = float(ingredient.get('fibre_g',   0) or 0)
    c  = float(ingredient.get('calories',  0) or 0)

    from engine.ranker import _normalise_goal
    norm_goals = {_normalise_goal(g) for g in goals}

    if 'weight_loss'      in norm_goals and c < 100:
        return 'low calorie — good for weight loss'
    if 'muscle_gain'      in norm_goals and p >= 10:
        return f'high protein ({p:.0f}g)'
    if 'manage_condition' in norm_goals and fb >= 3:
        return f'high fibre ({fb:.0f}g) — blood sugar friendly'
    if p >= 8:
        return f'{p:.0f}g protein'
    if fb >= 3:
        return f'{fb:.0f}g fibre'
    return ''


def _savings_message(savings):
    if savings <= 0:
        return ''
    monthly = round(savings * 30)
    return f'Ukiokoa {savings:.0f} KES kila siku — {monthly} KES mwezi wote!'