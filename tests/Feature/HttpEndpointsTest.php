<?php

namespace Teksite\SystemInfo\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Teksite\SystemInfo\Http\Middleware\AuthorizeSystemInfo;
use Teksite\SystemInfo\SystemInformationProvider;

final class HttpEndpointsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SystemInformationProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'production');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('system-info.http.token', 'a-very-long-secret-token');
        $app['config']->set('system-info.live.sample_window_ms', 0);
    }

    protected function tearDown(): void
    {
        AuthorizeSystemInfo::flush();
        parent::tearDown();
    }

    public function test_denied_by_default_outside_local(): void
    {
        $this->getJson('/system-info/metrics')->assertForbidden();
        $this->getJson('/system-info/metrics', ['Authorization' => 'Bearer wrong-token-value-xx'])->assertForbidden();
    }

    public function test_short_tokens_are_ignored(): void
    {
        $this->app['config']->set('system-info.http.token', 'short');
        $this->getJson('/system-info/metrics', ['Authorization' => 'Bearer short'])->assertForbidden();
    }

    public function test_metrics_json_and_chartjs(): void
    {
        $h = ['Authorization' => 'Bearer a-very-long-secret-token'];

        $this->getJson('/system-info/metrics', $h)->assertOk()->assertJsonStructure(['time', 'cpu' => ['usage'], 'memory' => ['percent'], 'network']);

        $this->getJson('/system-info/metrics?format=chartjs&labels=ms', $h)
            ->assertOk()
            ->assertJsonStructure(['meta', 'charts' => ['cpu' => ['labels', 'datasets'], 'network', 'memory']]);

        $this->getJson('/system-info/history?points=5', $h)->assertOk()->assertJsonPath('meta.label_format', 'time');
    }

    public function test_info_only_exposes_configured_sections(): void
    {
        $h = ['Authorization' => 'Bearer a-very-long-secret-token'];

        $json = $this->getJson('/system-info/info?only=hardware,database,network', $h)->assertOk()->json();

        $this->assertArrayHasKey('hardware', $json);
        $this->assertArrayNotHasKey('database', $json);   // not in http.info_sections
        $this->assertArrayNotHasKey('network', $json);
    }

    public function test_stream_requires_a_signed_url(): void
    {
        $h = ['Authorization' => 'Bearer a-very-long-secret-token'];

        $this->get('/system-info/stream')->assertForbidden();

        $url = $this->getJson('/system-info/stream-url?interval=1&format=chartjs', $h)->assertOk()->json('url');
        $this->assertStringContainsString('signature=', $url);

        // Tampering with a signed parameter invalidates it.
        $this->get(str_replace('interval=1', 'interval=9', $url))->assertForbidden();
    }

    public function test_custom_callback_overrides_everything(): void
    {
        AuthorizeSystemInfo::using(static fn ($request): bool => $request->query('let_me_in') === '1');

        $this->getJson('/system-info/metrics?let_me_in=1')->assertOk();
        $this->getJson('/system-info/metrics', ['Authorization' => 'Bearer a-very-long-secret-token'])->assertForbidden();
    }
}
