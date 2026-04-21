<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->relationLoaded('profile') ? $this->profile : null;
        $gardenOwner = $this->relationLoaded('gardenOwner') ? $this->gardenOwner : null;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role?->value ?? $this->role,
            'name' => $profile?->name,
            'surname' => $profile?->surname,
            'created_at' => $this->created_at,
            'profile' => $profile ? [
                'id' => $profile->id,
                'name' => $profile->name,
                'surname' => $profile->surname,
                'last_login' => $profile->last_login,
            ] : null,
            'garden_owner' => $gardenOwner ? [
                'id' => $gardenOwner->id,
                'user_id' => $gardenOwner->user_id,
                'id_user' => $gardenOwner->id_user,
                'fk_profile_id' => $gardenOwner->fk_profile_id,
            ] : null,
        ];
    }
}
