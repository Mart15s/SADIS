<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FarmMembership;
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
                'role' => ['The selected user role is invalid.'],
            ]);
        }

        if ((int) Auth::id() === (int) $user->id && $roleEnum !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'role' => ['An administrator cannot remove their own administrator access.'],
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
                'user' => ['An administrator cannot remove their own account.'],
            ]);
        }

        $soleOwnedFarm = FarmMembership::query()
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('farm_memberships as other_owners')
                    ->whereColumn('other_owners.farm_id', 'farm_memberships.farm_id')
                    ->whereColumn('other_owners.user_id', '!=', 'farm_memberships.user_id')
                    ->where('other_owners.role', 'owner')->where('other_owners.status', 'active');
            })
            ->exists();

        if ($soleOwnedFarm) {
            throw ValidationException::withMessages([
                'user' => ['Transfer ownership of every solely owned farm before deleting this account.'],
            ]);
        }

        // Stage 1 account removal is deliberately reversible: deactivate the
        // identity and revoke credentials while retaining historical actors and
        // every farm-domain record. A later retention workflow may anonymize it.
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->update(['status' => 'deactivated', 'deactivated_at' => now()]);
            AuditLog::query()->create([
                'admin_user_id' => Auth::id(), 'action' => 'user_deactivated',
                'target_user_id' => $user->id, 'context' => ['email' => $user->email], 'created_at' => now(),
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
