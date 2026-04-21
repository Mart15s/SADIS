<?php

namespace App\Http\Controllers\Concerns;

use App\Models\GardenOwner;
use App\Models\Plot;
use App\Services\AccessService;
use Illuminate\Http\Request;

trait AuthorizesPlotAccess
{
    protected function resolveGardenOwner(Request $request): GardenOwner
    {
        $owner = $request->user()?->gardenOwner;

        abort_unless($owner, 403, 'Naudotojas neturi sodininko profilio.');

        return $owner;
    }

    protected function ensureUserCanViewPlot(Request $request, Plot $plot, AccessService $accessService): GardenOwner
    {
        $owner = $this->resolveGardenOwner($request);

        abort_unless(
            $accessService->userHasAccess($owner, $plot),
            403,
            'Neturite teises perziureti sio sklypo.'
        );

        return $owner;
    }

    protected function ensureUserCanEditPlot(Request $request, Plot $plot, AccessService $accessService): GardenOwner
    {
        $owner = $this->resolveGardenOwner($request);

        abort_unless(
            $accessService->userCanEdit($owner, $plot),
            403,
            'Neturite teises redaguoti sio sklypo.'
        );

        return $owner;
    }

    protected function ensureUserOwnsPlot(Request $request, Plot $plot, AccessService $accessService): GardenOwner
    {
        $owner = $this->resolveGardenOwner($request);

        abort_unless(
            $accessService->userIsOwner($owner, $plot),
            403,
            'Neturite teises valdyti sio sklypo prieigu.'
        );

        return $owner;
    }
}
