<?php

namespace Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The editor is served from another origin, and the policy has to say so.
 *
 * Written after the fact: the integration worked on the server side — the
 * container was healthy, it fetched the file, the script was served — and the
 * browser blocked all of it because the policy still only knew about this
 * application's own origin. Nothing in the logs said a word.
 */
class OfficeContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): string
    {
        config(['services.onlyoffice.url' => 'http://localhost:8080']);

        $ctx = $this->createWorkspaceContext();

        return (string) $this->actingAs($ctx['user'])
            ->get(route('client.dashboard'))
            ->headers->get('content-security-policy');
    }

    /**
     * Each of these is a separate way for the editor to fail: no script, no
     * iframe to draw in, no websocket to talk over, no icons.
     */
    #[DataProvider('directives')]
    public function test_the_editor_origin_is_allowed_where_it_needs_to_be(string $directive, string $expected): void
    {
        $policy = collect(explode(';', $this->policy()))
            ->map(fn (string $part) => trim($part))
            ->first(fn (string $part) => str_starts_with($part, $directive.' '));

        $this->assertNotNull($policy, "the policy has no {$directive} directive");
        $this->assertStringContainsString($expected, $policy);
    }

    /** @return array<string, array{string, string}> */
    public static function directives(): array
    {
        return [
            'the script itself' => ['script-src-elem', 'http://localhost:8080'],
            'the iframe it draws in' => ['frame-src', 'http://localhost:8080'],
            'the websocket it keeps open' => ['connect-src', 'ws://localhost:8080'],
            'its own icons' => ['img-src', 'http://localhost:8080'],
            'the styles it injects' => ['style-src-elem', 'http://localhost:8080'],
        ];
    }

    public function test_both_spellings_of_the_host_are_allowed(): void
    {
        // The browser may be pointed at one while the configuration says the
        // other, and a policy matches the literal origin rather than resolving.
        $policy = $this->policy();

        $this->assertStringContainsString('http://localhost:8080', $policy);
        $this->assertStringContainsString('http://127.0.0.1:8080', $policy);
    }

    public function test_nothing_is_opened_up_while_the_editor_is_unconfigured(): void
    {
        config(['services.onlyoffice.url' => '']);
        $ctx = $this->createWorkspaceContext();

        $policy = (string) $this->actingAs($ctx['user'])
            ->get(route('client.dashboard'))
            ->headers->get('content-security-policy');

        $this->assertStringNotContainsString(':8080', $policy);
    }
}
