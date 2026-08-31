<?php

namespace Tests\Feature;

use Tests\TestCase;

class PreventSearchEngineIndexingTest extends TestCase
{
    public function test_all_responses_include_x_robots_tag(): void
    {
        $this->get('/robots.txt')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
