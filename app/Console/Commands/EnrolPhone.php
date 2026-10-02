<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneKey;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a link, and its QR code, that makes the device opening it a key. Here rather than on
 * a page because whoever can run this already owns the server, and a page would let anyone who
 * reached the address enrol a phone of their own.
 *
 * The first run creates the user. Its password is random and never shown: nothing asks for it,
 * and `login:revoke` replaces it to sign every device out.
 */
class EnrolPhone extends Command
{
    protected $signature = 'login:enrol
        {--name= : Your name, when this run creates the user}';

    protected $description = 'Print a one-time link that makes the phone opening it a key';

    public function handle(): int
    {
        $user = User::query()->oldest('id')->first() ?? User::create([
            'name' => $this->option('name') ?: $this->ask('Your name', 'Owner'),
            'password' => Str::random(64),
        ]);

        $url = PhoneKey::url(route('enrol', PhoneKey::enrolment($user), false));

        $this->output->writeln(PhoneKey::terminal($url), OutputInterface::OUTPUT_RAW);
        $this->newLine();
        $this->line($url);
        $this->newLine();
        $this->info('Open it on the phone within '.PhoneKey::ENROL_MINUTES.' minutes. It works once.');

        if (! config('auth.key_url')) {
            $this->warn('QR_LOGIN_URL is not set, so this uses APP_URL. A phone cannot reach localhost or a .test name.');
        }

        return self::SUCCESS;
    }
}
