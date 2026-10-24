<?php

use App\Livewire\Admin\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('role title is trimmed and tidied when saved', function (?string $given, ?string $stored) {
    $user = User::factory()->create(['role_title' => $given]);

    expect($user->fresh()->role_title)->toBe($stored);

    $user->update(['role_title' => $given]);

    expect($user->fresh()->role_title)->toBe($stored);
})->with([
    'trailing space' => ['WordPress Developer ', 'WordPress Developer'],
    'leading and trailing space' => ['  Project Manager  ', 'Project Manager'],
    'repeated internal space' => ['Senior  Business Analyst ', 'Senior Business Analyst'],
    'tab and newline' => ["Senior\t\nDeveloper", 'Senior Developer'],
    'non-breaking space' => ["Tester\u{00A0}", 'Tester'],
    'whitespace only' => ['   ', null],
    'empty string' => ['', null],
    'null' => [null, null],
    'already tidy' => ['Head of Delivery', 'Head of Delivery'],
]);

test('the admin edit panel saves a tidied role title', function () {
    $admin = User::factory()->admin()->create();
    $person = User::factory()->create(['role_title' => 'Developer']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $person->id)
        ->set('editRoleTitle', '  Senior  Developer ')
        ->call('save')
        ->assertHasNoErrors();

    expect($person->fresh()->role_title)->toBe('Senior Developer');
});

test('the migration tidies existing role titles that bypassed the mutator', function () {
    $messy = User::factory()->create();
    $doubleSpaced = User::factory()->create();
    $blank = User::factory()->create();
    $tidy = User::factory()->create(['role_title' => 'Tester']);
    $none = User::factory()->create();

    // Raw updates bypass the model, as the pre-existing rows did.
    DB::table('users')->where('id', $messy->id)->update(['role_title' => 'WordPress Developer ']);
    DB::table('users')->where('id', $doubleSpaced->id)->update(['role_title' => ' Senior  Business Analyst ']);
    DB::table('users')->where('id', $blank->id)->update(['role_title' => '   ']);
    DB::table('users')->where('id', $tidy->id)->update(['updated_at' => '2026-01-01 09:00:00']);

    (require database_path('migrations/2026_10_23_000010_trim_user_role_titles.php'))->up();

    expect(DB::table('users')->where('id', $messy->id)->value('role_title'))->toBe('WordPress Developer')
        ->and(DB::table('users')->where('id', $doubleSpaced->id)->value('role_title'))->toBe('Senior Business Analyst')
        ->and(DB::table('users')->where('id', $blank->id)->value('role_title'))->toBeNull()
        ->and(DB::table('users')->where('id', $tidy->id)->value('role_title'))->toBe('Tester')
        ->and(DB::table('users')->where('id', $none->id)->value('role_title'))->toBeNull()
        ->and(DB::table('users')->where('id', $tidy->id)->value('updated_at'))->toStartWith('2026-01-01 09:00:00');
});

test('the users admin page suggests existing role titles once each, sorted', function () {
    $admin = User::factory()->admin()->create(['role_title' => 'Senior Developer']);
    User::factory()->create(['role_title' => 'Tester']);
    User::factory()->create(['role_title' => 'Senior Developer']);
    User::factory()->create(['role_title' => 'Developer']);
    User::factory()->create(['role_title' => null]);

    $component = Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->assertSeeHtml('<input wire:model="editRoleTitle" type="text" list="role-titles"')
        ->assertSeeHtml('<datalist id="role-titles">')
        ->assertSeeHtml('<option value="Senior Developer">');

    preg_match('~<datalist id="role-titles">(.*?)</datalist>~s', $component->html(), $matches);
    preg_match_all('~<option value="([^"]*)">~', $matches[1], $options);

    expect($options[1])->toBe(['Developer', 'Senior Developer', 'Tester']);
});
