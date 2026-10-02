<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A phone is the only key: there is no password anyone knows. A phone becomes one by opening a
 * link `login:enrol` prints on the server, which signs it in with a remember cookie, and that
 * standing sign-in is what lets it approve others.
 *
 * Tokens live in the cache under their hash, so a dump of the cache holds nothing that opens a
 * link, and they expire there on their own.
 */
class PhoneKey
{
    public const ENROL_MINUTES = 10;

    public static function enrolment(User $user): string
    {
        $token = Str::random(40);

        Cache::put(self::key('enrol', $token), $user->id, now()->addMinutes(self::ENROL_MINUTES));

        return $token;
    }

    /**
     * Who a link would enrol, without spending it, for the page that offers the button.
     */
    public static function enrolling(string $token): ?User
    {
        $id = Cache::get(self::key('enrol', $token));

        return $id === null ? null : User::find($id);
    }

    public static function redeem(string $token): ?User
    {
        $key = self::key('enrol', $token);

        // add() is atomic where a get then a forget is not, so two taps cannot both sign in.
        if (! Cache::add("{$key}:spent", true, now()->addMinutes(self::ENROL_MINUTES))) {
            return null;
        }

        $id = Cache::pull($key);

        return $id === null ? null : User::find($id);
    }

    /**
     * Where a phone is sent. The phone resolves this, not the server: `localhost` or a `.test`
     * name the computer knows reaches nothing from a phone, which is what QR_LOGIN_URL is for.
     */
    public static function url(string $path, ?string $root = null): string
    {
        return rtrim(config('auth.key_url') ?: $root ?: config('app.url'), '/').$path;
    }

    /**
     * A QR code for a terminal, dark on light whatever the terminal's own colours: a scanner
     * reads the light-on-dark one a dark terminal draws unreliably or not at all.
     */
    public static function terminal(string $url): string
    {
        // Every line ends in a newline, so the last piece is empty; the blank ones before it
        // are the quiet zone and stay.
        $lines = explode("\n", (new Writer(new PlainTextRenderer(2)))->writeString($url));
        array_pop($lines);
        $width = max(array_map('mb_strlen', $lines));

        return implode("\n", array_map(
            fn (string $line) => "\e[30;47m".$line.str_repeat("\u{00A0}", $width - mb_strlen($line))."\e[0m",
            $lines,
        ));
    }

    private static function key(string $kind, string $token): string
    {
        return "phone-key:{$kind}:".hash('sha256', $token);
    }
}
