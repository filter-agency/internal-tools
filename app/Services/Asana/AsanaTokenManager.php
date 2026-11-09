<?php

namespace App\Services\Asana;

use App\Models\AsanaSyncLog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AsanaTokenManager
{
    private const TOKEN_URL = 'https://app.asana.com/-/oauth_token';

    public function getValidToken(User $user): ?string
    {
        if ($user->asana_access_token === null) {
            return null;
        }

        if ($user->asana_token_expires_at !== null && $user->asana_token_expires_at->isPast()) {
            return $this->refreshToken($user);
        }

        return $user->asana_access_token;
    }

    public function disconnect(User $user): void
    {
        $user->forceFill([
            'asana_access_token' => null,
            'asana_refresh_token' => null,
            'asana_token_expires_at' => null,
            'asana_user_gid' => null,
            'asana_workspace_gid' => null,
            'asana_connection_lost_at' => null,
            'asana_connection_alerted_at' => null,
        ])->save();
    }

    /**
     * Record that a connection dropped without the user asking for it, so the daily
     * health check can tell them to reconnect. Stamped after disconnect(), which
     * clears the tokens and would otherwise leave no trace that a connection existed.
     */
    private function recordConnectionLost(User $user): void
    {
        // Idempotent: keep the first observed drop time, so repeated failed calls
        // do not keep pushing the timestamp forward and re-trigger alerts.
        if ($user->asana_connection_lost_at !== null) {
            return;
        }

        $user->forceFill(['asana_connection_lost_at' => now()])->save();
    }

    private function refreshToken(User $user): ?string
    {
        if ($user->asana_refresh_token === null) {
            // Access token has expired and there is nothing to refresh with: the
            // connection is unusable and only a fresh OAuth round trip can fix it.
            $this->recordConnectionLost($user);

            return null;
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => config('services.asana.client_id'),
                'client_secret' => config('services.asana.client_secret'),
                'redirect_uri' => config('services.asana.redirect'),
                'refresh_token' => $user->asana_refresh_token,
            ]);
        } catch (ConnectionException|Throwable $e) {
            AsanaSyncLog::error('asana.token.refresh_exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], $user);

            return null;
        }

        if (! $response->successful()) {
            AsanaSyncLog::error('asana.token.refresh_failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ], $user);

            // Only a revoked/invalid refresh token requires a new connection.
            // Rate limits and upstream failures must keep the working credentials.
            if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
                $this->disconnect($user);
                $this->recordConnectionLost($user);
            }

            return null;
        }

        $data = $response->json();
        $newAccess = $data['access_token'] ?? null;
        if ($newAccess === null) {
            return null;
        }

        $user->forceFill([
            'asana_access_token' => $newAccess,
            'asana_refresh_token' => $data['refresh_token'] ?? $user->asana_refresh_token,
            'asana_token_expires_at' => now()->addSeconds(max(0, ($data['expires_in'] ?? 3600) - 60)),
        ])->save();

        return $newAccess;
    }
}
