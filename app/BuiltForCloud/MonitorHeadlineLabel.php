<?php

declare(strict_types=1);

namespace App\BuiltForCloud;

use ArtisanBuild\BuiltForCloud\Vitals\HeadlineLabel;

enum MonitorHeadlineLabel: string implements HeadlineLabel
{
    case EnabledMonitors = 'enabled-monitors';
}
