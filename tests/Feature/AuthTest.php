<?php

namespace Tests\Feature;

use App\Modules\Auth\Events\UserRegistered;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const STEAM64 = '76561197962734863';

    /**
     * Swap the real Steam provider for a controllable fake.
     */
    private function fakeProvider(?SocialiteUser $user = null): void
    {
        $socialUser = $user ?? $this->socialUser(self::STEAM64);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        $provider->shouldReceive('redirect')->andReturn(
            new RedirectResponse('https://steamcommunity.com/openid/login?openid.mode=checkid_setup')
        );

        Socialite::shouldReceive('driver')->with('steam')->andReturn($provider);
    }

    private function socialUser(string $steamId64, string $name = 'Test Player'): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map([
            'id' => $steamId64,
            'nickname' => $name,
            'name' => $name,
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        return $user;
    }

    public function test_redirect_returns_steam_openid_url(): void
    {
        $this->fakeProvider();

        $this->getJson('/api/auth/redirect')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://steamcommunity.com/openid/login?openid.mode=checkid_setup');
    }

    public function test_callback_creates_user_on_first_login(): void
    {
        Event::fake([UserRegistered::class]);
        $this->fakeProvider();

        $this->getJson('/api/auth/callback')
            ->assertOk()
            ->assertJsonPath('data.steam_id', self::STEAM64)
            ->assertJsonPath('data.name', 'Test Player')
            ->assertJsonPath('data.is_owner', false);

        $this->assertDatabaseHas('users', ['steam_id' => self::STEAM64, 'is_owner' => false]);

        Event::assertDispatched(UserRegistered::class);
    }

    public function test_callback_marks_owner_when_matching_owner_steam_id(): void
    {
        config()->set('app.owner_steam_id', self::STEAM64);
        $this->fakeProvider();

        $this->getJson('/api/auth/callback')
            ->assertOk()
            ->assertJsonPath('data.is_owner', true);

        $this->assertDatabaseHas('users', ['steam_id' => self::STEAM64, 'is_owner' => true]);
    }

    public function test_callback_updates_existing_user(): void
    {
        $existing = User::factory()->create(['steam_id' => self::STEAM64, 'name' => 'Old Name']);

        $this->fakeProvider($this->socialUser(self::STEAM64, 'New Name'));

        $this->getJson('/api/auth/callback')
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $existing->fresh()->name);
        $this->assertSame(1, User::query()->where('steam_id', self::STEAM64)->count());
    }

    public function test_callback_rejects_invalid_steam_id(): void
    {
        $this->fakeProvider($this->socialUser('not-a-steam-id'));

        $this->getJson('/api/auth/callback')
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_profile_when_authenticated(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.steam_id', $user->steam_id)
            ->assertJsonPath('data.is_owner', true);
    }

    public function test_logout_clears_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/logout');
        $response->assertOk();

        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }

    /**
     * Session fixation regression test: Auth::login() only writes the user
     * ID into the session, it does not rotate the session ID. Without an
     * explicit regenerate() call, a session ID seeded before login (e.g. by
     * an attacker who visited the panel first and handed the victim a
     * crafted cookie) would remain valid - and now authenticated - after
     * the victim logs in.
     */
    public function test_callback_regenerates_session_id(): void
    {
        $this->fakeProvider();

        $cookieName = config('session.cookie');

        // Any always-public, session-starting GET works here; /api/auth/redirect
        // is used (rather than /api/install/status) so this test does not
        // depend on the panel's installed state.
        $before = $this->getJson('/api/auth/redirect');
        $before->assertOk();
        $sessionIdBefore = $before->headers->getCookies()[0]->getValue();

        $after = $this->withCookie($cookieName, $sessionIdBefore)
            ->getJson('/api/auth/callback');
        $after->assertOk();
        $sessionIdAfter = $after->headers->getCookies()[0]->getValue();

        $this->assertNotSame($sessionIdBefore, $sessionIdAfter, 'session ID must rotate on login');
    }
}