<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';
require_once __DIR__ . '/../includes/admin-ui-settings.php';

requireAdminCapability('settings');

$pdo              = getDB();
$adminUser        = getAdminUser();
$resultUi         = processSettingsPost($pdo, $adminUser, 'ui_controls');
$resultTheme      = processSettingsPost($pdo, $adminUser, 'theme');
$error            = $resultUi['error'] !== '' ? $resultUi['error'] : $resultTheme['error'];
$success          = $resultUi['success'] !== '' ? $resultUi['success'] : $resultTheme['success'];
$settings         = $resultTheme['settings'];
$uiSettings       = getAdminUiSettings($pdo);
$uiScaleOptions   = getAdminUiScaleOptions();
$tableDensityOpts = getAdminTableDensityOptions();
$themePresets     = getThemePresets();
$activePresetKey  = getThemePresetKey($pdo);
$activePreset     = getActiveThemePreset($pdo);
$registrationUrl    = getRegistrationFormUrl($pdo);

$pageTitle          = 'UI controls';
$activePage         = 'settings-theme';
$erpSettingsActive  = 'ui';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Global UI controls</h2>
        <p class="card__subtitle">Compact scaling, spacing, inputs, tables and corner radius across the admin console.</p>
    </div>

    <?php if ($success !== '' && ($_POST['action'] ?? '') === 'ui_controls'): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== '' && ($_POST['action'] ?? '') === 'ui_controls'): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="ui_controls">

        <div class="form-group">
            <label class="form-label" for="ui_scale">UI scale</label>
            <select class="form-select" id="ui_scale" name="ui_scale">
                <?php foreach ($uiScaleOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $uiSettings['ui_scale'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="card_padding">Card padding</label>
            <input class="form-input" type="text" id="card_padding" name="card_padding" value="<?= h($uiSettings['card_padding']) ?>px" inputmode="numeric" pattern="[0-9]+px?" placeholder="20px">
            <p class="form-hint">12–32px</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="input_height">Input height</label>
            <input class="form-input" type="text" id="input_height" name="input_height" value="<?= h($uiSettings['input_height']) ?>px" inputmode="numeric" pattern="[0-9]+px?" placeholder="40px">
            <p class="form-hint">32–56px</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="table_density">Table density</label>
            <select class="form-select" id="table_density" name="table_density">
                <?php foreach ($tableDensityOpts as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $uiSettings['table_density'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="border_radius">Border radius</label>
            <input class="form-input" type="text" id="border_radius" name="border_radius" value="<?= h($uiSettings['border_radius']) ?>px" inputmode="numeric" pattern="[0-9]+px?" placeholder="12px">
            <p class="form-hint">4–24px</p>
        </div>

        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save UI controls</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Interface theme</h2>
        <p class="card__subtitle">Registration form and public site color presets.</p>
    </div>

    <?php if ($success !== '' && ($_POST['action'] ?? '') === 'theme'): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== '' && ($_POST['action'] ?? '') === 'theme'): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="theme-picker" id="theme-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="theme">

        <div class="theme-picker__controls form-grid">
            <div class="form-group">
                <label class="form-label" for="theme_category">Category</label>
                <select class="form-select" id="theme_category">
                    <?php foreach (getThemePresetCategories() as $catKey => $catLabel): ?>
                        <option value="<?= h($catKey) ?>"<?= $catKey === 'all' ? ' selected' : '' ?>><?= h($catLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="theme_preset">Interface theme</label>
                <select class="form-select" id="theme_preset" name="theme_preset" required>
                    <?php
                    $lastCategory = '';
                    foreach ($themePresets as $presetId => $preset):
                        if ($preset['category'] !== $lastCategory):
                            if ($lastCategory !== '') echo '</optgroup>';
                            echo '<optgroup label="' . h(ucfirst($preset['category'])) . '">';
                            $lastCategory = $preset['category'];
                        endif;
                    ?>
                        <option value="<?= h($presetId) ?>"
                                data-category="<?= h($preset['category']) ?>"
                                data-primary="<?= h($preset['primary']) ?>"
                                data-sidebar="<?= h($preset['sidebar']) ?>"
                                data-background="<?= h($preset['background']) ?>"
                                data-font="<?= h($preset['font']) ?>"
                                data-role="<?= h(getThemeRoleLabel($preset['category'])) ?>"
                                data-description="<?= h($preset['description']) ?>"
                            <?= $activePresetKey === $presetId ? ' selected' : '' ?>>
                            <?= h($preset['label']) ?> — <?= h($preset['description']) ?>
                        </option>
                    <?php endforeach;
                    if ($lastCategory !== '') echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="theme_font_family">Font family</label>
                <select class="form-select" id="theme_font_family" name="theme_font_family">
                    <option value="">Theme default</option>
                    <?php foreach (getThemeFontOptions() as $key => $option): ?>
                        <option value="<?= h($key) ?>"<?= ($settings['theme_font_family'] ?? '') === $key ? ' selected' : '' ?>><?= h($option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="theme_primary_color">Custom accent color</label>
                <div class="theme-color-field">
                    <input class="form-input theme-color-field__picker" type="color" id="theme_primary_color_picker" value="<?= h($activePreset['primary']) ?>">
                    <input class="form-input" type="text" id="theme_primary_color" name="theme_primary_color" value="<?= h($settings['theme_primary_color'] ?? '') ?>" placeholder="Optional">
                </div>
            </div>
        </div>

        <div class="theme-preview" id="theme-preview" aria-live="polite">
            <div class="theme-preview__swatch" id="theme-preview-swatch"></div>
            <div class="theme-preview__content">
                <div class="theme-preview__brand">
                    <span class="theme-preview__icon brand-icon" id="theme-preview-icon"><?= renderThemeCategoryIcon($activePreset['category']) ?></span>
                    <div class="theme-preview__brand-text">
                        <p class="theme-preview__site"><?= h($settings['site_name'] ?? 'Your Site Name') ?></p>
                        <p class="theme-preview__role" id="theme-preview-sample"><?= h(getThemeRoleLabel($activePreset['category'])) ?></p>
                    </div>
                </div>
                <p class="theme-preview__eyebrow" id="theme-preview-category"><?= h(getThemeCategoryMeta()[$activePreset['category']]['label'] ?? 'Theme') ?></p>
                <h4 class="theme-preview__title" id="theme-preview-title"><?= h($activePreset['label']) ?></h4>
                <p class="theme-preview__desc" id="theme-preview-desc"><?= h($activePreset['description']) ?></p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save theme</button>
            <a href="<?= h($registrationUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview form ↗</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
<script>
(function () {
    const themeSelect = document.getElementById('theme_preset');
    const categorySelect = document.getElementById('theme_category');
    const fontSelect = document.getElementById('theme_font_family');
    const fontHint = document.getElementById('theme-font-hint');
    const colorPicker = document.getElementById('theme_primary_color_picker');
    const colorText = document.getElementById('theme_primary_color');
    const previewSwatch = document.getElementById('theme-preview-swatch');
    const previewIcon = document.getElementById('theme-preview-icon');
    const previewCategory = document.getElementById('theme-preview-category');
    const previewTitle = document.getElementById('theme-preview-title');
    const previewDesc = document.getElementById('theme-preview-desc');
    const previewSample = document.getElementById('theme-preview-sample');
    const categoryIcons = <?= json_encode(getThemeCategoryIcons(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const categoryLabels = <?= json_encode(array_map(static fn (array $m): string => $m['label'], getThemeCategoryMeta()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const fontFamilies = { poppins: "'Poppins', sans-serif", inter: "'Inter', sans-serif", roboto: "'Roboto', sans-serif", system: "system-ui, sans-serif" };
    let fontTouched = <?= ($settings['theme_font_family'] ?? '') !== '' ? 'true' : 'false' ?>;

    function getSelectedOption() { return themeSelect?.options[themeSelect.selectedIndex] || null; }

    function filterThemesByCategory() {
        if (!themeSelect || !categorySelect) return;
        const category = categorySelect.value;
        let firstVisible = null, currentVisible = false;
        Array.from(themeSelect.options).forEach(function (option) {
            if (!option.dataset.category) return;
            const show = category === 'all' || option.dataset.category === category;
            option.hidden = !show; option.disabled = !show;
            if (show && !firstVisible) firstVisible = option;
            if (show && option.selected) currentVisible = true;
        });
        if (!currentVisible && firstVisible) firstVisible.selected = true;
        updatePreview();
    }

    function updatePreview() {
        const option = getSelectedOption();
        if (!option) return;
        const primary = colorText?.value.trim() || option.dataset.primary || '#2563eb';
        const sidebar = option.dataset.sidebar || '#111827';
        const background = option.dataset.background || '#f1f5f9';
        if (previewSwatch) previewSwatch.style.background = 'linear-gradient(90deg, ' + sidebar + ' 0 32%, ' + primary + ' 32% 58%, ' + background + ' 58% 100%)';
        const category = option.dataset.category || 'events';
        if (previewIcon && categoryIcons[category]) { previewIcon.innerHTML = categoryIcons[category]; previewIcon.style.background = primary; }
        if (previewCategory) previewCategory.textContent = categoryLabels[category] || category;
        if (previewTitle) { previewTitle.textContent = option.textContent.split(' — ')[0] || option.textContent; previewTitle.style.color = primary; }
        if (previewDesc) previewDesc.textContent = option.dataset.description || '';
        if (previewSample) previewSample.textContent = option.dataset.role || 'Event Staff';
        if (colorPicker && !colorText?.value.trim()) colorPicker.value = option.dataset.primary || '#2563eb';
    }

    categorySelect?.addEventListener('change', filterThemesByCategory);
    themeSelect?.addEventListener('change', updatePreview);
    fontSelect?.addEventListener('change', function () { fontTouched = fontSelect.value !== ''; updatePreview(); });
    colorPicker?.addEventListener('input', function () { if (colorText) colorText.value = colorPicker.value; updatePreview(); });
    colorText?.addEventListener('input', function () { if (colorPicker && /^#[0-9A-Fa-f]{6}$/.test(colorText.value)) colorPicker.value = colorText.value; updatePreview(); });
    filterThemesByCategory();
    updatePreview();
})();
</script>
