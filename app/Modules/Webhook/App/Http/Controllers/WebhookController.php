<?php

namespace App\Modules\Webhook\App\Http\Controllers;

use App\Modules\Webhook\App\Models\Webhook;
use App\Modules\Webhook\App\Services\WebhookService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Discord webhook management (C17). Owner-only (routes carry owner.only):
 * webhook URLs embed a Discord token, so the API never returns them.
 *
 * GET    /api/webhooks          – list (masked URL hints)
 * POST   /api/webhooks          – create
 * PUT    /api/webhooks/{id}     – update
 * DELETE /api/webhooks/{id}     – delete
 * POST   /api/webhooks/{id}/test – send a test embed (synchronous)
 */
class WebhookController
{
    public function __construct(private readonly WebhookService $webhooks)
    {
    }

    public function index(): JsonResponse
    {
        $webhooks = Webhook::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Webhook $webhook): array => $this->present($webhook))
            ->values()
            ->all();

        return Api::success($webhooks, ['events' => $this->eventOptions()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        $webhook = $this->webhooks->save(null, [
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => $data['events'],
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);

        return Api::success($this->present($webhook));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::query()->find($id);

        if ($webhook === null) {
            return Api::notFound();
        }

        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $data = $validator->validated();

        $this->webhooks->save($webhook, [
            'name' => $data['name'] ?? $webhook->name,
            'url' => $data['url'] ?? $webhook->url,
            'events' => $data['events'] ?? $webhook->events,
            'enabled' => array_key_exists('enabled', $data)
                ? (bool) $data['enabled']
                : $webhook->enabled,
        ]);

        return Api::success($this->present($webhook->refresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        $webhook = Webhook::query()->find($id);

        if ($webhook === null) {
            return Api::notFound();
        }

        $webhook->delete();

        return Api::success(['id' => $id]);
    }

    public function test(int $id): JsonResponse
    {
        $webhook = Webhook::query()->find($id);

        if ($webhook === null) {
            return Api::notFound();
        }

        $result = $this->webhooks->test($webhook);

        if (! $result['ok']) {
            return Api::error('webhook_delivery_failed', [
                'webhook' => $result,
            ], 502);
        }

        return Api::success($result);
    }

    /**
     * @return array<string, string|string[]>
     */
    private function rules(bool $requireUrl = true): array
    {
        // The regex rule must live in array form: it contains '|'
        // (alternation), which the pipe-delimited string form would split on.
        $url = [$requireUrl ? 'required' : 'sometimes', 'string', 'max:500',
            'regex:~^https://(canary|ptb\.)?discord(app)?\.com/api/webhooks/\d+/[A-Za-z0-9_-]+$~'];

        return [
            'name' => 'sometimes|required|string|max:191',
            'url' => $url,
            'events' => 'sometimes|required|array|min:1',
            'events.*' => 'required|string|in:'.implode(',', $this->eventOptions()),
            'enabled' => 'sometimes|required|boolean',
        ];
    }

    /**
     * @return list<string>
     */
    private function eventOptions(): array
    {
        return array_keys((array) config('webhook.events', []));
    }

    /**
     * URL is never revealed – only a masked hint of the tail.
     *
     * @return array{id: int, name: string, url_hint: string, events: array<int, string>, enabled: bool}
     */
    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url_hint' => '•••'.substr((string) $webhook->url, -12),
            'events' => $webhook->events ?? [],
            'enabled' => (bool) $webhook->enabled,
        ];
    }
}