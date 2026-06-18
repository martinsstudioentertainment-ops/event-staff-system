<?php

declare(strict_types=1);

/**
 * Post-submit confirmation panel (status.php after successful registration).
 *
 * @param array<int, array<string, mixed>> $rows Status rows for the applicant
 */
function renderRegistrationSuccessPanel(array $rows): void
{
    if ($rows === []) {
        return;
    }

    $person = $rows[0];
    $refIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $rows
    )));
    $refIds = array_values(array_filter($refIds, static fn(int $id): bool => $id > 0));
    $refLabel = $refIds !== []
        ? implode(', ', array_map(static fn(int $id): string => '#' . $id, $refIds))
        : '';

    $eventCount = count($rows);
    ?>
    <div class="reg-success-panel" role="status">
        <h2 class="reg-success-panel__title">Registration received</h2>
        <ul class="reg-success-panel__list">
            <li>Your registration has been received<?= $refLabel !== '' ? ' (reference ' . h($refLabel) . ')' : '' ?>.</li>
            <li><?= $eventCount === 1 ? 'Your event application has been submitted.' : $eventCount . ' event applications have been submitted.' ?></li>
            <li>The event organiser will review your application. You will be contacted if approved.</li>
            <li><strong>Olasentra is not your employer or payroll provider.</strong> Employment, contracts, payroll, and working conditions are the responsibility of the event organiser or contracting company.</li>
        </ul>
    </div>
    <?php
}
