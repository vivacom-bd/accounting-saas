<?php

declare(strict_types=1);

namespace App\Support\Reports\Export;

use App\Contracts\HtmlToPdf;
use Illuminate\Support\Facades\View;

/**
 * PDF of a report table. The HTML is rendered from `resources/views/reports/table.blade.php` and can be tested without a
 * browser; turning it into a PDF goes through {@see HtmlToPdf}, the same Chromium launcher the document PDFs use (04_TRD §2),
 * so `reports.chromium_path` and `reports.node_module_path` apply to both. A table with more than {@see self::PORTRAIT_COLUMNS}
 * columns is printed landscape, and one with more than {@see self::COMPACT_COLUMNS} also in a smaller type, so no column is clipped.
 * Every page repeats the column headings and carries "Page n of m".
 */
class PdfRenderer
{
    public const int PORTRAIT_COLUMNS = 6;

    public const int COMPACT_COLUMNS = 10;

    public function __construct(private readonly HtmlToPdf $engine) {}

    public function html(ReportTable $table): string
    {
        return View::make('reports.table', ['table' => $table, 'compact' => count($table->columns) > self::COMPACT_COLUMNS])->render();
    }

    public function landscape(ReportTable $table): bool
    {
        return count($table->columns) > self::PORTRAIT_COLUMNS;
    }

    public function pdf(ReportTable $table): string
    {
        return $this->engine->pdf($this->html($table), $this->footer($table), $this->landscape($table));
    }

    private function footer(ReportTable $table): string
    {
        $left = mb_substr($table->title.($table->subtitle !== '' ? ' · '.$table->subtitle : ''), 0, 150);

        return '<div style="width:100%;font-size:8px;color:#6b7280;padding:0 12mm;display:flex;justify-content:space-between;font-family:Helvetica,Arial,sans-serif">'
            .'<span>'.e($left).'</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>';
    }
}
