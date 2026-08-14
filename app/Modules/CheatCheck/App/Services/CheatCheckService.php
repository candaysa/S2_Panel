<?php

namespace App\Modules\CheatCheck\App\Services;

use App\Models\User;
use App\Modules\Audit\App\Services\AuditService;
use App\Modules\CheatCheck\App\Models\CheatScan;
use App\Modules\CheatCheck\App\Models\CheatScanToken;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cheat check flows (C18).
 *
 * An admin opens a scan for a player, which mints a token; the player runs
 * the scanner, which fetches its own source through that token and posts the
 * findings back. Everything here is panel-owned data.
 */
class CheatCheckService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLEAN = 'clean';

    public const STATUS_SUSPICIOUS = 'suspicious';

    public const STATUS_CHEAT = 'cheat';

    public const STATUS_ERROR = 'error';

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Open a scan and mint its download token.
     *
     * @param  array<string, mixed>  $data
     * @return array{scan: CheatScan, token: string, run_url: string, command: string}
     */
    public function open(User $admin, array $data, string $baseUrl, ?string $ip = null): array
    {
        $this->guardRateLimit($admin);

        $scan = CheatScan::query()->create([
            'player_name' => $data['player_name'],
            'steam_link' => $data['steam_link'],
            'discord_id' => $data['discord_id'] ?? null,
            'status' => self::STATUS_PENDING,
            'admin_steamid' => (int) $admin->steam_id,
            'admin_name' => $admin->name,
            'ip_address' => $ip,
        ]);

        $token = Str::random(64);

        CheatScanToken::query()->create([
            'token' => $token,
            'scan_id' => $scan->id,
            'admin_steamid' => (int) $admin->steam_id,
            'admin_name' => $admin->name,
            'expires_at' => now()->addMinutes((int) config('cheat_check.token_ttl_minutes', 30)),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);

        $this->audit->log('cheat_check.opened', 'cheat_scan', (string) $scan->id, [
            'player_name' => $scan->player_name,
            'steam_link' => $scan->steam_link,
        ]);

        $runUrl = rtrim($baseUrl, '/').'/checkcheat.ps1/'.$token;

        return [
            'scan' => $scan,
            'token' => $token,
            'run_url' => $runUrl,
            'command' => "irm '{$runUrl}' | iex",
        ];
    }

    /**
     * Consume one download of a token and return the ready-to-run script.
     *
     * @throws RuntimeException with a machine-readable reason
     */
    public function serveScript(string $tokenValue, string $baseUrl, ?string $ip = null): string
    {
        $token = CheatScanToken::query()->where('token', $tokenValue)->first();

        if ($token === null || $token->scan === null || $token->scan->status !== self::STATUS_PENDING) {
            throw new RuntimeException('invalid_token');
        }

        if ($token->expires_at->isPast()) {
            throw new RuntimeException('token_expired');
        }

        // lockForUpdate closes the TOCTOU window between the count check and
        // the increment when the elevation retry races the original fetch.
        $accepted = DB::transaction(function () use ($token, $ip): bool {
            $locked = CheatScanToken::query()->lockForUpdate()->find($token->id);

            if ($locked->download_count >= CheatScanToken::MAX_DOWNLOADS) {
                return false;
            }

            $locked->update([
                'consumed_at' => $locked->consumed_at ?? now(),
                'download_count' => $locked->download_count + 1,
                'download_ip' => $ip,
            ]);

            return true;
        });

        if (! $accepted) {
            throw new RuntimeException('token_used');
        }

        $this->audit->log('cheat_check.downloaded', 'cheat_scan', (string) $token->scan_id, [
            'download_ip' => $ip,
        ]);

        return $this->buildScript($tokenValue, $baseUrl);
    }

    /**
     * Record the scanner's results against its scan.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException with a machine-readable reason
     */
    public function recordResults(array $payload): CheatScan
    {
        $token = CheatScanToken::query()->where('token', $payload['token'])->first();

        if ($token === null || $token->scan === null) {
            throw new RuntimeException('invalid_token');
        }

        // A scan that already has a verdict must not be overwritten by the
        // scanner's own retry, so the status check runs under the same lock.
        $result = DB::transaction(function () use ($token, $payload): ?CheatScan {
            $scan = CheatScan::query()->lockForUpdate()->find($token->scan_id);

            if ($scan->status !== self::STATUS_PENDING) {
                return null;
            }

            $scan->update([
                'status' => $payload['status'],
                'finding_count' => $payload['finding_count'],
                'scan_duration' => $payload['scan_duration'],
                'findings' => $payload['findings'] ?? null,
                'computer_name' => $payload['computer_name'] ?? null,
                'scan_username' => $payload['username'] ?? null,
                'raw_log' => $payload['raw_log'] ?? null,
                'risk_score' => $payload['risk_score'] ?? 0,
                'high_count' => $payload['high_count'] ?? 0,
                'medium_count' => $payload['medium_count'] ?? 0,
                'scan_coverage' => $payload['scan_coverage'] ?? null,
                'is_partial' => $payload['partial'] ?? false,
                'was_elevated' => $payload['elevated'] ?? false,
            ]);

            return $scan;
        });

        if ($result === null) {
            throw new RuntimeException('already_resolved');
        }

        $this->audit->log('cheat_check.completed', 'cheat_scan', (string) $result->id, [
            'status' => $result->status,
            'finding_count' => $result->finding_count,
            'computer_name' => $result->computer_name,
        ]);

        return $result;
    }

    public function destroy(CheatScan $scan): bool
    {
        $id = $scan->id;

        $deleted = (bool) $scan->delete();

        if ($deleted) {
            $this->audit->log('cheat_check.deleted', 'cheat_scan', (string) $id);
        }

        return $deleted;
    }

    public function paginate(?string $status = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        return CheatScan::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== null && $search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('player_name', 'like', "%{$search}%")
                        ->orWhere('discord_id', 'like', "%{$search}%")
                        ->orWhere('admin_name', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Read the scanner source, fill in its placeholders and prepend the
     * elevation bootstrap.
     */
    private function buildScript(string $token, string $baseUrl): string
    {
        $path = __DIR__.'/../../Resources/scanner/check_cheat.ps1';

        if (! is_file($path)) {
            throw new RuntimeException('scanner_missing');
        }

        $script = (string) file_get_contents($path);

        // A UTF-8 BOM makes PowerShell read the first statement as a command
        // name when the script arrives through "irm | iex", so strip it even
        // though the file on disk is not supposed to carry one.
        if (str_starts_with($script, "\xEF\xBB\xBF")) {
            $script = substr($script, 3);
        }

        $baseUrl = rtrim($baseUrl, '/');
        $selfUrl = $baseUrl.'/checkcheat.ps1/'.$token;

        $script = str_replace(
            ['%%SCAN_TOKEN%%', '%%PANEL_URL%%', '%%API_KEY%%'],
            [$token, $baseUrl, (string) config('cheat_check.api_key', '')],
            $script,
        );

        return $this->elevationBootstrap($selfUrl).$script;
    }

    /**
     * Prepended to the scanner so the player only ever copies one plain
     * command. If the session is not elevated it re-runs itself through UAC
     * (re-fetching the same URL – hence the second allowed download) and
     * exits; a refused prompt falls through to the scanner's own limited
     * mode instead of failing outright.
     */
    private function elevationBootstrap(string $selfUrl): string
    {
        $linkError = 'This link is invalid, expired or already used. Ask your admin to start a new check.';
        $uacError = '  [!] Administrator rights unavailable (UAC declined?), continuing in limited mode...';

        return <<<PS1
        \$__ccIsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
        if (-not \$__ccIsAdmin) {
            try {
                Start-Process powershell -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-Command',"try { irm '{$selfUrl}' -UseBasicParsing | iex } catch { Write-Host '{$linkError}' -ForegroundColor Red; Start-Sleep -Seconds 8 }") -Verb RunAs -Wait -ErrorAction Stop
                exit
            } catch {
                Write-Host "{$uacError}" -ForegroundColor Yellow
                Start-Sleep -Seconds 2
            }
        }

        PS1;
    }

    private function guardRateLimit(User $admin): void
    {
        $limit = (int) config('cheat_check.rate_limit_per_hour', 10);

        if ($limit <= 0) {
            return;
        }

        $recent = CheatScanToken::query()
            ->where('admin_steamid', (int) $admin->steam_id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent >= $limit) {
            throw new InvalidArgumentException('rate_limit_exceeded');
        }
    }
}
