<?php

namespace App\Models;

class CommunityInvitation extends YavaModel
{
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
