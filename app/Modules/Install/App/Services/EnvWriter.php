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
            return (string) preg_replace($pattern, $line, $content, 1);
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

        if (preg_match('/[\s#]/', $value)) {
            return '"'.$value.'"';
        }

        return $value;
    }
}