<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

    public const REQUEST_SECONDS = 120;

    /**
     * Set on the device that enrolled, so signing out there can warn that it ends the key. It
     * only words the warning: who can sign in is the remember cookie's business, never this.
     */
    public const COOKIE = 'phone_key';

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
     * A sign-in a computer asks for and a key approves. The computer keeps the token in its
     * own session and finishes the sign-in itself, so a photo of its QR code lets nobody else
     * in: the photographer's phone is not a key, and their browser does not hold the session.
     *
     * @return array{0: string, 1: array{code: string, device: string, ip: ?string, status: string, user_id: ?int, expires_at: int}}
     */
    public static function request(Request $request): array
    {
        $token = Str::random(40);
        $expires = now()->addSeconds(self::REQUEST_SECONDS);

        $entry = [
            // Shown on both screens, so a code someone sent you to approve does not match yours.
            'code' => sprintf('%02d', random_int(0, 99)),
            'device' => self::device((string) $request->userAgent()),
            'ip' => $request->ip(),
            'status' => 'pending',
            'user_id' => null,
            'expires_at' => $expires->getTimestamp(),
        ];

        Cache::put(self::key('login', $token), $entry, $expires);

        return [$token, $entry];
    }

    public static function pending(string $token): ?array
    {
        return Cache::get(self::key('login', $token));
    }

    /**
     * Approve or deny once: a second answer, or one after the code expired, changes nothing.
     */
    public static function answer(string $token, User $user, bool $approve): bool
    {
        $entry = self::pending($token);

        if ($entry === null || $entry['status'] !== 'pending') {
            return false;
        }

        $entry['status'] = $approve ? 'approved' : 'denied';
        $entry['user_id'] = $approve ? $user->id : null;

        Cache::put(self::key('login', $token), $entry, Carbon::createFromTimestamp($entry['expires_at']));

        return true;
    }

    /**
     * The user an approved request signs in, spending it.
     */
    public static function claim(string $token): ?User
    {
        $entry = self::pending($token);

        if ($entry === null || $entry['status'] !== 'approved') {
            return null;
        }

        Cache::forget(self::key('login', $token));

        return User::find($entry['user_id']);
    }

    /**
     * An SVG data URI, for an img rather than v-html.
     */
    public static function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd)))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Enough of the user agent to tell the computer on the phone's screen from one that is not
     * yours. Android before Linux and iOS before macOS, since each agent names the other too.
     */
    private static function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : "{$browser} on {$system}";
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
