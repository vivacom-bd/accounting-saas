<?php

declare(strict_types=1);

use App\Contracts\HtmlToPdf;
use App\Services\Documents\BrowsershotHtmlToPdf;
use App\Support\Reports\Export\PdfRenderer;
use App\Support\Reports\Export\ReportRow;
use App\Support\Reports\Export\ReportTable;
use Tests\Support\FakePdfEngine;

/*
 * Report PDFs (plan 3c.21c): one way of launching Chromium for documents and reports, wide tables printed sideways, a page
 * footer with "Page n of m". The browser itself is not started here; the real PDFs were looked at as images (see the report).
 */

function tableOf(int $columns, int $rows = 3): ReportTable
{
    $cols = array_map(fn (int $i): array => ['label' => "Col {$i}", 'numeric' => $i > 0], range(0, $columns - 1));
    $data = array_map(fn (int $r): ReportRow => new ReportRow(array_map(fn (int $c) => $c === 0 ? "Row {$r}" : ['amount' => 100 * $r, 'currency' => 'USD'], range(0, $columns - 1))), range(1, $rows));

    return new ReportTable('Trial balance', 'Acme · As of 2026-10-31', $cols, $data, 2);
}

it('prints narrow reports upright and wide ones sideways, with a footer that numbers the pages', function (): void {
    $engine = new FakePdfEngine;
    app()->instance(HtmlToPdf::class, $engine);

    app(PdfRenderer::class)->pdf(tableOf(4));
    app(PdfRenderer::class)->pdf(tableOf(7));
    app(PdfRenderer::class)->pdf(tableOf(15));

    expect($engine->landscape)->toBe([false, true, true])
        ->and($engine->html[2])->toContain('font-size: 8px')->and($engine->html[0])->toContain('font-size: 10px')
        ->and($engine->html[0])->toContain('thead { display: table-header-group; }');
});

it('goes through the same Chromium launcher as the document PDFs', function (): void {
    // The renderer holds the shared contract, and the one real implementation applies both settings.
    expect((new ReflectionClass(PdfRenderer::class))->getConstructor()?->getParameters()[0]->getType()?->getName())->toBe(HtmlToPdf::class)
        ->and(app(HtmlToPdf::class))->toBeInstanceOf(BrowsershotHtmlToPdf::class);
    $source = (string) file_get_contents((new ReflectionClass(BrowsershotHtmlToPdf::class))->getFileName());
    expect($source)->toContain('reports.chromium_path')->toContain('reports.node_module_path');
    expect((string) file_get_contents((new ReflectionClass(PdfRenderer::class))->getFileName()))->not->toContain('Browsershot::');
});

it('keeps long tokens such as hashes inside the page and short ones whole', function (): void {
    $table = new ReportTable('Audit trail', 'Acme', [['label' => 'IP', 'numeric' => false], ['label' => 'Hash', 'numeric' => false]], [new ReportRow(['127.0.0.1', str_repeat('ab', 32)])], 2);
    $html = app(PdfRenderer::class)->html($table);
    expect($html)->toContain('<td class="tok">'.str_repeat('ab', 32))->and($html)->toContain('127.0.0.1</td>')->not->toContain('class="tok">127.0.0.1');
});
