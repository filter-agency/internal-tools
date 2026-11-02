<?php

use App\Livewire\TellPaul;
use App\Mail\TellPaulSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config(['tell-paul.recipient_email' => 'paul@example.com']);
});

function fillSuggestion()
{
    return Livewire::test(TellPaul::class)
        ->set('title', 'Try a new framework')
        ->set('proposal', 'Pilot it on an internal site.')
        ->set('reason', 'Our current builds take too long.')
        ->set('benefits', 'Faster delivery for clients.')
        ->set('exploration', 'Built a prototype and compared build times.');
}

test('staff can find the form and guests must sign in', function () {
    $this->get(route('tell-paul'))->assertRedirect(route('auth.login'));
    $this->actingAs(User::factory()->create())
        ->get(route('tell-paul'))->assertOk()->assertSee('Idea Box')->assertSee('Submit idea');
});

test('submissions include the signed-in sender and are emailed to Paul', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    fillSuggestion()->call('submit')->assertHasNoErrors()->assertSet('title', '')
        ->assertSee('Your idea has been emailed to us');
    Mail::assertSent(TellPaulSuggestion::class, fn ($mail) => $mail->hasTo('paul@example.com') && $mail->submitterEmail === $user->email
        && $mail->submitterName === $user->name
        && $mail->envelope()->replyTo[0]->address === $user->email);
});

test('incomplete suggestions are rejected', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test(TellPaul::class)->call('submit')
        ->assertHasErrors(['title', 'proposal', 'reason', 'benefits', 'exploration']);
    Mail::assertNothingSent();
});

test('failed mail delivery preserves answers and does not report success', function () {
    $this->actingAs(User::factory()->create());
    Mail::shouldReceive('mailer')->once()->andThrow(new RuntimeException('Transport unavailable'));
    fillSuggestion()->call('submit')->assertHasErrors('submit')
        ->assertSet('title', 'Try a new framework')
        ->assertDontSee('Your idea has been emailed to us');
});

test('repeated submissions are limited without sending another email', function () {
    $this->actingAs(User::factory()->create());
    for ($i = 0; $i < 5; $i++) {
        fillSuggestion()->call('submit')->assertHasNoErrors();
    }
    fillSuggestion()->call('submit')->assertHasErrors('submit');
    Mail::assertSentCount(5);
});
