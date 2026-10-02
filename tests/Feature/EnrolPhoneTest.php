<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PhoneKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The app opens to nobody until a phone is enrolled, and an enrolled phone stays signed in.
 */
class EnrolPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Jo', 'password' => 'random']);
    }

    public function test_a_signed_out_visit_is_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/transactions')->assertRedirect('/login');
        $this->post('/transactions')->assertRedirect('/login');
        $this->getJson('/forms/transaction')->assertUnauthorized();
    }

    public function test_the_sign_in_page_is_open_to_a_signed_out_visit_only(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('login'));

        $this->actingAs($this->user)->get('/login')->assertRedirect('/');
    }

    public function test_opening_an_enrol_link_spends_nothing(): void
    {
        $token = PhoneKey::enrolment($this->user);

        $this->get("/enrol/{$token}")->assertInertia(fn (Assert $page) => $page
            ->component('enrol')
            ->where('valid', true));
        $this->get("/enrol/{$token}")->assertInertia(fn (Assert $page) => $page->where('valid', true));
        $this->assertGuest();

        $this->post("/enrol/{$token}")->assertRedirect('/');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_enrolling_remembers_the_phone(): void
    {
        $this->post('/enrol/'.PhoneKey::enrolment($this->user))
            ->assertCookie(Auth::guard()->getRecallerName());
    }

    public function test_enrolling_returns_to_the_page_that_asked_for_sign_in(): void
    {
        $this->get('/forecast')->assertRedirect('/login');

        $this->post('/enrol/'.PhoneKey::enrolment($this->user))->assertRedirect('/forecast');
    }

    public function test_an_enrol_link_works_once(): void
    {
        $token = PhoneKey::enrolment($this->user);

        $this->post("/enrol/{$token}");
        $this->signOutEverywhere();

        $this->post("/enrol/{$token}")->assertRedirect("/enrol/{$token}");
        $this->assertGuest();
        $this->get("/enrol/{$token}")->assertInertia(fn (Assert $page) => $page->where('valid', false));
    }

    public function test_an_enrol_link_expires(): void
    {
        $token = PhoneKey::enrolment($this->user);

        $this->travel(PhoneKey::ENROL_MINUTES + 1)->minutes();

        $this->post("/enrol/{$token}")->assertRedirect("/enrol/{$token}");
        $this->assertGuest();
    }

    public function test_an_unknown_link_is_expired(): void
    {
        $this->get('/enrol/nonsense')->assertInertia(fn (Assert $page) => $page->where('valid', false));
        $this->post('/enrol/nonsense')->assertRedirect('/enrol/nonsense');
        $this->assertGuest();
    }

    public function test_signing_out_leaves_the_other_devices_remembered(): void
    {
        $this->user->setRememberToken('shared-by-every-device');
        $this->user->save();

        $this->actingAs($this->user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame('shared-by-every-device', $this->user->fresh()->getRememberToken());
    }

    public function test_a_remembered_phone_signs_back_in_until_revoked(): void
    {
        $name = Auth::guard()->getRecallerName();
        $cookie = $this->post('/enrol/'.PhoneKey::enrolment($this->user))->getCookie($name)->getValue();

        $this->signOutEverywhere();
        $this->withCookie($name, $cookie)->get('/')->assertOk();

        $this->artisan('login:revoke')->assertSuccessful();

        $this->signOutEverywhere();
        $this->withCookie($name, $cookie)->get('/')->assertRedirect('/login');
    }

    public function test_revoking_ends_a_session_that_is_still_open(): void
    {
        $this->post('/enrol/'.PhoneKey::enrolment($this->user));
        $this->get('/')->assertOk();

        $this->artisan('login:revoke')->assertSuccessful();

        // A fresh guard, as the next request would have: this one holds the user it loaded.
        Auth::forgetGuards();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_enrol_command_creates_the_user_once(): void
    {
        $this->user->delete();

        $this->artisan('login:enrol', ['--name' => 'Sam'])
            ->expectsOutputToContain('/enrol/')
            ->assertSuccessful();
        $this->artisan('login:enrol')->assertSuccessful();

        $this->assertSame(['Sam'], User::pluck('name')->all());
    }

    /**
     * As a new request with no session would find things: the test client keeps the guard and
     * the session between requests, which a browser that has lost its session does not.
     */
    private function signOutEverywhere(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['session']->flush();
    }
}
