<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VitalsDeviceVisionService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You read the display(s) of home medical device(s) (blood pressure monitor and/or blood glucose meter) from a photo, OR a printed clinic vitals slip. A single photo may contain MORE THAN ONE device — scan the entire image and report EVERY distinct device display you can see, not just the most prominent one. Return ONLY a JSON object, no other text, no markdown fences.

Schema:
{
  "readings": [
    {
      "device_type": "bp_monitor" | "glucometer" | "printed_slip" | "unclear",
      "systolic": integer | null,
      "diastolic": integer | null,
      "pulse": integer | null,
      "sugar_value": number | null,
      "sugar_unit": "mg_dl" | "mmol_l" | null,
      "readable": boolean,
      "reason_if_unreadable": string | null,
      "confidence": number (0.0 to 1.0)
    }
  ]
}

If nothing readable is in the photo at all, return "readings": [] (an empty array), not a single unclear entry.

Rules:
- Scan the WHOLE image first. If you can see two separate device screens (e.g. a glucometer AND a BP monitor side by side), include TWO separate objects in "readings" — one per device. Do not merge them or report only one.
- A BP monitor typically shows THREE numbers: systolic (largest, top), diastolic (middle), and pulse/heart rate (often bottom, sometimes with a heart icon). Do not confuse pulse with diastolic — pulse is a heart rate, not a blood pressure number.
- A glucometer shows ONE number, usually with mg/dL or mmol/L printed on screen. If the unit is not visible on the display, set sugar_unit to null — do not guess based on typical value ranges.
- READ DIGITS CAREFULLY, ONE AT A TIME. Home glucometers almost always display whole numbers in mg/dL (e.g. 107, not 100.7) and one-decimal numbers in mmol/L (e.g. 5.9). Before finalizing a number, re-check: does the decimal point position match what mg/dL vs mmol/L would normally look like? Do not insert or drop a decimal point that isn't clearly, visibly present on the display itself.
- If any digit is ambiguous, blurry, or you are not fully confident in the exact value, lower "confidence" accordingly rather than reporting a clean-looking number you are not sure of.
- If the device appears to still be mid-measurement (e.g. a counting/loading indicator, no stable final reading), set readable=false and reason_if_unreadable="still measuring" for that reading.
- If there is glare, blur, or the display is off, set readable=false with an appropriate reason_if_unreadable for that reading.
- Never fabricate a plausible-looking number. If in doubt, return null for that field rather than guessing.
- CRITICAL: whenever readable is false for a reading, reason_if_unreadable MUST be a non-null, non-empty string explaining specifically why (e.g. "glare on screen", "digits partially obscured", "still measuring", "display off"). Never leave reason_if_unreadable as null when readable is false — that combination is invalid output.
PROMPT;

    public function extract(string $jpegBytes): ?array
    {
        $base64 = base64_encode($jpegBytes);

        $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 500,
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
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'image/jpeg',
                                    'data' => $base64,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Read every vitals device display or slip visible in this image.',
                            ],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Vitals device vision extraction failed', [
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
            Log::error('Vitals device vision returned unparseable JSON', ['raw' => $text]);
            return null;
        }

        return $parsed;
    }
}