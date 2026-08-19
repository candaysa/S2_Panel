<?php

namespace App\Support;

use App\Modules\Settings\App\Services\SettingService;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Applies the owner's stored SMTP settings over config/mail.php at boot.
 *
 * The panel genuinely sends mail - an approved ban appeal notifies the
 * player - but config/mail.php reads .env, and an owner running a packaged
 * install has no comfortable way to edit that. So the same values are
 * settable from Settings and layered on here.
 *
 * .env stays authoritative when nothing is stored: an empty mail_host means
 * "leave config/mail.php exactly as it is", so an install already configured
 * through .env keeps working and is never half-overridden by blank fields.
 *
 * The password is encrypted at rest with the app key (see encrypt()) because
 * the settings table is otherwise readable in plain text by anything with
 * database access; everything else here is ordinary configuration.
 */
class MailConfig
{
    /**
     * True once the owner has stored a host - i.e. the panel is sending
     * through settings rather than through .env.
     */
    public static function isConfigured(SettingService $settings): bool
    {
        return trim((string) $settings->get('mail_host')) !== '';
    }

    public static function encrypt(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    /**
     * Decrypt a stored password, tolerating a value that was never
     * encrypted (e.g. seeded by hand) and one this app key can no longer
     * read - a rotated APP_KEY must degrade to "no password" rather than
     * throwing on every request that builds the mailer.
     */
    public static function decrypt(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }

    public static function apply(SettingService $settings): void
    {
        if (! self::isConfigured($settings)) {
            return;
        }

        $host = (string) $settings->get('mail_host');
        $encryption = (string) $settings->get('mail_encryption', 'tls');
        $from = (string) $settings->get('mail_from_address');
        $fromName = (string) $settings->get('mail_from_name');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => (int) $settings->get('mail_port', 587),
            // Laravel reads "null" as "no encryption"; the UI offers none/tls/ssl.
            'mail.mailers.smtp.encryption' => $encryption === 'none' ? null : $encryption,
            'mail.mailers.smtp.username' => (string) $settings->get('mail_username') ?: null,
            'mail.mailers.smtp.password' => self::decrypt((string) $settings->get('mail_password')) ?: null,
        ]);

        // A from address is optional here: leaving it blank keeps whatever
        // config/mail.php already had rather than sending as "".
        if ($from !== '') {
            config(['mail.from.address' => $from]);
        }

        if ($fromName !== '') {
            config(['mail.from.name' => $fromName]);
        }
    }
}
