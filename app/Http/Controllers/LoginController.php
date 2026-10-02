<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('login');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Not logout(): it cycles the remember token, which every device shares, so signing
        // out here would quietly stop the phone being a key once its session idled out.
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
