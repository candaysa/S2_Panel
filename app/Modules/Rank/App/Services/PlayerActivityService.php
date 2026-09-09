<?php

namespace App\Modules\Rank\App\Services;

use App\Modules\Appeal\App\Models\Appeal;
use App\Modules\Audit\App\Models\PanelLog;
use App\Modules\Ban\App\Models\AdminBan;
use App\Modules\Ban\App\Models\AdminGag;
use App\Modules\Ban\App\Models\AdminMute;
use App\Modules\Ban\App\Models\AdminWarn;
use App\Modules\Report\App\Models\Report;
use App\Modules\Vip\App\Services\VipService;
use App\Support\SteamId;
use InvalidArgumentException;

/**
 * Cross-module read for the player profile page (/players/{steam}):
 * punishment counts, VIP standing + history, staff notes, and a merged
 * reports/appeals timeline. Every source table here is already owned and
 * mutated by its own module (Ban/Vip/Report/Appeal/Audit/PlayerNoteService)
 * - this only reads across them into one response, so the profile page's
 * moderation/community section costs one extra request instead of five.
 */
class PlayerActivityService
{
    /** @var array<string, class-string<AdminBan|AdminMute|AdminGag|AdminWarn>> */
    private const PUNISHMENT_MODELS = [
        'ban' => AdminBan::class,
        'mute' => AdminMute::class,
        'gag' => AdminGag::class,
        'warn' => AdminWarn::class,
    ];

    /** Both the VIP history and the timeline are a snapshot, not a full
     *  audit browser (that already exists at /audit) - capped so one very
     *  active player's profile page cannot pull an unbounded history. */
    private const FEED_LIMIT = 20;

    public function __construct(
        private readonly VipService $vip,
        private readonly PlayerNoteService $notes,
    ) {
    }

    /**
     * @return array{
     *     punishments: array<string, array{active: int, total: int}>,
     *     vip: array{active: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>},
     *     timeline: array<int, array<string, mixed>>,
     *     notes: array<int, array<string, mixed>>,
     * }
     */
    public function forSteamId(string $steamId): array
    {
        if (! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('invalid_steamid');
        }

        $id = SteamId::parse($steamId);
        $steamId64 = (int) $id->steamId64();

        return [
            'punishments' => $this->punishmentSummary($steamId64),
            'vip' => $this->vipSummary($steamId, $id->accountId()),
            'timeline' => $this->timeline($steamId64),
            'notes' => $this->notes->listFor($steamId)->toArray(),
        ];
    }

    /**
     * @return array<string, array{active: int, total: int}>
     */
    private function punishmentSummary(int $steamId64): array
    {
        $summary = [];

        foreach (self::PUNISHMENT_MODELS as $type => $model) {
            $summary[$type] = [
                'active' => $model::query()->where('steamid', $steamId64)->active()->count(),
                'total' => $model::query()->where('steamid', $steamId64)->count(),
            ];
        }

        return $summary;
    }

    /**
     * @return array{active: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
     */
    private function vipSummary(string $steamId, int $accountId): array
    {
        $active = $this->vip->groupsFor($steamId)
            ->map(fn ($row): array => [
                'server_id' => $row->sid,
                'group' => $row->group,
                'expires' => $row->expires,
            ])
            ->all();

        // VipService only ever grants/revokes (see its class docblock) -
        // both call AuditService::log('vip.granted'|'vip.revoked',
        // 'vip_user', $accountId, ...), so this target_type/target_id pair
        // is the complete grant/revoke trail for this player, not a guess
        // at what might be in there.
        $history = PanelLog::query()
            ->where('target_type', 'vip_user')
            ->where('target_id', (string) $accountId)
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get(['action', 'details', 'actor_name', 'created_at'])
            ->map(fn (PanelLog $log): array => [
                'action' => $log->action,
                'details' => $log->details,
                'actor_name' => $log->actor_name,
                'created_at' => $log->created_at,
            ])
            ->all();

        return ['active' => $active, 'history' => $history];
    }

    /**
     * Reports (as reporter or target) and appeals, merged and sorted
     * newest-first - one feed instead of three separate lists the owner
     * would otherwise have to check one at a time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(int $steamId64): array
    {
        $reports = Report::query()
            ->where('reporter_steamid', $steamId64)
            ->orWhere('target_steamid', $steamId64)
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (Report $r): array => [
                'type' => 'report',
                'id' => $r->id,
                'ticket_type' => $r->ticket_type,
                'status' => $r->status,
                'resolution' => $r->resolution,
                'role' => (string) $r->reporter_steamid === (string) $steamId64 ? 'reporter' : 'target',
                'reason' => $r->report_reason,
                'created_at' => $r->created_at,
            ]);

        $appeals = Appeal::query()
            ->where('steamid', $steamId64)
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (Appeal $a): array => [
                'type' => 'appeal',
                'id' => $a->id,
                'status' => $a->status,
                'reason' => $a->reason,
                'decision_note' => $a->decision_note,
                'created_at' => $a->created_at,
            ]);

        return $reports->concat($appeals)
            ->sortByDesc('created_at')
            ->take(self::FEED_LIMIT)
            ->values()
            ->all();
    }
}
