<?php

namespace Teksite\SystemInfo\Contracts;

use Teksite\SystemInfo\DTOs\ApplicationData;

interface CollectorInterface {
    public function collect(): ApplicationData;

}
