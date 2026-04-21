<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Http\Resources\Inventory\InventoryItemResource;
use App\Models\GardenOwner;
use App\Models\InventoryItem;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryService $service)
    {
        $items = $service->listForOwner($this->resolveOwner($request));

        return InventoryItemResource::collection($items);
    }

    public function store(StoreInventoryItemRequest $request, InventoryService $service)
    {
        $item = $service->createForOwner(
            $this->resolveOwner($request),
            $request->validated()
        );

        return InventoryItemResource::make($item)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, InventoryItem $inventoryItem, InventoryService $service)
    {
        $item = $service->getForOwner($this->resolveOwner($request), $inventoryItem);

        return InventoryItemResource::make($item);
    }

    public function update(
        UpdateInventoryItemRequest $request,
        InventoryItem $inventoryItem,
        InventoryService $service
    ) {
        $item = $service->updateForOwner(
            $this->resolveOwner($request),
            $inventoryItem,
            $request->validated()
        );

        return InventoryItemResource::make($item);
    }

    public function destroy(Request $request, InventoryItem $inventoryItem, InventoryService $service): JsonResponse
    {
        $service->deleteForOwner($this->resolveOwner($request), $inventoryItem);

        return response()->json([
            'message' => 'Inventoriaus irasas sekmingai pasalintas',
        ]);
    }

    private function resolveOwner(Request $request): GardenOwner
    {
        $owner = $request->user()?->gardenOwner;

        abort_unless($owner, 403);

        return $owner;
    }
}
