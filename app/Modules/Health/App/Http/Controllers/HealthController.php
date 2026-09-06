<?php

namespace App\Modules\Health\App\Http\Controllers;

use App\Modules\Health\App\Models\HealthCheck;
use App\Support\Api;
use Illuminate\Http\JsonResponse;

/**
 * Current health status (C16). Owner-only (routes carry owner.only).
 */
class HealthController
{
    public function status(): JsonResponse
    {
        $components = HealthCheck::query()
            ->select('component')
            ->distinct()
            ->orderBy('component')
            ->pluck('component');

        $checks = [];

        foreach ($components as $component) {
            $latest = HealthCheck::query()
                ->where('component', $component)
                ->latest('checked_at')
                ->first();

            if ($latest !== null) {
                $checks[] = [
                    'component' => $component,
                    'status' => $latest->status,
                    'message' => $latest->message,
                    'checked_at' => optional($latest->checked_at)->toISOString(),
                ];
            }
        }

        $healthy = array_reduce(
            $checks,
            static fn (bool $carry, array $check): bool => $carry && $check['status'] === 'ok',
            true,
        );

        return Api::success([
            'healthy' => $healthy,
            'checked_at' => now()->toISOString(),
            'checks' => $checks,
        ]);
    }
}