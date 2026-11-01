<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-users-repository.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('users');

$pdo       = getDB();
$adminUser = getAdminUser();
$flash     = getAdminFlash();
$allUsers  = listAdminUsers($pdo);
$total     = count($allUsers);
$page      = adminListPage();
$users     = array_slice($allUsers, adminListOffset($page), adminListPerPage());
$editId    = (int) ($_GET['edit'] ?? 0);
$editUser  = $editId > 0 ? getAdminUserRecord($pdo, $editId) : null;

$pageTitle  = 'Users';
$activePage = 'users';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Team users</h2>
            <p class="card__subtitle">Add managers and staff so you are not managing everything alone.</p>
        </div>
    </div>

    <div class="erp-role-legend">
        <span class="erp-role-pill erp-role-pill--admin">Administrator — full access, website &amp; settings</span>
        <span class="erp-role-pill erp-role-pill--manager">Manager — staff, events, attendance, exports</span>
        <span class="erp-role-pill erp-role-pill--staff">Staff — attendance &amp; scan check-in only</span>
    </div>

    <div class="erp-split">
        <div class="erp-split__main">
            <?php if ($users === []): ?>
                <p class="form-hint">No users yet.</p>
            <?php else: ?>
                <div class="list-card-board">
                    <?php foreach ($users as $user): ?>
                        <?php $activeUser = (int) $user['is_active'] === 1; ?>
                        <details class="list-card list-card--<?= $activeUser ? 'ok' : 'issue' ?>">
                            <summary class="list-card__front">
                                <span class="list-card__who">
                                    <strong><?= h($user['full_name']) ?></strong>
                                    <span class="list-card__hint">Click to open</span>
                                </span>
                                <span class="list-card__badges">
                                    <span class="erp-role-badge erp-role-badge--<?= h($user['role']) ?>"><?= h(formatAdminRoleLabel($user['role'])) ?></span>
                                    <span class="badge <?= $activeUser ? 'badge--approved' : 'badge--rejected' ?>"><?= $activeUser ? 'Active' : 'Inactive' ?></span>
                                </span>
                            </summary>
                            <div class="list-card__body">
                                <dl class="list-card__facts">
                                    <div>
                                        <dt>Username</dt>
                                        <dd><?= h($user['username']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Email</dt>
                                        <dd><?= !empty($user['email']) ? h($user['email']) : '—' ?></dd>
                                    </div>
                                    <div>
                                        <dt>Last login</dt>
                                        <dd><?= !empty($user['last_login_at']) ? h(formatSystemDateTime((string) $user['last_login_at'], $pdo)) : '—' ?></dd>
                                    </div>
                                </dl>
                                <div class="list-card__actions">
                                    <a href="users.php?edit=<?= (int) $user['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                                    <?php if ((int) $user['id'] !== (int) $adminUser['id'] && $activeUser): ?>
                                        <form method="post" action="user-action.php" class="inline-form" onsubmit="return confirm('Deactivate this user?');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                            <button type="submit" class="btn btn--danger btn--sm">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="erp-split__aside">
            <div class="erp-panel user-form-panel">
                <h3 class="erp-panel__title"><?= $editUser ? 'Edit user' : 'Add user' ?></h3>
                <p class="user-form-panel__hint">Fill in the details below to <?= $editUser ? 'update this account' : 'create a new login' ?>.</p>
                <?php if ($editUser): ?>
                    <p class="form-hint"><a href="users.php">← Add new instead</a></p>
                <?php endif; ?>

                <form method="post" action="user-action.php" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="full_name">Full name</label>
                        <input class="form-input" type="text" id="full_name" name="full_name" required maxlength="100" value="<?= h($editUser['full_name'] ?? '') ?>">
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="username">Username</label>
                        <input class="form-input" type="text" id="username" name="username" required maxlength="50" autocomplete="off" value="<?= h($editUser['username'] ?? '') ?>">
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-input" type="email" id="email" name="email" maxlength="255" value="<?= h($editUser['email'] ?? '') ?>">
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="role">Role</label>
                        <select class="form-select" id="role" name="role" required>
                            <?php foreach (getAdminRoleOptions() as $role): ?>
                                <option value="<?= h($role) ?>"<?= ($editUser['role'] ?? 'staff') === $role ? ' selected' : '' ?>><?= h(formatAdminRoleLabel($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label<?= $editUser ? '' : ' form-label--required' ?>" for="password">Password<?= $editUser ? ' (leave blank to keep)' : '' ?></label>
                        <input class="form-input" type="password" id="password" name="password" autocomplete="new-password" minlength="8"<?= $editUser ? '' : ' required' ?>>
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-checkbox">
                            <input type="checkbox" name="is_active" value="1"<?= !$editUser || (int) ($editUser['is_active'] ?? 1) ? ' checked' : '' ?>>
                            Account active
                        </label>
                    </div>

                    <div class="form-actions form-group--full">
                        <button type="submit" class="btn btn--primary btn--block"><?= $editUser ? 'Save changes' : 'Create user' ?></button>
                    </div>
                </form>
            </div>
        </aside>
    </div>
    <?php renderAdminPagination($page, $total, 'users.php', $editId > 0 ? ['edit' => $editId] : []); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
