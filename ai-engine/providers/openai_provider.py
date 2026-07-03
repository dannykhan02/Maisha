import os
import config

# This module is a structural placeholder until OPENAI_API_KEY is purchased.
# All functions degrade gracefully when the key is absent — router.py
# will skip this provider automatically via test_connection().

try:
    import openai  # type: ignore  # noqa: F401
    _OPENAI_AVAILABLE = True
except ImportError:
    openai = None  # type: ignore
    _OPENAI_AVAILABLE = False

MODEL = 'gpt-4o-mini'  # cheap, fast — adjust when key is purchased


def _client():
    if not _OPENAI_AVAILABLE or not config.OPENAI_API_KEY:
        return None
    return openai.OpenAI(api_key=config.OPENAI_API_KEY)  # type: ignore


def explain_meal(meal: dict, user_context: dict) -> str:
    client = _client()
    if not client:
        raise RuntimeError('OpenAI not configured — no API key')

    # Mirrors claude_provider's plan_summary-based prompt
    budget  = float(user_context.get('budget_remaining_kes', 0))
    cost    = float(meal.get('est_cost_kes', 0))
    savings = float(meal.get('savings_kes', budget - cost))
    top_items = meal.get('top_items', [])
    meal_name = ' + '.join(top_items) if top_items else 'your meal plan'

    conditions    = user_context.get('health_conditions', []) or []
    primary_goals = user_context.get('primary_goals', []) or []
    fitness_goal  = user_context.get('fitness_goal', 'maintain')
    all_goals     = list(set(primary_goals + [fitness_goal]))

    prompt = f"""Write a meal recommendation.

Meal: {meal_name}
Est. cost: {cost:.0f} KES  |  Budget: {budget:.0f} KES  |  Savings: {savings:.0f} KES
Health conditions: {', '.join(conditions) or 'none'}
Goals: {', '.join(all_goals) or 'maintain'}"""

    response = client.chat.completions.create(
        model=MODEL,
        max_tokens=200,
        messages=[
            {'role': 'system', 'content': 'You are Maisha, a warm Kenyan nutrition companion. 3-4 sentences, mostly English with 1-2 Swahili words.'},
            {'role': 'user', 'content': prompt}
        ]
    )
    return response.choices[0].message.content.strip()


def classify_intent(message: str, user_context: dict) -> str:
    client = _client()
    if not client:
        raise RuntimeError('OpenAI not configured — no API key')

    response = client.chat.completions.create(
        model=MODEL,
        max_tokens=10,
        messages=[
            {'role': 'system', 'content': 'Classify food app messages. Reply with exactly one word: utakulaa, budget, history, or help.'},
            {'role': 'user', 'content': message}
        ]
    )
    intent = response.choices[0].message.content.strip().lower()
    return intent if intent in ['utakulaa', 'budget', 'history', 'help'] else 'utakulaa'


def test_connection() -> bool:
    """Returns False cleanly if no key is configured — this is what
    router.py checks before attempting to use this provider."""
    client = _client()
    if not client:
        return False
    try:
        r = client.chat.completions.create(
            model=MODEL, max_tokens=5,
            messages=[{'role': 'user', 'content': 'ok'}]
        )
        return bool(r.choices[0].message.content)
    except Exception:
        return False