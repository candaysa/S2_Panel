<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Steam login callback has to follow APP_URL. A fresh install copied
 * .env.example's STEAM_CALLBACK_URL=http://localhost:8000/... verbatim and
 * every Steam login on the live panel was sent back to the visitor's own
 * machine; the fix removes that line and makes an empty value fall back to
 * APP_URL too, since env()'s default only applies to a variable that is
 * not set at all.
 */
class SteamCallbackConfigTest extends TestCase
{
    /**
     * Evaluate config/services.php with the given environment, then restore it.
     *
     * @param  array<string, string|null>  $env  null = unset
     * @return array<string, mixed>
     */
    private function servicesWith(array $env): array
    {
        $previous = [];

        foreach ($env as $key => $value) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        try {
            return require base_path('config/services.php');
        } finally {
            foreach ($previous as $key => [$getenv, $env_, $server]) {
                $getenv === false ? putenv($key) : putenv("{$key}={$getenv}");

                if ($env_ === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env_;
                }

                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    public function test_an_unset_callback_follows_app_url(): void
    {
        $services = $this->servicesWith(['APP_URL' => 'https://panel.example.com', 'STEAM_CALLBACK_URL' => null]);

        $this->assertSame('https://panel.example.com/api/auth/callback', $services['steam']['redirect']);
    }

    public function test_an_empty_callback_follows_app_url_too(): void
    {
        $services = $this->servicesWith(['APP_URL' => 'https://panel.example.com/', 'STEAM_CALLBACK_URL' => '']);

        $this->assertSame('https://panel.example.com/api/auth/callback', $services['steam']['redirect']);
    }

    public function test_an_explicit_callback_still_wins(): void
    {
        $services = $this->servicesWith(['APP_URL' => 'https://internal.example', 'STEAM_CALLBACK_URL' => 'https://public.example/api/auth/callback']);

        $this->assertSame('https://public.example/api/auth/callback', $services['steam']['redirect']);
    }

    /**
     * The value that broke login must never ship in the template again.
     */
    public function test_env_example_does_not_ship_a_callback_value(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertDoesNotMatchRegularExpression('/^STEAM_CALLBACK_URL=\S/m', $example);
    }
}
