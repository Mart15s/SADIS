<?php

namespace App\Http\Controllers\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Models\Plot;
use App\Services\Plot\AccessService;
use App\Services\Plot\PdfExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    use AuthorizesPlotAccess;

    public function pdf(
        Request $request,
        Plot $plot,
        PdfExportService $pdfExportService,
        AccessService $accessService
    ): Response {
        $owner = $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return $pdfExportService->exportPlotReport($plot, $owner);
    }
}
