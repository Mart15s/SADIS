<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'garden_owner_id',
        'name',
        'city',
        'plot_size',
        'creation_date',
        'description',
        'share',
        'geometry',
    ];

    protected function casts(): array
    {
        return [
            'plot_size' => 'decimal:2',
            'creation_date' => 'date',
            'share' => 'boolean',
            'geometry' => 'array',
        ];
    }

    public function plantZones(): HasMany
    {
        return $this->hasMany(PlantZone::class, 'plot_id');
    }

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class, 'fk_plot_id');
    }

    public function rotationHistory(): HasMany
    {
        return $this->hasMany(RotationHistory::class, 'fk_plot_id');
    }

    public function rotationPlanDrafts(): HasMany
    {
        return $this->hasMany(RotationPlanDraft::class, 'plot_id');
    }

    public function rotationHistoryViaZone(): HasMany
    {
        return $this->hasMany(RotationHistory::class, 'fk_plot_via_zone');
    }

    public function taskCalendars(): HasMany
    {
        return $this->hasMany(TaskCalendar::class, 'plot_id');
    }

    public function harvestRecords(): HasMany
    {
        return $this->hasMany(HarvestRecord::class, 'plot_id');
    }

    public function accessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'plot_id');
    }

    public function communityPosts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'plot_id');
    }

    public function gardenOwner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(GardenOwner::class, 'has_plot', 'fk_plot_id', 'fk_owner_id', 'id', 'id_user')
            ->withPivot('fk_profile_id');
    }

    public function plotLinks(): HasMany
    {
        return $this->hasMany(HasPlot::class, 'fk_plot_id');
    }
}
