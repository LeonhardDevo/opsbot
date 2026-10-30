<?php

declare(strict_types=1);

namespace App\Ai;

use App\Internal\InternalClient;
use App\Legal\AiProviderDisclosure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

final readonly class AiClient
{
    public function __construct(private InternalClient $internal) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function chat(array $payload): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->timeout(90)->post('/v1/chat', $payload));
    }

    /** @return list<array<string, mixed>> */
    public function providers(?string $locale = null): array
    {
        $query = $locale !== null ? ['locale' => $locale] : [];

        /** @var list<array<string, mixed>> */
        return array_values($this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/providers', $query)));
    }

    /** @return array<string, mixed> */
    public function models(string $provider): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/providers/'.rawurlencode($provider).'/models'));
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/settings'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->put('/v1/admin/ai/settings', $settings));
    }

    /** @return array<string, mixed> */
    public function updateKey(string $provider, ?string $apiKey): array
    {
        $result = $this->send(fn (PendingRequest $request): Response => $request->put(
            '/v1/admin/ai/keys/'.rawurlencode($provider),
            ['api_key' => $apiKey],
        ));
        Cache::forget(AiProviderDisclosure::CACHE_KEY);

        return $result;
    }

    /** @return array<string, mixed> */
    public function test(): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->timeout(60)->post('/v1/admin/ai/test'));
    }

    /** @return array<string, mixed> */
    public function usage(int $days = 30): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/usage', ['days' => $days]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function search(array $payload): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->timeout(20)->post('/v1/search', $payload));
    }

    /** @return array{before: array<string, mixed>|null, chunk: array<string, mixed>|null, after: array<string, mixed>|null} */
    public function chunkContext(int $documentId, int $workspaceId, int $chunkId): array
    {
        $json = $this->send(fn (PendingRequest $request): Response => $request
            ->timeout(5)
            ->get('/v1/documents/'.$documentId.'/chunks/'.$chunkId, ['workspace_id' => $workspaceId, 'context' => 1]));

        $pick = fn (string $key): ?array => is_array($json[$key] ?? null) ? $json[$key] : null;

        return ['before' => $pick('before'), 'chunk' => $pick('chunk'), 'after' => $pick('after')];
    }

    /** @return list<array{chunk_id: int, page: int|null, section: string|null, content: string}> */
    public function documentChunks(int $documentId, int $workspaceId, int $limit = 5): array
    {
        $json = $this->send(fn (PendingRequest $request): Response => $request
            ->timeout(5)
            ->get('/v1/documents/'.$documentId.'/chunks', ['workspace_id' => $workspaceId, 'limit' => $limit]));

        $rows = array_is_list($json) ? $json : ($json['chunks'] ?? []);

        $chunks = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $section = $row['section'] ?? $row['section_path'] ?? null;

            $chunks[] = [
                'chunk_id' => (int) ($row['chunk_id'] ?? $row['id'] ?? 0),
                'page' => is_numeric($row['page'] ?? null) ? (int) $row['page'] : null,
                'section' => is_array($section) ? implode(' › ', array_map('strval', $section)) : (is_string($section) ? $section : null),
                'content' => (string) ($row['content'] ?? ''),
            ];
        }

        return $chunks;
    }

    /**
     * @param  \Closure(PendingRequest): Response  $call
     * @return array<array-key, mixed>
     */
    private function send(\Closure $call): array
    {
        try {
            $response = $call($this->internal->to('ai'));
        } catch (ConnectionException $exception) {
            throw AiServiceException::unreachable($exception);
        }

        if ($response->failed()) {
            throw AiServiceException::fromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
