<?php

namespace App\Services\Breathe;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BreatheClient
{
    /** Breathe's maximum page size. */
    private const PER_PAGE = 100;

    /** Guards against a paging bug looping forever. */
    private const MAX_PAGES = 50;

    public function isConfigured(): bool
    {
        return filled(config('services.breathe.api_key')) && filled(config('services.breathe.base_url'));
    }

    /**
     * Every employee record, current and former.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function employees(): array
    {
        $employees = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $this->request()
                ->get('employees', ['page' => $page, 'per_page' => self::PER_PAGE])
                ->throw()
                ->json('employees');

            if (! is_array($batch)) {
                throw new RuntimeException("Breathe returned no \"employees\" list on page {$page}.");
            }

            array_push($employees, ...array_values($batch));

            if (count($batch) < self::PER_PAGE) {
                return $employees;
            }
        }

        throw new RuntimeException('Breathe returned more than '.self::MAX_PAGES.' pages of employees.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.breathe.base_url'), '/'))
            ->withHeaders(['X-API-KEY' => (string) config('services.breathe.api_key')])
            ->acceptJson()
            ->timeout(30)
            // Breathe allows 60 requests a minute and answers 429 above that.
            ->retry(3, 2000, fn (\Throwable $e): bool => $e instanceof RequestException
                && ($e->response->status() === 429 || $e->response->serverError()), throw: false);
    }
}
