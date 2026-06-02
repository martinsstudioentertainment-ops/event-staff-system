<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/rich-text.php';
require_once __DIR__ . '/../includes/admin/forms-nav.php';

requireAdminCapability('forms');

$pdo   = getDB();
$slug  = strtolower(trim((string) ($_GET['slug'] ?? '')));
$forms = getRegistrationForms($pdo);

if ($slug === '' || !isset($forms[$slug])) {
    header('Location: forms.php');
    exit;
}

$form   = $forms[$slug];
$error  = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request.';
    } else {
        $payload = [];
        foreach ($forms as $formSlug => $existing) {
        $payload[$slug] = [
            'label'              => (string) ($existing['label'] ?? ''),
            'short_label'        => (string) ($existing['short_label'] ?? ''),
            'title'              => (string) ($existing['title'] ?? ''),
            'subtitle'           => (string) ($existing['subtitle'] ?? ''),
            'description'        => (string) ($existing['description'] ?? ''),
            'enabled'            => !empty($existing['enabled']),
            'show_notice'        => !empty($existing['show_notice']),
            'selection_mode'     => (string) ($existing['selection_mode'] ?? 'venue_first'),
            'allowed_work_types' => is_array($existing['allowed_work_types'] ?? null) ? $existing['allowed_work_types'] : getDefaultWorkTypesForFormSlug($formSlug),
        ];
        }
        $payload[$slug] = [
            'label'              => (string) ($_POST['label'] ?? ''),
            'short_label'        => (string) ($_POST['short_label'] ?? ''),
            'title'              => (string) ($_POST['title'] ?? ''),
            'subtitle'           => richPost('subtitle'),
            'description'        => richPost('description'),
            'enabled'            => !empty($_POST['enabled']),
            'show_notice'        => !empty($_POST['show_notice']),
            'selection_mode'     => 'venue_first',
            'allowed_work_types' => array_values(array_filter(
                array_map('strval', (array) ($_POST['allowed_work_types'] ?? []))
            )),
        ];
        saveRegistrationForms($pdo, $payload);
        $forms = getRegistrationForms($pdo);
        $form  = $forms[$slug];
        $success = 'Form saved.';
    }
}

$shareUrl           = getRegistrationFormUrl($pdo, $slug);
$pageTitle          = (string) ($form['label'] ?? ucfirst($slug));
$activePage         = 'forms';
$adminSectionNav    = getAdminFormsNavItems();
$adminSectionActive = $slug;

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($form['label'] ?? ucfirst($slug)) ?></h2>
            <p class="card__subtitle">Edit this registration form and copy its share link.</p>
        </div>
        <a href="forms.php" class="btn btn--secondary">← All forms</a>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="form-group form-group--full">
            <label class="form-label">Share link</label>
            <div class="form-share-card__url-row">
                <input class="form-input" id="form-share-url" type="text" value="<?= h($shareUrl) ?>" readonly>
                <button type="button" class="btn btn--secondary" data-copy-target="form-share-url">Copy</button>
                <a class="btn btn--secondary" href="<?= h($shareUrl) ?>" target="_blank" rel="noopener">Open ↗</a>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="label">Role label</label>
            <input class="form-input" id="label" name="label" value="<?= h($form['label'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="short_label">Short label</label>
            <input class="form-input" id="short_label" name="short_label" value="<?= h($form['short_label'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="enabled" value="1"<?= !empty($form['enabled']) ? ' checked' : '' ?>>
                Form enabled
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="title">Form page title</label>
            <input class="form-input" id="title" name="title" value="<?= h($form['title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="subtitle">Form subtitle</label>
            <textarea class="form-textarea rich-text" id="subtitle" name="subtitle" rows="2"><?= h($form['subtitle'] ?? '') ?></textarea>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-textarea rich-text" id="description" name="description" rows="2"><?= h($form['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="show_notice" value="1"<?= !empty($form['show_notice']) ? ' checked' : '' ?>>
                Show scrolling notice on this form
            </label>
            <p class="form-hint">Notice text: <a href="website-global.php#notice_items">Website → Global → Notice banner</a></p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Work types shown on this form</label>
            <div class="form-checkbox-group">
                <?php
                $savedTypes = getFormAllowedWorkTypes($form);
                foreach (getWorkTypeOptionsForRegistrationForms($pdo, $savedTypes) as $value => $label):
                ?>
                    <label class="form-checkbox">
                        <input type="checkbox" name="allowed_work_types[]" value="<?= h($value) ?>"<?= in_array($value, $savedTypes, true) ? ' checked' : '' ?>>
                        <?= h($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="form-hint">Staff choose a venue first, then only postings with these work types appear. <a href="work-types.php">Add more work types</a> if yours are not listed.</p>
        </div>

        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save form</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
