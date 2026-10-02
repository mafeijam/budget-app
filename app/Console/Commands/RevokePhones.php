<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Signs every device out, for a lost phone. The auth.session middleware compares each session
 * and remember cookie with the password hash, so replacing the password ends them all on their
 * next request; the new remember token stops a cookie from signing back in.
 */
class RevokePhones extends Command
{
    protected $signature = 'login:revoke';

    protected $description = 'Sign every device out; enrol a phone again afterwards';

    public function handle(): int
    {
        User::query()->each(function (User $user) {
            $user->password = Str::random(64);
            $user->setRememberToken(Str::random(60));
            $user->save();
        });

        $this->info('Every device is signed out. Run login:enrol to make a phone a key again.');

        return self::SUCCESS;
    }
}
