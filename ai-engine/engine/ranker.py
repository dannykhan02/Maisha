# engine/ranker.py
#
# Nutrition scoring + variety penalty.
# Called once per ingredient per goal — results averaged across all goals.
# Variety penalty is applied separately to prevent repetitive suggestions.
#
# Supports:
# - Weight loss
# - Muscle gain
# - Diabetes management
# - Heart health
# - Anaemia
# - Kidney support
# - Digestive health
# - General nutrition
# - Hydration
#
# Dashboard profile completion goals are normalised into canonical
# internal goals before scoring.

from datetime import date

def score_ingredient(ingredient: dict, goal: str) -> float:
    """
    Score a single ingredient against a single goal.

    Returns:
        float: Higher score = better fit for the goal.
    """

    p = float(ingredient.get('protein_g', 0) or 0)
    c = float(ingredient.get('calories', 0) or 0)
    f = float(ingredient.get('fat_g', 0) or 0)
    fb = float(ingredient.get('fibre_g', 0) or 0)
    cr = float(ingredient.get('carbs_g', 0) or 0)
    ir = float(ingredient.get('iron_mg', 0) or 0)

    goal = _normalise_goal(goal)

    if goal == 'weight_loss':
        # High protein + fibre improves satiety
        return (p * 2.0) + (fb * 1.5) - (c * 0.01) - (f * 0.5)

    elif goal == 'muscle_gain':
        # Protein first, carbs support training
        return (p * 3.5) + (cr * 0.5) - (f * 0.2)

    elif goal == 'manage_condition':
        # Generic chronic condition support
        return (fb * 2.5) + (p * 1.5) - (cr * 0.8) - (f * 0.3)

    elif goal == 'diabetic_control':
        # Strong carb penalty
        return (fb * 3.0) + (p * 1.5) - (cr * 1.2) - (f * 0.2)

    elif goal == 'heart_health':
        # Low fat + high fibre
        return (fb * 2.5) + (p * 1.0) - (f * 1.5) - (cr * 0.3)

    elif goal == 'anaemia':
        # Iron-focused
        return (ir * 4.0) + (p * 1.5) + (fb * 0.5) - (f * 0.2)

    elif goal == 'kidney_support':
        # Lower protein load
        return -(p * 0.5) + (cr * 0.3) - (f * 0.2)

    elif goal == 'digestive_health':
        # High fibre, low fat
        return (fb * 3.0) - (f * 2.0) + (p * 0.5) - (cr * 0.3)

    elif goal == 'eat_better':
        # Balanced nutrition
        return (p * 1.5) + (fb * 1.5) - (c * 0.005) - (f * 0.1)

    elif goal == 'hydration':
        # Water-rich foods proxy
        return (fb * 2.0) - (c * 0.02) - (f * 0.5)

    else:
        # maintain
        return (p * 1.5) + (fb * 1.0) - (c * 0.005)


def variety_penalty(ingredient_id, history_map: dict) -> float:
    """
    Penalize ingredients that have been used recently.

    history_map format expected from Laravel:
    {
        ingredient_id: {
            "last_used_at": "YYYY-MM-DD",   # last date this ingredient was selected
            "usage_count": int               # number of times used in the last 7 days
        }
    }
    Returns penalty between 0 and 1.0.
    """
    if not ingredient_id or ingredient_id not in history_map:
        return 0.0

    h = history_map[ingredient_id]
    last_used_str = h.get('last_used_at')
    usage_count = h.get('usage_count', 0)

    if not last_used_str:
        return 0.0

    try:
        last_used = date.fromisoformat(last_used_str)
        days_since = (date.today() - last_used).days
    except (ValueError, TypeError):
        return 0.0

    # Base penalty for recency
    if days_since <= 1:
        recency_penalty = 0.5      # used yesterday → strong penalty
    elif days_since <= 3:
        recency_penalty = 0.2      # used within 3 days → mild penalty
    else:
        recency_penalty = 0.0

    # Extra penalty if used repeatedly (even if not recent)
    frequency_penalty = min(0.3, (usage_count - 1) * 0.1) if usage_count > 1 else 0.0

    return round(recency_penalty + frequency_penalty, 2)


def _normalise_goal(goal: str) -> str:
    """
    Convert dashboard goals and onboarding goals
    into canonical internal nutrition goals.

    This allows frontend goal names to evolve
    without changing scoring logic.
    """

    if not goal:
        return 'maintain'

    goal = str(goal).strip().lower()

    mapping = {

        # --------------------------------------------------
        # Weight Management
        # --------------------------------------------------
        'lose_weight': 'weight_loss',
        'weight_loss': 'weight_loss',

        # --------------------------------------------------
        # Muscle / Fitness
        # --------------------------------------------------
        'gain_muscle': 'muscle_gain',
        'muscle_gain': 'muscle_gain',
        'build_muscle': 'muscle_gain',

        # --------------------------------------------------
        # Condition Management
        # --------------------------------------------------
        'manage_condition': 'manage_condition',

        # Diabetes
        'manage_diabetes': 'diabetic_control',
        'diabetic_control': 'diabetic_control',
        'blood_sugar': 'diabetic_control',

        # Heart Health
        'manage_heart': 'heart_health',
        'heart_health': 'heart_health',
        'hypertension': 'heart_health',

        # Anaemia
        'manage_anaemia': 'anaemia',
        'anaemia': 'anaemia',
        'iron_deficiency': 'anaemia',

        # Kidney
        'kidney_support': 'kidney_support',
        'kidney_disease': 'kidney_support',

        # Digestive Conditions
        'digestive_health': 'digestive_health',
        'ulcer_management': 'digestive_health',
        'gut_health': 'digestive_health',
        'h_pylori': 'digestive_health',

        # --------------------------------------------------
        # General Wellness
        # --------------------------------------------------
        'eat_better': 'eat_better',
        'nutrition': 'eat_better',
        'balanced_diet': 'eat_better',

        'hydration': 'hydration',

        'maintain': 'maintain',
        'maintenance': 'maintain',

        # --------------------------------------------------
        # Non-food goals
        # Map to closest nutrition behaviour
        # --------------------------------------------------
        'track_spending': 'eat_better',
        'save_money': 'eat_better',

        'read_more': 'maintain',
        'quit_habit': 'maintain',
    }

    return mapping.get(goal, 'maintain')