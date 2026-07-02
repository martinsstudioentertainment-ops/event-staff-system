<?php

declare(strict_types=1);

/** @var array<string, mixed> $row */
/** @var int $eventId */
/** @var PDO $pdo */

$bucket = resolveAttendanceBoardBucket($row);
$statusLabel = formatAttendanceBoardStatusLabel($row);
?>
<tr class="attendance-board-row attendance-board-row--<?= h($bucket) ?>">
    <td><?= h($row['first_name'] . ' ' . $row['surname']) ?></td>
    <td><?= h(formatEventLabel($row)) ?></td>
    <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
    <td>
        <?php if ($bucket === 'checked_in'): ?>
            <span class="badge badge--approved"><?= h($statusLabel) ?></span>
        <?php elseif ($bucket === 'awaiting'): ?>
            <span class="badge badge--pending"><?= h($statusLabel) ?></span>
        <?php else: ?>
            <span class="badge badge--rejected"><?= h($statusLabel) ?></span>
        <?php endif; ?>
    </td>
    <td>
        <?= ($ts = formatAttendanceCheckinDateTime($row, $pdo)) !== '' ? h($ts) : '—' ?>
    </td>
    <td><?= trim((string) ($row['bib_number'] ?? '')) !== '' ? h((string) $row['bib_number']) : '—' ?></td>
    <td<?= trim((string) ($row['hours_note'] ?? '')) !== '' ? ' title="' . h((string) $row['hours_note']) . '"' : '' ?>>
        <?php if ($bucket === 'checked_in'): ?>
            <strong><?= h(formatAttendanceRosterHours($row)) ?></strong>
            <?php
            $hoursPaid = (float) ($row['hours_paid'] ?? 0);
            $hoursWorked = (float) ($row['hours_worked'] ?? 0);
            if ($hoursPaid > 0 && $hoursWorked > 0 && $hoursPaid + 0.01 < $hoursWorked):
                ?>
                <span class="badge badge--pending" title="Adjusted payable hours">Adj</span>
            <?php endif; ?>
        <?php else: ?>
            —
        <?php endif; ?>
    </td>
    <td>
        <div class="action-group">
            <a href="qr.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">QR Code</a>
            <?php if ($bucket === 'awaiting'): ?>
                <form method="post" action="checkin-action.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <button type="submit" class="btn btn--small btn--success">Check In</button>
                </form>
            <?php elseif ($bucket === 'checked_in'): ?>
                <form method="post" action="checkin-reset-action.php" onsubmit="return confirm('Reset check-in for this staff member? They can sign in again.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <button type="submit" class="btn btn--small btn--secondary">Reset check-in</button>
                </form>
            <?php endif; ?>
        </div>
    </td>
</tr>
