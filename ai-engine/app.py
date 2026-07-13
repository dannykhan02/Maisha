# app.py

from flask import Flask, request
from flask_restful import Api
from dotenv import load_dotenv
import config

load_dotenv()

# Existing resources
from resources.health_check import HealthCheck
from resources.utakulaa import UtakulaaResource
from resources.intent import IntentResource

# New resources
from resources.meal_categories import MealCategoriesResource
from resources.hydration import HydrationResource
from resources.meal_pattern import MealPatternResource
from resources.classify_condition import ClassifyConditionResource


def create_app():
    app = Flask(__name__)
    api = Api(app)

    # ─────────────────────────────────────────────────────────────────
    # Internal token validation for all routes except /api/health
    # ─────────────────────────────────────────────────────────────────
    @app.before_request
    def check_internal_token():
        # /api/health is public — no token required
        if request.path == '/api/health':
            return
        
        token = request.headers.get('X-Maisha-Internal-Token', '')
        expected = config.MAISHA_INTERNAL_SECRET
        
        if not expected or token != expected:
            return {'error': 'Unauthorized'}, 403

    # Existing endpoints
    api.add_resource(HealthCheck,       '/api/health')
    api.add_resource(UtakulaaResource,  '/api/utakulaa')
    api.add_resource(IntentResource,    '/api/intent')

    # New endpoints
    api.add_resource(MealCategoriesResource, '/api/meal-categories')
    api.add_resource(HydrationResource,     '/api/hydration/calculate')
    api.add_resource(MealPatternResource,   '/api/meal-pattern/validate')
    api.add_resource(ClassifyConditionResource, '/api/classify-condition')

    return app


app = create_app()

if __name__ == '__main__':
    app.run(debug=False, host='0.0.0.0', port=5000)
