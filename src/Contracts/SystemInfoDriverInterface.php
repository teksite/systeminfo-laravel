<?php
namespace Teksite\SystemInfo\Contracts;

interface SystemInfoDriverInterface
{
    public function getOsInfo(): array;

    public function getCpuInfo(): array;

    public function getRamInfo(): array;

    public function getDiskInfo(): array;

    public function getWebServerInfo(): array;

    public function collect(): SystemInfoDTO;
}
