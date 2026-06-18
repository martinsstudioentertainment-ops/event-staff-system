<?php

declare(strict_types=1);

/** @var array<string, mixed> $row */
/** @var int $eventId */
?>
<tr>
    <td><?= h($row['first_name'] . ' ' . $row['surname']) ?></td>
    <td><?= h(formatEventLabel($row)) ?></td>
    <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
    <td>
        <?php if ((int) $row['is_checked_in'] === 1): ?>
            <span class="badge badge--approved">Checked In</span>
        <?php else: ?>
            <span class="badge badge--pending">Waiting</span>
        <?php endif; ?>
    </td>
    <td>
        <?= $row['checked_in_at'] ? h(formatSystemDateTime((string) $row['checked_in_at'], $pdo)) : '—' ?>
    </td>
    <td<?= trim((string) ($row['hours_note'] ?? '')) !== '' ? ' title="' . h((string) $row['hours_note']) . '"' : '' ?>>
        <?php if ((int) ($row['is_checked_in'] ?? 0) === 1): ?>
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
            <?php if ((int) $row['is_checked_in'] === 0): ?>
                <form method="post" action="checkin-action.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <button type="submit" class="btn btn--small btn--success">Check In</button>
                </form>
            <?php else: ?>
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
