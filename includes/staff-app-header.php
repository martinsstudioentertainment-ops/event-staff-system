<?php
/** Cute mobile header — back to staff app home. */
$staffAppBackLabel = $staffAppBackLabel ?? 'Home';
?>
<header class="staff-app-mini-header">
    <a href="staff-app.php" class="staff-app-mini-header__back" aria-label="Back to staff app home">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        <?= h($staffAppBackLabel) ?>
    </a>
</header>
