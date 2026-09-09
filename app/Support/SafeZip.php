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
    /** Entries beyond this make the archive suspicious on volume alone. */
    private const MAX_ENTRIES = 2000;

    /** Total uncompressed size the whole archive may expand to. */
    private const MAX_TOTAL_UNCOMPRESSED = 100 * 1024 * 1024;

    /** Uncompressed size any single entry may claim. */
    private const MAX_ENTRY_UNCOMPRESSED = 20 * 1024 * 1024;

    /**
     * Ratio of uncompressed to compressed size beyond which an entry is
     * treated as a bomb rather than a legitimately compressible file (a
     * plain-text or already-compressed asset does not get anywhere near
     * this; a crafted all-zeros payload easily exceeds 1000:1).
     */
    private const MAX_COMPRESSION_RATIO = 100;

    private const S_IFMT = 0170000;

    private const S_IFLNK = 0120000;

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
     * target directory via ".." traversal or an absolute path), symlinks,
     * and zip bombs (an archive whose declared uncompressed size - total or
     * per-entry - or compression ratio is wildly out of proportion to what
     * a genuine plugin/backup archive needs) before a single byte is
     * written to disk.
     */
    public static function assertSafeEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            throw new InvalidArgumentException('unsafe_zip_entry');
        }

        $totalUncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);

            if ($entry === false || $stat === false) {
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

            // PHP's ZipArchive recreates a symlink entry as an actual
            // symlink on extract on Unix hosts (the target path lives in
            // the entry's own file content). Unchecked, that is another
            // route to writing outside $destination alongside plain
            // path traversal above.
            //
            // The file-type bits live in the entry's *external attributes*,
            // which statIndex() does not return - its keys are only name,
            // index, crc, size, mtime, comp_size, comp_method and
            // encryption_method. Reading $stat['external attr'] therefore
            // silently evaluated to 0 for every entry and this check never
            // rejected anything; getExternalAttributesIndex() is the only
            // API that actually exposes them.
            if (self::isSymlink($zip, $i)) {
                $zip->close();

                throw new InvalidArgumentException('unsafe_zip_entry');
            }

            $size = (int) ($stat['size'] ?? 0);
            $compSize = (int) ($stat['comp_size'] ?? 0);

            if ($size > self::MAX_ENTRY_UNCOMPRESSED) {
                $zip->close();

                throw new InvalidArgumentException('zip_bomb_suspected');
            }

            if ($compSize > 0 && ($size / $compSize) > self::MAX_COMPRESSION_RATIO) {
                $zip->close();

                throw new InvalidArgumentException('zip_bomb_suspected');
            }

            $totalUncompressed += $size;

            if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED) {
                $zip->close();

                throw new InvalidArgumentException('zip_bomb_suspected');
            }
        }
    }

    /**
     * Whether entry $index carries Unix symlink file-type bits.
     *
     * Only meaningful when the entry was written by a Unix-like producer -
     * a DOS/Windows-created entry stores DOS attribute bits in the same
     * field, where the value that happens to look like S_IFLNK means
     * nothing of the sort. Checking the OS byte first keeps a legitimate
     * Windows-made archive from being rejected at random.
     */
    private static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = null;
        $attributes = null;

        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) !== true) {
            return false;
        }

        if ((int) $opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return ((((int) $attributes) >> 16) & self::S_IFMT) === self::S_IFLNK;
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
