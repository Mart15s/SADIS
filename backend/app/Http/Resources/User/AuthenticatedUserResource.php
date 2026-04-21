<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->relationLoaded('profile') ? $this->profile : null;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role?->value ?? $this->role,
            'profile' => [
                'name' => $profile?->name,
                'surname' => $profile?->surname,
            ],
        ];
    }
}
