<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MedicalImageClassifierService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Classify this photo into exactly one category. Return ONLY a JSON object, no other text, no markdown fences.

Schema:
{
  "category": "medication_label" | "handwritten_prescription" | "lab_report" | "vitals_device" | "not_medical" | "unclear",
  "confidence": number (0.0 to 1.0)
}

Definitions:
- medication_label: a printed pill bottle, box, or pharmacy label for ONE product.
- handwritten_prescription: a doctor's handwritten notes on a prescription pad or clinic slip, where the PRIMARY content is handwriting (e.g. a short handwritten diagnosis/finding, or a list of prescribed items written by hand) — may include pre-printed pharmacy letterhead/stock lists alongside the handwriting.
- lab_report: ANY document whose primary content is a printed, machine-generated table of values (e.g. a hematology or chemistry panel with numeric results and reference ranges) — even if it also has handwritten annotations in the margin, a doctor's signature, or a date written by hand. If a structured printed results table is present at all, classify as lab_report, not handwritten_prescription, regardless of how much handwriting is also visible.
- vitals_device: a photo of a blood pressure monitor or glucose meter's screen/display, OR a printed vitals slip from a clinic machine.
- not_medical: the photo is unrelated to health/medicine entirely.
- unclear: you cannot confidently tell which category this is.

Decision rule when a photo has BOTH printed tabular results AND handwriting:
always classify as lab_report. handwritten_prescription is only for documents
where handwriting is the primary and near-only content.

Rules:
- Pre-printed pharmacy letterhead, stock lists, or price lists at the bottom/margin of a page are NOT medication data — they are boilerplate. Their presence does not make a document a medication_label.
- Never let a signature block or date stamp be mistaken for prescribed items.
PROMPT;

    public function classify(string $jpegBytes): ?array
    {
        $base64 = base64_encode($jpegBytes);

        $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->timeout(20)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 100,
                'system' => [
                    [
                        'type' => 'text',
                        'text' => self::SYSTEM_PROMPT,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $base64],
                            ],
                            ['type' => 'text', 'text' => 'Classify this photo.'],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Medical image classification failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        $text = $response->json('content.0.text');
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $parsed = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Medical image classifier returned unparseable JSON', ['raw' => $text]);
            return null;
        }

        return $parsed;
    }
}