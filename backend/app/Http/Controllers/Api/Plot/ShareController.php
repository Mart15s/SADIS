<?php

namespace App\Http\Controllers\Api\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plot\StoreShareRequest;
use App\Http\Resources\Plot\AccessRightResource;
use App\Models\AccessRight;
use App\Models\Plot;
use App\Models\User;
use App\Services\AccessService;
use App\Services\PlotSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShareController extends Controller
{
    use AuthorizesPlotAccess;

    public function store(
        StoreShareRequest $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $grantor = $this->ensureUserOwnsPlot($request, $plot, $accessService);
        $recipientUser = User::query()
            ->with('gardenOwner')
            ->where('email', $request->validated('recipient_email'))
            ->firstOrFail();
        $recipient = $recipientUser->gardenOwner;

        if (! $recipient) {
            throw ValidationException::withMessages([
                'recipient_email' => ['Naudotojui nepriskirtas sodininko profilis.'],
            ]);
        }

        $accessRight = $accessService->sharePlot(
            $grantor,
            $plot,
            $recipient,
            $request->validated('role')
        );

        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plot_access_granted', $grantor, [
            'access_right_id' => $accessRight->id,
            'recipient_user_id' => $recipientUser->id,
            'recipient_garden_owner_id' => $recipient->id,
            'role' => $request->validated('role'),
        ]);

        return response()->json([
            'message' => 'Prieiga sekmingai suteikta',
            'access_right' => AccessRightResource::make($accessRight)->resolve(),
        ], 201);
    }

    public function destroy(
        Request $request,
        Plot $plot,
        User $recipient,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $grantor = $this->ensureUserOwnsPlot($request, $plot, $accessService);
        $recipientOwner = $recipient->gardenOwner;

        if (! $recipientOwner) {
            throw ValidationException::withMessages([
                'recipient' => ['Naudotojui nepriskirtas sodininko profilis.'],
            ]);
        }

        $accessService->revokeAccess($grantor, $plot, $recipientOwner);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plot_access_revoked', $grantor, [
            'recipient_user_id' => $recipient->id,
            'recipient_garden_owner_id' => $recipientOwner->id,
        ]);

        return response()->json([
            'message' => 'Prieiga panaikinta',
        ]);
    }

    public function destroyById(
        Request $request,
        AccessRight $accessRight,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $plot = $accessRight->plot;
        abort_unless($plot, 404);

        $grantor = $this->ensureUserOwnsPlot($request, $plot, $accessService);
        $metadata = [
            'access_right_id' => $accessRight->id,
            'recipient_garden_owner_id' => $accessRight->garden_owner_id,
            'recipient_user_id' => $accessRight->fk_recipient_owner_id,
        ];
        $accessService->revokeAccessRight($grantor, $accessRight);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plot_access_revoked', $grantor, $metadata);

        return response()->json([
            'message' => 'Prieiga panaikinta',
        ]);
    }

    public function index(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserOwnsPlot($request, $plot, $accessService);

        $accessRights = $plot->accessRights()
            ->with(['recipient.user', 'recipient.profile'])
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(AccessRightResource::collection($accessRights)->resolve());
    }
}
