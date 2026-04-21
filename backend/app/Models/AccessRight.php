<?php

namespace App\Models;

use App\Enums\AccessRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRight extends Model
{
    use HasFactory;

    protected $table = 'access_rights';

    public $timestamps = false;

    protected $fillable = [
        'granted_at',
        'role',
        'garden_owner_id',
        'plot_id',
        'fk_plot_id',
        'fk_grantor_owner_id',
        'fk_grantor_profile_id',
        'fk_recipient_owner_id',
        'fk_recipient_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'role' => AccessRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AccessRight $accessRight): void {
            $accessRight->plot_id ??= $accessRight->fk_plot_id;
            $accessRight->fk_plot_id ??= $accessRight->plot_id;
            $accessRight->garden_owner_id ??= $accessRight->fk_recipient_owner_id;
        });
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'plot_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'fk_grantor_owner_id', 'id_user');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }

    public function grantorGardenOwner(): BelongsTo
    {
        return $this->grantor();
    }

    public function recipientGardenOwner(): BelongsTo
    {
        return $this->recipient();
    }

    public function grantorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fk_grantor_owner_id');
    }

    public function grantorProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fk_grantor_profile_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fk_recipient_owner_id');
    }

    public function recipientProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fk_recipient_profile_id');
    }
}
