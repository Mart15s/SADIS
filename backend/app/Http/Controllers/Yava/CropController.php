<?php

namespace App\Http\Controllers\Yava;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Crop;
use App\Models\CropConditionRecord;
use App\Models\CropHarvest;
use App\Models\CropSeason;
use App\Models\CropVariety;
use App\Models\Field;
use App\Models\FieldZone;
use App\Services\Yava\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CropController extends Controller
{
    public function index(Request $request, PermissionService $permissions)
    {
        $farmId = $request->integer('farm_id');
        if ($farmId) {
            $permissions->authorizeFarm($request->user(), $farmId, 'view_farm');
        }
        $query = Crop::query()->with('varieties')->where(function ($q) use ($farmId): void {
            $q->where('is_global', true);
            if ($farmId) {
                $q->orWhere('farm_id', $farmId);
            }
        });

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function store(Request $request, PermissionService $permissions)
    {
        $data = $request->validate([
            'farm_id' => ['nullable', 'exists:farms,id'], 'name' => ['required', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:100'],
            'is_global' => ['sometimes', 'boolean'],
        ]);
        $global = (bool) ($data['is_global'] ?? false);
        if ($global) {
            abort_unless($request->user()->role === UserRole::Admin, 403, 'Only system administrators can manage the global crop catalogue.');
            $data['farm_id'] = null;
        } else {
            if (! isset($data['farm_id'])) {
                throw ValidationException::withMessages(['farm_id' => ['Farm custom crops must belong to a farm.']]);
            }
            $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], 'manage_crops');
        }
        $crop = Crop::query()->create($data + ['created_by_user_id' => $request->user()->id]);

        return response()->json(['data' => $crop], 201);
    }

    public function show(Request $request, Crop $crop, PermissionService $permissions)
    {
        if (! $crop->is_global) {
            $permissions->authorizeFarm($request->user(), $crop->farm_id, 'view_farm');
        }

        return response()->json(['data' => $crop->load('varieties')]);
    }

    public function update(Request $request, Crop $crop, PermissionService $permissions)
    {
        $this->authorizeCatalogMutation($request, $crop, $permissions);
        $crop->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'scientific_name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
        ]));

        return response()->json(['data' => $crop->fresh()]);
    }

    public function destroy(Request $request, Crop $crop, PermissionService $permissions)
    {
        $this->authorizeCatalogMutation($request, $crop, $permissions);
        abort_if(CropSeason::query()->where('crop_id', $crop->id)->exists(), 422, 'A crop used by crop seasons cannot be deleted.');
        $crop->delete();

        return response()->json(null, 204);
    }

    public function storeVariety(Request $request, Crop $crop, PermissionService $permissions)
    {
        $this->authorizeCatalogMutation($request, $crop, $permissions);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);
        $variety = CropVariety::query()->create($data + ['crop_id' => $crop->id, 'farm_id' => $crop->farm_id, 'is_global' => $crop->is_global]);

        return response()->json(['data' => $variety], 201);
    }

    public function seasons(Request $request, PermissionService $permissions)
    {
        $farmId = $request->integer('farm_id');
        abort_unless($farmId, 422, 'farm_id is required.');
        $permissions->authorizeFarm($request->user(), $farmId, 'view_farm');

        return response()->json(['data' => CropSeason::query()->with(['field', 'crop', 'conditions', 'harvests'])->where('farm_id', $farmId)->orderByDesc('starts_on')->get()]);
    }

    public function storeSeason(Request $request, PermissionService $permissions)
    {
        $data = $request->validate($this->seasonRules());
        $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], 'manage_crops');
        $field = Field::query()->where('farm_id', $data['farm_id'])->findOrFail($data['field_id']);
        $crop = Crop::query()->where(fn ($q) => $q->where('is_global', true)->orWhere('farm_id', $data['farm_id']))->findOrFail($data['crop_id']);
        if (isset($data['field_zone_id'])) {
            FieldZone::query()->where('field_id', $field->id)->findOrFail($data['field_zone_id']);
        }
        if (isset($data['crop_variety_id'])) {
            CropVariety::query()->where('crop_id', $crop->id)->findOrFail($data['crop_variety_id']);
        }
        $season = DB::transaction(function () use ($data, $request): CropSeason {
            $season = CropSeason::query()->create($data + ['created_by_user_id' => $request->user()->id]);
            DB::table('crop_rotation_entries')->insert([
                'field_id' => $season->field_id, 'field_zone_id' => $season->field_zone_id,
                'crop_season_id' => $season->id, 'crop_id' => $season->crop_id,
                'season_year' => (int) $season->starts_on->format('Y'), 'source' => 'crop_season',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('planning_history')->insert([
                'farm_id' => $season->farm_id, 'field_id' => $season->field_id, 'actor_user_id' => $request->user()->id,
                'event' => 'crop_season_created', 'subject_type' => CropSeason::class, 'subject_id' => $season->id,
                'after' => json_encode($season->toArray()), 'created_at' => now(),
            ]);

            return $season;
        }, 3);

        return response()->json(['data' => $season->load(['field', 'crop'])], 201);
    }

    public function showSeason(Request $request, CropSeason $cropSeason, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $cropSeason->farm_id, 'view_farm');

        return response()->json(['data' => $cropSeason->load(['field', 'crop', 'conditions', 'harvests'])]);
    }

    public function updateSeason(Request $request, CropSeason $cropSeason, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $cropSeason->farm_id, 'manage_crops');
        $before = $cropSeason->toArray();
        $data = $request->validate($this->seasonRules(true));
        if (isset($data['farm_id']) && (int) $data['farm_id'] !== (int) $cropSeason->farm_id) {
            throw ValidationException::withMessages(['farm_id' => ['Crop seasons cannot be moved between farms.']]);
        }
        $fieldId = (int) ($data['field_id'] ?? $cropSeason->field_id);
        Field::query()->where('farm_id', $cropSeason->farm_id)->findOrFail($fieldId);
        $fieldZoneId = array_key_exists('field_zone_id', $data) ? $data['field_zone_id'] : $cropSeason->field_zone_id;
        if ($fieldZoneId !== null) {
            $belongsToField = FieldZone::query()->where('field_id', $fieldId)->whereKey($fieldZoneId)->exists();
            if (! $belongsToField) {
                throw ValidationException::withMessages(['field_zone_id' => ['The field zone must belong to the selected field.']]);
            }
        }
        $cropId = (int) ($data['crop_id'] ?? $cropSeason->crop_id);
        Crop::query()->where(fn ($q) => $q->where('is_global', true)->orWhere('farm_id', $cropSeason->farm_id))->findOrFail($cropId);
        $varietyId = array_key_exists('crop_variety_id', $data) ? $data['crop_variety_id'] : $cropSeason->crop_variety_id;
        if ($varietyId !== null) {
            $belongsToCrop = CropVariety::query()->where('crop_id', $cropId)->whereKey($varietyId)->exists();
            if (! $belongsToCrop) {
                throw ValidationException::withMessages(['crop_variety_id' => ['The crop variety must belong to the selected crop.']]);
            }
        }
        unset($data['farm_id']);
        $cropSeason = DB::transaction(function () use ($cropSeason, $data, $before, $request): CropSeason {
            $locked = CropSeason::query()->lockForUpdate()->findOrFail($cropSeason->id);
            $locked->update($data);
            $rotation = [
                'field_id' => $locked->field_id,
                'field_zone_id' => $locked->field_zone_id,
                'crop_id' => $locked->crop_id,
                'season_year' => (int) $locked->starts_on->format('Y'),
                'source' => 'crop_season',
                'updated_at' => now(),
            ];
            $updated = DB::table('crop_rotation_entries')->where('crop_season_id', $locked->id)->update($rotation);
            if ($updated === 0 && ! DB::table('crop_rotation_entries')->where('crop_season_id', $locked->id)->exists()) {
                DB::table('crop_rotation_entries')->insert($rotation + [
                    'crop_season_id' => $locked->id,
                    'created_at' => now(),
                ]);
            }
            DB::table('planning_history')->insert([
                'farm_id' => $locked->farm_id, 'field_id' => $locked->field_id, 'actor_user_id' => $request->user()->id,
                'event' => 'crop_season_updated', 'subject_type' => CropSeason::class, 'subject_id' => $locked->id,
                'before' => json_encode($before), 'after' => json_encode($locked->fresh()->toArray()), 'created_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);

        return response()->json(['data' => $cropSeason]);
    }

    public function destroySeason(Request $request, CropSeason $cropSeason, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $cropSeason->farm_id, 'manage_crops');
        abort_if($cropSeason->harvests()->exists(), 422, 'A crop season with harvest history cannot be deleted. Archive it instead.');
        $cropSeason->delete();

        return response()->json(null, 204);
    }

    public function condition(Request $request, CropSeason $cropSeason, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $cropSeason->farm_id, 'manage_crops');
        $data = $request->validate([
            'condition' => ['required', 'string', 'max:100'], 'severity' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string'], 'observations' => ['nullable', 'array'], 'observed_at' => ['sometimes', 'date'],
        ]);
        $record = CropConditionRecord::query()->create($data + ['crop_season_id' => $cropSeason->id, 'recorded_by_user_id' => $request->user()->id, 'observed_at' => $data['observed_at'] ?? now()]);

        return response()->json(['data' => $record], 201);
    }

    public function harvest(Request $request, CropSeason $cropSeason, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $cropSeason->farm_id, 'manage_crops');
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'], 'unit' => ['required', 'string', 'max:32'],
            'harvested_on' => ['required', 'date'], 'quality_grade' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string'],
        ]);
        $harvest = CropHarvest::query()->create($data + ['crop_season_id' => $cropSeason->id, 'recorded_by_user_id' => $request->user()->id]);

        return response()->json(['data' => $harvest], 201);
    }

    public function rotationWarnings(Request $request, CropSeason $cropSeason, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $cropSeason->farm_id, 'view_farm');
        $previous = DB::table('crop_rotation_entries')->join('crops', 'crops.id', '=', 'crop_rotation_entries.crop_id')
            ->where('crop_rotation_entries.field_id', $cropSeason->field_id)
            ->where('crop_rotation_entries.crop_season_id', '!=', $cropSeason->id)
            ->whereBetween('crop_rotation_entries.season_year', [(int) $cropSeason->starts_on->format('Y') - 3, (int) $cropSeason->starts_on->format('Y')])
            ->where('crops.category', $cropSeason->crop->category)->exists();

        return response()->json(['data' => $previous ? [[
            'severity' => 'warning', 'code' => 'recent_same_crop_family',
            'message' => 'This field recently grew a crop from the same category. Review rotation and soil-health risks.',
        ]] : []]);
    }

    private function authorizeCatalogMutation(Request $request, Crop $crop, PermissionService $permissions): void
    {
        if ($crop->is_global) {
            abort_unless($request->user()->role === UserRole::Admin, 403, 'Only system administrators can manage the global crop catalogue.');
        } else {
            $permissions->authorizeFarm($request->user(), $crop->farm_id, 'manage_crops');
        }
    }

    private function seasonRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'farm_id' => [$required, 'exists:farms,id'], 'field_id' => [$required, 'exists:fields,id'],
            'field_zone_id' => ['nullable', 'exists:field_zones,id'], 'crop_id' => [$required, 'exists:crops,id'],
            'crop_variety_id' => ['nullable', 'exists:crop_varieties,id'], 'name' => ['nullable', 'string', 'max:255'],
            'starts_on' => [$required, 'date'], 'expected_ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'ended_on' => ['nullable', 'date'], 'planted_area_square_metres' => ['nullable', 'numeric', 'gt:0'],
            'status' => ['sometimes', 'in:planned,active,harvested,completed,cancelled'], 'notes' => ['nullable', 'string'],
        ];
    }
}
