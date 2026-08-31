<?php
namespace App\Console;

use App\Service\ReportService;

final class ImportCommand
{
    public function run(ReportService $reports): string
    {
        return $reports->render();
    }
}
