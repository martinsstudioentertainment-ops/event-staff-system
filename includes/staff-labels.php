<?php

if (!function_exists('formatGenderLabel')) {
    function formatGenderLabel(string $gender): string
    {
        return match ($gender) {
            'male'              => 'Male',
            'female'            => 'Female',
            'other'             => 'Other',
            'prefer_not_to_say' => 'Prefer not to say',
            default             => ucfirst($gender),
        };
    }
}
