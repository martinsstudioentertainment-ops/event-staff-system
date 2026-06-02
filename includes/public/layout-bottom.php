</main>

<footer class="site-footer">
    <div class="site-footer__grid">
        <div class="site-footer__brand-col">
            <a class="site-footer__brand" href="<?= h(getWebsitePageUrl('home', $web['pdo'], $web['assetBase'])) ?>">
                <span class="site-footer__logo">
                    <?php renderSiteBrandLogo($web['pdo'], 'footer', $web['assetBase'], $web['companyName'] ?? ''); ?>
                </span>
                <span class="site-footer__brand-name"><?= h($web['companyName']) ?></span>
            </a>
            <p class="site-footer__tagline"><?= h($web['content']['global']['footer_tagline'] ?? '') ?></p>
            <a class="btn btn--primary site-footer__register" href="<?= h($web['registrationUrl']) ?>">Register for events</a>
        </div>
        <?php foreach (($web['content']['footer']['columns'] ?? []) as $column): ?>
            <div class="site-footer__col">
                <h3 class="site-footer__heading"><?= h($column['title'] ?? '') ?></h3>
                <ul class="site-footer__links">
                    <?php foreach (($column['links'] ?? []) as $link): ?>
                        <li>
                            <a href="<?= h(getWebsitePageUrl($link['page'] ?? 'home', $web['pdo'], $web['assetBase'])) ?>"><?= h($link['label'] ?? '') ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <div class="site-footer__col">
            <h3 class="site-footer__heading">Contact</h3>
            <ul class="site-footer__links site-footer__contact">
                <li><a href="mailto:<?= h($web['email']) ?>"><?= h($web['email']) ?></a></li>
                <li><a href="<?= h(formatTelHref($web['phone'])) ?>"><?= h($web['phone']) ?></a></li>
                <?php if (($web['whatsappUrl'] ?? '') !== ''): ?>
                    <li><a href="<?= h($web['whatsappUrl']) ?>" target="_blank" rel="noopener">WhatsApp: <?= h($web['whatsapp']) ?></a></li>
                <?php endif; ?>
                <?php if (trim((string) ($web['whatsappGroup'] ?? '')) !== ''): ?>
                    <li><a href="<?= h($web['whatsappGroup']) ?>" target="_blank" rel="noopener">WhatsApp group</a></li>
                <?php endif; ?>
                <li><a href="<?= h(getWebsitePageUrl('contact', $web['pdo'], $web['assetBase'])) ?>">Contact page</a></li>
            </ul>
        </div>
    </div>
    <div class="site-footer__bar">
        <p>&copy; <?= date('Y') ?> <?= h($web['companyName']) ?>. All rights reserved.</p>
        <div class="site-footer__bar-links">
            <a href="<?= h($web['registrationUrl']) ?>">Staff registration</a>
            <a href="<?= h($web['assetBase']) ?>privacy.php">Privacy</a>
            <a href="admin/login.php">Admin</a>
        </div>
    </div>
</footer>

<script src="<?= h($web['assetBase']) ?>assets/js/site-notice.js"></script>
<script src="<?= h($web['assetBase']) ?>assets/js/website.js"></script>
</body>
</html>
