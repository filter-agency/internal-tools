<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes to JDW clients whether or not they are archived', function () {
    $jdw = Client::factory()->create(['task_billability_profile' => ClientTaskBillabilityProfile::Jdw]);
    $archived = Client::factory()->create(['task_billability_profile' => ClientTaskBillabilityProfile::Jdw, 'is_archived' => true]);
    Client::factory()->create(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);

    expect(Client::query()->jdw()->orderBy('id')->pluck('id')->all())->toBe([$jdw->id, $archived->id]);
});

it('scopes to clients that are not archived and use the JDW billability profile', function () {
    $jdw = Client::factory()->create(['task_billability_profile' => ClientTaskBillabilityProfile::Jdw]);
    Client::factory()->create(['task_billability_profile' => ClientTaskBillabilityProfile::Jdw, 'is_archived' => true]);
    Client::factory()->create(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);

    expect(Client::query()->activeJdw()->pluck('id')->all())->toBe([$jdw->id]);
});
