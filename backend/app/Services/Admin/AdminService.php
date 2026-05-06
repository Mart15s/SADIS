<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\InventoryItem;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminService
{
    /**
     * @return Collection<int, User>
     */
    public function listUsers(array $filters = []): Collection
    {
        return User::query()
            ->with(['profile', 'gardenOwner.profile'])
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where('email', 'like', "%{$search}%")
                        ->orWhereHas('profile', function ($profileQuery) use ($search) {
                            $profileQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('surname', 'like', "%{$search}%");
                        });
                });
            })
            ->when(filled($filters['role'] ?? null), fn ($query) => $query->where('role', $filters['role']))
            ->orderBy('id')
            ->get();
    }

    public function getUser(int $id): User
    {
        return User::query()
            ->with(['profile', 'gardenOwner.profile'])
            ->findOrFail($id);
    }

    public function updateUserRole(User $user, string $role): User
    {
        $roleEnum = UserRole::tryFrom($role);

        if (! $roleEnum) {
            throw ValidationException::withMessages([
                'role' => ['Nurodyta netinkama naudotojo role.'],
            ]);
        }

        if ((int) Auth::id() === (int) $user->id && $roleEnum !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'role' => ['Administratorius negali panaikinti savo administratoriaus teisiu.'],
            ]);
        }

        $previousRole = $user->role?->value;

        $user->update([
            'role' => $roleEnum,
        ]);

        AuditLog::query()->create([
            'admin_user_id' => Auth::id(),
            'action' => 'role_changed',
            'target_user_id' => $user->id,
            'context' => [
                'from' => $previousRole,
                'to' => $roleEnum->value,
            ],
            'created_at' => now(),
        ]);

        return $user->fresh(['profile', 'gardenOwner.profile']);
    }

    public function deleteUser(User $user): void
    {
        if ((int) Auth::id() === (int) $user->id) {
            throw ValidationException::withMessages([
                'user' => ['Administratorius negali pasalinti savo paskyros.'],
            ]);
        }

        $user->loadMissing(['profile', 'gardenOwner']);

        $owner = $user->gardenOwner;
        $profile = $user->profile;
        $plotIds = [];
        $inventoryItemIds = [];

        if ($owner) {
            $plotIds = Plot::query()
                ->where('garden_owner_id', $owner->id)
                ->pluck('id')
                ->unique()
                ->all();

            $inventoryItemIds = InventoryItem::query()
                ->where('garden_owner_id', $owner->id)
                ->pluck('id')
                ->unique()
                ->all();
        }

        $adminUserId = Auth::id();
        $targetUserId = $user->id;
        $targetEmail = $user->email;

        DB::transaction(function () use ($user, $profile, $plotIds, $inventoryItemIds, $adminUserId, $targetUserId, $targetEmail) {
            $user->tokens()->delete();

            if ($user->gardenOwner?->id) {
                Plot::query()
                    ->where('garden_owner_id', $user->gardenOwner->id)
                    ->update(['garden_owner_id' => null]);

                InventoryItem::query()
                    ->where('garden_owner_id', $user->gardenOwner->id)
                    ->update(['garden_owner_id' => null]);
            }

            if ($profile) {
                $profile->delete();
            }

            $user->delete();

            $this->reassignLegacyLinkedPlots($plotIds);
            $this->reassignLegacyLinkedInventoryItems($inventoryItemIds);
            $this->deleteOrphanedPlots($plotIds);
            $this->deleteOrphanedInventoryItems($inventoryItemIds);

            AuditLog::query()->create([
                'admin_user_id' => $adminUserId,
                'action' => 'user_deleted',
                'target_user_id' => null,
                'context' => [
                    'deleted_user_id' => $targetUserId,
                    'deleted_email' => $targetEmail,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<int, int|string>  $plotIds
     */
    private function reassignLegacyLinkedPlots(array $plotIds): void
    {
        foreach ($plotIds as $plotId) {
            $plot = Plot::query()->find($plotId);

            if (! $plot || $plot->garden_owner_id !== null) {
                continue;
            }

            $remainingOwnerId = HasPlot::query()
                ->where('fk_plot_id', $plotId)
                ->orderBy('fk_owner_id')
                ->value('fk_owner_id');

            if ($remainingOwnerId !== null) {
                $plot->update(['garden_owner_id' => $remainingOwnerId]);
            }
        }
    }

    /**
     * @param  array<int, int|string>  $inventoryItemIds
     */
    private function reassignLegacyLinkedInventoryItems(array $inventoryItemIds): void
    {
        foreach ($inventoryItemIds as $inventoryItemId) {
            $inventoryItem = InventoryItem::query()->find($inventoryItemId);

            if (! $inventoryItem || $inventoryItem->garden_owner_id !== null) {
                continue;
            }

            $remainingOwnerId = HasInventory::query()
                ->where('fk_inventory_item_id', $inventoryItemId)
                ->orderBy('fk_owner_id')
                ->value('fk_owner_id');

            if ($remainingOwnerId !== null) {
                $inventoryItem->update(['garden_owner_id' => $remainingOwnerId]);
            }
        }
    }

    /**
     * @param  array<int, int|string>  $plotIds
     */
    private function deleteOrphanedPlots(array $plotIds): void
    {
        if ($plotIds === []) {
            return;
        }

        Plot::query()
            ->whereIn('id', $plotIds)
            ->whereNull('garden_owner_id')
            ->delete();
    }

    /**
     * @param  array<int, int|string>  $inventoryItemIds
     */
    private function deleteOrphanedInventoryItems(array $inventoryItemIds): void
    {
        if ($inventoryItemIds === []) {
            return;
        }

        InventoryItem::query()
            ->whereIn('id', $inventoryItemIds)
            ->whereNull('garden_owner_id')
            ->delete();
    }
}
