<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/settings-repository.php';

require_once __DIR__ . '/includes/theme.php';

require_once __DIR__ . '/includes/site-urls.php';

require_once __DIR__ . '/includes/company.php';

require_once __DIR__ . '/includes/website-content.php';



$pdo = getDB();

$web = initPublicWebsite($pdo, 'contact');

$p   = $web['content']['contact'];

$pageTitle = $p['title'] ?? 'Contact';

$pageDescription = ($p['subtitle'] ?? '') . ' — ' . $web['companyName'];

$whatsappUrl = $web['whatsappUrl'] ?? formatWhatsappHref($web['whatsapp'] ?? '');

$whatsappGroup = trim((string) ($web['whatsappGroup'] ?? ''));



include __DIR__ . '/includes/public/layout-top.php';

$hero = ['title' => $p['title'], 'subtitle' => $p['subtitle'], 'intro' => $p['intro']];

include __DIR__ . '/includes/public/page-hero.php';

?>



<section class="site-section">

    <div class="site-section__inner site-contact-grid">

        <div class="site-contact-card">

            <h2>Get in touch</h2>

            <dl class="site-contact-list">

                <div>

                    <dt>Email</dt>

                    <dd><a href="mailto:<?= h($web['email']) ?>"><?= h($web['email']) ?></a></dd>

                </div>

                <div>

                    <dt>Phone</dt>

                    <dd><a href="<?= h(formatTelHref($web['phone'])) ?>"><?= h($web['phone']) ?></a></dd>

                </div>

                <div>

                    <dt>WhatsApp</dt>

                    <dd>

                        <?php if ($whatsappUrl !== ''): ?>

                            <a href="<?= h($whatsappUrl) ?>" target="_blank" rel="noopener"><?= h($web['whatsapp']) ?></a>

                        <?php else: ?>

                            <?= h($web['whatsapp']) ?>

                        <?php endif; ?>

                    </dd>

                </div>

                <?php if ($whatsappGroup !== ''): ?>

                <div>

                    <dt>WhatsApp group</dt>

                    <dd><a href="<?= h($whatsappGroup) ?>" target="_blank" rel="noopener">Join our WhatsApp group</a></dd>

                </div>

                <?php endif; ?>

                <?php if (($p['hours'] ?? '') !== ''): ?>

                <div>

                    <dt>Hours</dt>

                    <dd><?= h($p['hours']) ?></dd>

                </div>

                <?php endif; ?>

            </dl>

            <div class="site-contact-actions">

                <a class="btn btn--primary btn--block" href="mailto:<?= h($web['email']) ?>?subject=Registration%20question">Send email</a>

                <?php if ($whatsappUrl !== ''): ?>

                    <a class="btn btn--whatsapp btn--block" href="<?= h($whatsappUrl) ?>" target="_blank" rel="noopener">Message on WhatsApp</a>

                <?php endif; ?>

                <?php if ($whatsappGroup !== ''): ?>

                    <a class="btn btn--secondary btn--block" href="<?= h($whatsappGroup) ?>" target="_blank" rel="noopener">Join WhatsApp group</a>

                <?php endif; ?>

            </div>

        </div>

        <div class="site-contact-side">

            <h3>Applying for work?</h3>

            <p>Use the registration form to apply for open events — it is the fastest way to get started.</p>

            <a class="btn btn--secondary btn--block" href="<?= h($web['registrationUrl']) ?>">Staff registration form</a>

            <hr>

            <h3>Common questions</h3>

            <p>Many answers are in our FAQ before you need to email.</p>

            <a class="site-text-link" href="<?= h(getWebsitePageUrl('faq', $pdo)) ?>">Read FAQ →</a>

        </div>

    </div>

</section>



<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

