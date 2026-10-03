<?php

namespace App\Http\Controllers;

use App\Support\PhoneKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a key opens from a computer's QR code. Viewing it changes nothing, for the same
 * reason an enrol link's does not: a camera or a chat app fetches a link to preview it.
 */
class ApproveController extends Controller
{
    /**
     * Open to a phone that is not a key, which is told so, rather than sent to a sign-in page
     * that would show it a QR code of its own to scan.
     */
    public function show(Request $request, string $token): Response
    {
        $isKey = PhoneKey::holds($request);
        $entry = $isKey ? PhoneKey::pending($token) : null;

        return Inertia::render('approve', [
            'token' => $token,
            'isKey' => $isKey,
            'request' => $entry === null ? null : [
                'code' => $entry['code'],
                'device' => $entry['device'],
                'ip' => $entry['ip'],
                'status' => $entry['status'],
            ],
        ]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        $approve = $request->validate(['approve' => ['required', 'boolean']])['approve'];

        // Signed in is not enough: the page this returns to tells a device that is not a key so.
        if (! PhoneKey::holds($request)) {
            return to_route('approve', $token);
        }

        PhoneKey::answer($token, $request->user(), (bool) $approve);

        return to_route('approve', $token);
    }
}
