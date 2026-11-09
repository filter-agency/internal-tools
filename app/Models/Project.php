<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Domain\TimeTracking\MonthLockGuard;
use App\Enums\BudgetType;
use App\Enums\JdwCategory;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property int|null $manager_user_id
 * @property string $code
 * @property string $name
 * @property bool $is_billable
 * @property float|null $default_hourly_rate
 * @property int|null $rate_id
 * @property BudgetType|null $budget_type
 * @property float|null $budget_amount
 * @property float|null $budget_hours
 * @property Carbon|null $budget_starts_on
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_archived
 * @property bool $asana_task_required
 * @property JdwCategory|null $jdw_category
 * @property int|null $jdw_sort_order
 * @property string|null $jdw_status
 * @property string|null $jdw_estimated_launch
 * @property string|null $jdw_description
 * @property Client $client
 * @property Collection<int, Task> $tasks
 * @property Collection<int, User> $users
 * @property Collection<int, AsanaProject> $asanaProjects
 * @property Collection<int, ScheduleAssignment> $scheduleAssignments
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @var array<int, string> */
    private const ASANA_TASK_MATCH_STOP_WORDS = [
        'a',
        'an',
        'and',
        'app',
        'apps',
        'build',
        'development',
        'for',
        'maintenance',
        'monthly',
        'ongoing',
        'phase',
        'project',
        'projects',
        'retainer',
        'site',
        'support',
        'the',
        'website',
        'with',
        'work',
    ];

    protected $fillable = [
        'client_id', 'manager_user_id', 'code', 'name', 'is_billable', 'default_hourly_rate', 'rate_id',
        'budget_type', 'budget_amount', 'budget_hours', 'budget_starts_on',
        'starts_on', 'ends_on', 'is_archived', 'asana_task_required',
        'jdw_category', 'jdw_sort_order', 'jdw_status', 'jdw_estimated_launch', 'jdw_description',
    ];

    /**
     * Defaults applied to newly-constructed (un-saved or freshly inserted)
     * instances. Mirrors the DB-level defaults so reading $project->asana_task_required
     * after a Project::factory()->create() doesn't return null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'asana_task_required' => true,
    ];

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty('client_id')) {
            return parent::save($options);
        }

        return app(MonthLockGuard::class)->withProjectClientWrite($this, fn () => parent::save($options));
    }

    protected function casts(): array
    {
        return [
            'is_billable' => 'boolean',
            'budget_type' => BudgetType::class,
            'jdw_category' => JdwCategory::class,
            'default_hourly_rate' => Decimal::class.':2',
            'budget_amount' => Decimal::class.':2',
            'budget_hours' => Decimal::class.':2',
            'budget_starts_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_archived' => 'boolean',
            'asana_task_required' => 'boolean',
            'jdw_sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsToMany<Task, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)
            ->withPivot(['is_billable', 'hourly_rate_override', 'rate_id']);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['hourly_rate_override', 'rate_id']);
    }

    /** @return BelongsTo<Rate, $this> */
    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<ScheduleAssignment, $this> */
    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    /** @return BelongsToMany<AsanaProject, $this> */
    public function asanaProjects(): BelongsToMany
    {
        return $this->belongsToMany(
            AsanaProject::class,
            'project_asana_links',
            'project_id',
            'asana_project_gid',
            'id',
            'gid',
        )
            ->withPivot('asana_custom_field_gid')
            ->withTimestamps();
    }

    public function asanaLinked(): bool
    {
        return $this->asanaProjects()->exists();
    }

    public function timesheetDisplayName(): string
    {
        $code = trim((string) $this->code);

        if ($code === '' || $this->belongsToJdwClient()) {
            return $this->name;
        }

        return "[{$code}] {$this->name}";
    }

    /** @return array<int, string> */
    public function asanaTaskMatchTerms(): array
    {
        $terms = [];
        $addTerm = function (?string $term) use (&$terms): void {
            $normalized = $this->normalizeAsanaTaskMatchTerm($term);

            if ($normalized !== '' && ! $this->isGenericAsanaTaskMatchTerm($normalized)) {
                $terms[] = $normalized;
            }
        };

        $addTerm($this->code);
        $addTerm($this->compactAsanaTaskMatchTerm($this->code));

        $clientName = $this->client->name;
        $addTerm($clientName);
        $addTerm($this->compactAsanaTaskMatchTerm($clientName));

        foreach ($this->asanaTaskMatchSegments($this->name) as $segment) {
            $normalizedSegment = $this->normalizeAsanaTaskMatchTerm($segment);

            if ($normalizedSegment === '' || $this->isGenericAsanaTaskMatchTerm($normalizedSegment)) {
                continue;
            }

            $addTerm($normalizedSegment);
            $addTerm($this->compactAsanaTaskMatchTerm($normalizedSegment));

            foreach ($this->domainBasesForAsanaTaskMatch($normalizedSegment) as $domainBase) {
                $addTerm($domainBase);
            }

            foreach ($this->tokensForAsanaTaskMatch($normalizedSegment) as $token) {
                $addTerm($token);
            }
        }

        return array_values(array_unique($terms));
    }

    public function belongsToJdwClient(): bool
    {
        return $this->client->usesJdwTaskDefaults();
    }

    public function attachClientDefaultTasks(): void
    {
        $this->loadMissing('client.defaultTasks');

        $sync = [];
        foreach ($this->client->defaultTasks as $task) {
            $sync[$task->id] = [
                'is_billable' => $task->defaultBillableForProject($this),
                'hourly_rate_override' => null,
                'rate_id' => null,
            ];
        }

        if ($sync !== []) {
            $this->tasks()->syncWithoutDetaching($sync);
        }
    }

    public function reapplyTaskBillabilityToTasks(): void
    {
        $this->loadMissing(['client', 'tasks']);

        foreach ($this->tasks as $task) {
            $this->tasks()->updateExistingPivot($task->id, [
                'is_billable' => $task->defaultBillableForProject($this),
            ]);
        }
    }

    /** @return array<int, string> */
    private function asanaTaskMatchSegments(?string $value): array
    {
        $parts = preg_split('/\s*[-\/:|]\s*/', (string) $value) ?: [];

        return array_values(array_filter($parts, fn (string $part) => trim($part) !== ''));
    }

    /** @return array<int, string> */
    private function domainBasesForAsanaTaskMatch(string $value): array
    {
        preg_match_all('/\b([a-z0-9][a-z0-9-]*)\.[a-z]{2,}(?:\.[a-z]{2,})?\b/i', $value, $matches);

        return $matches[1];
    }

    /** @return array<int, string> */
    private function tokensForAsanaTaskMatch(string $value): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];

        return array_values(array_filter($tokens, function (string $token): bool {
            if (in_array($token, self::ASANA_TASK_MATCH_STOP_WORDS, true)) {
                return false;
            }

            return strlen($token) >= 4 || preg_match('/^[a-z]{2,5}\d{0,3}$/', $token) === 1;
        }));
    }

    private function normalizeAsanaTaskMatchTerm(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        $normalized = preg_replace('/[^a-z0-9.]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return trim($normalized);
    }

    private function compactAsanaTaskMatchTerm(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)) ?? '';
    }

    private function isGenericAsanaTaskMatchTerm(string $term): bool
    {
        if (strlen($term) < 3) {
            return true;
        }

        $words = preg_split('/[^a-z0-9]+/', $term) ?: [];
        $words = array_values(array_filter($words, fn (string $word) => $word !== ''));

        return $words !== []
            && collect($words)->every(fn (string $word) => in_array($word, self::ASANA_TASK_MATCH_STOP_WORDS, true));
    }
}
