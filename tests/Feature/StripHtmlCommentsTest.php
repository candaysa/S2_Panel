<?php

namespace Tests\Feature;

use App\Http\Middleware\StripHtmlComments;
use Illuminate\Support\Facades\Route;
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