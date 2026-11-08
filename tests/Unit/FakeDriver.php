<?php

namespace Teksite\SystemInfo\Tests\Unit;

use Teksite\SystemInfo\Drivers\AbstractDriver;

/** Scripted driver: returns the queued samples in order (the last one repeats). */
final class FakeDriver extends AbstractDriver
{
    public int $calls = 0;

    /** @param list<array<string, mixed>> $samples */
    public function __construct(private array $samples = [])
    {
        parent::__construct();
    }

    public function family(): string
    {
        return 'Fake';
    }

    public function sample(): array
    {
        $this->calls++;
        $sample = count($this->samples) > 1 ? array_shift($this->samples) : $this->samples[0];
        $sample['time'] ??= microtime(true);

        return $sample;
    }

    protected function buildBase(): array
    {
        return [
            'cpu' => ['model' => 'Fake CPU', 'cores' => 2, 'threads' => 4, 'sockets' => 1, 'max_clock_mhz' => 3000, 'architecture' => 'x86_64', 'limit_cores' => null],
            'gpu' => [], 'version' => ['name' => 'FakeOS'], 'timeZone' => 'UTC', 'boot_time' => time() - 90061,
        ];
    }
}
