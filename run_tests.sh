#!/bin/bash

# Base URLs – change these to your actual endpoints
BASE_URL="http://localhost:5000"
LARAVEL_URL="http://localhost:8000"

# Internal secret (must match Flask .env)
FLASK_SECRET="your_maisha_internal_secret_here"

echo "Testing Maisha Phase 2 endpoints..."

echo "===== ── USER 1 — Amina (Weight Loss, Sedentary, No Conditions) ── ====="
echo ">>> 01 Onboarding — Amina"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"name\": \"Amina\",   \"age\": 34,   \"gender\": \"female\",   \"weight_kg\": 72,   \"height_cm\": 163,   \"activity_level\": \"sedentary\",   \"primary_goal\": \"weight_loss\",   \"target_weight_kg\": 60,   \"timeline_weeks\": 24,   \"budget_daily_kes\": 150,   \"conditions\": [],   \"allergies\": [],   \"medications\": [],   \"meal_slots\": [\"breakfast\", \"lunch\"],   \"pantry\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 },     { \"ingredient\": \"sugar\", \"tier\": 1 },     { \"ingredient\": \"tea_leaves\", \"tier\": 1 }   ],   \"location\": \"Nairobi CBD\" }'
echo ""
sleep 1
echo ">>> 02 Morning Plan — Amina (normal day)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"date\": \"2025-01-20\",   \"time\": \"06:45\" }'
echo ""
sleep 1
echo ">>> 03 Edge Case — Meal Skip + Time Constraint"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"message\": \"niko busy sana leo sina time ya breakfast\",   \"timestamp\": \"2025-01-20T11:00:00\" }'
echo ""
sleep 1
echo ">>> 04 Edge Case — Variety Request (tired of ugali)"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"message\": \"nimechoka na ugali kila siku\",   \"timestamp\": \"2025-01-22T09:00:00\" }'
echo ""
sleep 1
echo ">>> 05 Edge Case — Progress Update (lost 1kg)"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"message\": \"nimepoteza kilo moja!\",   \"timestamp\": \"2025-01-24T08:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 2 — James (Type 2 Diabetes, Metformin, Kisumu) ── ====="
echo ">>> 06 Onboarding — James (flags 2-large-meal pattern)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR002\",   \"name\": \"James\",   \"age\": 52,   \"gender\": \"male\",   \"weight_kg\": 84,   \"height_cm\": 172,   \"activity_level\": \"light\",   \"primary_goal\": \"diabetic_control\",   \"budget_daily_kes\": 200,   \"conditions\": [\"type2_diabetes\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Metformin\",       \"dose\": \"500mg\",       \"times\": [\"08:00\", \"20:00\"],       \"requires_food\": true     }   ],   \"meal_slots\": [\"breakfast\", \"lunch\"],   \"pantry\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1 },     { \"ingredient\": \"rice\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 },     { \"ingredient\": \"onions\", \"tier\": 1 },     { \"ingredient\": \"tomatoes\", \"tier\": 2, \"quantity\": 5 }   ],   \"location\": \"Kisumu\" }'
echo ""
sleep 1
echo ">>> 07 Morning Plan — James (5-meal diabetic plan)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR002\",   \"date\": \"2025-01-20\",   \"time\": \"06:50\" }'
echo ""
sleep 1
echo ">>> 08 TIER 1 SAFETY — Breakfast Not Eaten at 8:15am"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR002\",   \"message\": \"sijala breakfast bado\",   \"timestamp\": \"2025-01-20T08:15:00\" }'
echo ""
sleep 1
echo ">>> 09 Edge Case — Doctor Changes Carb Target"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR002\",   \"message\": \"doctor amesema nipunguze wanga zaidi\",   \"timestamp\": \"2025-01-22T10:00:00\" }'
echo ""
sleep 1
echo ">>> 10 Edge Case — James Eats Ugali Anyway"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR002\",   \"message\": \"nilikula ugali kwa lunch leo\",   \"timestamp\": \"2025-01-21T14:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 3 — Brenda (H. Pylori, University Student, 80 KES) ── ====="
echo ">>> 11 Onboarding — Brenda (flags breakfast skip with medication)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR003\",   \"name\": \"Brenda\",   \"age\": 22,   \"gender\": \"female\",   \"weight_kg\": 56,   \"height_cm\": 161,   \"activity_level\": \"moderate\",   \"primary_goal\": \"nutrient_intake\",   \"budget_daily_kes\": 80,   \"conditions\": [\"h_pylori\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Amoxicillin\",       \"times\": [\"07:00\", \"14:00\", \"21:00\"],       \"requires_food\": true     },     {       \"name\": \"Clarithromycin\",       \"times\": [\"07:00\", \"14:00\", \"21:00\"],       \"requires_food\": true     }   ],   \"meal_slots\": [\"lunch\", \"dinner\"],   \"pantry\": [],   \"location\": \"Nairobi (University Campus)\" }'
echo ""
sleep 1
echo ">>> 12 Morning Plan — Brenda (ultra-budget 3-meal)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR003\",   \"date\": \"2025-01-20\",   \"time\": \"06:45\" }'
echo ""
sleep 1
echo ">>> 13 Edge Case — Ate Chips for Lunch"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR003\",   \"message\": \"nimekula chips tu kwa lunch nikiwa busy\",   \"timestamp\": \"2025-01-20T14:00:00\" }'
echo ""
sleep 1
echo ">>> 14 Edge Case — Treatment Complete"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR003\",   \"message\": \"nimemaliza dawa yangu yote leo\",   \"timestamp\": \"2025-01-30T09:00:00\" }'
echo ""
sleep 1
echo ">>> 15 Edge Case — Budget Hit Zero Mid-Week"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR003\",   \"message\": \"nimekwisha pesa leo kabisa\",   \"timestamp\": \"2025-01-23T12:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 4 — Brian (Muscle Gain, Lactose Intolerant, Westlands) ── ====="
echo ">>> 16 Onboarding — Brian (protein/budget conflict detection)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR004\",   \"name\": \"Brian\",   \"age\": 28,   \"gender\": \"male\",   \"weight_kg\": 78,   \"height_cm\": 180,   \"activity_level\": \"very_active\",   \"primary_goal\": \"muscle_gain\",   \"budget_daily_kes\": 400,   \"conditions\": [],   \"allergies\": [\"lactose\"],   \"medications\": [],   \"meal_slots\": [\"pre_workout\", \"breakfast\", \"lunch\", \"post_workout\", \"dinner\"],   \"workout_time\": \"06:00\",   \"pantry\": [     { \"ingredient\": \"oats\", \"tier\": 2, \"quantity\": \"500g\" },     { \"ingredient\": \"peanut_butter\", \"tier\": 2, \"quantity\": \"350g\" },     { \"ingredient\": \"protein_powder\", \"tier\": 2, \"quantity\": \"1kg\" },     { \"ingredient\": \"eggs\", \"tier\": 2, \"quantity\": 60 },     { \"ingredient\": \"rice\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 }   ],   \"location\": \"Westlands, Nairobi\" }'
echo ""
sleep 1
echo ">>> 17 Pre-Workout Plan — Brian (5:40am)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR004\",   \"date\": \"2025-01-20\",   \"time\": \"05:40\" }'
echo ""
sleep 1
echo ">>> 18 Edge Case — Rest Day (missed gym)"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR004\",   \"message\": \"haikuwezekanika kwenda gym leo\",   \"timestamp\": \"2025-01-21T07:00:00\" }'
echo ""
sleep 1
echo ">>> 19 Edge Case — Protein Powder Depleted"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR004\",   \"message\": \"protein powder yameisha\",   \"timestamp\": \"2025-01-22T08:00:00\" }'
echo ""
sleep 1
echo ">>> 20 Edge Case — Dairy Accidentally Consumed"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR004\",   \"message\": \"nilikuwa na chai ya maziwa asubuhi bila kukumbuka\",   \"timestamp\": \"2025-01-20T09:30:00\" }'
echo ""
sleep 1

echo "===== ── USER 5 — Mary (Anaemia, Single Mother, Family Budget, Kibera) ── ====="
echo ">>> 21 Onboarding — Mary (family nutrition + iron medication timing)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR005\",   \"name\": \"Mary\",   \"age\": 45,   \"gender\": \"female\",   \"weight_kg\": 63,   \"height_cm\": 158,   \"activity_level\": \"moderate\",   \"primary_goal\": \"nutrient_intake\",   \"budget_daily_kes\": 200,   \"cooking_for\": 4,   \"children_ages\": [8, 11, 14],   \"conditions\": [\"anaemia\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Ferrous Sulfate\",       \"times\": [\"06:00\"],       \"requires_empty_stomach\": true,       \"food_after_minutes\": 30     }   ],   \"meal_slots\": [\"breakfast\", \"lunch\", \"dinner\"],   \"pantry\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1 },     { \"ingredient\": \"rice\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 },     { \"ingredient\": \"salt\", \"tier\": 1 },     { \"ingredient\": \"onions\", \"tier\": 1 }   ],   \"location\": \"Kibera, Nairobi\" }'
echo ""
sleep 1
echo ">>> 22 Morning Plan — Mary (family day, iron anchored)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR005\",   \"date\": \"2025-01-20\",   \"time\": \"06:00\" }'
echo ""
sleep 1
echo ">>> 23 Edge Case — Children Refuse Beans"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR005\",   \"message\": \"watoto wamekataa beans tena\",   \"timestamp\": \"2025-01-20T19:00:00\" }'
echo ""
sleep 1
echo ">>> 24 Edge Case — Mary Exhausted After Hard Day"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR005\",   \"message\": \"nimechoka sana leo kazi ilikuwa ngumu\",   \"timestamp\": \"2025-01-20T18:30:00\" }'
echo ""
sleep 1
echo ">>> 25 Edge Case — Mary Skips Her Iron Tablet"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR005\",   \"message\": \"nilisahau dawa ya chuma asubuhi\",   \"timestamp\": \"2025-01-20T10:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 6 — Kevin (H. Pylori + Ulcer, Erratic Schedule, Mombasa) ── ====="
echo ">>> 26 Onboarding — Kevin (multiple red flags, night owl schedule)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR006\",   \"name\": \"Kevin\",   \"age\": 19,   \"gender\": \"male\",   \"weight_kg\": 52,   \"height_cm\": 178,   \"activity_level\": \"light\",   \"primary_goal\": \"weight_gain\",   \"budget_daily_kes\": 120,   \"conditions\": [\"h_pylori\", \"ulcer\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Omeprazole\",       \"times\": [\"11:00\"],       \"requires_empty_stomach\": true,       \"food_after_minutes\": 30     },     {       \"name\": \"Amoxicillin\",       \"times\": [\"11:30\", \"15:00\", \"22:00\"],       \"requires_food\": true     },     {       \"name\": \"Clarithromycin\",       \"times\": [\"11:30\", \"15:00\", \"22:00\"],       \"requires_food\": true     }   ],   \"meal_slots\": [],   \"sleep_schedule\": { \"sleep\": \"03:00\", \"wake\": \"11:00\" },   \"eating_pattern\": \"erratic\",   \"pantry\": [],   \"location\": \"Mombasa\" }'
echo ""
sleep 1
echo ">>> 27 'Morning' Plan — Kevin (11:05am wake)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR006\",   \"date\": \"2025-01-20\",   \"time\": \"11:05\" }'
echo ""
sleep 1
echo ">>> 28 TIER 1 SAFETY — Forgot Midday Medication + Food"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR006\",   \"message\": \"nimesahau kula na dawa zangu za saa tisa\",   \"timestamp\": \"2025-01-20T15:00:00\" }'
echo ""
sleep 1
echo ">>> 29 Edge Case — Hungry at 2am"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR006\",   \"message\": \"naona njaa sana\",   \"timestamp\": \"2025-01-20T02:00:00\" }'
echo ""
sleep 1
echo ">>> 30 Edge Case — Side Effects from Triple Therapy"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR006\",   \"message\": \"sijisikii vizuri dawa zinanisumbua\",   \"timestamp\": \"2025-01-22T13:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 7 — Grace (Hypertension, Low-Sodium, Eldoret) ── ====="
echo ">>> 31 Onboarding — Grace (hypertension, sodium restriction)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR007\",   \"name\": \"Grace\",   \"age\": 48,   \"gender\": \"female\",   \"weight_kg\": 78,   \"height_cm\": 162,   \"activity_level\": \"light\",   \"primary_goal\": \"weight_loss\",   \"budget_daily_kes\": 180,   \"conditions\": [\"hypertension\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Amlodipine\",       \"dose\": \"5mg\",       \"times\": [\"08:00\"],       \"requires_food\": false,       \"note\": \"can take with or without food, but consistency matters\"     }   ],   \"meal_slots\": [\"breakfast\", \"lunch\", \"dinner\"],   \"sodium_limit_mg\": 1500,   \"pantry\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 },     { \"ingredient\": \"salt\", \"tier\": 1 }   ],   \"location\": \"Eldoret\" }'
echo ""
sleep 1
echo ">>> 32 Morning Plan — Grace (low-sodium day)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR007\",   \"date\": \"2025-01-20\",   \"time\": \"07:00\" }'
echo ""
sleep 1
echo ">>> 33 Edge Case — Used Royco in Cooking"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR007\",   \"message\": \"nilitumia royco kidogo kwa beans zangu\",   \"timestamp\": \"2025-01-20T13:30:00\" }'
echo ""
sleep 1
echo ">>> 34 Edge Case — Blood Pressure Reading Shared"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR007\",   \"message\": \"BP yangu leo ilikuwa 158/95\",   \"timestamp\": \"2025-01-20T09:00:00\" }'
echo ""
sleep 1
echo ">>> 35 Edge Case — Craving Salty Snack"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR007\",   \"message\": \"nataka kitu cha chumvi sana sasa hivi\",   \"timestamp\": \"2025-01-21T15:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 8 — Samuel (Kidney Disease, Fluid & Potassium Restricted) ── ====="
echo ">>> 36 Onboarding — Samuel (kidney disease, multiple restrictions)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR008\",   \"name\": \"Samuel\",   \"age\": 58,   \"gender\": \"male\",   \"weight_kg\": 70,   \"height_cm\": 169,   \"activity_level\": \"sedentary\",   \"primary_goal\": \"nutrient_intake\",   \"budget_daily_kes\": 250,   \"conditions\": [\"chronic_kidney_disease\"],   \"ckd_stage\": 3,   \"fluid_limit_ml\": 1200,   \"potassium_limit_mg\": 2000,   \"phosphorus_limit_mg\": 800,   \"allergies\": [],   \"medications\": [     {       \"name\": \"Furosemide\",       \"dose\": \"40mg\",       \"times\": [\"08:00\"],       \"requires_food\": true     }   ],   \"meal_slots\": [\"breakfast\", \"lunch\", \"dinner\"],   \"pantry\": [     { \"ingredient\": \"rice\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 }   ],   \"location\": \"Nakuru\" }'
echo ""
sleep 1
echo ">>> 37 Morning Plan — Samuel (kidney-safe day)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR008\",   \"date\": \"2025-01-20\",   \"time\": \"07:30\" }'
echo ""
sleep 1
echo ">>> 38 Edge Case — Ate Banana (high potassium)"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR008\",   \"message\": \"nilikula ndizi moja asubuhi\",   \"timestamp\": \"2025-01-20T08:30:00\" }'
echo ""
sleep 1
echo ">>> 39 Edge Case — Drank Extra Water"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR008\",   \"message\": \"nimekuwa nikinywa maji mengi leo — glasi 6 tayari\",   \"timestamp\": \"2025-01-20T14:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 9 — Rose (Pregnant, 2nd Trimester, Meru) ── ====="
echo ">>> 40 Onboarding — Rose (pregnancy nutrition, folic acid, iron)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR009\",   \"name\": \"Rose\",   \"age\": 27,   \"gender\": \"female\",   \"weight_kg\": 67,   \"height_cm\": 160,   \"activity_level\": \"light\",   \"primary_goal\": \"nutrient_intake\",   \"pregnancy_week\": 18,   \"budget_daily_kes\": 200,   \"conditions\": [\"pregnancy\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Folic Acid\",       \"times\": [\"08:00\"],       \"requires_food\": false     },     {       \"name\": \"Ferrous Sulfate\",       \"times\": [\"10:00\"],       \"requires_empty_stomach\": false,       \"note\": \"take between meals for absorption\"     },     {       \"name\": \"Calcium\",       \"times\": [\"20:00\"],       \"requires_food\": false     }   ],   \"meal_slots\": [\"breakfast\", \"morning_snack\", \"lunch\", \"afternoon_snack\", \"dinner\"],   \"pantry\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 },     { \"ingredient\": \"rice\", \"tier\": 1 }   ],   \"location\": \"Meru\" }'
echo ""
sleep 1
echo ">>> 41 Morning Plan — Rose (pregnancy 5-meal plan)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR009\",   \"date\": \"2025-01-20\",   \"time\": \"07:00\" }'
echo ""
sleep 1
echo ">>> 42 Edge Case — Morning Sickness, Can't Eat"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR009\",   \"message\": \"naona kichefuchefu sana asubuhi siwezi kula chochote\",   \"timestamp\": \"2025-01-20T08:00:00\" }'
echo ""
sleep 1
echo ">>> 43 Edge Case — Craving Unusual Food (ugali + soil/clay)"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR009\",   \"message\": \"ninataka kula udongo au kitu cha uchungu sana\",   \"timestamp\": \"2025-01-21T10:00:00\" }'
echo ""
sleep 1
echo ">>> 44 Edge Case — Asks if Papaya is Safe"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR009\",   \"message\": \"ninaweza kula papai wakati wa ujauzito?\",   \"timestamp\": \"2025-01-22T11:00:00\" }'
echo ""
sleep 1

echo "===== ── USER 10 — Joseph (Elderly, 68, Multiple Conditions, Thika) ── ====="
echo ">>> 45 Onboarding — Joseph (elderly, diabetes + hypertension + arthritis)"
curl -X POST ${BASE_URL}/api/onboarding -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR010\",   \"name\": \"Joseph\",   \"age\": 68,   \"gender\": \"male\",   \"weight_kg\": 75,   \"height_cm\": 170,   \"activity_level\": \"sedentary\",   \"primary_goal\": \"diabetic_control\",   \"budget_daily_kes\": 300,   \"conditions\": [\"type2_diabetes\", \"hypertension\", \"arthritis\"],   \"allergies\": [],   \"medications\": [     {       \"name\": \"Metformin\",       \"dose\": \"1000mg\",       \"times\": [\"07:30\", \"19:30\"],       \"requires_food\": true     },     {       \"name\": \"Lisinopril\",       \"dose\": \"10mg\",       \"times\": [\"08:00\"],       \"requires_food\": false     },     {       \"name\": \"Diclofenac\",       \"dose\": \"50mg\",       \"times\": [\"12:00\", \"20:00\"],       \"requires_food\": true,       \"note\": \"NSAID — must take with food to protect stomach lining\"     }   ],   \"meal_slots\": [\"breakfast\", \"mid_morning\", \"lunch\", \"afternoon\", \"dinner\"],   \"dietary_texture\": \"soft_foods_preferred\",   \"pantry\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1 },     { \"ingredient\": \"rice\", \"tier\": 1 },     { \"ingredient\": \"cooking_oil\", \"tier\": 1 }   ],   \"location\": \"Thika\" }'
echo ""
sleep 1
echo ">>> 46 Morning Plan — Joseph (multi-condition 5-meal)"
curl -X POST ${BASE_URL}/api/meal-plan/daily -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR010\",   \"date\": \"2025-01-20\",   \"time\": \"07:00\" }'
echo ""
sleep 1
echo ">>> 47 Edge Case — Joint Pain Flare, Can't Cook"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR010\",   \"message\": \"viungo vyangu vinauma sana leo siwezi kupika\",   \"timestamp\": \"2025-01-20T12:00:00\" }'
echo ""
sleep 1
echo ">>> 48 Edge Case — Multiple Medications Conflict Query"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR010\",   \"message\": \"daktari amenipa dawa mpya — Atorvastatin kwa cholesterol, kuchukua jioni\",   \"timestamp\": \"2025-01-25T16:00:00\" }'
echo ""
sleep 1
echo ">>> 49 Edge Case — Blood Sugar Very Low (hypoglycaemia signal)"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR010\",   \"message\": \"nahisi kizunguzungu na jasho — damu yangu ya sukari inaweza kuwa chini\",   \"timestamp\": \"2025-01-20T10:30:00\" }'
echo ""
sleep 1

echo "===== ── CROSS-CUTTING: Pantry Management ── ====="
echo ">>> 50 Pantry — Declare Tier 1 Bulk Stock"
curl -X POST ${BASE_URL}/api/pantry/update -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"updates\": [     { \"ingredient\": \"maize_flour\", \"tier\": 1, \"action\": \"restock\", \"quantity\": \"4kg\" },     { \"ingredient\": \"rice\", \"tier\": 1, \"action\": \"restock\", \"quantity\": \"2kg\" },     { \"ingredient\": \"cooking_oil\", \"tier\": 1, \"action\": \"restock\", \"quantity\": \"500ml\" }   ] }'
echo ""
sleep 1
echo ">>> 51 Pantry — Weekly Stock Update (eggs tray)"
curl -X POST ${BASE_URL}/api/pantry/update -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"updates\": [     { \"ingredient\": \"eggs\", \"tier\": 2, \"action\": \"restock\", \"quantity\": 30, \"unit\": \"pieces\", \"cost_kes\": 180 }   ] }'
echo ""
sleep 1
echo ">>> 52 Pantry — WhatsApp Text Depletion Report"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR001\",   \"message\": \"nimemaliza mayai\",   \"timestamp\": \"2025-01-24T07:00:00\" }'
echo ""
sleep 1
echo ">>> 53 Pantry — Shopping List Request"
curl -X GET ${BASE_URL}/api/pantry/shopping-list/USR001 
echo ""
sleep 1

echo "===== ── CROSS-CUTTING: Hydration Engine ── ====="
echo ">>> 54 Hydration — Targets by Condition"
curl -X POST ${BASE_URL}/api/hydration/targets -H 'Content-Type: application/json' -H 'X-Maisha-Internal-Token: ${FLASK_SECRET}' -d '{   \"users\": [     { \"user_id\": \"USR001\", \"expected_ml\": 1800 },     { \"user_id\": \"USR002\", \"expected_ml\": 2200 },     { \"user_id\": \"USR004\", \"expected_ml\": 3200 },     { \"user_id\": \"USR008\", \"expected_ml\": 1200 }   ] }'
echo ""
sleep 1
echo ">>> 55 Hydration — H. Pylori Water Timing Rule"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR006\",   \"message\": \"kunywa maji wakati wa chakula ni sawa?\",   \"timestamp\": \"2025-01-20T12:00:00\" }'
echo ""
sleep 1

echo "===== ── CROSS-CUTTING: System-Wide Validation ── ====="
echo ">>> 56 Calorie Engine — BMR Calculations Audit"
curl -X POST ${BASE_URL}/api/nutrition/calculate -H 'Content-Type: application/json' -d '{   \"profiles\": [     { \"id\": \"A\", \"gender\": \"female\", \"age\": 34, \"weight_kg\": 72, \"height_cm\": 163, \"activity\": \"sedentary\", \"goal\": \"weight_loss\",      \"expected_bmr\": 1420, \"expected_tdee\": 1704, \"expected_target\": 1204 },     { \"id\": \"B\", \"gender\": \"male\",   \"age\": 52, \"weight_kg\": 84, \"height_cm\": 172, \"activity\": \"light\",     \"goal\": \"diabetic_control\", \"expected_bmr\": 1680, \"expected_tdee\": 2310, \"expected_target\": 1600 },     { \"id\": \"C\", \"gender\": \"male\",   \"age\": 28, \"weight_kg\": 78, \"height_cm\": 180, \"activity\": \"very_active\",\"goal\": \"muscle_gain\",     \"expected_bmr\": 1820, \"expected_tdee\": 3458, \"expected_target\": 3758 }   ] }'
echo ""
sleep 1
echo ">>> 57 Condition Flag — Verify Exclusions"
curl -X POST ${BASE_URL}/api/nutrition/condition-check -H 'Content-Type: application/json' -d '{   \"condition\": \"type2_diabetes\",   \"check_ingredients\": [\"ugali\", \"white_rice\", \"sugar\", \"sorghum_uji\", \"sweet_potato\", \"brown_rice\", \"millet\", \"beans\"] }'
echo ""
sleep 1
echo ">>> 58 Condition Flag — H. Pylori Exclusions"
curl -X POST ${BASE_URL}/api/nutrition/condition-check -H 'Content-Type: application/json' -d '{   \"condition\": \"h_pylori\",   \"check_ingredients\": [\"coffee\", \"pilipili\", \"soda\", \"oranges\", \"ugali\", \"uji\", \"eggs\", \"milk\", \"ginger_tea\"] }'
echo ""
sleep 1
echo ">>> 59 Intent Classification — Grok Multi-Class"
curl -X POST ${BASE_URL}/api/intent/classify -H 'Content-Type: application/json' -d '{   \"messages\": [     { \"id\": 1, \"text\": \"niko busy sana leo sina time ya breakfast\",       \"expected\": \"meal_skip+time_constraint\" },     { \"id\": 2, \"text\": \"nimechoka na ugali kila siku\",                     \"expected\": \"meal_feedback+variety_request\" },     { \"id\": 3, \"text\": \"nimepoteza kilo moja!\",                             \"expected\": \"progress_update\" },     { \"id\": 4, \"text\": \"sijala breakfast bado\",                             \"expected\": \"medication_safety_tier1\" },     { \"id\": 5, \"text\": \"doctor amesema nipunguze wanga zaidi\",              \"expected\": \"goal_change+medical_instruction\" },     { \"id\": 6, \"text\": \"protein powder yameisha\",                           \"expected\": \"pantry_update+nutrition_gap\" },     { \"id\": 7, \"text\": \"nimechoka sana leo kazi ilikuwa ngumu\",             \"expected\": \"fatigue+emotional_state\" },     { \"id\": 8, \"text\": \"sijisikii vizuri dawa zinanisumbua\",                \"expected\": \"side_effect+treatment_concern\" },     { \"id\": 9, \"text\": \"naona njaa sana\",                                   \"expected\": \"hunger+unusual_time\" },     { \"id\": 10,\"text\": \"nimemaliza dawa yangu yote leo\",                   \"expected\": \"medication_complete\" }   ] }'
echo ""
sleep 1
echo ">>> 60 Priority Tier — Medication Safety Always Fires"
curl -X POST ${BASE_URL}/api/webhook/whatsapp -H 'Content-Type: application/json' -d '{   \"user_id\": \"USR002\",   \"message\": \"sijala breakfast bado\",   \"timestamp\": \"2025-01-20T08:15:00\",   \"test_inactive_user\": true,   \"days_since_last_engagement\": 5 }'
echo ""
sleep 1
