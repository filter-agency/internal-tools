<?php

use App\Domain\Reporting\ContractPositionTimeline;
use App\Enums\ContractPosition;
use App\Livewire\Admin\Users\Index;
use App\Models\User;
use App\Models\UserContractPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('contract positions have the audit labels and cover all twelve roles', function () {
    expect(ContractPosition::CeoCto->value)->toBe('ceo_cto')
        ->and(ContractPosition::CeoCto->label())->toBe('CEO and CTO (50% each)')
        ->and(ContractPosition::PlannerProductOwner->label())->toBe('Planner / Product Owner')
        ->and(ContractPosition::UxDesign->label())->toBe('UX and Design')
        ->and(ContractPosition::OUTSIDE_LABEL)->toBe('Outside contracted team')
        ->and(count(ContractPosition::cases()))->toBe(12);

    expect(collect(ContractPosition::cases())->map->label()->all())->toBe([
        'CEO and CTO (50% each)',
        'Head of Delivery',
        'Head of Development',
        'Head of Support',
        'Planner / Product Owner',
        'Technical Architect',
        'Senior Developer',
        'UX and Design',
        'Developer',
        'Project Manager',
        'Tester',
        'Support',
    ]);
});

test('the timeline resolves the position a person held on a date', function () {
    $user = User::factory()->create();
    UserContractPosition::create(['user_id' => $user->id, 'position' => ContractPosition::SeniorDeveloper, 'effective_from' => '2026-08-01']);
    UserContractPosition::create(['user_id' => $user->id, 'position' => null, 'effective_from' => '2026-03-01']);
    UserContractPosition::create(['user_id' => $user->id, 'position' => ContractPosition::Developer, 'effective_from' => '2024-03-01']);

    $timeline = ContractPositionTimeline::load();

    expect($timeline->on($user->id, '2024-02-29'))->toBeNull()
        ->and($timeline->on($user->id, '2024-03-01'))->toBe(ContractPosition::Developer)
        ->and($timeline->on($user->id, '2025-01-15'))->toBe(ContractPosition::Developer)
        ->and($timeline->on($user->id, '2026-02-28'))->toBe(ContractPosition::Developer)
        ->and($timeline->on($user->id, '2026-03-01'))->toBeNull()
        ->and($timeline->on($user->id, '2026-04-01'))->toBeNull()
        ->and($timeline->on($user->id, '2026-07-31'))->toBeNull()
        ->and($timeline->on($user->id, '2026-08-01'))->toBe(ContractPosition::SeniorDeveloper)
        ->and($timeline->on($user->id, '2030-01-01'))->toBe(ContractPosition::SeniorDeveloper)
        ->and($timeline->labelOn($user->id, '2026-04-01'))->toBe('Outside contracted team')
        ->and($timeline->labelOn($user->id, '2026-09-01'))->toBe('Senior Developer')
        ->and($timeline->labelOn($user->id, '2024-02-29'))->toBe('Outside contracted team');
});

test('the timeline accepts dates as Carbon instances and ignores the time of day', function () {
    $user = User::factory()->create();
    UserContractPosition::create(['user_id' => $user->id, 'position' => ContractPosition::Tester, 'effective_from' => '2025-02-01']);

    $timeline = ContractPositionTimeline::load();

    expect($timeline->on($user->id, now()->setDate(2025, 2, 1)->setTime(23, 59)))->toBe(ContractPosition::Tester)
        ->and($timeline->on($user->id, now()->setDate(2025, 1, 31)->setTime(23, 59)))->toBeNull()
        ->and($timeline->on($user->id, '2025-02-01 08:30:00'))->toBe(ContractPosition::Tester);
});

test('the timeline keeps people apart and treats a person without rows as outside', function () {
    $tester = User::factory()->create();
    $developer = User::factory()->create();
    $nobody = User::factory()->create();
    UserContractPosition::create(['user_id' => $tester->id, 'position' => ContractPosition::Tester, 'effective_from' => '2025-01-01']);
    UserContractPosition::create(['user_id' => $developer->id, 'position' => ContractPosition::Developer, 'effective_from' => '2025-06-01']);

    $timeline = ContractPositionTimeline::load();

    expect($timeline->on($tester->id, '2025-07-01'))->toBe(ContractPosition::Tester)
        ->and($timeline->on($developer->id, '2025-07-01'))->toBe(ContractPosition::Developer)
        ->and($timeline->on($developer->id, '2025-05-31'))->toBeNull()
        ->and($timeline->on($nobody->id, '2025-07-01'))->toBeNull()
        ->and($timeline->labelOn($nobody->id, '2025-07-01'))->toBe('Outside contracted team');
});

test('a user lists their contract positions oldest first', function () {
    $user = User::factory()->create();
    $later = UserContractPosition::create(['user_id' => $user->id, 'position' => ContractPosition::Developer, 'effective_from' => '2026-01-01']);
    $earlier = UserContractPosition::create(['user_id' => $user->id, 'position' => null, 'effective_from' => '2025-01-01']);

    expect($user->contractPositions->pluck('id')->all())->toBe([$earlier->id, $later->id]);
});

test('an admin adds a contract position and the same date cannot be used twice', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('newPosition', ContractPosition::Tester->value)
        ->set('newPositionFrom', '2025-02-01')
        ->call('addContractPosition')
        ->assertHasNoErrors()
        ->assertSet('newPosition', '')
        ->assertSet('newPositionFrom', '');

    $row = UserContractPosition::query()->where('user_id', $person->id)->sole();
    expect($row->position)->toBe(ContractPosition::Tester)
        ->and($row->effective_from->toDateString())->toBe('2025-02-01');

    $component
        ->set('newPosition', ContractPosition::Developer->value)
        ->set('newPositionFrom', '2025-02-01')
        ->call('addContractPosition')
        ->assertHasErrors(['newPositionFrom']);

    expect(UserContractPosition::query()->where('user_id', $person->id)->count())->toBe(1)
        ->and(UserContractPosition::query()->where('user_id', $person->id)->sole()->position)->toBe(ContractPosition::Tester);
});

test('an admin records that a person left the contracted team', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('newPosition', '')
        ->set('newPositionFrom', '2026-03-01')
        ->call('addContractPosition')
        ->assertHasNoErrors();

    $row = UserContractPosition::query()->where('user_id', $person->id)->sole();
    expect($row->position)->toBeNull()
        ->and(ContractPositionTimeline::load()->labelOn($person->id, '2026-03-01'))->toBe('Outside contracted team');
});

test('adding a contract position validates the position and the date', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('newPosition', 'chief_tea_maker')
        ->set('newPositionFrom', '2025-02-01')
        ->call('addContractPosition')
        ->assertHasErrors(['newPosition'])
        ->set('newPosition', ContractPosition::Tester->value)
        ->set('newPositionFrom', '')
        ->call('addContractPosition')
        ->assertHasErrors(['newPositionFrom'])
        ->assertSee('The effective date field is required.')
        ->set('newPositionFrom', 'not a date')
        ->call('addContractPosition')
        ->assertHasErrors(['newPositionFrom']);

    expect(UserContractPosition::query()->count())->toBe(0);
});

test('a position change on the same date for a different person is allowed', function () {
    $admin = User::factory()->admin()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();
    UserContractPosition::create(['user_id' => $first->id, 'position' => ContractPosition::Tester, 'effective_from' => '2025-02-01']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $second->id)
        ->set('newPosition', ContractPosition::Tester->value)
        ->set('newPositionFrom', '2025-02-01')
        ->call('addContractPosition')
        ->assertHasNoErrors();

    expect(UserContractPosition::query()->where('user_id', $second->id)->count())->toBe(1);
});

test('an admin removes a contract position of the person being edited', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();
    $row = UserContractPosition::create(['user_id' => $person->id, 'position' => ContractPosition::Tester, 'effective_from' => '2025-02-01']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->call('removeContractPosition', $row->id);

    expect(UserContractPosition::query()->count())->toBe(0);
});

test('removing another persons contract position does nothing', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();
    $other = User::factory()->create();
    $othersRow = UserContractPosition::create(['user_id' => $other->id, 'position' => ContractPosition::Developer, 'effective_from' => '2025-02-01']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->call('removeContractPosition', $othersRow->id);

    expect(UserContractPosition::query()->whereKey($othersRow->id)->exists())->toBeTrue();
});

test('only admins can add or remove contract positions', function () {
    $manager = User::factory()->manager()->create();
    $person = User::factory()->create();
    $row = UserContractPosition::create(['user_id' => $person->id, 'position' => ContractPosition::Tester, 'effective_from' => '2025-02-01']);

    Livewire::actingAs($manager)
        ->test(Index::class)
        ->set('newPosition', ContractPosition::Developer->value)
        ->set('newPositionFrom', '2025-03-01')
        ->call('addContractPosition')
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test(Index::class)
        ->call('removeContractPosition', $row->id)
        ->assertForbidden();

    expect(UserContractPosition::query()->count())->toBe(1);
});

test('the edit panel lists contract positions newest first with an add form', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();
    UserContractPosition::create(['user_id' => $person->id, 'position' => null, 'effective_from' => '2026-03-01']);
    UserContractPosition::create(['user_id' => $person->id, 'position' => ContractPosition::SeniorDeveloper, 'effective_from' => '2026-08-01']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->assertSeeInOrder([
            'Contract position',
            'Senior Developer from 1 Aug 2026',
            'Outside contracted team from 1 Mar 2026',
        ])
        ->assertSee('Changes here save straight away.')
        ->assertSeeHtml('<option value="">Outside contracted team</option>')
        ->assertSeeHtml('<option value="ceo_cto">CEO and CTO (50% each)</option>');
});

test('the contract position section only shows the person being edited', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();
    $other = User::factory()->create();
    UserContractPosition::create(['user_id' => $other->id, 'position' => ContractPosition::Tester, 'effective_from' => '2025-02-01']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->assertDontSee('Tester from 1 Feb 2025');
});

test('opening another persons panel clears a half-filled add form', function () {
    $admin = User::factory()->admin()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $first->id)
        ->set('newPosition', ContractPosition::Tester->value)
        ->set('newPositionFrom', 'not a date')
        ->call('addContractPosition')
        ->assertHasErrors(['newPositionFrom'])
        ->call('edit', $second->id)
        ->assertSet('newPosition', '')
        ->assertSet('newPositionFrom', '')
        ->assertHasNoErrors();
});

test('saving the panel refuses a position change that was filled in but not added', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['role_title' => 'Developer']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editRoleTitle', 'Senior Developer')
        ->set('newPosition', ContractPosition::Tester->value)
        ->set('newPositionFrom', '2026-03-01')
        ->call('save')
        ->assertHasErrors(['newPositionFrom'])
        ->assertSee('Add this position change or clear its date before saving.')
        ->assertSet('editingId', $person->id);

    expect(UserContractPosition::query()->count())->toBe(0)
        ->and($person->fresh()->role_title)->toBe('Developer');
});

test('saving the panel works once the position date is added or cleared', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['role_title' => 'Developer']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editRoleTitle', 'Senior Developer')
        ->set('newPositionFrom', '2026-03-01')
        ->call('save')
        ->assertHasErrors(['newPositionFrom'])
        ->set('newPositionFrom', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($person->fresh()->role_title)->toBe('Senior Developer');
});

test('a contract position date must be from 2000 onwards', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('newPosition', ContractPosition::Tester->value)
        ->set('newPositionFrom', '0205-01-01')
        ->call('addContractPosition')
        ->assertHasErrors(['newPositionFrom' => 'after_or_equal']);

    expect($component->errors()->first('newPositionFrom'))->toBe('Enter a date from 2000 onwards.')
        ->and(UserContractPosition::query()->count())->toBe(0);

    $component
        ->set('newPositionFrom', '2000-01-01')
        ->call('addContractPosition')
        ->assertHasNoErrors();

    expect(UserContractPosition::query()->count())->toBe(1);
});

test('a whitespace-only position is treated as outside the contracted team', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('newPosition', '   ')
        ->set('newPositionFrom', '2026-03-01')
        ->call('addContractPosition')
        ->assertHasNoErrors();

    $row = UserContractPosition::query()->where('user_id', $person->id)->sole();
    expect($row->position)->toBeNull();
});

test('a position value with stray spaces around it is still recognised', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('newPosition', ' tester ')
        ->set('newPositionFrom', '2026-03-01')
        ->call('addContractPosition')
        ->assertHasNoErrors();

    expect(UserContractPosition::query()->where('user_id', $person->id)->sole()->position)->toBe(ContractPosition::Tester);
});
