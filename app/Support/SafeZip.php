<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ZipArchive;

/**
 * Zip-slip-safe extraction, shared by every feature that lets an owner
 * upload a .zip (Plugins, Backup restore). A malicious entry name like
 * "../../../../etc/passwd" or "C:\Windows\..." must never be allowed to
 * write outside the intended destination directory.
 */
final class SafeZip
{
    /**
     * Opens $path, rejects any unsafe entry, and extracts everything into
     * $destination (created if missing). Returns the ZipArchive entry count
     * for callers that want a quick sanity check (e.g. "not an empty zip").
     */
    public static function extract(string $path, string $destination): int
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new InvalidArgumentException('invalid_zip_file');
        }

        self::assertSafeEntries($zip);

        File::ensureDirectoryExists($destination);
        $zip->extractTo($destination);
        $count = $zip->numFiles;
        $zip->close();

        return $count;
    }

    /**
     * Rejects zip-slip attempts (entries that would extract outside the
     * target directory via ".." traversal or an absolute path) before a
     * single byte is written to disk.
     */
    public static function assertSafeEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if ($entry === false) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entry);

            if (
                str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:#', $normalized) === 1
                || str_contains($normalized, '../')
                || str_ends_with($normalized, '/..')
                || $normalized === '..'
            ) {
                $zip->close();

                throw new InvalidArgumentException('unsafe_zip_entry');
            }
        }
    }

    /**
     * A zip whose only top-level entry is a single directory (common when a
     * folder was compressed directly) has that directory flattened up one
     * level, so callers can assume their expected files sit at the root.
     */
    public static function flattenSingleTopLevelDirectory(string $extractTo): string
    {
        $entries = array_values(array_diff(scandir($extractTo) ?: [], ['.', '..']));

        if (count($entries) === 1 && File::isDirectory($extractTo.'/'.$entries[0])) {
            return $extractTo.'/'.$entries[0];
        }

        return $extractTo;
    }
}
