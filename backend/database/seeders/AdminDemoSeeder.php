<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminDemoSeeder extends Seeder
{
    private const EMAIL = 'admin@gmail.com';

    private const PASSWORD = 'password';

    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->updateOrCreate(
                ['email' => self::EMAIL],
                [
                    'password' => self::PASSWORD,
                    'role' => UserRole::Admin,
                ],
            );

            $profile = Profile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => 'Administratorius',
                    'surname' => 'Demo',
                    'last_login' => null,
                ],
            );

            GardenOwner::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'id' => $user->id,
                    'id_user' => $user->id,
                    'fk_profile_id' => $profile->id,
                ],
            );
        });

        $this->command?->info('Demo administrator account seeded.');
    }
}
