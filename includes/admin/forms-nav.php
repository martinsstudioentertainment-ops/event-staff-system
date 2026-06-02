<?php

/** @return array<int, array{key: string, label: string, url: string}> */
function getAdminFormsNavItems(): array
{
    return [
        ['key' => 'list', 'label' => 'All forms', 'url' => 'forms.php'],
        ['key' => 'new',  'label' => 'Add form',  'url' => 'form-new.php'],
    ];
}
