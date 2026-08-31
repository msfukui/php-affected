<?php
// CLI の入口。ここは静的に辿れる
require __DIR__ . '/../src/Console/ImportCommand.php';
require __DIR__ . '/../src/Service/ReportService.php';

(new App\Console\ImportCommand())->run(new App\Service\ReportService());
