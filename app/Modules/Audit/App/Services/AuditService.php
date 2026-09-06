<?php

namespace App\Modules\Audit\App\Services;

use App\Modules\Audit\App\Models\PanelLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single entry point for writing panel audit rows (C7).
 *
 * Called by module services after mutations – never by controllers
 * directly. The actor (Authenticated user + IP) is resolved from the
 * current request, which keeps callers free of plumbing.
 */
class AuditService
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function log(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
        ?Request $request = null,
    ): PanelLog {
        $request ??= request();
        $user = Auth::user();

        return PanelLog::query()->create([
            'actor_steamid' => $user?->steam_id !== null ? (int) $user->steam_id : null,
            'actor_name' => $user?->name,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details === [] ? null : $details,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}