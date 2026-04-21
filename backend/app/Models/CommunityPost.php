<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityPost extends Model
{
    use HasFactory;

    protected $table = 'community_posts';

    public $timestamps = false;

    protected $fillable = [
        'garden_owner_id',
        'plot_id',
        'name',
        'text',
        'share',
        'created_at',
        'fk_owner_id',
        'fk_profile_id',
        'fk_plot_id',
    ];

    protected function casts(): array
    {
        return [
            'share' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CommunityPost $communityPost): void {
            $communityPost->garden_owner_id ??= $communityPost->fk_owner_id;
            $communityPost->plot_id ??= $communityPost->fk_plot_id;
            $communityPost->fk_plot_id ??= $communityPost->plot_id;
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fk_owner_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fk_profile_id');
    }

    public function ownerProfile(): BelongsTo
    {
        return $this->profile();
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'plot_id');
    }
}
