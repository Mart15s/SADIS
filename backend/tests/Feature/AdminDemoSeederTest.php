<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\AdminDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_demo_seeder_is_rerunnable_and_creates_login_ready_admin(): void
    {
        Artisan::call('db:seed', [
            '--class' => AdminDemoSeeder::class,
        ]);

        Artisan::call('db:seed', [
            '--class' => AdminDemoSeeder::class,
        ]);

        $admin = User::query()
            ->where('email', 'admin@gmail.com')
            ->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, Profile::query()->where('user_id', $admin->id)->count());
        $this->assertSame(1, GardenOwner::query()->where('user_id', $admin->id)->count());
        $this->assertDatabaseHas('profiles', [
            'user_id' => $admin->id,
            'name' => 'Administratorius',
            'surname' => 'Demo',
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@gmail.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.email', 'admin@gmail.com')
            ->assertJsonPath('user.role', UserRole::Admin->value);
    }
}
