<?php

declare(strict_types=1);

/**
 * Google Sheets tab names on the shared payroll spreadsheet.
 * Copy to sheets.local.php to override tab names if yours differ.
 */
return [
    'spreadsheet_id' => '12WiqfB2KS3FeiKeA_APANAvAWJ_QYcGsVbt7iPR7UWM',
    'tab_payroll'    => 'Payroll Staff',
  // Main roster — try Master Sheet first, then Overall
    'tab_master'     => 'Master Sheet',
    'tab_master_alt' => 'Overall',
];
