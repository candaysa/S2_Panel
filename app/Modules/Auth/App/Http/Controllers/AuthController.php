<?php

namespace App\Modules\Auth\App\Http\Controllers;

use App\Modules\Auth\Events\UserRegistered;
use App\Support\Api;
use App\Support\SteamId;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController
{
    /**
     * GET /api/auth/redirect
     *
     * A plain link click (Blade "Steam ile giriş yap" button) sends the
     * browser straight to Steam. A JSON/fetch caller gets the target URL
     * back instead, so a JS client can open it itself (e.g. a popup).
     */
    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        // Remember where the visitor was so the callback can put them back
        // there. Public pages link straight here rather than via /login, so
        // without this every login would dump the user on the dashboard
        // regardless of what they were reading.
        //
        // Only same-origin paths are stored: "return" arrives from the query
        // string, and echoing an arbitrary absolute URL back into a redirect
        // is an open redirect.
        $return = (string) $request->query('return', '');

        if ($return !== '') {
            $path = parse_url($return, PHP_URL_PATH) ?: '/';
            $host = parse_url($return, PHP_URL_HOST);

            if ($host === null || $host === $request->getHost()) {
                $query = parse_url($return, PHP_URL_QUERY);
                $request->session()->put('url.intended', $path.($query ? '?'.$query : ''));
            }
        }

        $target = Socialite::driver('steam')->redirect();

        if ($request->expectsJson()) {
            return Api::success(['url' => $target->getTargetUrl()]);
        }

        return $target;
    }

    /**
     * GET|POST /api/auth/callback
     *
     * Steam redirects the browser here after OpenID. First login creates
     * the panel user; OWNER_STEAM_ID is honoured when it matches.
     */
    public function callback(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('steam')->user();
        } catch (Throwable $e) {
            Log::warning('Steam OpenID callback failed', ['error' => $e->getMessage()]);

            return Api::error(Api::MSG_INVALID_INPUT, ['steam' => 'openid_verification_failed'], 422);
        }

        $steamId64 = (string) $socialUser->getId();

        if (! SteamId::isValid($steamId64)) {
            return Api::error(Api::MSG_INVALID_INPUT, ['steam' => 'invalid_steam_id'], 422);
        }

        $ownerId = (string) (config('app.owner_steam_id') ?? '');
        $isOwner = $ownerId !== '' && $ownerId === $steamId64;

        $user = User::query()->where('steam_id', $steamId64)->first();

        if ($user === null) {
            $user = User::query()->create([
                'steam_id' => $steamId64,
                'name' => $socialUser->getNickname() ?: ($socialUser->getName() ?: 'Player'),
                'avatar' => $socialUser->getAvatar(),
                'is_owner' => $isOwner,
            ]);

            UserRegistered::dispatch($user);
        } else {
            $user->update([
                'name' => $socialUser->getNickname() ?: ($socialUser->getName() ?: $user->name),
                'avatar' => $socialUser->getAvatar() ?? $user->avatar,
                'is_owner' => $user->is_owner || $isOwner,
            ]);
        }

        Auth::login($user, true);

        // Session fixation: a session ID created before login (e.g. seeded
        // into the victim's browser by an attacker who visited the panel
        // first) must never remain valid after login. Laravel's Auth::login
        // only writes the user ID into the session - it does not rotate the
        // session ID, so this call is required.
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return Api::success($this->profile($user));
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * POST /api/auth/logout
     *
     * The sidebar's Logout row is a plain <form method="POST"> (CSRF-
     * protected), so this needs the same dual-mode response as the rest of
     * this controller: JSON for a fetch() caller, a real redirect for a
     * browser form submit.
     */
    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return Api::success(null);
        }

        return redirect()->route('login');
    }

    /**
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        return Api::success($this->profile(Auth::user()));
    }

    /**
     * @return array{steam_id: string, name: string, avatar: ?string, is_owner: bool}
     */
    private function profile(User $user): array
    {
        return [
            'steam_id' => $user->steam_id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'is_owner' => $user->isOwner(),
        ];
    }
}