<?php

namespace Uhf\Monocycle\Database;

interface Driver
{
    public function executeRequest(array $request): string;

    public function close(): void;
}