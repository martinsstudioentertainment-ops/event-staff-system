<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/notification-center.php';

/**
 * @param list<array<string, mixed>> $items
 */
function renderNotificationList(array $items, string $emptyMessage, bool $adminLinks = false): void
{
    if ($items === []) {
        $parts = explode('.', trim($emptyMessage), 2);
        $title = trim($parts[0] ?? 'No notifications yet');
        $detail = trim($parts[1] ?? 'You will see updates here when your application is reviewed.');
        echo '<div class="notif-empty-state" role="status">';
        echo '<span class="notif-empty-state__icon" aria-hidden="true">🔔</span>';
        echo '<p class="notif-empty-state__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($detail !== '') {
            echo '<p class="notif-empty-state__text">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '</div>';

        return;
    }

    echo '<ul class="notif-list">';
    foreach ($items as $item) {
        $id          = (int) ($item['id'] ?? 0);
        $unread      = (int) ($item['is_read'] ?? 1) === 0;
        $title       = (string) ($item['title'] ?? '');
        $body        = (string) ($item['body'] ?? '');
        $time        = formatNotificationTime((string) ($item['created_at'] ?? ''));
        $actionUrl   = trim((string) ($item['action_url'] ?? ''));
        $actionLabel = trim((string) ($item['action_label'] ?? ''));

        if ($adminLinks && $actionUrl !== '' && !str_starts_with($actionUrl, 'http') && !str_starts_with($actionUrl, '/')) {
            $actionUrl = ltrim($actionUrl, '/');
        }

        if (!$adminLinks && $actionUrl !== '') {
            $actionUrl = resolveStaffNotificationActionUrl(null, $actionUrl);
        }

        $hasLink = $actionUrl !== '' && $actionUrl !== '#';
        $class   = 'notif-item' . ($unread ? ' notif-item--unread' : '');

        echo '<li class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="notif-item__content">';

        echo '<div class="notif-item__top">';
        echo '<h3 class="notif-item__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
        echo '<span class="notif-item__time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</span>';
        echo '</div>';
        echo '<p class="notif-item__body">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>';

        if ($hasLink) {
            $ctaLabel = $actionLabel !== '' ? $actionLabel : 'Open link';
            echo '<a class="notif-item__cta" href="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '"';
            if ($id > 0) {
                echo ' data-notif-id="' . $id . '"';
            }
            echo '>' . htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8') . '</a>';
        } else {
            echo '<button type="button" class="notif-item__cta notif-item__cta--ghost notif-item__toggle"';
            if ($id > 0) {
                echo ' data-notif-id="' . $id . '"';
            }
            echo ' aria-expanded="false">Read full message</button>';
        }

        echo '</div></li>';
    }
    echo '</ul>';
}

/**
 * Staff app v3 notification cards (matches shift / menu card styling).
 *
 * @param list<array<string, mixed>> $items
 */
function renderStaffV3NotificationList(array $items, string $emptyMessage): void
{
    if ($items === []) {
        $parts  = explode('.', trim($emptyMessage), 2);
        $title  = trim($parts[0] ?? 'No notifications yet');
        $detail = trim($parts[1] ?? 'You will see updates here when your application is reviewed.');
        ?>
        <div class="es-ds__empty es-v3__animate-in" role="status">
            <span class="es-ds__empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </span>
            <p class="es-ds__empty-title"><?= h($title) ?></p>
            <?php if ($detail !== ''): ?>
                <p class="es-ds__empty-text"><?= h($detail) ?></p>
            <?php endif; ?>
        </div>
        <?php

        return;
    }
    ?>
    <div class="es-v3__notif-list">
        <?php foreach ($items as $item): ?>
            <?php
            $id          = (int) ($item['id'] ?? 0);
            $unread      = (int) ($item['is_read'] ?? 1) === 0;
            $title       = (string) ($item['title'] ?? '');
            $body        = (string) ($item['body'] ?? '');
            $time        = formatNotificationTime((string) ($item['created_at'] ?? ''));
            $actionUrl   = trim((string) ($item['action_url'] ?? ''));
            $actionLabel = trim((string) ($item['action_label'] ?? ''));
            if ($actionUrl !== '') {
                $actionUrl = resolveStaffNotificationActionUrl(null, $actionUrl);
            }
            $hasLink = $actionUrl !== '' && $actionUrl !== '#';
            ?>
            <article class="es-v3__notif-card es-v3__animate-in<?= $unread ? ' es-v3__notif-card--unread' : '' ?>">
                <div class="es-v3__notif-card-head">
                    <h3 class="es-v3__notif-card-title"><?= h($title) ?></h3>
                    <time class="es-v3__notif-card-time" datetime="<?= h((string) ($item['created_at'] ?? '')) ?>"><?= h($time) ?></time>
                </div>
                <p class="es-v3__notif-card-body"><?= h($body) ?></p>
                <?php if ($hasLink): ?>
                    <a class="es-v3__notif-card-cta" href="<?= h($actionUrl) ?>"<?= $id > 0 ? ' data-notif-id="' . $id . '"' : '' ?>>
                        <?= h($actionLabel !== '' ? $actionLabel : 'Open link') ?>
                    </a>
                <?php elseif ($body !== ''): ?>
                    <button type="button" class="es-v3__notif-card-cta es-v3__notif-card-cta--ghost es-v3__notif-toggle"<?= $id > 0 ? ' data-notif-id="' . $id . '"' : '' ?> aria-expanded="false">
                        Read full message
                    </button>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
}
