# app.py

from flask import Flask
from flask_restful import Api
from dotenv import load_dotenv

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

    # Existing endpoints
    api.add_resource(HealthCheck,       '/api/health')
    api.add_resource(UtakulaaResource,  '/api/utakulaa')
    api.add_resource(IntentResource,    '/api/intent')

    # New endpoints
    api.add_resource(MealCategoriesResource, '/api/meal-categories')
    api.add_resource(HydrationResource,     '/api/hydration/calculate')
    api.add_resource(MealPatternResource,   '/api/meal-pattern/validate')
    # In create_app(), after the existing add_resource lines:
    api.add_resource(ClassifyConditionResource, '/api/classify-condition')

    return app


app = create_app()

if __name__ == '__main__':
    app.run(debug=False, host='0.0.0.0', port=5000)