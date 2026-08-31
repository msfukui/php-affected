<?php
// Web の入口。bootstrap から Kernel まで静的に辿れる
$kernel = require __DIR__ . '/../bootstrap/app.php';
$kernel->handle();
