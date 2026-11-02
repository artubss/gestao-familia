<?php

namespace Modules\Spending;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Read-only Pluggy client. Auth and connect_token are the only writes, and both talk to Pluggy,
// never to a bank payment API. Transactions, accounts and items are GET.
class PluggyClient
{
    public function configured(): bool
    {
        return filled(config('services.pluggy.client_id')) && filled(config('services.pluggy.client_secret'));
    }

    public function sandbox(): bool
    {
        return (bool) config('services.pluggy.sandbox');
    }

    public function connectToken(string $clientUserId): string
    {
        $response = $this->authed()->post($this->url('/connect_token'), [
            'clientUserId' => $clientUserId,
        ])->throw();

        $token = $response->json('accessToken');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Pluggy connect token missing');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    public function item(string $itemId): array
    {
        return $this->authed()->get($this->url('/items/'.$itemId))->throw()->json() ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function accounts(string $itemId): array
    {
        return $this->authed()->get($this->url('/accounts'), ['itemId' => $itemId])->throw()->json('results') ?? [];
    }

    /**
     * Every transaction page for one account. Stops after a few pages so a click cannot walk years of history.
     *
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountId, string $from): array
    {
        $all = [];
        $page = 1;
        $totalPages = 1;
        while ($page <= $totalPages && $page <= 5) {
            $body = $this->authed()->get($this->url('/transactions'), [
                'accountId' => $accountId,
                'from' => $from,
                'page' => $page,
                'pageSize' => 100,
            ])->throw()->json();
            $all = array_merge($all, $body['results'] ?? []);
            $totalPages = (int) ($body['totalPages'] ?? 1);
            $page++;
        }

        return $all;
    }

    private function authed(): PendingRequest
    {
        return Http::withHeaders(['X-API-KEY' => $this->apiKey()])->acceptJson();
    }

    private function apiKey(): string
    {
        $response = Http::acceptJson()->post($this->url('/auth'), [
            'clientId' => config('services.pluggy.client_id'),
            'clientSecret' => config('services.pluggy.client_secret'),
        ])->throw();
        $key = $response->json('apiKey');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Pluggy api key missing');
        }

        return $key;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.pluggy.url'), '/').$path;
    }
}
