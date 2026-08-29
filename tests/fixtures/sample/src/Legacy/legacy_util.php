<?php

function legacy_slugify(string $s): string
{
    return strtolower(str_replace(' ', '-', $s));
}
