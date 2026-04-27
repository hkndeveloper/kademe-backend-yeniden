<?php

namespace App\Support;

use App\Exports\ArrayExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminExportResponder
{
    public static function download(
        string $format,
        string $filename,
        string $title,
        array $headings,
        array $rows,
    ): Response|BinaryFileResponse {
        $normalizedFormat = strtolower($format);

        if ($normalizedFormat === 'xlsx' || $normalizedFormat === 'excel') {
            return Excel::download(
                new ArrayExport($headings, $rows),
                "{$filename}.xlsx",
                ExcelFormat::XLSX
            );
        }

        if ($normalizedFormat === 'pdf') {
            $pdf = Pdf::loadView('exports.table', [
                'title' => $title,
                'headings' => $headings,
                'rows' => $rows,
                'generatedAt' => now()->format('d.m.Y H:i'),
            ])->setPaper('a4', 'landscape');

            return $pdf->download("{$filename}.pdf");
        }

        return Excel::download(
            new ArrayExport($headings, $rows),
            "{$filename}.csv",
            ExcelFormat::CSV
        );
    }
}
