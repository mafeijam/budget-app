<?php

namespace App\Http\Controllers;

use App\Support\PhoneKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class EnrolController extends Controller
{
    /**
     * Offers the button and spends nothing: a chat app or a camera opens a link to draw its
     * preview, and a GET that enrolled would spend the link before the phone got to it.
     */
    public function show(string $token): Response
    {
        return Inertia::render('enrol', [
            'token' => $token,
            'valid' => PhoneKey::enrolling($token) !== null,
            'minutes' => PhoneKey::ENROL_MINUTES,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $user = PhoneKey::redeem($token);

        if ($user === null) {
            return to_route('enrol', $token);
        }

        // Remembered, because a phone that has to sign in again is no longer a key.
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        Cookie::queue(Cookie::forever(PhoneKey::COOKIE, '1'));

        return redirect()->intended(route('home'));
    }
}
