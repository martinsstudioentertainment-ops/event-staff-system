<?php

declare(strict_types=1);

require_once __DIR__ . '/../staff-messages.php';
require_once __DIR__ . '/../date-format.php';
require_once __DIR__ . '/../rich-text.php';
require_once __DIR__ . '/../system-settings.php';

/**
 * @param array<int, array<string, mixed>> $messages
 */
function renderMessageThread(array $messages, bool $adminView = false, ?PDO $pdo = null): void
{
    if ($messages === []) {
        echo '<div class="msg-thread__empty-state" role="status">';
        echo '<span class="msg-thread__empty-icon" aria-hidden="true">💬</span>';
        echo '<p class="msg-thread__empty-title">No messages yet</p>';
        echo '<p class="msg-thread__empty-text">Send the first message below — a coordinator will reply here.</p>';
        echo '</div>';

        return;
    }

    echo '<div class="msg-thread" role="log" aria-live="polite">';
    foreach ($messages as $msg) {
        $isStaff    = ($msg['direction'] ?? '') === 'staff_to_admin';
        $side       = $isStaff ? 'staff' : 'admin';
        $subject    = trim((string) ($msg['subject'] ?? ''));
        $body       = (string) ($msg['body'] ?? '');
        $time       = (string) ($msg['created_at'] ?? '');
        $status     = (string) ($msg['delivery_status'] ?? '');
        $recipient  = trim((string) ($msg['recipient_email'] ?? ($msg['staff_email'] ?? '')));
        $senderMail = $isStaff ? trim((string) ($msg['staff_email'] ?? '')) : '';

        if ($adminView && !$isStaff && $pdo instanceof PDO) {
            $fromEmail = trim((string) getSetting($pdo, 'mail_from_email', ''));
            if ($fromEmail !== '') {
                $senderMail = $fromEmail;
            }
        }

        if ($adminView && $isStaff) {
            $label = 'Staff';
        } elseif ($adminView && !$isStaff) {
            $label = 'You' . (!empty($msg['admin_name']) ? ' · ' . (string) $msg['admin_name'] : '');
        } else {
            $label = $isStaff ? 'You' : 'Coordinator';
        }

        $statusLabel = formatStaffMessageDeliveryLabel($status);
        ?>
        <article class="msg-bubble msg-bubble--<?= h($side) ?>">
            <div class="msg-bubble__meta">
                <span class="msg-bubble__who"><?= h($label) ?></span>
                <?php if ($time !== ''): ?>
                    <time class="msg-bubble__time" datetime="<?= h($time) ?>">
                        <?= h($pdo instanceof PDO ? formatSystemDateTime($time, $pdo) : $time) ?>
                    </time>
                <?php endif; ?>
            </div>
            <?php if ($subject !== ''): ?>
                <h3 class="msg-bubble__subject"><?= h($subject) ?></h3>
            <?php endif; ?>
            <?php if ($adminView): ?>
                <dl class="msg-bubble__headers">
                    <?php if ($senderMail !== ''): ?>
                        <div><dt>From</dt><dd><?= h($senderMail) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($recipient !== ''): ?>
                        <div><dt>To</dt><dd><?= h($recipient) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($statusLabel !== ''): ?>
                        <div><dt>Status</dt><dd><?= h($statusLabel) ?></dd></div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
            <div class="msg-bubble__body rich-content"><?= $isStaff ? nl2br(h($body)) : renderRichText($body) ?></div>
        </article>
        <?php
    }
    echo '</div>';
}
