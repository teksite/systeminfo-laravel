<?php

namespace Teksite\SystemInfo\Contracts;

use Teksite\SystemInfo\DTOs\ApplicationData;

interface ApplicationCollectorInterface {
    public function collect(): ApplicationData;

}
