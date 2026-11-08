<?php

namespace Teksite\SystemInfo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Teksite\SystemInfo\Metrics\ChartFormatter;
use Teksite\SystemInfo\Support\Options;
use Teksite\SystemInfo\SystemInfoManager;

final class SystemInfoController
{
    private const array NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly SystemInfoManager $manager, private readonly Options $options,) {
    }

    /** GET /system-info/info?only=hardware,php&fresh=1 */
    public function info(Request $request): JsonResponse
    {
        $allowed = array_values(array_intersect(
            SystemInfoManager::SECTIONS,
            (array) $this->options->get('http.info_sections', [])
        ));

        $requested = array_filter(array_map('trim', explode(',', (string) $request->query('only', ''))));
        $sections = $requested === [] ? $allowed : array_values(array_intersect($allowed, $requested));

        return $this->json($this->manager->info($sections, $request->boolean('fresh')));
    }

    /** GET /system-info/metrics?format=json|chartjs&labels=time|iso|ms */
    public function metrics(Request $request): JsonResponse
    {
        return $this->json($this->metricsPayload($request->query('format'), $request->query('labels')));
    }

    /** GET /system-info/history?points=60&since=<epoch ms>&labels=time|iso|ms */
    public function history(Request $request): JsonResponse
    {
        $max = max(1, (int) $this->options->get('history.points', 300));
        $points = $request->has('points') ? min($max, max(1, $request->integer('points'))) : null;
        $since = $request->has('since') ? max(0, $request->integer('since')) : null;

        return $this->json($this->manager->history($points, $since, $this->labels($request->query('labels'))));
    }

    /**
     * GET /system-info/stream-url?interval=2&format=chartjs
     *
     * EventSource cannot send an Authorization header, so the (authorised) client first asks for a
     * short-lived signed URL and opens that. The stream route itself never touches the session.
     */
    public function streamUrl(Request $request): JsonResponse
    {
        $ttl = max(30, (int) $this->options->get('stream.url_ttl', 300));

        $parameters = array_filter([
            'interval' => $this->interval($request),
            'format'   => $this->format($request->query('format')),
            'labels'   => $this->labels($request->query('labels')),
        ], static fn ($v) => $v !== null);

        $url = URL::temporarySignedRoute(
            (string) $this->options->get('http.name', 'system-info.') . 'stream',
            now()->addSeconds($ttl),
            $parameters,
            absolute: !$this->options->get('stream.relative_url', true)
        );

        return $this->json(['url' => $url, 'expires_in' => $ttl, 'interval' => $parameters['interval']]);
    }

    /**
     * GET /system-info/stream  (Server-Sent Events, signed URL)
     *
     * Holds one PHP worker for at most `stream.max_duration` seconds, then ends cleanly so the
     * browser's EventSource transparently reconnects. Use polling when workers are scarce.
     */
    public function stream(Request $request): StreamedResponse
    {
        $interval = $this->interval($request);
        $format = $this->format($request->query('format'));
        $labels = $this->labels($request->query('labels'));
        $maxDuration = max($interval, (int) $this->options->get('stream.max_duration', 55));

        return new StreamedResponse(function () use ($interval, $format, $labels, $maxDuration): void {
            // Output buffering would hold events back until the buffer fills.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            @ini_set('zlib.output_compression', '0');
            @set_time_limit($maxDuration + 15);

            echo 'retry: ' . (int) ($interval * 1000) . "\n\n";
            flush();

            $started = microtime(true);
            $tick = $started;

            while (true) {
                $payload = $this->metricsPayload($format, $labels);

                echo "event: metrics\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                flush();

                $tick += $interval;

                if (connection_aborted() || ($tick - $started) >= $maxDuration) {
                    echo "event: end\ndata: {}\n\n";
                    flush();

                    return;
                }

                // Sleep until the next tick (not a fixed interval) so slow samples do not accumulate drift.
                $sleep = $tick - microtime(true);
                if ($sleep > 0) {
                    usleep((int) ($sleep * 1e6));
                }
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache, no-store, no-transform',
            'X-Accel-Buffering' => 'no', // nginx
        ]);
    }

    // ---- helpers -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function metricsPayload(mixed $format, mixed $labels): array
    {
        return $this->format($format) === 'chartjs'
            ? $this->manager->chart($this->labels($labels))
            : $this->manager->metrics();
    }

    private function format(mixed $value): ?string
    {
        return in_array($value, ['json', 'chartjs'], true) ? $value : null;
    }

    private function labels(mixed $value): ?string
    {
        return in_array($value, ChartFormatter::LABEL_FORMATS, true) ? $value : null;
    }

    private function interval(Request $request): float
    {
        $min = max(0.5, (float) $this->options->get('stream.min_interval', 1));
        $max = max($min, (float) $this->options->get('stream.max_interval', 30));
        $default = (float) $this->options->get('stream.default_interval', 2);

        $value = $request->query('interval');

        return min($max, max($min, is_numeric($value) ? (float) $value : $default));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, self::NO_STORE, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
