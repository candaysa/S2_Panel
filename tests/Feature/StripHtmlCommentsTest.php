<?php

namespace Tests\Feature;

use App\Http\Middleware\StripHtmlComments;
use App\Support\SourceComments;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StripHtmlCommentsTest extends TestCase
{
    public function test_html_comments_are_stripped_but_conditional_comments_survive(): void
    {
        Route::get('/html-comment-test', fn () => response(
            '<!-- dev note --><p>Hello</p><!--[if IE]>ie-fallback<![endif]-->'
        ))->middleware(StripHtmlComments::class);

        $this->get('/html-comment-test')
            ->assertOk()
            ->assertSee('<p>Hello</p>', false)
            ->assertDontSee('dev note', false)
            ->assertSee('<!--[if IE]>ie-fallback<![endif]-->', false);
    }

    /**
     * Every rendered page's scripts are also checked end to end: the pages
     * in PageScriptsTest go through this middleware, and it asserts their
     * JavaScript is still whole.
     */
    #[DataProvider('scripts')]
    public function test_script_comments_go_and_code_stays_byte_for_byte(string $source, string $expected): void
    {
        $this->assertSame($expected, SourceComments::stripJs($source));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function scripts(): array
    {
        return [
            'line comment on its own line' => ["a();\n    // note\nb();", "a();\nb();"],
            'trailing line comment' => ["a(); // note\nb();", "a();\nb();"],
            'block comment inline' => ['x = 1 /* one */ + 2;', 'x = 1 + 2;'],
            'block comment between tokens' => ['a/* x */b', 'a b'],
            'multi-line block keeps a break' => ["return /* a\n b */ x;", "return\n x;"],
            'url in a string' => ["u = 'https://example.com/a'; // c", "u = 'https://example.com/a';"],
            'url in double quotes' => ['u = "//cdn.example.com/x.js";', 'u = "//cdn.example.com/x.js";'],
            'escaped quote in string' => ["s = 'it\\'s // not'; // c", "s = 'it\\'s // not';"],
            'template literal' => ['t = `a // b ${ c /* d */ } e`;', 't = `a // b ${ c } e`;'],
            'nested template' => ['t = `x ${ `y // ${ z }` } w`; // c', 't = `x ${ `y // ${ z }` } w`;'],
            'object in template expression' => ['t = `${ {a: 1}.a } // k`;', 't = `${ {a: 1}.a } // k`;'],
            'regex with slashes' => ['r = /\\/\\//g; // c', 'r = /\\/\\//g;'],
            'regex with class' => ['r = /[/]+/.test(s);', 'r = /[/]+/.test(s);'],
            'regex after return' => ["return /a\\/\\/b/;\n// c", "return /a\\/\\/b/;\n"],
            'division is not a regex' => ['x = a / b / c; // c', 'x = a / b / c;'],
            'division after call' => ['x = f(a) / 2 // c', 'x = f(a) / 2'],
        ];
    }

    public function test_inline_scripts_styles_and_alpine_attributes_are_cleaned(): void
    {
        $html = implode("\n", [
            '<div x-data="{ open: false, // a note',
            '  url: \'https://example.com\' }" @click="go() /* why */">x</div>',
            '<script>',
            '    // setup',
            '    const a = "<!-- not a comment -->";',
            '</script>',
            '<script src="/app.js">// external, untouched</script>',
            '<script type="application/ld+json">{"a": "b // c"}</script>',
            '<style>/* theme */ .a { content: "/* kept */"; }</style>',
            '<!-- dev note -->',
        ]);

        $out = (new StripHtmlComments)->strip($html);

        $this->assertStringNotContainsString('a note', $out);
        $this->assertStringNotContainsString('why', $out);
        $this->assertStringNotContainsString('setup', $out);
        $this->assertStringNotContainsString('theme', $out);
        $this->assertStringNotContainsString('dev note', $out);
        $this->assertStringContainsString("url: 'https://example.com'", $out);
        $this->assertStringContainsString('const a = "<!-- not a comment -->";', $out);
        $this->assertStringContainsString('// external, untouched', $out);
        $this->assertStringContainsString('{"a": "b // c"}', $out);
        $this->assertStringContainsString('content: "/* kept */"', $out);
    }

    public function test_non_html_responses_are_left_untouched(): void
    {
        Route::get('/json-comment-test', fn () => response()->json([
            'note' => '<!-- untouched -->',
        ]))->middleware(StripHtmlComments::class);

        $this->getJson('/json-comment-test')
            ->assertOk()
            ->assertJsonPath('note', '<!-- untouched -->');
    }
}