<?php

namespace App\Services\Breathe;

use App\Notifications\BreatheSyncNeedsAttention;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/** Emails once per distinct set of issues, so a daily sync does not repeat the same alert. */
final class BreatheSyncAlert
{
    private const CACHE_KEY = 'breathe:sync-employees:alerted-fingerprint';

    /**
     * @param  list<string>  $unmatched
     * @param  list<string>  $problems
     */
    public function report(?string $failure, array $unmatched, array $problems): bool
    {
        $fingerprint = hash('sha256', (string) json_encode([$failure, $unmatched, $problems]));
        if (Cache::get(self::CACHE_KEY) === $fingerprint) {
            return false;
        }

        Notification::route('mail', (string) config('services.breathe.sync_alert_email'))
            ->notify(new BreatheSyncNeedsAttention($failure, $unmatched, $problems));

        Cache::forever(self::CACHE_KEY, $fingerprint);

        return true;
    }

    public function resolve(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
