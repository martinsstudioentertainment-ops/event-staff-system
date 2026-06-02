<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/work-types-repository.php';
require_once __DIR__ . '/../includes/admin/forms-nav.php';

requireAdminCapability('forms');

$pdo   = getDB();
$error = '';
$old   = [
    'slug'        => '',
    'label'       => '',
    'short_label' => '',
    'title'       => '',
    'enabled'     => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request.';
    } else {
        $old = [
            'slug'        => (string) ($_POST['slug'] ?? ''),
            'label'       => (string) ($_POST['label'] ?? ''),
            'short_label' => (string) ($_POST['short_label'] ?? ''),
            'title'       => (string) ($_POST['title'] ?? ''),
            'enabled'     => !empty($_POST['enabled']) ? '1' : '',
        ];
        $result = createRegistrationForm($pdo, $old['slug'], [
            'label'              => $old['label'],
            'short_label'        => $old['short_label'],
            'title'              => $old['title'],
            'enabled'            => $old['enabled'] === '1',
            'show_notice'        => true,
            'allowed_work_types' => array_values(array_filter(
                array_map('strval', (array) ($_POST['allowed_work_types'] ?? []))
            )),
        ]);
        if ($result['ok'] && !empty($result['slug'])) {
            header('Location: form-edit.php?slug=' . urlencode((string) $result['slug']) . '&created=1');
            exit;
        }
        $error = $result['message'];
    }
}

$pageTitle          = 'Add registration form';
$activePage         = 'forms';
$adminSectionNav    = getAdminFormsNavItems();
$adminSectionActive = 'new';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Add registration form / role</h2>
            <p class="card__subtitle">Creates a new public form and staff role. Use letters, numbers, underscores (e.g. <code>fire_marshal</code>, <code>first_aider</code>).</p>
        </div>
        <a href="forms.php" class="btn btn--secondary">← All forms</a>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="form-group">
            <label class="form-label form-label--required" for="slug">Form ID (URL)</label>
            <input class="form-input" type="text" id="slug" name="slug" value="<?= h($old['slug']) ?>" placeholder="fire_marshal" pattern="[a-zA-Z0-9_]+" required>
            <p class="form-hint">Share link will be: <?= h(getRegistrationSiteUrl($pdo)) ?>?form=<strong>your_id</strong></p>
        </div>
        <div class="form-group">
            <label class="form-label form-label--required" for="label">Role label</label>
            <input class="form-input" type="text" id="label" name="label" value="<?= h($old['label']) ?>" placeholder="Fire Marshal" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="short_label">Short label</label>
            <input class="form-input" type="text" id="short_label" name="short_label" value="<?= h($old['short_label']) ?>" placeholder="Optional">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="title">Form page title</label>
            <input class="form-input" type="text" id="title" name="title" value="<?= h($old['title']) ?>" placeholder="Fire Marshal Registration">
        </div>
        <div class="form-group form-group--full">
            <label class="form-checkbox">
                <input type="checkbox" name="enabled" value="1"<?= $old['enabled'] === '1' ? ' checked' : '' ?>>
                <span>Form enabled on registration site</span>
            </label>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Work types on this form</label>
            <div class="form-checkbox-group">
                <?php foreach (getWorkTypeOptionsForRegistrationForms($pdo) as $value => $label): ?>
                    <label class="form-checkbox">
                        <input type="checkbox" name="allowed_work_types[]" value="<?= h($value) ?>"<?= in_array($value, ['special_event', 'festival'], true) ? ' checked' : '' ?>>
                        <?= h($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Create form</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
