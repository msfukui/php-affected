<?php
// Router から動的に読み込まれるため、どのファイルからも依存されていない
return [
    '/home' => ['App\Http\HomeController', 'index'],
];
