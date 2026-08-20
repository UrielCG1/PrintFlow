<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\CatalogHealthFinding;
use App\Application\Catalog\CatalogHealthReportBuilder;
use App\Enum\Catalog\CatalogHealthSeverity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogo', name: 'admin_catalog_health_')]
final class CatalogHealthController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CatalogHealthReportBuilder $reportBuilder): Response
    {
        $this->denyAccessUnlessGranted('catalog.view');

        $report = $reportBuilder->build();

        $area = $request->query->getString('area', 'all');
        $validAreas = ['all', 'items', 'categories', 'units', 'characteristics'];
        if (!in_array($area, $validAreas, true)) {
            $area = 'all';
        }

        $severity = $request->query->getString('severity', 'all');
        $severityEnum = $severity === 'all' ? null : CatalogHealthSeverity::tryFrom($severity);
        if ($severity !== 'all' && $severityEnum === null) {
            $severity = 'all';
        }

        $findings = array_values(array_filter(
            $report['findings'],
            static function (CatalogHealthFinding $finding) use ($area, $severityEnum): bool {
                if ($area !== 'all' && $finding->area !== $area) {
                    return false;
                }

                if ($severityEnum !== null && $finding->severity !== $severityEnum) {
                    return false;
                }

                return true;
            },
        ));

        return $this->render('admin/catalog/health/index.html.twig', [
            'report' => $report,
            'findings' => $findings,
            'selectedArea' => $area,
            'selectedSeverity' => $severity,
            'severityCases' => CatalogHealthSeverity::orderedCases(),
        ]);
    }
}
