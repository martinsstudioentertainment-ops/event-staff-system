<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/notification-center.php';

/**
 * @param list<array<string, mixed>> $items
 */
function renderNotificationList(array $items, string $emptyMessage, bool $adminLinks = false): void
{
    if ($items === []) {
        echo '<p class="notif-empty">' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</p>';

        return;
    }

    echo '<ul class="notif-list">';
    foreach ($items as $item) {
        $id       = (int) ($item['id'] ?? 0);
        $unread   = (int) ($item['is_read'] ?? 1) === 0;
        $title    = (string) ($item['title'] ?? '');
        $body     = (string) ($item['body'] ?? '');
        $time     = formatNotificationTime((string) ($item['created_at'] ?? ''));
        $actionUrl = trim((string) ($item['action_url'] ?? ''));
        $actionLabel = trim((string) ($item['action_label'] ?? ''));

        if ($adminLinks && $actionUrl !== '' && !str_starts_with($actionUrl, 'http') && !str_starts_with($actionUrl, '/')) {
            $actionUrl = ltrim($actionUrl, '/');
        }

        $href = $actionUrl !== '' ? $actionUrl : '#';
        $class = 'notif-item' . ($unread ? ' notif-item--unread' : '');

        echo '<li class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        echo '<a class="notif-item__link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
        if ($id > 0) {
            echo ' data-notif-id="' . $id . '"';
        }
        echo '>';
        echo '<div class="notif-item__top">';
        echo '<h3 class="notif-item__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
        echo '<span class="notif-item__time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</span>';
        echo '</div>';
        echo '<p class="notif-item__body">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($actionLabel !== '' && $actionUrl !== '') {
            echo '<span class="notif-item__action">' . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') . ' →</span>';
        }
        echo '</a></li>';
    }
    echo '</ul>';
}
