from flask_restful import Resource
from flask import request
from engine.hydration_engine import calculate_water_target, distribute_hydration

class HydrationResource(Resource):
    def post(self):
        payload = request.get_json(silent=True)
        if not payload:
            return {'error': 'Invalid payload'}, 400

        # Pass the whole payload to calculate_water_target
        target = calculate_water_target(payload)

        slots = payload.get('active_slots', ['breakfast', 'lunch', 'dinner'])
        conditions = payload.get('health_conditions', [])
        plan = distribute_hydration(target, slots, conditions)

        return {
            'target_ml': target,
            'plan': plan
        }