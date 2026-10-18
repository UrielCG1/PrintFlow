<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class PrintingCompletionFormUsabilityTest extends TestCase
{
    public function testPlannedRollCloseShowsOneInputPerQuantityAndMakesSurplusVisible(): void
    {
        $xpath = $this->renderCloseForm(true, '1.500000', '1.000000');

        self::assertSame(1, $xpath->query('//input[@name="total_consumed"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="actual_inventory_quantity"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="waste"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="surplus_quantity"]')->length);
        self::assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " printing-plan-summary ")]')->length);
        self::assertSame(1, $xpath->query('//section[@aria-labelledby="printing-surplus-title"]')->length);
        self::assertSame(0, $xpath->query('//details//input[@name="surplus_quantity"]')->length);
        self::assertSame(0, $xpath->query('//input[@name="total_consumed" and @required]')->length);
        self::assertSame(1, $xpath->query('//input[@name="actual_inventory_quantity" and @required]')->length);

        $this->assertNoDuplicateIds($xpath);
    }

    public function testUnplannedNonRollCloseRequiresActualConsumptionWithoutLinearField(): void
    {
        $xpath = $this->renderCloseForm(false, null, null);

        self::assertSame(1, $xpath->query('//input[@name="total_consumed" and @required]')->length);
        self::assertSame(1, $xpath->query('//input[@name="waste"]')->length);
        self::assertSame(0, $xpath->query('//input[@name="actual_inventory_quantity"]')->length);
        self::assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " printing-plan-summary ")]')->length);
        $this->assertNoDuplicateIds($xpath);
    }

    private function renderCloseForm(bool $linearRoll, ?string $plannedArea, ?string $plannedLength): DOMXPath
    {
        $template = file_get_contents(dirname(__DIR__, 3).'/templates/admin/printing/show.html.twig');
        self::assertIsString($template);
        self::assertSame(1, preg_match('~<form class="pf-form printing-completion-form".*?</form>~s', $template, $matches));

        $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/'.$route));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));
        $html = $twig->createTemplate($matches[0])->render([
            'activeExecution' => ['id' => 1],
            'orderVersion' => 1,
            'completeCommandId' => 'test-command',
            'confirmedPlanQuantity' => $plannedArea,
            'plannedInventoryQuantity' => $plannedLength,
            'scannedMaterial' => [
                'linearRoll' => $linearRoll,
                'consumptionUnit' => ['symbol' => $linearRoll ? 'm²' : 'pza'],
                'inventoryUnit' => ['symbol' => $linearRoll ? 'm' : 'pza'],
            ],
            'currentScan' => ['stockUnit' => ['unitCode' => 'IMU-QA', 'remainingQuantity' => '2.000000']],
        ]);

        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new DOMXPath($document);
    }

    private function assertNoDuplicateIds(DOMXPath $xpath): void
    {
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');
            self::assertArrayNotHasKey($id, $ids, 'ID HTML repetido: '.$id);
            $ids[$id] = true;
        }
    }
}
