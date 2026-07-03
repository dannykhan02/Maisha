<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Maisha QuitHabitsSeeder
 *
 * These are habit_direction = 'quit' or 'limit'.
 * They are tracked differently in the UI:
 *   - quit: shows a sobriety counter (days since last use)
 *   - limit: shows a daily/weekly threshold tracker
 *
 * Research: YouGov (2022) — top bad habits people want to quit:
 *   not exercising, not saving, procrastinating, poor sleep, late nights,
 *   overeating, caffeine, screen time, smoking, alcohol.
 *
 * Dr. Jud Brewer: the same dopamine loop drives heroin addiction and
 *   doomscrolling — behavioral and substance habits share one mechanism.
 */
class QuitHabitsSeeder extends Seeder
{
    public function run(): void
    {
        $habits = [

            // ═══════════════════════════════════════════════════════════════
            // SUBSTANCE QUITTING
            // ═══════════════════════════════════════════════════════════════

            [
                'name'    => 'No cigarettes today',
                'name_sw' => 'Sigara zero leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => true,
                'limit_target' => 0, 'limit_unit' => 'cigarettes',
                'recommended_for_conditions' => json_encode(['hypertension', 'high_cholesterol', 'kidney_disease']),
                'recommended_for_goals' => json_encode(['manage_condition', 'maintain']),
                'is_system' => true,
            ],
            [
                'name'    => 'No alcohol today',
                'name_sw' => 'Pombe zero leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => true,
                'limit_target' => 0, 'limit_unit' => 'drinks',
                'recommended_for_conditions' => json_encode(['hypertension', 'diabetes', 'high_cholesterol', 'kidney_disease']),
                'recommended_for_goals' => json_encode(['manage_condition', 'weight_loss']),
                'is_system' => true,
            ],
            [
                'name'    => 'No recreational drugs today',
                'name_sw' => 'Hakuna dawa za kulevya leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => true,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['manage_condition', 'maintain']),
                'is_system' => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // DIGITAL & SCREEN HABITS — the most common modern addiction
            // ═══════════════════════════════════════════════════════════════

            [
                'name'    => 'No doom-scrolling today',
                'name_sw' => 'Hakuna kukaa kwenye simu kwa muda mrefu leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain']),
                'is_system' => true,
            ],
            [
                'name'    => 'No social media before noon today',
                'name_sw' => 'Hakuna mitandao ya kijamii kabla ya saa sita mchana',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'morning',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain']),
                'is_system' => true,
            ],
            [
                'name'    => 'Maximum 1 hour of social media today',
                'name_sw' => 'Si zaidi ya saa 1 ya mitandao ya kijamii leo',
                'category' => 'quitting', 'habit_direction' => 'limit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 60, 'limit_unit' => 'minutes',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain']),
                'is_system' => true,
            ],
            [
                'name'    => 'No phone use after 9 PM tonight',
                'name_sw' => 'Hakuna simu baada ya saa tatu usiku',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'evening',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode(['hypertension', 'diabetes']),
                'recommended_for_goals' => json_encode(['maintain', 'manage_condition']),
                'is_system' => true,
            ],
            [
                'name'    => 'No phone during meals today',
                'name_sw' => 'Hakuna simu wakati wa milo leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'easy', 'trigger_time' => 'after_meal',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain']),
                'is_system' => true,
            ],
            [
                'name'    => 'No pornography today',
                'name_sw' => 'Hakuna porn leo',
                'category' => 'quitting', 
                'habit_direction' => 'quit',
                'difficulty' => 'hard', 
                'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 
                'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 
                'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain', 'mental_health']),
                'is_system' => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // FOOD & EATING BAD HABITS
            // ═══════════════════════════════════════════════════════════════

            [
                'name'    => 'No sugary drinks today',
                'name_sw' => 'Vinywaji vya sukari zero leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'glasses',
                'recommended_for_conditions' => json_encode(['diabetes', 'high_cholesterol']),
                'recommended_for_goals' => json_encode(['weight_loss', 'manage_condition']),
                'is_system' => true,
            ],
            [
                'name'    => 'No junk food or fast food today',
                'name_sw' => 'Hakuna chakula cha fast food leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode(['diabetes', 'hypertension', 'high_cholesterol']),
                'recommended_for_goals' => json_encode(['weight_loss', 'manage_condition', 'save_money']),
                'is_system' => true,
            ],
            [
                'name'    => 'No eating after 8 PM tonight',
                'name_sw' => 'Hakuna chakula baada ya saa mbili usiku',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'evening',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode(['diabetes', 'high_cholesterol', 'ulcer']),
                'recommended_for_goals' => json_encode(['weight_loss', 'manage_condition']),
                'is_system' => true,
            ],
            [
                'name'    => 'No added salt to food today',
                'name_sw' => 'Hakuna chumvi ya ziada leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode(['hypertension', 'kidney_disease']),
                'recommended_for_goals' => json_encode(['manage_condition']),
                'is_system' => true,
            ],
            [
                'name'    => 'No sugary snacks today',
                'name_sw' => 'Hakuna vitafunio vya sukari leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode(['diabetes', 'high_cholesterol']),
                'recommended_for_goals' => json_encode(['weight_loss', 'manage_condition']),
                'is_system' => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // PROCRASTINATION & BEHAVIOUR
            // ═══════════════════════════════════════════════════════════════

            [
                'name'    => 'No procrastination on your top task today',
                'name_sw' => 'Usiahirishe kazi yako kubwa leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'morning',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain', 'save_money']),
                'is_system' => true,
            ],
            [
                'name'    => 'No impulsive purchases today',
                'name_sw' => 'Hakuna manunuzi ya msukumo leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'purchases',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['save_money']),
                'is_system' => true,
            ],
            [
                'name'    => 'No complaining or negative self-talk today',
                'name_sw' => 'Hakuna malalamiko au maneno mabaya kuhusu nafsi leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain']),
                'is_system' => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // PHYSICAL BAD HABITS
            // ═══════════════════════════════════════════════════════════════

            [
                'name'    => 'No nail biting today',
                'name_sw' => 'Hakuna kuuma kucha leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'medium', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => false,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['maintain']),
                'is_system' => true,
            ],
            [
                'name'    => 'No gambling today',
                'name_sw' => 'Hakuna kamari leo',
                'category' => 'quitting', 'habit_direction' => 'quit',
                'difficulty' => 'hard', 'trigger_time' => 'anytime',
                'duration_estimate' => 'instant', 'frequency' => 'daily',
                'is_keystone' => true,
                'limit_target' => 0, 'limit_unit' => 'times',
                'recommended_for_conditions' => json_encode([]),
                'recommended_for_goals' => json_encode(['save_money', 'manage_condition']),
                'is_system' => true,
            ],

        ]; // end $habits

        foreach ($habits as $habit) {
            DB::table('habits')->insertOrIgnore(array_merge($habit, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('✓ Quit/Limit habits seeded: ' . count($habits) . ' items');
        $this->command->info('  Directions: quit(' . collect($habits)->where('habit_direction','quit')->count() . ')');
        $this->command->info('             limit(' . collect($habits)->where('habit_direction','limit')->count() . ')');
    }
}