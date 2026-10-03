<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PhoneKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A computer signs in by showing a QR code that a key, a phone already signed in, approves.
 *
 * Both devices share the test client, so the phone is a guard set with actingAs(), which writes
 * nothing to the session, and the computer is the session, which forgetGuards() returns to.
 */
class PhoneApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Jo', 'password' => 'random']);
        $this->key = PhoneKey::issue($this->user);
    }

    public function test_with_no_phone_enrolled_there_is_no_code_to_scan(): void
    {
        $this->user->delete();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('login')
            ->where('enrolled', false)
            ->missing('qr'));
    }

    public function test_the_sign_in_page_keeps_its_code_while_it_polls(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('enrolled', true)
            ->where('status', 'pending')
            ->where('expiresIn', PhoneKey::REQUEST_SECONDS)
            ->where('qr', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml;base64,')));
        $token = $this->computerToken();

        $this->get('/login');

        $this->assertSame($token, $this->computerToken());
    }

    public function test_an_expired_code_is_replaced(): void
    {
        $this->get('/login');
        $token = $this->computerToken();

        $this->travel(PhoneKey::REQUEST_SECONDS + 1)->seconds();
        $this->get('/login');

        $this->assertNotSame($token, $this->computerToken());
    }

    public function test_an_approved_computer_signs_in(): void
    {
        $this->get('/forecast');
        $this->get('/login');
        $token = $this->computerToken();

        $this->asThePhone()->post("/approve/{$token}", ['approve' => true])->assertRedirect("/approve/{$token}");

        $this->asTheComputer()->get('/login')->assertInertia(fn (Assert $page) => $page->where('status', 'approved'));
        $this->post('/login')->assertRedirect('/forecast')->assertCookieMissing(PhoneKey::COOKIE);

        $this->assertAuthenticatedAs($this->user);
        $this->assertNull(PhoneKey::pending($token), 'a sign-in spends its code');
    }

    public function test_nothing_signs_in_before_the_phone_approves(): void
    {
        $this->get('/login');

        $this->post('/login')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_only_the_browser_that_showed_the_code_can_finish_the_sign_in(): void
    {
        $this->get('/login');
        $token = $this->computerToken();
        $this->asThePhone()->post("/approve/{$token}", ['approve' => true]);

        // Another browser, which has the token from a photo of the code but not the session.
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['session']->flush();

        $this->post('/login')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame('approved', PhoneKey::pending($token)['status']);
    }

    public function test_a_denied_computer_does_not_sign_in_and_gets_a_new_code(): void
    {
        $this->get('/login');
        $token = $this->computerToken();

        $this->asThePhone()->post("/approve/{$token}", ['approve' => false]);

        $this->asTheComputer()->post('/login')->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('denied', true)
            ->where('status', 'pending'));
        $this->assertNotSame($token, $this->computerToken());
    }

    public function test_a_code_is_answered_once(): void
    {
        $this->get('/login');
        $token = $this->computerToken();

        $this->asThePhone()->post("/approve/{$token}", ['approve' => true]);
        $this->post("/approve/{$token}", ['approve' => false]);

        $this->assertSame('approved', PhoneKey::pending($token)['status']);
    }

    public function test_an_expired_code_cannot_be_approved(): void
    {
        $this->get('/login');
        $token = $this->computerToken();

        $this->travel(PhoneKey::REQUEST_SECONDS + 1)->seconds();

        $this->asThePhone()->post("/approve/{$token}", ['approve' => true]);
        $this->get("/approve/{$token}")->assertInertia(fn (Assert $page) => $page->where('request', null));

        $this->asTheComputer()->post('/login')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_opening_the_code_on_the_phone_changes_nothing(): void
    {
        $this->get('/login', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36']);
        $token = $this->computerToken();
        $code = PhoneKey::pending($token)['code'];

        $this->asThePhone()->get("/approve/{$token}")->assertInertia(fn (Assert $page) => $page
            ->component('approve')
            ->where('isKey', true)
            ->where('request.device', 'Chrome on Windows')
            ->where('request.code', $code)
            ->where('request.status', 'pending'));

        $this->assertSame('pending', PhoneKey::pending($token)['status']);
    }

    public function test_a_phone_that_is_not_a_key_cannot_approve_or_see_the_request(): void
    {
        $this->get('/login');
        $token = $this->computerToken();

        $this->get("/approve/{$token}")->assertInertia(fn (Assert $page) => $page
            ->where('isKey', false)
            ->where('request', null));
        $this->post("/approve/{$token}", ['approve' => true])->assertRedirect('/login');

        $this->assertSame('pending', PhoneKey::pending($token)['status']);
    }

    public function test_a_computer_the_key_signed_in_cannot_approve_another(): void
    {
        $this->get('/login');
        $this->asThePhone()->post("/approve/{$this->computerToken()}", ['approve' => true]);
        $this->asTheComputer()->post('/login');
        $this->assertAuthenticatedAs($this->user);

        $token = $this->anotherComputersToken();

        $this->get("/approve/{$token}")->assertInertia(fn (Assert $page) => $page
            ->where('isKey', false)
            ->where('request', null));
        $this->post("/approve/{$token}", ['approve' => true])->assertRedirect("/approve/{$token}");

        $this->assertSame('pending', PhoneKey::pending($token)['status']);
    }

    public function test_a_key_cookie_the_server_did_not_issue_is_not_a_key(): void
    {
        $token = $this->anotherComputersToken();

        $this->actingAs($this->user)->withCookie(PhoneKey::COOKIE, '1')
            ->post("/approve/{$token}", ['approve' => true]);

        $this->assertSame('pending', PhoneKey::pending($token)['status']);
    }

    public function test_a_revoked_key_cannot_approve_even_when_signed_in_again(): void
    {
        $this->artisan('login:revoke')->assertSuccessful();
        $token = $this->anotherComputersToken();

        $this->asThePhone()->get("/approve/{$token}")->assertInertia(fn (Assert $page) => $page->where('isKey', false));
        $this->post("/approve/{$token}", ['approve' => true]);

        $this->assertSame('pending', PhoneKey::pending($token)['status']);
    }

    public function test_the_phone_is_told_which_device_asked(): void
    {
        $agents = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' => 'Safari on iOS',
            'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36' => 'Chrome on Android',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15' => 'Safari on macOS',
            'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0' => 'Firefox on Linux',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 Edg/140.0' => 'Edge on Windows',
        ];

        foreach ($agents as $agent => $device) {
            $this->flushSession();
            $this->app['session']->flush();

            $this->get('/login', ['User-Agent' => $agent]);

            $this->assertSame($device, PhoneKey::pending($this->computerToken())['device']);
        }
    }

    private function computerToken(): string
    {
        return session('phone_key.request');
    }

    private function asThePhone(): static
    {
        return $this->actingAs($this->user)->withCookie(PhoneKey::COOKIE, $this->key);
    }

    private function asTheComputer(): static
    {
        Auth::forgetGuards();
        $this->defaultCookies = [];

        return $this;
    }

    /**
     * A code shown by some other browser, which the test client's session knows nothing of.
     */
    private function anotherComputersToken(): string
    {
        return PhoneKey::request(Request::create('/login'))[0];
    }
}
