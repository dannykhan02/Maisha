<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ProcessedMedia extends Model
{
    protected $fillable = ['media_sid'];

    /**
     * Atomically claim a media SID – returns true if claimed successfully
     * (i.e., it didn't already exist), false if already processed.
     *
     * This is the single source of truth for duplicate detection. Use it
     * in the webhook controller before dispatching the job.
     */
    public static function claim(string $mediaSid): bool
    {
        // firstOrCreate atomically checks existence and inserts if missing
        // using database unique constraint on media_sid.
        $created = static::firstOrCreate(
            ['media_sid' => $mediaSid],
            ['media_sid' => $mediaSid] // fillable fields
        )->wasRecentlyCreated;

        if (!$created) {
            Log::info('Duplicate media_sid ignored', ['media_sid' => $mediaSid]);
        }

        return $created;
    }

    /**
     * Check if a media SID has already been processed (without claiming it).
     * Useful for diagnostics or manual checks.
     */
    public static function isProcessed(string $mediaSid): bool
    {
        return static::where('media_sid', $mediaSid)->exists();
    }
}