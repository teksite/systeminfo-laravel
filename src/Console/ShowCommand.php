<?php

namespace Teksite\SystemInfo\Console;

use Illuminate\Console\Command;
use JsonException;
use Teksite\SystemInfo\SystemInfoManager;

final class ShowCommand extends Command
{
    protected $signature = 'system-info:show
                            {--section=* : Limit to sections (hardware, os, network, web_server, php, laravel, database)}
                            {--json : Print raw JSON}
                            {--fresh : Bypass the inventory cache}';

    protected $description = 'Show system, PHP and Laravel runtime information';

    /**
     * @throws JsonException
     */
    public function handle(SystemInfoManager $manager): int
    {
        $sections = array_filter((array)$this->option('section'));
        $info = $manager->info($sections === [] ? null : $sections, (bool)$this->option('fresh'));

        if ($this->option('json')) {
            $this->line((string)json_encode($info, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $rows = [];
        $this->flatten($info, '', $rows);
        $this->table(['Key', 'Value'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param array<int|string, mixed>          $data
     * @param list<array{0: string, 1: string}> $rows
     */
    private function flatten(array $data, string $prefix, array &$rows): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $this->flatten($value, $path, $rows);

            } elseif (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, 'is_array') !== []) {

                foreach ($value as $i => $item) {
                    is_array($item) ? $this->flatten($item, $path.'.'.$i, $rows) : $rows[] = [$path.'.'.$i, (string)$item];
                }

            } else {
                $rows[] = [$path, match (true) {
                    is_bool($value)  => $value ? 'true' : 'false',
                    $value === null  => '-',
                    is_array($value) => implode(', ', array_map('strval', $value)),
                    default          => (string)$value,
                }];
            }
        }
    }
}
