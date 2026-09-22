<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\BuiltForCloud\User;

final class CurrentWeeklyDigestRecipient
{
    public function forUser(int $userId): ?string
    {
        $user = User::query()
            ->select(['id', 'email', 'status', 'email_is_generated'])
            ->find($userId);

        if ($user === null
            || $user->status !== 'active'
            || $user->email_is_generated
            || filter_var($user->email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $user->email;
    }
}
