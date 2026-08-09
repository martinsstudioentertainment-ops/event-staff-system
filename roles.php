<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/settings-repository.php';

require_once __DIR__ . '/includes/theme.php';

require_once __DIR__ . '/includes/site-urls.php';

require_once __DIR__ . '/includes/registration-forms.php';

require_once __DIR__ . '/includes/company.php';

require_once __DIR__ . '/includes/website-content.php';



$pdo = getDB();

$web = initPublicWebsite($pdo, 'roles');

$p   = $web['content']['roles'];

$pageTitle = $p['title'] ?? 'Roles';

$pageDescription = ($p['subtitle'] ?? '') . ' — ' . $web['companyName'];

$forms = getEnabledRegistrationForms($pdo);



include __DIR__ . '/includes/public/layout-top.php';

$hero = ['title' => $p['title'], 'subtitle' => $p['subtitle'], 'intro' => $p['intro']];

include __DIR__ . '/includes/public/page-hero.php';

?>



<section class="site-section">

    <div class="site-section__inner">

        <div class="site-card-grid site-card-grid--2">

            <?php foreach ($forms as $slug => $form): ?>

                <article class="site-card site-card--feature">

                    <span class="site-card__icon site-card__icon--lg"><?= renderHomeServiceIcon($form['icon'] ?? ($slug === 'steward' ? 'steward' : 'shield')) ?></span>

                    <h2 class="site-card__title"><?= h($form['label'] ?? ucfirst($slug)) ?></h2>

                    <div class="site-card__text rich-content"><?= renderRichText($form['description'] ?? $form['subtitle'] ?? '') ?></div>

                    <a class="btn btn--primary" href="<?= h(getRegistrationFormUrl($pdo, $slug)) ?>">Register as <?= h($form['short_label'] ?? ucfirst($slug)) ?></a>

                </article>

            <?php endforeach; ?>

        </div>

    </div>

</section>



<section class="site-cta-band site-cta-band--slim">

    <div class="site-cta-band__inner">

        <p>Not sure which role fits? Browse all registration forms on one page.</p>

        <a class="btn btn--white" href="<?= h($web['registrationUrl']) ?>">View all forms</a>

    </div>

</section>



<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

