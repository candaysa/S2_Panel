<?php

namespace App\Modules\Install\App\Services;

/**
 * Appends or updates KEY=VALUE lines inside a dot-env file.
 *
 * Used by the installer to persist DB credentials, Steam settings, module
 * toggles and the INSTALLED flag. Values are sanitized against newlines
 * and quoted when they contain spaces or comment characters.
 */
class EnvWriter
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $content = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $content = $this->writeKey($content, strtoupper((string) $key), $value);
        }

        file_put_contents($this->path, $content);
    }

    private function writeKey(string $content, string $key, string|int|bool|null $value): string
    {
        $line = $key.'='.$this->sanitize($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content)) {
            // Callback, not a replacement string: preg_replace() reads $1
            // and \1 in its replacement as backreferences, so a value that
            // happens to contain them (a generated DB password like
            // "a$1b" is enough) would be silently rewritten into something
            // else on its way into .env. The callback form takes the line
            // verbatim.
            return (string) preg_replace_callback($pattern, fn (): string => $line, $content, 1);
        }

        return rtrim($content).PHP_EOL.$line.PHP_EOL;
    }

    private function sanitize(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = str_replace(["\r", "\n"], '', (string) $value);

        if ($value === '') {
            return '';
        }

        // A value that needs quoting has to survive being quoted: a
        // password like  ab" cd  would otherwise close the quote early and
        // leave the rest of the line as stray dotenv syntax, so the value
        // read back at boot is not the one that was set. Backslash first,
        // or it would escape the escapes added right after it.
        if (preg_match('/["\\\\\s#]/', $value)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"'.$escaped.'"';
        }

        return $value;
    }
}