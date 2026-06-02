<?php

/** @return array<int, array{key: string, label: string, url: string}> */
function getAdminFormsNavItems(): array
{
    return [
        ['key' => 'list',    'label' => 'All forms', 'url' => 'forms.php'],
        ['key' => 'dsp',     'label' => 'DSP',       'url' => 'form-edit.php?slug=dsp'],
        ['key' => 'static',  'label' => 'Static',    'url' => 'form-edit.php?slug=static'],
        ['key' => 'steward', 'label' => 'Steward',   'url' => 'form-edit.php?slug=steward'],
    ];
}
