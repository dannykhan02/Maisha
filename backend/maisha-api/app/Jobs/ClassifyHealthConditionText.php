<?php

namespace App\Jobs;

use App\Models\HealthProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClassifyHealthConditionText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $userId,
        private string $rawText
    ) {}

    public function handle(): void
    {
        $profile = HealthProfile::where('user_id', $this->userId)->first();
        if (!$profile) return;

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Maisha-Internal-Token' => config('services.maisha.internal_secret'),
                ])
                ->post(config('services.flask.url') . '/api/classify-condition', [
                    'text' => $this->rawText,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    'Flask classify-condition returned ' . $response->status()
                );
            }

            $result     = $response->json();
            $tags       = $result['tags']       ?? [];
            $confidence = $result['confidence'] ?? 'none';

            $profile->update([
                'mapped_condition_tags'            => $tags,
                'has_unmapped_condition'           => empty($tags) || $confidence === 'low',
                'condition_classification_status'  => 'done',
            ]);

        } catch (\Throwable $e) {
            Log::warning("Condition classification failed for user {$this->userId}: " . $e->getMessage());

            // Fail safe — conservative flag set even on error
            $profile->update([
                'has_unmapped_condition'          => true,
                'condition_classification_status' => 'failed',
            ]);
        }
    }
}