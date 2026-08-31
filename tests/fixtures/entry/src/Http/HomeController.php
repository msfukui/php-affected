<?php
namespace App\Http;

use App\Service\ReportService;

final class HomeController
{
    public function index(ReportService $reports): string
    {
        return $reports->render();
    }
}
