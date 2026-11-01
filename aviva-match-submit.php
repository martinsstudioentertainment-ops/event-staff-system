<?php
/**
 * AVIVA Match – Event Staff: on-site sign-up form (registered Olasentra staff only, 70 places).
 * URL: /aviva-match/apply  (also /aviva-match-submit.php). Test mode: ?test=1&key=<aviva_match_intake_key>.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/app-environment.php';
initSecureSession();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';
require_once __DIR__ . '/includes/aviva-match.php';

header('Cache-Control: no-store');
$pdo = getDB();
avivaMatchEnsureSchema($pdo);

// Admin test mode (guarded by intake key); marks rows is_test=1 so they can be deleted.
if (isset($_GET['test'])) {
    $expected = trim((string) getSetting($pdo, 'aviva_match_intake_key', ''));
    $_SESSION['aviva_match_test'] = $expected !== '' && hash_equals($expected, (string) ($_GET['key'] ?? '')) && $_GET['test'] === '1';
}
$isTest = !empty($_SESSION['aviva_match_test']);

$roles = ['Door Supervisor (DSP)', 'Static Security'];
$genders = ['Male', 'Female'];
$fields = ['first_name', 'surname', 'mobile', 'email', 'full_address', 'eircode', 'gender', 'bank_iban', 'staff_role', 'psa_licence'];
$old = array_fill_keys($fields, '');
$errors = [];
$result = null; // ['type' => success|error|unregistered|duplicate|full, 'message' => ...]

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($fields as $f) { $old[$f] = trim((string) ($_POST[$f] ?? '')); }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif (avivaMatchRateLimited($ip)) {
        $errors[] = 'Too many attempts. Wait 10 minutes and try again.';
    } else {
        if ($old['first_name'] === '' || $old['surname'] === '') { $errors[] = 'Enter your first name and surname.'; }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
        if (strlen(avivaMatchPhoneKey($old['mobile'])) < 9) { $errors[] = 'Enter a valid mobile number.'; }
        if ($old['full_address'] === '') { $errors[] = 'Enter your full address.'; }
        if ($old['eircode'] === '') { $errors[] = 'Enter your Eircode.'; }
        if (!in_array($old['gender'], $genders, true)) { $errors[] = 'Select your gender.'; }
        $iban = strtoupper(preg_replace('/\s+/', '', $old['bank_iban']) ?? '');
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) { $errors[] = 'Enter a valid IBAN (e.g. IE29AIBK93115212345678).'; }
        if (!in_array($old['staff_role'], $roles, true)) { $errors[] = 'Select your role.'; }
        if ($old['psa_licence'] === '') { $errors[] = 'Enter your PSA licence number.'; }
        if (empty($_POST['confirm'])) { $errors[] = 'Tick the confirmation box.'; }
    }

    if ($errors === []) {
        // 1) Eligibility first (no files stored for people who are rejected).
        $check = avivaMatchSignup($pdo, ['email' => $old['email'], 'mobile' => $old['mobile']], true);
        if (empty($check['ok'])) {
            $code = (string) ($check['code'] ?? 'error');
            $result = ['type' => $code === 'not_registered' ? 'unregistered' : $code, 'message' => (string) $check['message']];
        } else {
            // 2) Store PSA badge photos (web-denied storage, never in git).
            $front = avivaMatchStorePsaUpload('psa_front_image', 'front');
            $back  = avivaMatchStorePsaUpload('psa_back_image', 'back');
            foreach ([$front, $back] as $u) { if (isset($u['error'])) { $errors[] = $u['error']; } }
            if ($errors === []) {
                $row = [
                    'email' => $old['email'], 'mobile' => $old['mobile'], 'is_test' => $isTest ? 1 : 0,
                    'response_id' => 'site-' . bin2hex(random_bytes(6)), 'source' => 'site',
                    'form_first_name' => $old['first_name'], 'form_surname' => $old['surname'],
                    'form_email' => strtolower($old['email']), 'form_mobile' => $old['mobile'],
                    'full_address' => $old['full_address'], 'eircode' => strtoupper($old['eircode']),
                    'gender' => $old['gender'], 'bank_iban' => $iban, 'staff_role' => $old['staff_role'],
                    'psa_licence' => $old['psa_licence'],
                    'psa_front_path' => $front['path'], 'psa_back_path' => $back['path'],
                ];
                // 3) Final atomic check + insert (handles races for place 70 and duplicates).
                $r = avivaMatchSignup($pdo, $row, false);
                if (!empty($r['ok'])) {
                    avivaMatchAppendToSheet($pdo, $row + ['staff_id' => $r['staff_id'] ?? '']);
                    $result = ['type' => 'success', 'message' => (string) $r['message']];
                    $old = array_fill_keys($fields, '');
                } else {
                    foreach ([$front['path'], $back['path']] as $p) { @unlink(__DIR__ . '/' . $p); }
                    $code = (string) ($r['code'] ?? 'error');
                    $result = ['type' => $code === 'not_registered' ? 'unregistered' : $code, 'message' => (string) $r['message']];
                }
            }
        }
    }
}

$count = avivaMatchCount($pdo);
$full = $count >= AVIVA_MATCH_CAPACITY;
$date = avivaMatchDate($pdo);
$dateLabel = $date === 'TBC' ? 'Date TBC' : date('l j F Y', (int) strtotime($date));

$web = initPublicWebsite($pdo, 'home');
$pageTitle = AVIVA_MATCH_NAME . ' – Apply';
$pageDescription = AVIVA_MATCH_NAME . ' · ' . AVIVA_MATCH_VENUE . ' · ' . $dateLabel . ' · ' . AVIVA_MATCH_TIME . '. Registered Olasentra staff only.';
include __DIR__ . '/includes/public/layout-top.php';
$e = static fn(string $k): string => h($old[$k] ?? '');
?>
<style>
.am-form{display:grid;gap:14px;max-width:640px}
.am-form label{display:block;font-weight:600;margin-bottom:4px}
.am-form input[type=text],.am-form input[type=email],.am-form input[type=tel],.am-form select,.am-form textarea{width:100%;padding:12px;font-size:16px;border-radius:8px;border:1px solid #888;box-sizing:border-box}
.am-alert{padding:14px;border-radius:8px;margin:12px 0;font-weight:600}
.am-alert--ok{background:#e6f6ea;color:#14532d}.am-alert--err{background:#fdeaea;color:#7f1d1d}
.am-check{display:flex;gap:10px;align-items:flex-start}.am-check input{width:22px;height:22px}
</style>
<section class="site-section">
  <div class="site-section__inner">
    <p class="hp-eyebrow"><?= $full ? 'Event full' : 'Registered Olasentra staff only' ?><?= $isTest ? ' · TEST MODE' : '' ?></p>
    <h1 class="site-section__title"><?= h(AVIVA_MATCH_NAME) ?></h1>
    <p><?= h(AVIVA_MATCH_VENUE) ?> · <?= h($dateLabel) ?> · <?= h(AVIVA_MATCH_TIME) ?></p>
    <p><strong><?= (int) $count ?></strong> / <?= AVIVA_MATCH_CAPACITY ?> places confirmed</p>

    <?php if ($result !== null): ?>
      <?php if ($result['type'] === 'success'): ?>
        <div class="am-alert am-alert--ok" role="status"><?= h($result['message']) ?> You are <?= (int) $count ?> of <?= AVIVA_MATCH_CAPACITY ?>.</div>
      <?php elseif ($result['type'] === 'unregistered'): ?>
        <div class="am-alert am-alert--err" role="alert">Please register on olasentra.com first. <a href="https://register.olasentra.com/">Register here</a>, then come back to this form using the same email or mobile.</div>
      <?php elseif ($result['type'] === 'duplicate'): ?>
        <div class="am-alert am-alert--err" role="alert">You already have a place on this event.</div>
      <?php elseif ($result['type'] === 'full'): ?>
        <div class="am-alert am-alert--err" role="alert">Event full.</div>
      <?php else: ?>
        <div class="am-alert am-alert--err" role="alert"><?= h($result['message']) ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
      <div class="am-alert am-alert--err" role="alert"><?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($full): ?>
      <div class="am-alert am-alert--err">Event full. All <?= AVIVA_MATCH_CAPACITY ?> places are taken.</div>
    <?php elseif (($result['type'] ?? '') !== 'success'): ?>
      <p>Only staff already registered on Olasentra can take a place. Use the same email or mobile you registered with.</p>
      <form class="am-form" method="post" enctype="multipart/form-data" action="/aviva-match-submit.php" novalidate>
        <?= csrfField() ?>
        <div><label for="first_name">First name</label><input type="text" id="first_name" name="first_name" value="<?= $e('first_name') ?>" autocomplete="given-name" required></div>
        <div><label for="surname">Surname</label><input type="text" id="surname" name="surname" value="<?= $e('surname') ?>" autocomplete="family-name" required></div>
        <div><label for="mobile">Mobile number</label><input type="tel" id="mobile" name="mobile" value="<?= $e('mobile') ?>" autocomplete="tel" inputmode="tel" required></div>
        <div><label for="email">Email address</label><input type="email" id="email" name="email" value="<?= $e('email') ?>" autocomplete="email" required></div>
        <div><label for="full_address">Full address</label><textarea id="full_address" name="full_address" rows="2" autocomplete="street-address" required><?= $e('full_address') ?></textarea></div>
        <div><label for="eircode">Eircode</label><input type="text" id="eircode" name="eircode" value="<?= $e('eircode') ?>" autocomplete="postal-code" required></div>
        <div><label for="gender">Gender</label><select id="gender" name="gender" required><option value="">Select…</option>
          <?php foreach ($genders as $g): ?><option<?= $old['gender'] === $g ? ' selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
        <div><label for="bank_iban">IBAN</label><input type="text" id="bank_iban" name="bank_iban" value="<?= $e('bank_iban') ?>" autocomplete="off" required></div>
        <div><label for="staff_role">Role</label><select id="staff_role" name="staff_role" required><option value="">Select…</option>
          <?php foreach ($roles as $r): ?><option<?= $old['staff_role'] === $r ? ' selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?></select></div>
        <div><label for="psa_licence">PSA licence number</label><input type="text" id="psa_licence" name="psa_licence" value="<?= $e('psa_licence') ?>" required></div>
        <div><label for="psa_front_image">PSA badge – front (photo)</label><input type="file" id="psa_front_image" name="psa_front_image" accept="image/*" required></div>
        <div><label for="psa_back_image">PSA badge – back (photo)</label><input type="file" id="psa_back_image" name="psa_back_image" accept="image/*" required></div>
        <div class="am-check"><input type="checkbox" id="confirm" name="confirm" value="1" required>
          <label for="confirm">I confirm I am registered on Olasentra, my details are correct, and I can work <?= h(AVIVA_MATCH_TIME) ?> on the day.</label></div>
        <button class="hp-btn hp-btn--primary" type="submit">Submit</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>
