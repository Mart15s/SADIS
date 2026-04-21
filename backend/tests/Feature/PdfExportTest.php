<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Services\PdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PdfExportTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_authorized_owner_can_export_plot_pdf(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $this->createPlantForPlot($plot, $zone);

        Sanctum::actingAs($ownerUser);

        $this->get("/api/plots/{$plot->id}/export/pdf")
            ->assertOk();
    }

    public function test_authorized_editor_can_export_plot_pdf(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$editorUser, $editor] = $this->createGardenOwner('editor@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $editor, AccessRole::Editor);

        Sanctum::actingAs($editorUser);

        $this->get("/api/plots/{$plot->id}/export/pdf")
            ->assertOk();
    }

    public function test_authorized_viewer_can_export_plot_pdf(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$viewerUser, $viewer] = $this->createGardenOwner('viewer@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $viewer, AccessRole::Viewer);

        Sanctum::actingAs($viewerUser);

        $this->get("/api/plots/{$plot->id}/export/pdf")
            ->assertOk();
    }

    public function test_unauthorized_user_gets_403_on_export(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$outsiderUser] = $this->createGardenOwner('outsider@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($outsiderUser);

        $this->get("/api/plots/{$plot->id}/export/pdf")
            ->assertForbidden();
    }

    public function test_export_returns_a_valid_downloadable_pdf_response(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, ['name' => 'Mano Sklypas']);
        $zone = $this->createZoneForPlot($plot);
        $this->createPlantForPlot($plot, $zone);

        Sanctum::actingAs($ownerUser);

        $response = $this->get("/api/plots/{$plot->id}/export/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename="plot-report.pdf"');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_export_handles_plots_with_missing_optional_sections_without_failing(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'description' => null,
        ]);

        Sanctum::actingAs($ownerUser);

        $response = $this->get("/api/plots/{$plot->id}/export/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_rendered_pdf_html_uses_geometry_plan_preview_when_available(): void
    {
        [, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'geometry' => [
                'points' => [
                    ['x' => 0.10, 'y' => 0.10],
                    ['x' => 0.86, 'y' => 0.14],
                    ['x' => 0.82, 'y' => 0.84],
                    ['x' => 0.14, 'y' => 0.78],
                ],
            ],
        ]);
        $this->createZoneForPlot($plot, [
            'name' => 'Zona A',
            'geometry' => [
                'points' => [
                    ['x' => 0.18, 'y' => 0.18],
                    ['x' => 0.44, 'y' => 0.21],
                    ['x' => 0.41, 'y' => 0.45],
                    ['x' => 0.21, 'y' => 0.43],
                ],
            ],
        ]);

        $html = app(PdfExportService::class)->renderPlotReportHtml($plot, $owner);

        $this->assertStringContainsString('data-plan-source="geometry"', $html);
        $this->assertStringContainsString('<svg class="plan-svg"', $html);
        $this->assertStringContainsString('Zona A', $html);
    }

    public function test_rendered_pdf_html_falls_back_to_default_plan_preview_without_geometry(): void
    {
        [, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'geometry' => null,
        ]);
        $this->createZoneForPlot($plot, [
            'geometry' => null,
        ]);

        $html = app(PdfExportService::class)->renderPlotReportHtml($plot, $owner);

        $this->assertStringContainsString('data-plan-source="fallback"', $html);
        $this->assertStringContainsString('<svg class="plan-svg"', $html);
    }
}
