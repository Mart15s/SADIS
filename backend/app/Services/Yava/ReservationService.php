<?php

namespace App\Services\Yava;

use App\Models\ResourceReservation;
use App\Models\SharedResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function request(User $user, SharedResource $resource, array $data): ResourceReservation
    {
        $startsAt = $this->parseReservationTime($data['starts_at'], $resource->timezone);
        $endsAt = $this->parseReservationTime($data['ends_at'], $resource->timezone);
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }

        return DB::transaction(function () use ($user, $resource, $data, $startsAt, $endsAt): ResourceReservation {
            $reservation = ResourceReservation::query()->create([
                'shared_resource_id' => $resource->id, 'requested_by_user_id' => $user->id,
                'farm_id' => $data['farm_id'] ?? null, 'field_id' => $data['field_id'] ?? null, 'status' => 'pending',
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'purpose' => $data['purpose'] ?? null,
            ]);

            return $resource->requires_approval
                ? $reservation
                : $this->decide($user, $reservation, 'approved', 'Automatically approved by resource policy.');
        }, 3);
    }

    public function decide(User $actor, ResourceReservation $reservation, string $status, ?string $notes = null): ResourceReservation
    {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => ['A pending reservation can only be approved or rejected.']]);
        }

        return DB::transaction(function () use ($actor, $reservation, $status, $notes): ResourceReservation {
            // The parent resource row is the per-resource mutex. This serializes
            // approvals on PostgreSQL and keeps the overlap check race-free.
            SharedResource::query()->lockForUpdate()->findOrFail($reservation->shared_resource_id);
            $locked = ResourceReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['This reservation has already been decided.']]);
            }
            if ($status === 'approved') {
                $conflict = ResourceReservation::query()
                    ->where('shared_resource_id', $locked->shared_resource_id)
                    ->where('status', 'approved')
                    ->where('id', '!=', $locked->id)
                    ->where('starts_at', '<', $locked->ends_at)
                    ->where('ends_at', '>', $locked->starts_at)
                    ->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['starts_at' => ['This resource already has an approved reservation in that time range.']]);
                }
            }
            $locked->update([
                'status' => $status, 'decided_by_user_id' => $actor->id,
                'decision_notes' => $notes, 'decided_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function parseReservationTime(string $value, string $timezone): CarbonImmutable
    {
        $value = trim($value);
        $hasExplicitOffset = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;

        return CarbonImmutable::parse($value, $hasExplicitOffset ? null : $timezone)->utc();
    }
}
