<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('new users reach the dashboard without verification or a verification email', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'new@example.com',
        'email_verified_at' => null,
    ]);
    Notification::assertNothingSent();
    $this->get(route('dashboard'))->assertOk();
});

test('existing unverified users can log in and reach their dashboard', function () {
    $user = User::factory()->unverified()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertOk();
});

test('unverified users can access protected application pages', function (string $routeName) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route($routeName))->assertOk();
})->with(['dashboard', 'customers.index', 'invoices.index', 'payments.index', 'reports.index', 'inventory.index', 'ask.index', 'profile.edit', 'appearance.edit']);

test('protected application pages still require login', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with(['dashboard', 'customers.index', 'invoices.index', 'payments.index', 'reports.index', 'inventory.index', 'ask.index', 'profile.edit', 'appearance.edit']);

test('email verification endpoints are disabled', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/email/verify')->assertNotFound();
    $this->post('/email/verification-notification')->assertNotFound();
    $this->get('/email/verify/'.$user->id.'/'.sha1($user->email))->assertNotFound();
});

test('the legacy insights URL redirects to the reports page while requiring authentication', function () {
    $this->get(route('insights.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('insights.index'))->assertRedirect(route('reports.index'));
});
