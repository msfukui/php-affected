<?php
// リポジトリ全体の composer.json に登録されており、どのテストにも読み込まれる
function shared_helper(): string
{
    return 'shared';
}
