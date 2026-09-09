<?php

namespace Tests\Unit;

use App\Support\SafeZip;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class SafeZipTest extends TestCase
{
    private string $extractTo;

    protected function tearDown(): void
    {
        if (isset($this->extractTo)) {
            File::deleteDirectory($this->extractTo);
        }

        parent::tearDown();
    }

    private function buildZip(callable $fill): string
    {
        $path = tempnam(sys_get_temp_dir(), 'safezip_test_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $fill($zip);
        $zip->close();

        return $path;
    }

    public function test_extracts_a_plain_zip(): void
    {
        $path = $this->buildZip(fn (ZipArchive $zip) => $zip->addFromString('readme.txt', 'hello'));
        $this->extractTo = storage_path('framework/testing/safezip-'.uniqid());

        $count = SafeZip::extract($path, $this->extractTo);

        $this->assertSame(1, $count);
        $this->assertFileExists($this->extractTo.'/readme.txt');

        @unlink($path);
    }

    public function test_rejects_path_traversal_entry(): void
    {
        $path = $this->buildZip(fn (ZipArchive $zip) => $zip->addFromString('../../etc/passwd', 'x'));
        $this->extractTo = storage_path('framework/testing/safezip-'.uniqid());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe_zip_entry');

        try {
            SafeZip::extract($path, $this->extractTo);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_an_entry_claiming_an_oversized_uncompressed_size(): void
    {
        // A real bomb hides behind a small compressed size and a huge
        // declared uncompressed size - simulated here by writing genuinely
        // large (but trivially compressible) content rather than crafting a
        // raw deflate stream by hand, which ZipArchive gives no API for.
        $path = $this->buildZip(function (ZipArchive $zip): void {
            $zip->addFromString('big.bin', str_repeat("\0", 21 * 1024 * 1024));
        });
        $this->extractTo = storage_path('framework/testing/safezip-'.uniqid());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zip_bomb_suspected');

        try {
            SafeZip::extract($path, $this->extractTo);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The symlink guard used to read $stat['external attr'], a key
     * statIndex() does not return - so it evaluated to 0 for every entry
     * and rejected nothing. This asserts the guard actually fires, which
     * only getExternalAttributesIndex() makes possible.
     */
    public function test_rejects_a_unix_symlink_entry(): void
    {
        $path = $this->buildZip(function (ZipArchive $zip): void {
            $zip->addFromString('innocent.txt', 'hello');
            // Content of a symlink entry is its target path.
            $zip->addFromString('link', '../../../../etc/passwd');
            $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        $this->extractTo = storage_path('framework/testing/safezip-'.uniqid());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe_zip_entry');

        try {
            SafeZip::extract($path, $this->extractTo);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The counterpart to the test above: a plain file written by a Unix
     * producer carries Unix attributes too, and must still extract - the
     * guard keys on the file-type bits, not on the OS byte alone.
     */
    public function test_allows_a_plain_unix_file_entry(): void
    {
        $path = $this->buildZip(function (ZipArchive $zip): void {
            $zip->addFromString('plain.txt', 'hello');
            $zip->setExternalAttributesName('plain.txt', ZipArchive::OPSYS_UNIX, 0100644 << 16);
        });
        $this->extractTo = storage_path('framework/testing/safezip-'.uniqid());

        try {
            $this->assertSame(1, SafeZip::extract($path, $this->extractTo));
            $this->assertFileExists($this->extractTo.'/plain.txt');
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_too_many_entries(): void
    {
        $path = $this->buildZip(function (ZipArchive $zip): void {
            for ($i = 0; $i < 2001; $i++) {
                $zip->addFromString("f{$i}.txt", 'x');
            }
        });
        $this->extractTo = storage_path('framework/testing/safezip-'.uniqid());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe_zip_entry');

        try {
            SafeZip::extract($path, $this->extractTo);
        } finally {
            @unlink($path);
        }
    }
}
