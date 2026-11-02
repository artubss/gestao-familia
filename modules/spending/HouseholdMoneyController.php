<?php

namespace Modules\Spending;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Household money that the expense lists don't model: fixed bills, a manual PIX (or cash) entry,
// and several read-only bank connectors tagged with who they belong to.
class HouseholdMoneyController
{
    public function __construct(private PluggyClient $pluggy) {}

    public function show(Request $request): Response
    {
        $members = $this->members();
        $posted = Spend::query()
            ->whereNotNull('fixed_expense_id')
            ->whereBetween('spent_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->pluck('fixed_expense_id');

        return Inertia::render('spending/household', [
            'members' => $members->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'currencies' => Currencies::ALL,
            'defaultCurrency' => SpendSetting::defaultCurrency() ?? Currencies::FALLBACK,
            'methods' => FixedExpense::METHODS,
            'pluggyConfigured' => $this->pluggy->configured(),
            'pluggySandbox' => $this->pluggy->sandbox(),
            'fixed' => FixedExpense::query()->with('payer:id,name')->orderBy('name')->get()->map(fn (FixedExpense $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'amount' => $row->amount,
                'currency' => $row->currency,
                'day' => $row->day_of_month,
                'method' => $row->payment_method,
                'payer' => $row->payer?->name,
                'payerId' => $row->payer_id,
                'active' => $row->active,
                'posted' => $posted->contains($row->id),
            ])->values(),
            'connections' => BankConnection::query()->with('owner:id,name')->latest('id')->get()->map(fn (BankConnection $row) => [
                'id' => $row->id,
                'label' => $row->label,
                'connector' => $row->connector_name,
                'owner' => $row->owner?->name,
                'ownerId' => $row->user_id,
                'status' => $row->status,
                'lastSynced' => $row->last_synced_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    public function storeFixed(Request $request): RedirectResponse
    {
        $data = $this->validateBill($request);
        FixedExpense::create([
            ...$this->billColumns($data),
            'created_by' => $request->user()->id,
        ]);

        return back();
    }

    public function destroyFixed(int $fixed): RedirectResponse
    {
        FixedExpense::findOrFail($fixed)->delete();

        return back();
    }

    public function postFixed(Request $request, int $fixed): RedirectResponse
    {
        $row = FixedExpense::findOrFail($fixed);
        abort_unless($row->active, 422);
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        if (Spend::where('fixed_expense_id', $row->id)->whereBetween('spent_at', [$start, $end])->exists()) {
            return back()->withErrors(['fixed' => Translations::get('spending.fixed_posted')]);
        }

        $day = min($row->day_of_month, now()->daysInMonth);
        $this->record($request->user(), [
            'amount' => $row->amount / 100,
            'currency' => $row->currency,
            'category' => $row->category,
            'payer_key' => 'u'.$row->payer_id,
            'note' => $row->name,
            'store' => $row->name,
            'spent_at' => now()->setDay($day)->toDateString(),
        ], $row->payment_method, fixedId: $row->id);

        return back();
    }

    public function storeManual(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'in:'.implode(',', Currencies::ALL)],
            'payment_method' => ['required', Rule::in(FixedExpense::METHODS)],
            'payer_id' => ['required', 'integer', Rule::in($this->members()->pluck('id')->all())],
            'spent_at' => ['required', 'date'],
        ]);
        $this->record($request->user(), [
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'category' => 'other',
            'payer_key' => 'u'.$data['payer_id'],
            'note' => $data['name'],
            'store' => $data['name'],
            'spent_at' => Carbon::parse($data['spent_at'])->toDateString(),
        ], $data['payment_method']);

        return back();
    }

    public function connectToken(Request $request): JsonResponse
    {
        abort_unless($this->pluggy->configured(), 422);
        $request->validate([
            'user_id' => ['required', 'integer', Rule::in($this->members()->pluck('id')->all())],
        ]);
        $token = $this->pluggy->connectToken('family-'.CurrentFamily::id().'-user-'.$request->integer('user_id'));

        return response()->json(['token' => $token, 'accessToken' => $token, 'sandbox' => $this->pluggy->sandbox()]);
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        abort_unless($this->pluggy->configured(), 422);
        $data = $request->validate([
            'item_id' => ['required', 'uuid', 'unique:spending_bank_connections,item_id'],
            'user_id' => ['required', 'integer', Rule::in($this->members()->pluck('id')->all())],
            'label' => ['required', 'string', 'max:80'],
        ]);
        $item = $this->pluggy->item($data['item_id']);
        BankConnection::create([
            'user_id' => $data['user_id'],
            'label' => $data['label'],
            'provider' => 'pluggy',
            'item_id' => $data['item_id'],
            'connector_name' => $item['connector']['name'] ?? null,
            'status' => $item['status'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back();
    }

    public function destroyConnection(int $connection): RedirectResponse
    {
        BankConnection::findOrFail($connection)->delete();

        return back();
    }

    public function sync(Request $request, int $connection): RedirectResponse
    {
        $row = BankConnection::findOrFail($connection);
        abort_unless($this->pluggy->configured(), 422);
        $from = ($row->last_synced_at ?? now()->subDays(90))->toDateString();
        $imported = 0;
        foreach ($this->pluggy->accounts($row->item_id) as $account) {
            if (empty($account['id'])) {
                continue;
            }
            foreach ($this->pluggy->transactions((string) $account['id'], $from) as $tx) {
                if ($this->importTransaction($request->user(), $row, $tx)) {
                    $imported++;
                }
            }
        }
        $item = $this->pluggy->item($row->item_id);
        $row->update([
            'status' => $item['status'] ?? $row->status,
            'connector_name' => $item['connector']['name'] ?? $row->connector_name,
            'last_synced_at' => now(),
        ]);

        return back()->with('imported', $imported);
    }

    /** @param  array<string, mixed>  $data */
    private function record(User $actor, array $data, string $method, ?int $fixedId = null, ?int $connectionId = null, ?string $externalId = null): Spend
    {
        $event = SpendEvent::others($actor);
        $spend = $event->spends()->create([
            ...$event->spendColumns($data),
            'created_by' => $actor->id,
            'payment_method' => $method,
            'fixed_expense_id' => $fixedId,
            'bank_connection_id' => $connectionId,
            'external_id' => $externalId,
        ]);

        return $spend;
    }

    /** @param  array<string, mixed>  $tx */
    private function importTransaction(User $actor, BankConnection $connection, array $tx): bool
    {
        $type = strtoupper((string) ($tx['type'] ?? ''));
        if ($type !== 'DEBIT') {
            return false;
        }
        $id = (string) ($tx['id'] ?? '');
        $currency = strtoupper((string) ($tx['currencyCode'] ?? ''));
        if ($id === '' || ! in_array($currency, Currencies::ALL, true) || Spend::where('external_id', $id)->exists()) {
            return false;
        }
        $amount = (float) ($tx['amount'] ?? 0);
        if ($amount <= 0) {
            return false;
        }
        $this->record($actor, [
            'amount' => $amount,
            'currency' => $currency,
            'category' => 'other',
            'payer_key' => 'u'.$connection->user_id,
            'note' => mb_substr((string) ($tx['description'] ?? $connection->label), 0, 255),
            'store' => mb_substr((string) ($tx['description'] ?? ''), 0, 120) ?: null,
            'spent_at' => Carbon::parse($tx['date'] ?? now())->toDateString(),
        ], $this->method($tx), connectionId: $connection->id, externalId: $id);

        return true;
    }

    /** @param  array<string, mixed>  $tx */
    private function method(array $tx): string
    {
        $blob = strtolower(($tx['description'] ?? '').' '.json_encode($tx['paymentData'] ?? []));

        return str_contains($blob, 'pix') ? 'pix' : (str_contains($blob, 'boleto') ? 'boleto' : 'card');
    }

    /** @return array<string, mixed> */
    private function validateBill(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'in:'.implode(',', Currencies::ALL)],
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'payment_method' => ['required', Rule::in(FixedExpense::METHODS)],
            'payer_id' => ['required', 'integer', Rule::in($this->members()->pluck('id')->all())],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function billColumns(array $data): array
    {
        return [
            'name' => $data['name'],
            'amount' => (int) round($data['amount'] * 100),
            'currency' => $data['currency'],
            'category' => 'other',
            'payer_id' => $data['payer_id'],
            'day_of_month' => $data['day_of_month'],
            'payment_method' => $data['payment_method'],
            'active' => true,
        ];
    }

    private function members()
    {
        return Family::findOrFail(CurrentFamily::id())->members()->orderBy('users.name')->get(['users.id', 'name']);
    }
}
