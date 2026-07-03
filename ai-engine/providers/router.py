import config
import logging

logger = logging.getLogger(__name__)

# Provider routing is intentionally hardcoded by design:
# - Meal explanations: Claude (primary) → OpenAI (fallback) → hardcoded safe message
# - Intent classification: Claude (primary) → OpenAI (fallback) → default 'utakulaa'
# - Health condition classification: Claude only (no OpenAI fallback — hallucinated tags are worse than no tags)
#
# This chain is not configurable via environment variables. If provider flexibility
# is needed in the future, refactor to use config.py variables and add provider
# factory logic here.


def get_meal_explanation(meal: dict, user_context: dict) -> tuple:
    """
    Returns:
        (explanation_text, provider_used)

    Flow:
        Claude -> OpenAI -> Hardcoded fallback
    """

    # Primary Provider: Claude
    try:
        from providers.claude_provider import explain_meal as claude_explain

        explanation = claude_explain(meal, user_context)
        return explanation, "claude"

    except Exception as e:
        logger.warning(f"[Router] Claude explain_meal failed: {e}")

    # Fallback Provider: OpenAI
    try:
        from providers.openai_provider import explain_meal as openai_explain

        explanation = openai_explain(meal, user_context)
        return explanation, "openai"

    except Exception as e:
        logger.warning(
            f"[Router] OpenAI explain_meal failed or unavailable: {e}"
        )

    # Final Emergency Fallback
    top_items = meal.get("top_items", [])
    meal_name = " + ".join(top_items) if top_items else "your meal plan"
    cost = meal.get("est_cost_kes", 0)

    fallback_message = (
        f"Sawa! Leo unaweza kula {meal_name} — KES {cost:.0f} tu. "
        f"Chakula hiki ni nzuri kwa afya yako. Endelea vizuri!"
    )

    return fallback_message, "fallback"


def get_intent(message: str, user_context: dict) -> str:
    """
    Intent classification fallback chain:
        Claude -> OpenAI -> default
    """

    # Primary Provider: Claude
    try:
        from providers.claude_provider import (
            classify_intent as claude_classify
        )

        return claude_classify(message, user_context)

    except Exception as e:
        logger.warning(f"[Router] Claude classify_intent failed: {e}")

    # Fallback Provider: OpenAI
    try:
        from providers.openai_provider import (
            classify_intent as openai_classify
        )

        return openai_classify(message, user_context)

    except Exception as e:
        logger.warning(
            f"[Router] OpenAI classify_intent failed or unavailable: {e}"
        )

    # Final fallback
    return "utakulaa"


def health_check_providers() -> dict:
    """
    Health check for all configured AI providers.
    """

    status = {}

    # Claude
    try:
        from providers.claude_provider import (
            test_connection as claude_test
        )

        status["claude"] = claude_test()

    except Exception:
        status["claude"] = False

    # OpenAI
    try:
        from providers.openai_provider import (
            test_connection as openai_test
        )

        status["openai"] = openai_test()

    except Exception:
        status["openai"] = False

    return status


# --- NEW FUNCTION (no OpenAI fallback, conservative empty result) ---
def classify_health_condition(text: str) -> dict:
    """
    Condition text classification fallback chain:
        Claude -> hardcoded safe fallback

    No OpenAI fallback because hallucinated tags are worse than no tags.
    Empty result triggers has_unmapped_condition = true in the job.
    """
    try:
        from providers.claude_provider import classify_health_condition as claude_classify
        return claude_classify(text)
    except Exception as e:
        logger.warning(f"[Router] Claude classify_health_condition failed: {e}")

    # Safe fallback — conservative, engine will treat as unmapped
    return {'tags': [], 'confidence': 'none'}