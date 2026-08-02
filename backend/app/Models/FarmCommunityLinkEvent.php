<?php

namespace App\Models;

class FarmCommunityLinkEvent extends YavaModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
