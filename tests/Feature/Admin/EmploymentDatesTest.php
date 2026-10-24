<?php

use App\Livewire\Admin\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the edit panel shows the saved start and leave dates', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['started_on' => '2025-03-10', 'left_on' => '2026-06-30']);
    $newStarter = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->assertSet('editStartedOn', '2025-03-10')
        ->assertSet('editLeftOn', '2026-06-30')
        ->call('edit', $newStarter->id)
        ->assertSet('editStartedOn', '')
        ->assertSet('editLeftOn', '');
});

test('an admin can save start and leave dates', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editStartedOn', '2025-03-10')
        ->set('editLeftOn', '2026-06-30')
        ->call('save')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->started_on?->toDateString())->toBe('2025-03-10')
        ->and($person->left_on?->toDateString())->toBe('2026-06-30');
});

test('clearing the date fields removes the saved dates', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['started_on' => '2025-03-10', 'left_on' => '2026-06-30']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editStartedOn', '')
        ->set('editLeftOn', '')
        ->call('save')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->started_on)->toBeNull()
        ->and($person->left_on)->toBeNull();
});

test('the leave date cannot be before the start date', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['started_on' => '2024-01-08']);

    $component = Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editStartedOn', '2025-03-10')
        ->set('editLeftOn', '2025-03-09')
        ->call('save')
        ->assertHasErrors(['editLeftOn' => 'after_or_equal']);

    expect($component->errors()->first('editLeftOn'))->toBe("The leave date can't be before the start date.")
        ->and($person->fresh()->started_on?->toDateString())->toBe('2024-01-08')
        ->and($person->fresh()->left_on)->toBeNull();
});

test('a person can leave on the day they started', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editStartedOn', '2025-03-10')
        ->set('editLeftOn', '2025-03-10')
        ->call('save')
        ->assertHasNoErrors();

    expect($person->fresh()->left_on?->toDateString())->toBe('2025-03-10');
});

test('a leave date can be saved without a start date', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editLeftOn', '2026-06-30')
        ->call('save')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->started_on)->toBeNull()
        ->and($person->left_on?->toDateString())->toBe('2026-06-30');
});

test('the date fields reject values that are not dates', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editStartedOn', 'not a date')
        ->set('editLeftOn', 'also not a date')
        ->call('save')
        ->assertHasErrors(['editStartedOn' => 'date', 'editLeftOn' => 'date']);

    expect($person->fresh()->started_on)->toBeNull();
});

test('a validation error does not carry over to the next person opened', function () {
    $admin = User::factory()->admin()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $first->id)
        ->set('editStartedOn', '2025-03-10')
        ->set('editLeftOn', '2025-03-09')
        ->call('save')
        ->assertHasErrors(['editLeftOn'])
        ->call('edit', $second->id)
        ->assertHasNoErrors();
});

test('cancelling the panel clears its validation errors', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editStartedOn', '2025-03-10')
        ->set('editLeftOn', '2025-03-09')
        ->call('save')
        ->assertHasErrors(['editLeftOn'])
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);
});
