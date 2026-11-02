<?php

use Illuminate\Support\Facades\Http;
use Modules\Spending\BankConnection;
use Modules\Spending\FixedExpense;
use Modules\Spending\Spend;

use function Pest\Laravel\actingAs;

if (! function_exists('expenseFamily')) {
    function expenseFamily(): array
    {
        $users = \App\Models\User::factory()->count(2)->create();
        $family = \App\Models\Family::factory()->create(['owner_id' => $users[0]->id]);
        foreach ($users as $i => $u) {
            $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
        }
        session(['active_family_id' => $family->id]);
        test()->artisan('modules:migrate')->assertExitCode(0);
        app(\App\Support\Modules\ModuleRegistry::class)->enable('spending', $family->id);

        return [$family, $users];
    }
}

it('stores a fixed bill and posts it once per month onto Others', function () {
    [, $users] = expenseFamily();

    actingAs($users[0])->post('/m/spending/household/fixed', [
        'name' => 'Aluguel',
        'amount' => 1500,
        'currency' => 'EUR',
        'day_of_month' => 5,
        'payment_method' => 'pix',
        'payer_id' => $users[1]->id,
    ])->assertRedirect();

    $fixed = FixedExpense::first();
    expect($fixed->amount)->toBe(150000)->and($fixed->payer_id)->toBe($users[1]->id);

    actingAs($users[0])->post("/m/spending/household/fixed/{$fixed->id}/post")->assertRedirect();
    actingAs($users[0])->post("/m/spending/household/fixed/{$fixed->id}/post")->assertSessionHasErrors('fixed');

    expect(Spend::where('fixed_expense_id', $fixed->id)->count())->toBe(1)
        ->and(Spend::first()->payment_method)->toBe('pix')
        ->and(Spend::first()->payer_id)->toBe($users[1]->id);
});

it('records a manual pix expense', function () {
    [, $users] = expenseFamily();

    actingAs($users[0])->post('/m/spending/household/manual', [
        'name' => 'Padaria',
        'amount' => 18.5,
        'currency' => 'EUR',
        'payment_method' => 'pix',
        'payer_id' => $users[0]->id,
        'spent_at' => '2026-11-02',
    ])->assertRedirect();

    expect(Spend::first()->payment_method)->toBe('pix')->and(Spend::first()->amount)->toBe(1850);
});

it('keeps several read-only connectors, each tagged with a member, and imports only debits', function () {
    config([
        'services.pluggy.client_id' => 'id',
        'services.pluggy.client_secret' => 'secret',
        'services.pluggy.url' => 'https://api.pluggy.ai',
    ]);
    [, $users] = expenseFamily();
    $itemA = '11111111-1111-1111-1111-111111111111';
    $itemB = '22222222-2222-2222-2222-222222222222';
    BankConnection::create([
        'user_id' => $users[0]->id, 'label' => 'Nubank da Ana', 'provider' => 'pluggy', 'item_id' => $itemA,
        'connector_name' => 'Nubank', 'created_by' => $users[0]->id,
    ]);
    BankConnection::create([
        'user_id' => $users[1]->id, 'label' => 'Itau do Bruno', 'provider' => 'pluggy', 'item_id' => $itemB,
        'connector_name' => 'Itau', 'created_by' => $users[0]->id,
    ]);

    Http::fake([
        'https://api.pluggy.ai/auth' => Http::response(['apiKey' => 'key']),
        'https://api.pluggy.ai/accounts*' => Http::response(['results' => [['id' => 'acc-1']]]),
        'https://api.pluggy.ai/transactions*' => Http::response([
            'totalPages' => 1,
            'results' => [
                ['id' => 'tx-pix', 'description' => 'PIX padaria', 'amount' => 12.5, 'date' => '2026-11-01', 'type' => 'DEBIT', 'currencyCode' => 'EUR', 'paymentData' => ['method' => 'PIX']],
                ['id' => 'tx-in', 'description' => 'Salario', 'amount' => 100, 'date' => '2026-11-01', 'type' => 'CREDIT', 'currencyCode' => 'EUR'],
            ],
        ]),
        'https://api.pluggy.ai/items/*' => Http::response(['status' => 'UPDATED', 'connector' => ['name' => 'Nubank']]),
    ]);

    $nubank = BankConnection::where('item_id', $itemA)->first();
    actingAs($users[0])->post("/m/spending/household/banks/{$nubank->id}/sync")->assertRedirect();
    actingAs($users[0])->post("/m/spending/household/banks/{$nubank->id}/sync")->assertRedirect();

    expect(Spend::count())->toBe(1)
        ->and(Spend::first()->payment_method)->toBe('pix')
        ->and(Spend::first()->payer_id)->toBe($users[0]->id)
        ->and(Spend::first()->external_id)->toBe('tx-pix');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/payments'));
    actingAs($users[0])->get('/m/spending/household')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.ownerId', $users[1]->id)
            ->where('connections.1.ownerId', $users[0]->id));
});
