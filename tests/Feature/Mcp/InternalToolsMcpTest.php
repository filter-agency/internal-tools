<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Mcp\InternalToolsServer;
use App\Mcp\Tools\ArchiveClient;
use App\Mcp\Tools\ArchiveProject;
use App\Mcp\Tools\AssignProjectMember;
use App\Mcp\Tools\CreateClient;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\DeleteTimeEntry;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\LogTimeEntry;
use App\Mcp\Tools\TimeReport;
use App\Mcp\Tools\UpdateClient;
use App\Mcp\Tools\UpdateProject;
use App\Mcp\Tools\UpdateTimeEntry;
use App\Models\AsanaProject;
use App\Models\AsanaTask;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\McpPendingAction;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function mcpAssignedProject(
    User $user,
    string $clientName = 'Acme',
    string $projectName = 'Website rebuild',
    string $taskName = 'Development',
): array {
    $client = Client::factory()->create(['name' => $clientName]);
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => $projectName]);
    $task = Task::factory()->create(['name' => $taskName]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    return [$client, $project, $task];
}

function mcpToolFrom(array $tools, string $name): ?array
{
    return collect($tools)
        ->firstWhere('name', $name);
}

function mcpLinkedAsanaProject(User $user, bool $asanaTaskRequired = true, bool $billable = true): array
{
    [$client, $project, $task] = mcpAssignedProject($user);
    $project->forceFill([
        'asana_task_required' => $asanaTaskRequired,
        'is_billable' => $billable,
    ])->save();

    $asanaProject = AsanaProject::create([
        'gid' => 'AP1',
        'workspace_gid' => 'WS1',
        'name' => 'Asana board',
        'is_archived' => false,
    ]);

    $project->asanaProjects()->attach($asanaProject->gid, ['asana_custom_field_gid' => null]);

    $asanaTask = AsanaTask::create([
        'gid' => 'AT1',
        'asana_project_gid' => $asanaProject->gid,
        'name' => 'Asana task',
        'is_completed' => false,
    ]);

    return [$client, $project, $task, $asanaTask];
}

test('oauth dynamic registration is rate limited', function () {
    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/oauth/register', [
            'client_name' => "Trusted AI connector {$attempt}",
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => 'mcp:use',
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/oauth/register', [
        'client_name' => 'Trusted AI connector overflow',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'scope' => 'mcp:use',
        'token_endpoint_auth_method' => 'none',
    ])->assertTooManyRequests();
});

test('oauth authorize renders a consent screen for registered MCP clients', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';

    $clientId = $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [$redirectUri],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'scope' => 'mcp:use',
        'token_endpoint_auth_method' => 'none',
    ])
        ->assertCreated()
        ->json('client_id');

    $response = $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => 'H4RW8540FmDndrERFfkQHOE_EfPMMHY-30CFd74gnTE',
            'code_challenge_method' => 'S256',
            'state' => 'test-state',
            'scope' => 'mcp:use',
            'resource' => 'https://internal.filter.agency/mcp',
        ]))
        ->assertOk()
        ->assertSee('Claude')
        ->assertSee('Approve');

    preg_match('/name="auth_token" value="([^"]+)"/', $response->getContent(), $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    $approval = $this->post(route('passport.authorizations.approve'), [
        'auth_token' => $matches[1],
    ]);

    $approval->assertRedirect();

    expect($approval->headers->get('Location'))
        ->toStartWith($redirectUri.'?code=')
        ->toContain('state=test-state');
});

test('mcp endpoint requires an OAuth token with the MCP scope', function () {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 'init-1',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'Test Client', 'version' => '1.0.0'],
        ],
    ];

    $this->postJson('/mcp', $payload)->assertUnauthorized();

    Passport::actingAs(User::factory()->create(), []);

    $this->postJson('/mcp', $payload)->assertForbidden();

    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson('/mcp', $payload)
        ->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 'init-1');
});

test('list projects advertises all projects filter and lets admins request org-wide projects', function () {
    Passport::actingAs(User::factory()->admin()->create(), ['mcp:use']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'tools-1',
        'method' => 'tools/list',
        'params' => [],
    ])->assertOk();

    $tool = mcpToolFrom($response->json('result.tools'), 'list-projects');

    expect($tool)->not->toBeNull();

    $schema = $tool['inputSchema'];
    expect(array_keys($schema['properties']))->toEqualCanonicalizing([
        'all',
        'include_archived',
    ])
        ->and($schema['properties']['all']['type'])->toBe('boolean')
        ->and($schema['properties']['include_archived']['type'])->toBe('boolean');

    $admin = User::factory()->admin()->create();
    $regularUser = User::factory()->create();
    [, $assignedProject] = mcpAssignedProject($admin);
    $assignedProject->users()->attach($regularUser->id, [
        'hourly_rate_override' => null,
        'rate_id' => null,
    ]);
    [, $unassignedProject] = mcpAssignedProject(
        User::factory()->create(),
        clientName: 'Another client',
        projectName: 'Unassigned project',
        taskName: 'Strategy',
    );

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(ListProjects::class)
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($assignedProject, $unassignedProject) {
            $json->has('projects');

            $projectIds = collect($json->toArray()['projects'])->pluck('id')->all();

            expect($projectIds)->toContain($assignedProject->id)
                ->and($projectIds)->not->toContain($unassignedProject->id);
        });

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(ListProjects::class, ['all' => true])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($assignedProject, $unassignedProject) {
            $json->has('projects');

            $projectIds = collect($json->toArray()['projects'])->pluck('id')->all();

            expect($projectIds)->toContain($assignedProject->id)
                ->and($projectIds)->toContain($unassignedProject->id);
        });

    InternalToolsServer::actingAs($regularUser, 'api')
        ->tool(ListProjects::class, ['all' => true])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($assignedProject, $unassignedProject) {
            $json->has('projects');

            $projectIds = collect($json->toArray()['projects'])->pluck('id')->all();

            expect($projectIds)->toContain($assignedProject->id)
                ->and($projectIds)->not->toContain($unassignedProject->id);
        });
});

test('mcp tools advertise complete input schemas for accepted arguments', function () {
    Passport::actingAs(User::factory()->admin()->create(), ['mcp:use']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'tools-1',
        'method' => 'tools/list',
        'params' => [],
    ])->assertOk();

    $tools = collect($response->json('result.tools'))->keyBy('name');

    $expectations = [
        'archive-client' => [
            'required' => ['client_id'],
            'properties' => [
                'archive' => ['type' => 'boolean'],
                'client_id' => ['type' => 'integer'],
            ],
        ],
        'archive-project' => [
            'required' => ['project_id'],
            'properties' => [
                'archive' => ['type' => 'boolean'],
                'project_id' => ['type' => 'integer'],
            ],
        ],
        'assign-project-member' => [
            'required' => ['project_id', 'user_id'],
            'properties' => [
                'project_id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer'],
            ],
        ],
        'create-client' => [
            'required' => ['name'],
            'properties' => [
                'code' => ['type' => 'string'],
                'default_task_ids' => ['type' => 'array', 'items' => 'integer'],
                'name' => ['type' => 'string'],
                'task_billability_profile' => ['type' => 'string', 'enum' => ['agency', 'jdw']],
            ],
        ],
        'delete-time-entry' => [
            'required' => ['time_entry_id'],
            'properties' => [
                'time_entry_id' => ['type' => 'integer'],
            ],
        ],
        'get-project-budget' => [
            'required' => ['project_id'],
            'properties' => [
                'project_id' => ['type' => 'integer'],
            ],
        ],
        'list-clients' => [
            'required' => [],
            'properties' => [
                'include_archived' => ['type' => 'boolean'],
            ],
        ],
        'list-tasks' => [
            'required' => [],
            'properties' => [
                'include_archived' => ['type' => 'boolean'],
            ],
        ],
        'list-time-entries' => [
            'required' => [],
            'properties' => [
                'from' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
                'project_id' => ['type' => 'integer'],
                'task_id' => ['type' => 'integer'],
                'to' => ['type' => 'string'],
                'user_id' => ['type' => 'integer'],
            ],
        ],
        'list-users' => [
            'required' => [],
            'properties' => [
                'include_inactive' => ['type' => 'boolean'],
            ],
        ],
        'start-timer' => [
            'required' => ['project_id', 'spent_on', 'task_id'],
            'properties' => [
                'asana_task_gid' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'project_id' => ['type' => 'integer'],
                'spent_on' => ['type' => 'string'],
                'task_id' => ['type' => 'integer'],
            ],
        ],
        'time-report' => [
            'required' => ['from', 'to'],
            'properties' => [
                'billable_only' => ['type' => 'boolean'],
                'client_id' => ['type' => 'integer'],
                'from' => ['type' => 'string'],
                'group_by' => ['type' => 'string', 'enum' => ['client', 'project', 'task', 'user']],
                'project_id' => ['type' => 'integer'],
                'task_id' => ['type' => 'integer'],
                'to' => ['type' => 'string'],
                'user_id' => ['type' => 'integer'],
            ],
        ],
        'unassign-project-member' => [
            'required' => ['project_id', 'user_id'],
            'properties' => [
                'project_id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer'],
            ],
        ],
        'update-client' => [
            'required' => ['client_id'],
            'properties' => [
                'client_id' => ['type' => 'integer'],
                'code' => ['type' => 'string'],
                'default_task_ids' => ['type' => 'array', 'items' => 'integer'],
                'name' => ['type' => 'string'],
                'task_billability_profile' => ['type' => 'string', 'enum' => ['agency', 'jdw']],
            ],
        ],
        'update-project' => [
            'required' => ['project_id'],
            'properties' => [
                'asana_task_required' => ['type' => 'boolean'],
                'budget_amount' => ['type' => 'number'],
                'budget_hours' => ['type' => 'number'],
                'budget_starts_on' => ['type' => 'string'],
                'budget_type' => ['type' => 'string', 'enum' => ['fixed_fee', 'monthly_ci']],
                'client_id' => ['type' => 'integer'],
                'code' => ['type' => 'string'],
                'default_hourly_rate' => ['type' => 'number'],
                'ends_on' => ['type' => 'string'],
                'is_billable' => ['type' => 'boolean'],
                'manager_user_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'project_id' => ['type' => 'integer'],
                'starts_on' => ['type' => 'string'],
                'task_ids' => ['type' => 'array', 'items' => 'integer'],
                'user_ids' => ['type' => 'array', 'items' => 'integer'],
            ],
        ],
        'update-time-entry' => [
            'required' => ['time_entry_id'],
            'properties' => [
                'asana_task_gid' => ['type' => 'string'],
                'hours' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'project_id' => ['type' => 'integer'],
                'spent_on' => ['type' => 'string'],
                'task_id' => ['type' => 'integer'],
                'time_entry_id' => ['type' => 'integer'],
            ],
        ],
    ];

    foreach ($expectations as $toolName => $expectation) {
        $tool = $tools->get($toolName);
        expect($tool)->not->toBeNull();

        $schema = $tool['inputSchema'];
        expect(array_keys($schema['properties'] ?? []))->toEqualCanonicalizing(array_keys($expectation['properties']))
            ->and($schema['required'] ?? [])->toEqualCanonicalizing($expectation['required']);

        foreach ($expectation['properties'] as $propertyName => $propertyExpectation) {
            $property = $schema['properties'][$propertyName] ?? null;
            expect($property)->not->toBeNull()
                ->and((array) $property['type'])->toContain($propertyExpectation['type']);

            if (array_key_exists('items', $propertyExpectation)) {
                expect($property['items']['type'])->toBe($propertyExpectation['items']);
            }

            if (array_key_exists('enum', $propertyExpectation)) {
                expect($property['enum'])->toEqualCanonicalizing($propertyExpectation['enum']);
            }
        }
    }
});

test('log time entry writes immediately and records an MCP audit row', function () {
    $user = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(LogTimeEntry::class, [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_at' => '2026-05-04T09:30:00+01:00',
            'hours' => '1:30',
            'notes' => 'Build landing page',
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'time_entry_id' => 1,
        ]);

    $entry = TimeEntry::firstOrFail();
    expect((float) $entry->hours)->toBe(1.5)
        ->and($entry->spent_on->toDateString())->toBe('2026-05-04')
        ->and($entry->user_id)->toBe($user->id);

    expect(McpAuditLog::where('action', 'log_time_entry')
        ->where('user_id', $user->id)
        ->where('status', 'completed')
        ->exists())->toBeTrue();
});

test('log time entry rejects invalid Asana task even when linked project marks it optional', function () {
    $user = User::factory()->create();
    [, $project, $task] = mcpLinkedAsanaProject($user, asanaTaskRequired: false);
    AsanaTask::create([
        'gid' => 'OUTSIDER',
        'asana_project_gid' => 'OUTSIDE',
        'name' => 'Foreign task',
        'is_completed' => false,
    ]);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(LogTimeEntry::class, [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_at' => '2026-05-04T09:30:00+01:00',
            'hours' => '1:30',
            'asana_task_gid' => 'OUTSIDER',
        ])
        ->assertHasErrors(['The selected Asana task does not belong to this project.']);

    expect(TimeEntry::count())->toBe(0);
});

test('updating another users time entry creates a pending approval and does not mutate data', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($owner);
    $entry = TimeEntry::factory()->create([
        'user_id' => $owner->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'hours' => 1.0,
        'notes' => 'Original',
    ]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(UpdateTimeEntry::class, [
            'time_entry_id' => $entry->id,
            'hours' => '3:00',
            'notes' => 'Admin change',
        ])
        ->assertOk()
        ->assertSee('approval_url')
        ->assertSee('approval_required');

    $entry->refresh();
    expect((float) $entry->hours)->toBe(1.0)
        ->and($entry->notes)->toBe('Original')
        ->and(McpPendingAction::where('action', 'update_time_entry')->where('requested_by_user_id', $admin->id)->count())->toBe(1);

    $pending = McpPendingAction::firstOrFail();
    expect(json_encode($pending->payload))->not->toContain('Admin change')
        ->and($pending->payload_hash)->not->toBeNull();
});

test('deleting a time entry always requires owner approval and approval executes the delete', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
    ]);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(DeleteTimeEntry::class, ['time_entry_id' => $entry->id])
        ->assertOk()
        ->assertSee('approval_url');

    expect(TimeEntry::whereKey($entry->id)->exists())->toBeTrue();

    $pending = McpPendingAction::firstOrFail();

    $this->actingAs($otherUser)
        ->get(route('mcp.pending-actions.show', $pending->approval_token))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('mcp.pending-actions.approve', $pending->approval_token))
        ->assertRedirect(route('mcp.pending-actions.show', $pending->approval_token));

    expect(TimeEntry::whereKey($entry->id)->exists())->toBeFalse();
    expect($pending->refresh()->status)->toBe('approved');
});

test('approving a stale pending time entry action is rejected without mutating data', function () {
    $user = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'hours' => 1.0,
        'notes' => 'Original note',
    ]);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(DeleteTimeEntry::class, ['time_entry_id' => $entry->id])
        ->assertOk()
        ->assertSee('approval_url');

    $pending = McpPendingAction::firstOrFail();
    $entry->update(['notes' => 'Changed after approval request']);

    $this->actingAs($user)
        ->post(route('mcp.pending-actions.approve', $pending->approval_token))
        ->assertSessionHasErrors('action');

    expect(TimeEntry::whereKey($entry->id)->exists())->toBeTrue()
        ->and($entry->fresh()->notes)->toBe('Changed after approval request')
        ->and($pending->refresh()->status)->toBe('stale');
});

test('archiving clients and projects through MCP requires approval', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create(['is_archived' => false]);
    $project = Project::factory()->create(['client_id' => $client->id, 'is_archived' => false]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(ArchiveClient::class, [
            'client_id' => $client->id,
            'archive' => true,
        ])
        ->assertOk()
        ->assertSee('approval_url');

    expect($client->fresh()->is_archived)->toBeFalse();

    $clientPending = McpPendingAction::where('action', 'archive_client')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('mcp.pending-actions.approve', $clientPending->approval_token))
        ->assertRedirect(route('mcp.pending-actions.show', $clientPending->approval_token));

    expect($client->fresh()->is_archived)->toBeTrue();

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(ArchiveProject::class, [
            'project_id' => $project->id,
            'archive' => true,
        ])
        ->assertOk()
        ->assertSee('approval_url');

    expect($project->fresh()->is_archived)->toBeFalse();

    $projectPending = McpPendingAction::where('action', 'archive_project')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('mcp.pending-actions.approve', $projectPending->approval_token))
        ->assertRedirect(route('mcp.pending-actions.show', $projectPending->approval_token));

    expect($project->fresh()->is_archived)->toBeTrue();
});

test('mcp audit logs redact free-text notes while retaining a forensic hash', function () {
    $user = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(LogTimeEntry::class, [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-05-04',
            'hours' => '1:30',
            'notes' => 'Do not persist this raw note',
        ])
        ->assertOk();

    $log = McpAuditLog::where('action', 'log_time_entry')->firstOrFail();

    expect(json_encode($log->input))->not->toContain('Do not persist this raw note')
        ->and($log->input['_hash'])->not->toBeNull()
        ->and($log->input['data']['notes'])->toBe('[redacted]');
});

test('admin project and client writes audit immediately without approval', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $task = Task::factory()->create(['name' => 'Strategy']);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(CreateClient::class, [
            'name' => 'Globex',
            'code' => 'GLX',
            'task_billability_profile' => ClientTaskBillabilityProfile::Jdw->value,
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'client_id' => 1,
        ]);

    expect(Client::firstOrFail()->task_billability_profile)->toBe(ClientTaskBillabilityProfile::Jdw);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(UpdateClient::class, [
            'client_id' => Client::firstOrFail()->id,
            'task_billability_profile' => ClientTaskBillabilityProfile::Agency->value,
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'client_id' => 1,
        ]);

    expect(Client::firstOrFail()->task_billability_profile)->toBe(ClientTaskBillabilityProfile::Agency);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(CreateProject::class, [
            'client_id' => Client::firstOrFail()->id,
            'code' => 'GLX-001',
            'name' => 'Discovery',
            'task_ids' => [$task->id],
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'project_id' => 1,
        ]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(AssignProjectMember::class, [
            'project_id' => Project::firstOrFail()->id,
            'user_id' => $member->id,
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'project_id' => 1,
            'user_id' => $member->id,
        ]);

    expect(McpPendingAction::count())->toBe(0);
    expect(McpAuditLog::where('action', 'create_client')->where('status', 'completed')->exists())->toBeTrue();
    expect(McpAuditLog::where('action', 'update_client')->where('status', 'completed')->exists())->toBeTrue();
    expect(McpAuditLog::where('action', 'create_project')->where('status', 'completed')->exists())->toBeTrue();
    expect(McpAuditLog::where('action', 'assign_project_member')->where('status', 'completed')->exists())->toBeTrue();
});

test('mcp updateProject re-applies task billability when the client changes', function () {
    $admin = User::factory()->admin()->create();
    $agencyClient = Client::factory()->create([
        'task_billability_profile' => ClientTaskBillabilityProfile::Agency,
    ]);
    $jdwClient = Client::factory()->create([
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);
    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);
    $project = Project::factory()->create(['client_id' => $agencyClient->id]);
    $project->tasks()->attach($task->id, ['is_billable' => false]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(UpdateProject::class, [
            'project_id' => $project->id,
            'client_id' => $jdwClient->id,
        ])
        ->assertOk();

    expect((bool) $project->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});

test('time report includes archived project hours in totals and groups', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();
    $active = Project::factory()->create(['client_id' => $client->id, 'name' => 'Live project']);
    $archived = Project::factory()->create(['client_id' => $client->id, 'name' => 'Archived project', 'is_archived' => true]);
    $task = Task::factory()->create();

    foreach ([[$active, 1.0], [$archived, 2.0]] as [$project, $hours]) {
        TimeEntry::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-07-10',
            'hours' => $hours,
            'is_running' => false,
            'is_billable' => true,
            'billable_rate_snapshot' => 100.0,
            'billable_amount' => $hours * 100,
        ]);
    }

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(TimeReport::class, ['from' => '2026-07-01', 'to' => '2026-07-31', 'group_by' => 'project'])
        ->assertOk()
        ->assertStructuredContent(function ($json) {
            $json->has('totals')->has('groups');

            $report = $json->toArray();

            expect((float) $report['totals']['total_hours'])->toBe(3.0)
                ->and(collect($report['groups'])->pluck('label')->all())->toContain('Live project', 'Archived project');
        });
});
