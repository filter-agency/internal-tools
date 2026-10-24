<?php

namespace App\Models;

use App\Enums\ClientTaskBillabilityProfile;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property ClientTaskBillabilityProfile $task_billability_profile
 * @property bool $is_archived
 * @property Collection<int, Project> $projects
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'task_billability_profile', 'is_archived'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'task_billability_profile' => ClientTaskBillabilityProfile::Agency->value,
    ];

    protected function casts(): array
    {
        return [
            'task_billability_profile' => ClientTaskBillabilityProfile::class,
            'is_archived' => 'boolean',
        ];
    }

    public function usesJdwTaskDefaults(): bool
    {
        return $this->task_billability_profile === ClientTaskBillabilityProfile::Jdw;
    }

    /**
     * Clients that are not archived and use the JDW task billability profile.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeActiveJdw(Builder $query): Builder
    {
        return $query
            ->where('is_archived', false)
            ->where('task_billability_profile', ClientTaskBillabilityProfile::Jdw);
    }

    public function reapplyTaskBillabilityToProjects(): void
    {
        $projects = $this->projects()->with('tasks')->get();

        foreach ($projects as $project) {
            $project->setRelation('client', $this);
            $project->reapplyTaskBillabilityToTasks();
        }
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Project, $this> */
    public function activeProjects(): HasMany
    {
        return $this->hasMany(Project::class)->where('is_archived', false);
    }

    /** @return BelongsToMany<Task, $this> */
    public function defaultTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'client_default_tasks')
            ->withPivot('sort_order')
            ->orderBy('client_default_tasks.sort_order');
    }
}
