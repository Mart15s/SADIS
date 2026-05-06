<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function updateAccount(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user->update([
                'email' => $data['email'],
            ]);

            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => $data['name'],
                    'surname' => $data['surname'],
                ]
            );

            return $user->fresh(['profile']);
        });
    }
}
