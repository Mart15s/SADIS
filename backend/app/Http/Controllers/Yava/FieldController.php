<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\Field;
use App\Models\FieldMarker;
use App\Models\FieldZone;
use App\Services\Yava\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FieldController extends Controller
{
    public function index(Request $request, PermissionService $permissions)
    {
        $farmId = $request->integer('farm_id');
        if ($farmId) {
            $permissions->authorizeFarm($request->user(), $farmId, 'view_farm');
            $query = Field::query()->where('farm_id', $farmId);
        } else {
            $query = Field::query()->whereHas('farm.memberships', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active'));
        }

        return response()->json(['data' => $query->withCount('zones')->orderBy('name')->get()]);
    }

    public function store(Request $request, PermissionService $permissions)
    {
        $data = $request->validate($this->rules());
        $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], 'manage_fields');
        $field = Field::query()->create($data);

        return response()->json(['data' => $field], 201);
    }

    public function show(Request $request, Field $field, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'view_farm');

        return response()->json(['data' => $field->load(['zones', 'markers'])]);
    }

    public function update(Request $request, Field $field, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'manage_fields');
        $data = $request->validate($this->rules(true));
        if (isset($data['farm_id']) && (int) $data['farm_id'] !== (int) $field->farm_id) {
            throw ValidationException::withMessages(['farm_id' => ['Fields cannot be moved between farms.']]);
        }
        unset($data['farm_id']);
        $field->update($data);

        return response()->json(['data' => $field->fresh(['zones', 'markers'])]);
    }

    public function destroy(Request $request, Field $field, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'manage_fields');
        abort_if($field->cropSeasons()->whereIn('status', ['planned', 'active'])->exists(), 422, 'A field with active crop seasons cannot be deleted.');
        $field->delete();

        return response()->json(null, 204);
    }

    public function storeZone(Request $request, Field $field, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'manage_fields');
        $zone = $field->zones()->create($request->validate($this->zoneRules()));

        return response()->json(['data' => $zone], 201);
    }

    public function updateZone(Request $request, Field $field, FieldZone $zone, PermissionService $permissions)
    {
        abort_unless($zone->field_id === $field->id, 404);
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'manage_fields');
        $zone->update($request->validate($this->zoneRules(true)));

        return response()->json(['data' => $zone->fresh()]);
    }

    public function destroyZone(Request $request, Field $field, FieldZone $zone, PermissionService $permissions)
    {
        abort_unless($zone->field_id === $field->id, 404);
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'manage_fields');
        abort_if($zone->is_whole_field, 422, 'The whole-field zone cannot be deleted.');
        $zone->delete();

        return response()->json(null, 204);
    }

    public function workspace(Request $request, Field $field, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $field->farm_id, 'manage_fields');
        $data = $request->validate([
            'geometry' => ['nullable', 'array'], 'boundary' => ['nullable', 'array'], 'client_revision' => ['required', 'integer', 'min:0'],
            'zones' => ['present', 'array'], 'zones.*.id' => ['nullable'],
            'zones.*.name' => ['required', 'string', 'max:255'], 'zones.*.area_square_metres' => ['sometimes', 'numeric', 'min:0'],
            'zones.*.boundary' => ['nullable', 'array'], 'zones.*.colour' => ['nullable', 'string', 'max:20'],
            'markers' => ['present', 'array'], 'markers.*.id' => ['nullable'],
            'markers.*.field_zone_id' => ['nullable'], 'markers.*.type' => ['required', 'string', 'max:50'],
            'markers.*.label' => ['nullable', 'string', 'max:255'], 'markers.*.latitude' => ['nullable', 'numeric'],
            'markers.*.longitude' => ['nullable', 'numeric'], 'markers.*.position' => ['nullable', 'array'],
            'markers.*.metadata' => ['nullable', 'array'],
        ]);

        $saved = DB::transaction(function () use ($field, $data, $request): Field {
            $locked = Field::query()->lockForUpdate()->findOrFail($field->id);
            if ((int) $locked->workspace_revision !== (int) $data['client_revision']) {
                throw new ConflictHttpException('The field workspace changed elsewhere. Reload it before saving again.');
            }
            $locked->update(['boundary' => $data['boundary'] ?? $data['geometry'] ?? null, 'workspace_revision' => $locked->workspace_revision + 1]);
            $zoneIds = [];
            $zoneIdMap = [];
            foreach ($data['zones'] as $zoneData) {
                $clientId = $zoneData['id'] ?? null;
                $zone = $clientId !== null && ctype_digit((string) $clientId)
                    ? FieldZone::query()->where('field_id', $locked->id)->findOrFail($zoneData['id'])
                    : new FieldZone(['field_id' => $locked->id]);
                $zone->fill(collect($zoneData)->except('id')->all())->save();
                $zoneIds[] = $zone->id;
                if ($clientId !== null) {
                    $zoneIdMap[(string) $clientId] = $zone->id;
                }
            }
            FieldZone::query()->where('field_id', $locked->id)->where('is_whole_field', false)->whereNotIn('id', $zoneIds ?: [0])->delete();
            $markerIds = [];
            foreach ($data['markers'] as $markerData) {
                $markerId = $markerData['id'] ?? null;
                $marker = $markerId !== null && ctype_digit((string) $markerId)
                    ? FieldMarker::query()->where('field_id', $locked->id)->findOrFail($markerData['id'])
                    : new FieldMarker(['field_id' => $locked->id]);
                if (isset($markerData['field_zone_id'], $zoneIdMap[(string) $markerData['field_zone_id']])) {
                    $markerData['field_zone_id'] = $zoneIdMap[(string) $markerData['field_zone_id']];
                }
                if (! empty($markerData['field_zone_id'])) {
                    $belongsToField = FieldZone::query()->where('field_id', $locked->id)
                        ->whereKey($markerData['field_zone_id'])->exists();
                    if (! $belongsToField) {
                        throw ValidationException::withMessages([
                            'markers' => ['Every marker zone must belong to this field.'],
                        ]);
                    }
                }
                $marker->fill(collect($markerData)->except('id')->all())->save();
                $markerIds[] = $marker->id;
            }
            FieldMarker::query()->where('field_id', $locked->id)->whereNotIn('id', $markerIds ?: [0])->delete();
            DB::table('planning_history')->insert([
                'farm_id' => $locked->farm_id, 'field_id' => $locked->id, 'actor_user_id' => $request->user()->id,
                'event' => 'workspace_saved', 'subject_type' => Field::class, 'subject_id' => $locked->id,
                'after' => json_encode(['revision' => $locked->workspace_revision]), 'created_at' => now(),
            ]);

            return $locked->fresh(['zones', 'markers']);
        }, 3);

        return response()->json(['data' => $saved]);
    }

    private function rules(bool $partial = false): array
    {
        return [
            'farm_id' => [$partial ? 'sometimes' : 'required', 'exists:farms,id'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'area_square_metres' => ['sometimes', 'numeric', 'min:0'], 'soil_type' => ['nullable', 'string', 'max:100'],
            'boundary' => ['nullable', 'array'], 'status' => ['sometimes', 'in:active,inactive,archived'],
        ];
    }

    private function zoneRules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'area_square_metres' => ['sometimes', 'numeric', 'min:0'], 'boundary' => ['nullable', 'array'],
            'colour' => ['nullable', 'string', 'max:20'], 'is_whole_field' => ['sometimes', 'boolean'],
        ];
    }
}
