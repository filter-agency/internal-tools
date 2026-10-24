<?php

namespace App\Livewire\Admin\MonthLocks;

use App\Domain\TimeTracking\MonthLockService;
use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    /** Year and month in Y-m form, as an <input type="month"> sends it. */
    public string $month = '';

    public string $issuedOn = '';

    /** @var array<int, int|string> */
    public array $clientIds = [];

    public ?int $overrideLockId = null;

    public string $overrideReason = '';

    public ?string $confirmation = null;

    public function mount(): void
    {
        Gate::authorize('access-admin');

        $today = CarbonImmutable::today();

        $this->month = $today->startOfMonth()->subMonth()->format('Y-m');
        $this->issuedOn = $today->toDateString();
        $this->clientIds = Client::query()
            ->where('is_archived', false)
            ->where('task_billability_profile', ClientTaskBillabilityProfile::Jdw)
            ->orderBy('name')
            ->pluck('id')
            ->all();
    }

    /**
     * Lock the chosen month for every ticked client, or for none of them if
     * any one fails. The service's own validation errors (keys month and
     * issued_on) land on the form untouched.
     */
    public function lock(MonthLockService $service): void
    {
        Gate::authorize('access-admin');

        $this->confirmation = null;

        Validator::make(
            ['month' => $this->month, 'issued_on' => $this->issuedOn, 'clientIds' => $this->clientIds],
            [
                'month' => ['required', 'date_format:Y-m'],
                'issued_on' => ['required', 'date_format:Y-m-d'],
                'clientIds' => ['required', 'array', 'min:1'],
                'clientIds.*' => ['integer', 'exists:clients,id'],
            ],
            [
                'month.required' => 'Choose a month to lock.',
                'month.date_format' => 'Choose a valid month.',
                'issued_on.required' => 'Enter the date the report was issued.',
                'issued_on.date_format' => 'Enter a valid issued date.',
                'clientIds.required' => 'Pick at least one client.',
                'clientIds.min' => 'Pick at least one client.',
            ],
        )->validate();

        // Both strings passed the format rules above.
        $month = CarbonImmutable::parse($this->month.'-01')->startOfDay();
        $issuedOn = CarbonImmutable::parse($this->issuedOn)->startOfDay();
        /** @var User $admin */
        $admin = auth()->user();
        $clients = Client::query()->whereIn('id', $this->clientIds)->orderBy('name')->get();

        DB::transaction(function () use ($clients, $month, $issuedOn, $admin, $service): void {
            foreach ($clients as $client) {
                $service->lock($client, $month, $issuedOn, $admin);
            }
        });

        $this->confirmation = sprintf(
            'Locked %s for %d %s.',
            $month->format('F Y'),
            $clients->count(),
            Str::plural('client', $clients->count()),
        );
    }

    public function startOverride(int $lockId): void
    {
        Gate::authorize('access-admin');

        $this->overrideLockId = MonthLock::findOrFail($lockId)->id;
        $this->overrideReason = '';
        $this->resetErrorBag();
    }

    public function openOverride(MonthLockService $service): void
    {
        Gate::authorize('access-admin');

        $lock = MonthLock::with('client')->findOrFail($this->overrideLockId);
        /** @var User $admin */
        $admin = auth()->user();

        $override = $service->openOverride($lock, $admin, $this->overrideReason);

        $this->overrideLockId = null;
        $this->overrideReason = '';
        $this->confirmation = sprintf(
            'Override open for %s, %s, until %s.',
            $lock->client->name,
            $lock->label(),
            $override->expires_at->format('H:i'),
        );
    }

    public function closeOverride(int $overrideId, MonthLockService $service): void
    {
        Gate::authorize('access-admin');

        $service->closeOverride(MonthLockOverride::findOrFail($overrideId));

        $this->confirmation = 'Override closed.';
    }

    public function render(): View
    {
        $locks = MonthLock::query()
            ->with(['client', 'lockedBy', 'overrides' => fn ($query) => $query->active()->with('user')])
            ->withSum('snapshots', 'hours')
            ->orderByDesc('month')
            ->orderBy(Client::query()->select('name')->whereColumn('clients.id', 'month_locks.client_id'))
            ->get();

        return view('livewire.admin.month-locks.index', [
            'locks' => $locks,
            'clients' => Client::query()->orderBy('name')->get(),
        ]);
    }
}
