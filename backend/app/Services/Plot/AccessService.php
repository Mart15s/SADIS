<?php

namespace App\Services\Plot;

use App\Enums\AccessRole;
use App\Models\AccessRight;
use App\Models\GardenOwner;
use App\Models\Plot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessService
{
    private const OWNER_ROLE = 'owner';

    public function sharePlot(GardenOwner $grantor, Plot $plot, GardenOwner $recipient, string $role): AccessRight
    {
        $accessRole = AccessRole::tryFrom($role);

        if (! $accessRole) {
            throw ValidationException::withMessages([
                'role' => ['Nurodyta netinkama prieigos role.'],
            ]);
        }

        if ($this->sameOwner($grantor, $recipient)) {
            throw ValidationException::withMessages([
                'recipient_email' => ['Negalima suteikti prieigos sau.'],
            ]);
        }

        return DB::transaction(function () use ($grantor, $plot, $recipient, $accessRole) {
            $lockedPlot = Plot::query()->whereKey($plot->id)->lockForUpdate()->first();

            if (! $lockedPlot || ! $this->userIsOwner($grantor, $lockedPlot)) {
                throw new AuthorizationException('Tik sklypo savininkas gali suteikti prieiga.');
            }

            if ($this->userIsOwner($recipient, $lockedPlot)
                || $this->sharedAccessQuery($recipient, $lockedPlot)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'recipient_email' => ['Naudotojas jau turi prieiga prie sio sklypo.'],
                ]);
            }

            $accessRight = AccessRight::query()->create([
                'granted_at' => now(),
                'role' => $accessRole->value,
                'garden_owner_id' => $recipient->id,
                'plot_id' => $plot->id,
                'fk_plot_id' => $plot->id,
                'fk_grantor_owner_id' => $grantor->id_user,
                'fk_grantor_profile_id' => $grantor->fk_profile_id,
                'fk_recipient_owner_id' => $recipient->id_user,
                'fk_recipient_profile_id' => $recipient->fk_profile_id,
            ]);

            return $accessRight->fresh(['recipient.user', 'recipient.profile']);
        });
    }

    public function revokeAccess(GardenOwner $grantor, Plot $plot, GardenOwner $recipient): void
    {
        if ($this->sameOwner($grantor, $recipient)) {
            throw ValidationException::withMessages([
                'recipient' => ['Savininko prieigos panaikinti negalima.'],
            ]);
        }

        DB::transaction(function () use ($grantor, $plot, $recipient) {
            $lockedPlot = Plot::query()->whereKey($plot->id)->lockForUpdate()->first();

            if (! $lockedPlot || ! $this->userIsOwner($grantor, $lockedPlot)) {
                throw new AuthorizationException('Tik sklypo savininkas gali panaikinti prieiga.');
            }

            $accessRight = $this->sharedAccessQuery($recipient, $lockedPlot)
                ->lockForUpdate()
                ->first();

            if (! $accessRight) {
                throw ValidationException::withMessages([
                    'recipient' => ['Naudotojui si prieiga nesuteikta.'],
                ]);
            }

            $accessRight->delete();
        });
    }

    public function revokeAccessRight(GardenOwner $grantor, AccessRight $accessRight): void
    {
        DB::transaction(function () use ($grantor, $accessRight) {
            $lockedAccessRight = AccessRight::query()
                ->whereKey($accessRight->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedAccessRight) {
                throw ValidationException::withMessages([
                    'access_right_id' => ['Prieigos irasas nerastas.'],
                ]);
            }

            $plot = Plot::query()->find($lockedAccessRight->fk_plot_id);

            if (! $plot || ! $this->userIsOwner($grantor, $plot)) {
                throw new AuthorizationException('Tik sklypo savininkas gali panaikinti prieiga.');
            }

            $lockedAccessRight->delete();
        });
    }

    public function getUserRoleForPlot(GardenOwner $owner, Plot $plot): string|null
    {
        if ($this->userIsOwner($owner, $plot)) {
            return self::OWNER_ROLE;
        }

        $accessRight = $this->sharedAccessQuery($owner, $plot)->first();

        if (! $accessRight) {
            return null;
        }

        $role = $accessRight->role;

        return $role instanceof AccessRole ? $role->value : (string) $role;
    }

    public function userHasAccess(GardenOwner $owner, Plot $plot): bool
    {
        return $this->userIsOwner($owner, $plot)
            || $this->sharedAccessQuery($owner, $plot)->exists();
    }

    public function userCanEdit(GardenOwner $owner, Plot $plot): bool
    {
        $role = $this->getUserRoleForPlot($owner, $plot);

        return in_array($role, [self::OWNER_ROLE, AccessRole::Editor->value], true);
    }

    public function userIsOwner(GardenOwner $owner, Plot $plot): bool
    {
        return (int) $plot->garden_owner_id === (int) $owner->id;
    }

    /**
     * @return array<int, int>
     */
    public function accessiblePlotIds(GardenOwner $owner): array
    {
        $directPlotIds = Plot::query()
            ->where('garden_owner_id', $owner->id)
            ->pluck('id');

        $sharedPlotIds = AccessRight::query()
            ->where('garden_owner_id', $owner->id)
            ->pluck('plot_id');

        return $directPlotIds
            ->merge($sharedPlotIds)
            ->unique()
            ->values()
            ->map(fn (mixed $plotId) => (int) $plotId)
            ->all();
    }

    private function sharedAccessQuery(GardenOwner $owner, Plot $plot): Builder
    {
        return AccessRight::query()
            ->where('plot_id', $plot->id)
            ->where('garden_owner_id', $owner->id);
    }

    private function sameOwner(GardenOwner $left, GardenOwner $right): bool
    {
        return (int) $left->id === (int) $right->id;
    }
}
