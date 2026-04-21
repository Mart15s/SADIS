<?php

namespace App\Http\Resources\Plot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessRightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recipient = $this->relationLoaded('recipient') ? $this->recipient : null;
        $profile = $recipient?->relationLoaded('profile') ? $recipient->profile : null;
        $user = $recipient?->relationLoaded('user') ? $recipient->user : null;

        return [
            'access_right_id' => $this->id,
            'user_id' => $user?->id,
            'name' => trim(implode(' ', array_filter([
                $profile?->name,
                $profile?->surname,
            ]))),
            'email' => $user?->email,
            'role' => $this->role?->value ?? $this->role,
            'granted_at' => $this->granted_at?->toISOString(),
        ];
    }
}
