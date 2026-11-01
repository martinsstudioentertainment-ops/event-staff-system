<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<nav class="mobile-bottom-nav">

    <a href="/staff-app.php"
       class="mobile-bottom-nav__item <?= $currentPage === 'staff-app.php' ? 'is-active' : '' ?>">

        <span class="mobile-bottom-nav__icon">🏠</span>
        <span class="mobile-bottom-nav__label">Home</span>

    </a>

    <a href="/index.php"
       class="mobile-bottom-nav__item <?= $currentPage === 'index.php' ? 'is-active' : '' ?>">

        <span class="mobile-bottom-nav__icon">🎫</span>
        <span class="mobile-bottom-nav__label">Events</span>

    </a>

    <a href="/status.php"
       class="mobile-bottom-nav__item <?= $currentPage === 'status.php' ? 'is-active' : '' ?>">

        <span class="mobile-bottom-nav__icon">✅</span>
        <span class="mobile-bottom-nav__label">Status</span>

    </a>

    <button type="button"
            class="mobile-bottom-nav__item mobile-bottom-nav__item--install"
            id="mobile-install-trigger">

        <span class="mobile-bottom-nav__icon">📲</span>
        <span class="mobile-bottom-nav__label">Install</span>

    </button>

</nav>