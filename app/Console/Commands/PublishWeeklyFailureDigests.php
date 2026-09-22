<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WeeklyDigestPublisher;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;

final class PublishWeeklyFailureDigests extends SystemAuthorityCommand
{
    protected $signature = 'digests:publish-weekly';

    protected $description = 'Publish pending weekly digest deliveries to the configured queue.';

    public function handle(WeeklyDigestPublisher $publisher): int
    {
        $published = $publisher->publishEligible();

        $this->info("Published {$published} weekly failure digest job(s).");

        return self::SUCCESS;
    }
}
