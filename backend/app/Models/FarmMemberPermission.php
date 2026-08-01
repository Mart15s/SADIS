<?php

namespace App\Models;

class FarmMemberPermission extends YavaModel
{
    protected function casts(): array { return ['allowed' => 'boolean']; }
}
