<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\GlobalRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_new_registrations_default_to_ordinary_active_user(): void
    {
        $this->post('/register', [
            'name' => 'Plain User',
            'email' => 'plain@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'plain@example.com')->firstOrFail();
        $this->assertSame(GlobalRole::User, $user->global_role);
        $this->assertSame(AccountStatus::Active, $user->account_status);
    }
}
