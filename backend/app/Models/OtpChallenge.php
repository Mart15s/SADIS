<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OtpChallenge extends YavaModel
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $hidden = ['code_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'resend_available_at' => 'datetime', 'verified_at' => 'datetime']; }
}
