<?php

namespace App\Modules\CheatCheck\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single cheat check run (C18).
 *
 * status: pending | clean | suspicious | cheat | error
 */
class CheatScan extends Model
{
    protected $table = 'cheat_scans';

    protected $fillable = [
        'player_name',
        'steam_link',
        'discord_id',
        'status',
        'finding_count',
        'risk_score',
        'high_count',
        'medium_count',
        'scan_duration',
        'scan_coverage',
        'is_partial',
        'was_elevated',
        'findings',
        'computer_name',
        'scan_username',
        'raw_log',
        'admin_steamid',
        'admin_name',
        'ip_address',
    ];

    protected $hidden = ['raw_log'];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'scan_duration' => 'float',
            'finding_count' => 'integer',
            'risk_score' => 'integer',
            'high_count' => 'integer',
            'medium_count' => 'integer',
            'admin_steamid' => 'integer',
            'is_partial' => 'boolean',
            'was_elevated' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CheatScanToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(CheatScanToken::class, 'scan_id');
    }

    /**
     * Findings arrive as single technical lines, e.g.
     * "[HIGH] UnsignedSuspiciousFile: C:\... [SHA256: ...]". Split them into
     * risk/category/detail/hash and sort by severity so the panel can render
     * a readable table without re-parsing on the client.
     *
     * @return array<int, array{risk: string, category: string, detail: string, hash: string|null}>
     */
    public function getParsedFindingsAttribute(): array
    {
        if (! is_array($this->findings) || $this->findings === []) {
            return [];
        }

        $riskOrder = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2, 'INFO' => 3];
        $parsed = [];

        foreach ($this->findings as $raw) {
            $risk = 'INFO';
            $rest = (string) $raw;

            if (preg_match('/^\[(HIGH|MEDIUM|LOW)\]\s*(.+)$/su', $rest, $m)) {
                $risk = $m[1];
                $rest = $m[2];
            }

            $hash = null;
            if (preg_match('/\[SHA256:\s*([a-f0-9]{64})\]\s*$/i', $rest, $hm)) {
                $hash = $hm[1];
                $rest = trim(substr($rest, 0, -strlen($hm[0])));
            }

            $category = 'Finding';
            $detail = $rest;
            if (preg_match('/^([A-Za-z][A-Za-z0-9]{2,40}):\s*(.+)$/su', $rest, $cm)) {
                $category = $cm[1];
                $detail = $cm[2];
            }

            $parsed[] = [
                'risk' => $risk,
                'category' => trim((string) preg_replace('/(?<!^)(?=[A-Z])/', ' ', $category)),
                'detail' => $detail,
                'hash' => $hash,
            ];
        }

        usort($parsed, fn (array $a, array $b): int => ($riskOrder[$a['risk']] ?? 3) <=> ($riskOrder[$b['risk']] ?? 3));

        return $parsed;
    }
}
