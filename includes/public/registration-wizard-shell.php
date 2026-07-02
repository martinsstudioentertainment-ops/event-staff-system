<?php

declare(strict_types=1);

/**
 * Registration wizard chrome - progress bar + nav (inside #registration-form).
 *
 * @param int $openEventsCount
 */
function renderRegistrationWizardShell(int $openEventsCount = 0): void
{
    $steps = [
        1 => 'Welcome',
        2 => 'Pick shift',
        3 => 'Email',
        4 => 'About you',
        5 => 'Contact',
        6 => 'Payroll',
        7 => 'PSA',
        8 => 'Review',
    ];
    ?>
    <div class="reg-wizard" id="registration-wizard" data-total-steps="8">
        <div class="reg-wizard__progress" aria-label="Registration progress">
            <div class="reg-wizard__progress-meta">
                <span class="reg-wizard__step-label" id="reg-wizard-step-label">Step 1 of 8</span>
                <span class="reg-wizard__step-name" id="reg-wizard-step-name">Welcome</span>
            </div>
            <div class="reg-wizard__bar" role="progressbar" aria-valuemin="1" aria-valuemax="8" aria-valuenow="1" id="reg-wizard-progress-bar">
                <span class="reg-wizard__bar-fill" id="reg-wizard-progress-fill" style="width:12.5%"></span>
            </div>
            <ol class="reg-wizard__dots" aria-hidden="true">
                <?php foreach ($steps as $num => $label): ?>
                    <li class="reg-wizard__dot<?= $num === 1 ? ' reg-wizard__dot--active' : '' ?>" data-step-dot="<?= (int) $num ?>" title="<?= h($label) ?>"></li>
                <?php endforeach; ?>
            </ol>
            <p class="reg-wizard__save-status" id="reg-wizard-save-status" aria-live="polite" hidden>
                <span class="reg-wizard__save-status-icon" aria-hidden="true"></span>
                <span id="reg-wizard-save-text">Draft saved on this device</span>
            </p>
        </div>

        <p class="reg-wizard__estimate">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            Estimated completion time: <strong>3-5 minutes</strong>
        </p>

        <?php if ($openEventsCount > 0): ?>
            <p class="reg-wizard__opportunities">
                <strong><?= (int) $openEventsCount ?></strong> event opportunit<?= $openEventsCount === 1 ? 'y' : 'ies' ?> open now
            </p>
        <?php endif; ?>

        <ul class="reg-wizard__trust" aria-label="Trust indicators">
            <li>Free registration</li>
            <li>Mobile friendly</li>
            <li>Secure profile storage</li>
            <li>Connects you to opportunities</li>
        </ul>

        <p class="reg-wizard__platform-note" role="note">
            Olasentra connects people with opportunities. Employment, pay, contracts and working conditions are handled by employers and event organisers.
        </p>
    </div>
    <?php
}

function renderRegistrationWizardNav(): void
{
    ?>
    <nav class="reg-wizard__nav" id="reg-wizard-nav" aria-label="Wizard navigation">
        <button type="button" class="btn btn--secondary reg-wizard__btn-back" id="reg-wizard-back" hidden>Back</button>
        <button type="button" class="btn btn--primary reg-wizard__btn-next" id="reg-wizard-next">Continue</button>
        <button type="submit" class="btn btn--primary reg-wizard__btn-submit" id="reg-wizard-submit" form="registration-form" hidden>Submit registration</button>
        <a href="staff-app.php" class="btn btn--primary reg-wizard__btn-home" id="reg-wizard-home" hidden>Return to home</a>
    </nav>
    <?php
}
