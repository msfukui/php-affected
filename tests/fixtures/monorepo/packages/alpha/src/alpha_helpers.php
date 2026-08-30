<?php
// alpha の composer.json にだけ登録されている。beta のテストには読み込まれない
function alpha_helper(): string
{
    return 'alpha';
}
