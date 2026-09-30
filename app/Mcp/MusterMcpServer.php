<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\MonitorHistoryTool;
use App\Mcp\Tools\UnhealthyMonitorsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Muster')]
#[Version('1.0.0')]
#[Instructions('Read-only monitor health and bounded incident history for this Muster installation.')]
final class MusterMcpServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        UnhealthyMonitorsTool::class,
        MonitorHistoryTool::class,
    ];
}
