import os
import json
import anthropic
from dotenv import load_dotenv

load_dotenv()

client = anthropic.Anthropic(api_key=os.getenv('ANTHROPIC_API_KEY', ''))

# Single shared model constant — every function below uses this.
# claude-3-haiku-20240307 was retired; this was breaking explain_meal,
# classify_intent, test_connection, and classify_health_condition all at once.
MODEL = 'claude-haiku-4-5-20251001'

SYSTEM_PROMPT = """You are Maisha, a friendly Kenyan health and budget companion.

Write 3-4 sentences only — this is read on WhatsApp or a mobile screen, keep it short.

Language rules:
- Write mostly in English
- Use Swahili only for 1-2 warm words or phrases like "Sawa!", "Pole pole", "Hongera", "Chakula chema", "Endelea hivyo"
- Never write full Swahili sentences

Content rules:
- Mention the meal name and exact cost in KES
- Give one clear reason it fits the user's health goal or condition
- If they saved money, mention the savings amount
- End with one short encouraging line

Tone: warm, supportive friend — not a doctor, not a robot.
Never introduce yourself. Never say you are an AI."""


def explain_meal(meal: dict, user_context: dict) -> str:
    try:
        budget  = float(user_context.get('budget_remaining_kes', 0))
        cost    = float(meal.get('est_cost_kes', 0))
        savings = float(meal.get('savings_kes', budget - cost))

        conditions    = user_context.get('health_conditions', []) or []
        primary_goals = user_context.get('primary_goals', []) or []
        fitness_goal  = user_context.get('fitness_goal', 'maintain')
        all_goals     = list(set(primary_goals + [fitness_goal]))

        top_items = meal.get('top_items', [])
        meal_name = ' + '.join(top_items) if top_items else 'your meal plan'

        water_ml = meal.get('water_target_ml', 0)

        user_prompt = f"""Write a meal recommendation.

Meal: {meal_name}
Est. cost: {cost:.0f} KES  |  Budget: {budget:.0f} KES  |  Savings: {savings:.0f} KES
Health conditions: {', '.join(conditions) or 'none'}
Goals: {', '.join(all_goals) or 'maintain'}
Daily water target: {water_ml}ml"""

        msg = client.messages.create(
            model=MODEL,
            max_tokens=200,
            system=SYSTEM_PROMPT,
            messages=[{'role': 'user', 'content': user_prompt}]
        )
        return msg.content[0].text.strip()

    except Exception as e:
        print(f'[Claude] explain_meal failed: {e}')
        top_items = meal.get('top_items', [])
        name = ' + '.join(top_items) if top_items else 'your meal plan'
        cost = meal.get('est_cost_kes', 0)
        return (f"Sawa! Leo unaweza kula {name} — KES {cost:.0f} tu. "
                f"Chakula hiki ni nzuri kwa afya yako. Endelea vizuri!")


def classify_intent(message: str, user_context: dict) -> str:
    try:
        msg = client.messages.create(
            model=MODEL,
            max_tokens=10,
            system="Classify food app messages. Reply with exactly one word: utakulaa, budget, history, or help. Nothing else.",
            messages=[{'role': 'user', 'content': message}]
        )
        intent = msg.content[0].text.strip().lower()
        return intent if intent in ['utakulaa', 'budget', 'history', 'help'] else 'utakulaa'
    except Exception:
        return 'utakulaa'


def test_connection() -> bool:
    try:
        r = client.messages.create(
            model=MODEL,
            max_tokens=5,
            messages=[{'role': 'user', 'content': 'ok'}]
        )
        return bool(r.content[0].text)
    except Exception:
        return False


def _strip_json_fences(raw: str) -> str:
    """
    Claude sometimes wraps JSON replies in markdown code fences
    (```json ... ```) even when told not to. Strip them defensively
    rather than relying solely on prompt instructions.
    """
    raw = raw.strip()
    if raw.startswith('```'):
        # Drop the opening fence (with optional 'json' language tag)
        # and the closing fence.
        parts = raw.split('```')
        # parts[0] is '', parts[1] is the fenced content, parts[2] is ''
        raw = parts[1] if len(parts) > 1 else raw
        if raw.lower().startswith('json'):
            raw = raw[4:]
        raw = raw.strip()
    return raw


def classify_health_condition(text: str) -> dict:
    """
    Maps free-text health descriptions to a fixed set of structured tags.
    This NEVER diagnoses — it only classifies what the person has already
    stated about themselves into categories the engine can act on.

    Returns: {'tags': [...], 'confidence': 'high'|'low'|'none'}
    Always returns this shape, even on failure — callers rely on it.
    """
    try:
        msg = client.messages.create(
            model=MODEL,
            max_tokens=150,
            system=(
                "You map a person's free-text health description to a fixed set of "
                "tags. Valid tags: cancer_general, immune_compromised, appetite_support_needed, "
                "respiratory, autoimmune, mental_health_disclosed, pregnancy_related, "
                "rare_condition, chronic_pain, elderly_specific, paediatric_specific, other. "
                "Reply with raw JSON only — no markdown, no code fences, no backticks, "
                "no text before or after the object: "
                "{\"tags\": [...], \"confidence\": \"high\"|\"low\"|\"none\"}. "
                "Use 'low' confidence whenever you are not certain. Never invent a diagnosis "
                "the person did not state themselves."
            ),
            messages=[{'role': 'user', 'content': text}]
        )
        raw = _strip_json_fences(msg.content[0].text)
        result = json.loads(raw)

        # Defensive shape-check — never let a malformed-but-valid-JSON
        # response (e.g. missing keys) propagate as a different shape
        if not isinstance(result, dict) or 'tags' not in result:
            raise ValueError(f'Unexpected response shape: {result!r}')

        return {
            'tags': result.get('tags', []) or [],
            'confidence': result.get('confidence', 'none'),
        }

    except Exception as e:
        print(f'[Claude] classify_health_condition failed: {e}')
        return {'tags': [], 'confidence': 'none'}