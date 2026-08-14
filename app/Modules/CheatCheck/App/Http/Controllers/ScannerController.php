<?php

namespace App\Modules\CheatCheck\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CheatCheck\App\Services\CheatCheckService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The two endpoints the scanner itself talks to (C18). Neither has a
 * session: the script is fetched with nothing but its token, and results
 * come back authenticated by the shared API key.
 */
class ScannerController extends Controller
{
    /**
     * Machine-readable failure reason -> HTTP status.
     */
    private const REASON_STATUS = [
        'invalid_token' => 404,
        'token_expired' => 410,
        'token_used' => 410,
        'already_resolved' => 409,
        'scanner_missing' => 500,
    ];

    public function __construct(private readonly CheatCheckService $scans)
    {
    }

    /**
     * GET /checkcheat.ps1/{token} – serves the ready-to-run scanner.
     *
     * Failures are returned as PowerShell comments rather than JSON: the
     * response is piped straight into Invoke-Expression, so anything that is
     * not valid PowerShell surfaces to the player as a parser error instead
     * of a message.
     */
    public function script(Request $request, string $token): Response
    {
        try {
            $script = $this->scans->serveScript($token, $this->baseUrl($request), $request->ip());
        } catch (RuntimeException $e) {
            return $this->refuse($e->getMessage());
        }

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * POST /api/cheat-check/results – the scanner's callback.
     */
    public function results(Request $request): JsonResponse
    {
        $expected = (string) config('cheat_check.api_key', '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-API-Key'))) {
            return Api::error('invalid_api_key', [], 401);
        }

        $validator = Validator::make($request->all(), [
            'token' => 'required|string|max:64',
            'status' => 'required|string|in:clean,suspicious,cheat,error',
            'finding_count' => 'required|integer|min:0',
            'scan_duration' => 'required|numeric|min:0',
            'findings' => 'nullable|array|max:5000',
            'findings.*' => 'string|max:1000',
            'computer_name' => 'nullable|string|max:128',
            'username' => 'nullable|string|max:128',
            'raw_log' => 'nullable|string|max:2000000',
            'risk_score' => 'nullable|integer|min:0',
            'high_count' => 'nullable|integer|min:0',
            'medium_count' => 'nullable|integer|min:0',
            'scan_coverage' => 'nullable|string|max:20',
            'partial' => 'nullable|boolean',
            'elevated' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        try {
            $scan = $this->scans->recordResults($validator->validated());
        } catch (RuntimeException $e) {
            return Api::error($e->getMessage(), [], self::REASON_STATUS[$e->getMessage()] ?? 400);
        }

        return Api::success(['id' => $scan->id, 'status' => $scan->status]);
    }

    private function refuse(string $reason): Response
    {
        $message = match ($reason) {
            'token_expired' => 'This link has expired. Ask your admin to start a new check.',
            'token_used' => 'This link has already been used. Ask your admin to start a new check.',
            'scanner_missing' => 'The scanner is not installed on the panel. Contact the panel owner.',
            default => 'This link is not valid. Ask your admin to start a new check.',
        };

        $body = "Write-Host '{$message}' -ForegroundColor Red\nStart-Sleep -Seconds 8\n";

        return response($body, self::REASON_STATUS[$reason] ?? 400, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function baseUrl(Request $request): string
    {
        return config('cheat_check.force_scheme', 'https').'://'.$request->getHost();
    }
}
