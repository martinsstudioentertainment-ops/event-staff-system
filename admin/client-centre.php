<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/clients-repository.php';

requireAdminCapability('invoices');

$pdo   = getDB();
$flash = getAdminFlash();
auto_ensure_schema($pdo);
auto_ensure_phase67_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'add_note') {
        $admin = getAdminUser();
        clients_add_note($pdo, (int) ($_POST['client_id'] ?? 0), (string) ($_POST['note_text'] ?? ''), $admin ? (int) $admin['id'] : null);
        setAdminFlash('success', 'Note added.');
        header('Location: client-centre.php?id=' . (int) ($_POST['client_id'] ?? 0));
        exit;
    }
    clients_save($pdo, $_POST, (int) ($_POST['id'] ?? 0) ?: null);
    setAdminFlash('success', 'Client saved.');
    header('Location: client-centre.php');
    exit;
}

$clientId = (int) ($_GET['id'] ?? 0);
$client   = $clientId > 0 ? clients_get($pdo, $clientId) : null;
$clients  = clients_list($pdo);
$events   = $client ? clients_event_history($pdo, $clientId) : [];
$invoices = $client ? clients_invoice_history($pdo, (string) ($client['name'] ?? '')) : [];
$contacts = $clientId > 0 ? clients_contacts($pdo, $clientId) : [];
$notes    = $clientId > 0 ? clients_notes($pdo, $clientId) : [];

$pageTitle  = 'Customer Management';
$activePage = 'client-centre';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Customer Management</h1>
        <p class="wf-hero__subtitle">Clients, contacts, venues, contracts, invoices, and event history.</p>
    </div>
    <a href="venues.php" class="btn btn--secondary">Venues</a>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:1rem;">
    <section class="card erp-card">
        <h2 class="wf-panel__title">Clients</h2>
        <ul style="list-style:none;padding:0;margin:0;">
            <?php foreach ($clients as $c): ?>
                <li style="margin-bottom:0.35rem;"><a href="client-centre.php?id=<?= (int) ($c['id'] ?? 0) ?>"><?= h((string) ($c['name'] ?? '')) ?></a>
                <?php if (!empty($c['invoice_count'])): ?> <span class="text-muted">(<?= (int) $c['invoice_count'] ?> inv.)</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if ($clients === []): ?><li class="text-muted">No clients — add below or from invoices.</li><?php endif; ?>
        </ul>
    </section>

    <div>
        <section class="card erp-card">
            <h2 class="wf-panel__title"><?= $client ? 'Edit client' : 'Add client' ?></h2>
            <form method="post" class="wf-filters"><?= csrfField() ?>
                <?php if ($client): ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><?php endif; ?>
                <div><label>Name</label><input name="name" class="input" required value="<?= h((string) ($client['name'] ?? '')) ?>"></div>
                <div><label>Contact</label><input name="contact_name" class="input" value="<?= h((string) ($client['contact_name'] ?? '')) ?>"></div>
                <div><label>Email</label><input name="email" type="email" class="input" value="<?= h((string) ($client['email'] ?? '')) ?>"></div>
                <div><label>Phone</label><input name="phone" class="input" value="<?= h((string) ($client['phone'] ?? '')) ?>"></div>
                <div class="form-group--full" style="grid-column:1/-1;"><label>Address</label><textarea name="address" class="input"><?= h((string) ($client['address'] ?? '')) ?></textarea></div>
                <div><label><input type="checkbox" name="is_active" value="1" <?= !$client || !empty($client['is_active']) ? 'checked' : '' ?>> Active</label></div>
                <div><button class="btn btn--primary">Save</button></div>
            </form>
        </section>

        <?php if ($client && $clientId > 0): ?>
        <section class="card erp-card" style="margin-top:1rem;">
            <h2 class="wf-panel__title">Client notes</h2>
            <form method="post" class="wf-filters"><?= csrfField() ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="client_id" value="<?= $clientId ?>">
                <div class="form-group--full" style="grid-column:1/-1;"><textarea name="note_text" class="input" rows="2" required placeholder="Add a note…"></textarea></div>
                <div><button class="btn btn--secondary">Add note</button></div>
            </form>
            <?php if ($notes === []): ?><p class="text-muted">No notes yet.</p><?php else: ?>
            <ul><?php foreach ($notes as $n): ?><li><?= nl2br(h((string) ($n['note_text'] ?? ''))) ?> <span class="text-muted">— <?= h(formatSystemDateTime((string) ($n['created_at'] ?? ''), $pdo)) ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section class="card erp-card" style="margin-top:1rem;">
            <h2 class="wf-panel__title">Event history</h2>
            <?php if ($events === []): ?><p class="text-muted">No events linked (assign client_id on events).</p><?php else: ?>
            <ul><?php foreach ($events as $ev): ?><li><?= h($ev['name'] ?? '') ?> — <?= h(formatSystemDate((string) ($ev['event_date'] ?? ''), $pdo)) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section class="card erp-card" style="margin-top:1rem;">
            <h2 class="wf-panel__title">Invoice / revenue history</h2>
            <?php if ($invoices === []): ?><p class="text-muted">No invoices for this client name.</p><?php else: ?>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Status</th><th>Amount</th></tr></thead><tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr><td><?= h((string) ($inv['invoice_number'] ?? '')) ?></td><td><?= h((string) ($inv['status'] ?? '')) ?></td><td><?= h(formatSystemCurrencyAmount((float) ($inv['total_amount'] ?? 0), $pdo)) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
