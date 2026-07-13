from flask_restful import Resource
from flask import request
from engine.utakulaa_algorithm import run_utakulaa
import os

class MealCategoriesResource(Resource):
    def post(self):
        payload = request.get_json(silent=True)
        if not payload:
            return {'error': 'Invalid payload'}, 400
        result = run_utakulaa(payload)
        # Return only the slot menus (not the legacy top_meal)
        return {
            'slot_menus': result.get('slot_menus', {}),
            'slot_targets': result.get('slot_targets', {}),
            'hydration': result.get('hydration', {}),
            'pattern_warnings': result.get('pattern_warnings', [])
        }