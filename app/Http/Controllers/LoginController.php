<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PhoneKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    private const SESSION_KEY = 'phone_key.request';

    /**
     * The page polls this, so it keeps the code it showed until the code expires or is denied,
     * and only then shows a new one.
     */
    public function show(Request $request): Response
    {
        // The user is made by the first login:enrol, so none means no phone yet, and a QR code
        // nothing can approve would only look broken.
        if (! User::query()->exists()) {
            return Inertia::render('login', ['enrolled' => false]);
        }

        $token = $request->session()->get(self::SESSION_KEY);
        $entry = $token === null ? null : PhoneKey::pending($token);
        $denied = ($entry['status'] ?? null) === 'denied';

        if ($entry === null || $denied) {
            [$token, $entry] = PhoneKey::request($request);
            $request->session()->put(self::SESSION_KEY, $token);
        }

        return Inertia::render('login', [
            'enrolled' => true,
            'qr' => PhoneKey::qr(PhoneKey::url(route('approve', $token, false), $request->root())),
            'code' => $entry['code'],
            'status' => $entry['status'],
            // Seconds rather than a time, so the page's clock disagreeing with this one moves nothing.
            'expiresIn' => max(0, $entry['expires_at'] - now()->getTimestamp()),
            'denied' => $denied,
        ]);
    }

    /**
     * Finishes a sign-in a key approved. A POST the page sends once it sees the approval, so
     * nothing signs in on a GET.
     */
    public function store(Request $request): RedirectResponse
    {
        $token = $request->session()->get(self::SESSION_KEY);
        $user = $token === null ? null : PhoneKey::claim($token);

        if ($user === null) {
            return to_route('login');
        }

        $request->session()->forget(self::SESSION_KEY);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Not logout(): it cycles the remember token, which every device shares, so signing
        // out here would quietly stop the phone being a key once its session idled out.
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        PhoneKey::withdraw($request);
        Cookie::queue(Cookie::forget(PhoneKey::COOKIE));

        return to_route('login');
    }
}
