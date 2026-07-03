from flask_restful import Resource
from flask import request
from engine.meal_pattern_engine import validate_meal_pattern
import os

class MealPatternResource(Resource):
    def post(self):
        payload = request.get_json(silent=True)
        if not payload:
            return {'error': 'Invalid payload'}, 400
        slots = payload.get('active_slots', [])
        goals = payload.get('goals', [])
        conditions = payload.get('conditions', [])
        warnings = validate_meal_pattern(slots, goals, conditions)
        return {'warnings': warnings}