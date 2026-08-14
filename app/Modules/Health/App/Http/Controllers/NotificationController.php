<?php

namespace App\Modules\Health\App\Http\Controllers;

use App\Models\User;
use App\Modules\Health\App\Models\PanelNotification;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel owner notification center (C16). Notifications are created by the
 * health monitor (and later modules) for the owner only; endpoints carry
 * steam.auth + owner.only, so every row is scoped to the acting owner.
 */
class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = PanelNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (PanelNotification $n): array => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'read' => $n->read_at !== null,
                'created_at' => optional($n->created_at)->toISOString(),
            ])
            ->values()
            ->all();

        return Api::success($notifications);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = PanelNotification::query()
            ->where('user_id', $user->id)
            ->find($id);

        if ($notification === null) {
            return Api::notFound();
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return Api::success(['id' => $notification->id, 'read' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = PanelNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return Api::success(['updated' => $updated]);
    }
}