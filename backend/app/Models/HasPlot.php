<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HasPlot extends Model
{
    use HasFactory;

    protected $table = 'has_plot';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'fk_plot_id',
        'fk_owner_id',
        'fk_profile_id',
    ];

    protected function casts(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::created(function (HasPlot $hasPlot): void {
            Plot::query()
                ->whereKey($hasPlot->fk_plot_id)
                ->whereNull('garden_owner_id')
                ->update(['garden_owner_id' => $hasPlot->fk_owner_id]);
        });
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'fk_plot_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'fk_owner_id', 'id_user');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fk_owner_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fk_profile_id');
    }
}
