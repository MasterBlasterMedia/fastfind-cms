<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeoRedirectSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function activate(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'seo-safety@example.test', 'password' => 'long-test-password', 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);
        config(['app.url' => 'https://example.test']);
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/install')->assertOk();
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/activate')->assertOk();
    }

    public static function normalizedCycles(): array
    {
        return [
            'query self' => [[['source' => '/a', 'destination' => '/a?source=redirect', 'status' => 302]]],
            'fragment self' => [[['source' => '/a', 'destination' => '/a#intro', 'status' => 302]]],
            'dot segment self' => [[['source' => '/a', 'destination' => '/other/../a', 'status' => 301]]],
            'encoded dot self' => [[['source' => '/a', 'destination' => '/other/%2e%2e/a', 'status' => 301]]],
            'absolute multi-hop' => [[['source' => '/a', 'destination' => 'https://EXAMPLE.test/b?tracked=1', 'status' => 301], ['source' => '/b', 'destination' => '/a#top', 'status' => 301]]],
            'reserved encoded source' => [[['source' => '/%73tudio', 'destination' => '/new', 'status' => 302]]],
            'fragment source' => [[['source' => '/old#intro', 'destination' => '/new', 'status' => 302]]],
            'dot segment source' => [[['source' => '/a/../studio', 'destination' => '/new', 'status' => 302]]],
        ];
    }

    #[DataProvider('normalizedCycles')]
    public function test_normalized_loops_and_unmatchable_sources_are_rejected(array $redirects): void
    {
        $this->activate();
        $this->putJson('/api/v1/plugins/fastfind/seo/redirects', compact('redirects'))->assertUnprocessable()->assertJsonValidationErrors('redirects');
        $this->assertDatabaseCount('fastfind_seo_redirects', 0);
    }

    public function test_noncyclic_query_fragment_and_external_destinations_remain_supported(): void
    {
        $this->activate();
        $redirects = [
            ['source' => '/old', 'destination' => '/new?campaign=help#intro', 'status' => 302],
            ['source' => '/external', 'destination' => 'https://elsewhere.test/external', 'status' => 301],
        ];
        $this->putJson('/api/v1/plugins/fastfind/seo/redirects', compact('redirects'))->assertOk();
        $this->get('/old')->assertRedirect('/new?campaign=help#intro')->assertStatus(302);
        $this->get('/external')->assertRedirect('https://elsewhere.test/external')->assertStatus(301);
    }
}
