<?php

namespace Tests\Unit;

use App\Modules\Install\App\Services\EnvWriter;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * The installer and the backup restore both write credentials through
 * EnvWriter, so a value that does not survive the round trip is a panel
 * that cannot reach its own database - with no error until the next
 * request. These cover the characters a generated password realistically
 * contains.
 */
class EnvWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 's2panel_envwriter_');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * Reads a key back through the parser Laravel actually boots with.
     *
     * Deliberately not a hand-rolled regex: an approximation of dotenv
     * syntax is exactly how a quoting bug slips through - the file looks
     * plausible to the test and still parses to something else in
     * production. phpdotenv is the only opinion that counts here.
     */
    private function readBack(string $key): string
    {
        $parsed = Dotenv::parse((string) file_get_contents($this->path));

        $this->assertArrayHasKey($key, $parsed);

        return (string) $parsed[$key];
    }

    public function test_plain_value_is_written_unquoted(): void
    {
        (new EnvWriter($this->path))->set(['DB_DATABASE' => 's2_panel']);

        $this->assertStringContainsString('DB_DATABASE=s2_panel', (string) file_get_contents($this->path));
    }

    public function test_value_with_spaces_survives_the_round_trip(): void
    {
        (new EnvWriter($this->path))->set(['APP_NAME' => 'S2 Panel']);

        $this->assertSame('S2 Panel', $this->readBack('APP_NAME'));
    }

    public function test_embedded_double_quote_is_escaped(): void
    {
        // Unescaped, this closed the quote early and left ` def#123` as
        // stray dotenv syntax on the line.
        (new EnvWriter($this->path))->set(['DB_PASSWORD' => 'abc" def#123']);

        $this->assertSame('abc" def#123', $this->readBack('DB_PASSWORD'));
    }

    public function test_backslash_is_escaped(): void
    {
        (new EnvWriter($this->path))->set(['DB_PASSWORD' => 'back\\slash pass']);

        $this->assertSame('back\\slash pass', $this->readBack('DB_PASSWORD'));
    }

    public function test_dollar_sequences_are_not_treated_as_backreferences(): void
    {
        // Overwriting an existing key went through preg_replace(), whose
        // replacement string reads $1/\1 as capture groups - so a password
        // containing them was silently rewritten into something else.
        (new EnvWriter($this->path))->set(['DB_PASSWORD' => 'first']);
        (new EnvWriter($this->path))->set(['DB_PASSWORD' => 'a$1b\\2c$0']);

        $this->assertSame('a$1b\\2c$0', $this->readBack('DB_PASSWORD'));
    }

    public function test_newlines_cannot_inject_another_line(): void
    {
        (new EnvWriter($this->path))->set(['DB_PASSWORD' => "pass\nINSTALLED=true"]);

        $content = (string) file_get_contents($this->path);

        $this->assertStringNotContainsString("\nINSTALLED=true", $content);
    }

    public function test_existing_key_is_replaced_not_duplicated(): void
    {
        $writer = new EnvWriter($this->path);
        $writer->set(['DB_HOST' => '127.0.0.1']);
        $writer->set(['DB_HOST' => 'db.internal']);

        $content = (string) file_get_contents($this->path);

        $this->assertSame(1, substr_count($content, 'DB_HOST='));
        $this->assertSame('db.internal', $this->readBack('DB_HOST'));
    }
}
