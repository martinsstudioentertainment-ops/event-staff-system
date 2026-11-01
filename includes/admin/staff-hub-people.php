<?php
/**
 * Staff hub — People tab (profiles + PSA). Expects $pdo and layout already started.
 * Variables set by staff.php: $peopleFilters, $peoplePage, $peoplePerPage, $peopleTotal,
 * $peopleList, $peopleBulkCount, $peoplePaginationQuery, $staffPortalUrl, $siteName, $pendingBadgeCount
 */
?>
<section class="card staff-dir-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff</h2>
            <p class="card__subtitle">People profiles — PSA verified first. Use Signups to approve shifts.</p>
        </div>
        <div class="toolbar toolbar--compact">
            <?php if ($peopleBulkCount > 0): ?>
                <form method="post" action="staff-send-profile-link.php" class="staff-dir-bulk-form" onsubmit="return confirm('Send profile update link to <?= (int) $peopleBulkCount ?> registered staff? Each person gets one email with sign-in links (not an approval email).');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="bulk_all" value="1">
                    <input type="hidden" name="redirect" value="<?= h('staff.php?tab=people' . ($peoplePaginationQuery !== [] ? '&' . http_build_query($peoplePaginationQuery) : '')) ?>">
                    <input type="hidden" name="filter_q" value="<?= h($peopleFilters['q']) ?>">
                    <input type="hidden" name="filter_role" value="<?= h($peopleFilters['role']) ?>">
                    <?php if ($peopleFilters['blacklisted'] !== null): ?>
                        <input type="hidden" name="filter_blacklisted" value="<?= (int) $peopleFilters['blacklisted'] ?>">
                    <?php endif; ?>
                    <?php if ($peopleFilters['profile'] !== ''): ?>
                        <input type="hidden" name="filter_profile" value="<?= h($peopleFilters['profile']) ?>">
                    <?php endif; ?>
                    <?php if ($peopleFilters['psa'] !== ''): ?>
                        <input type="hidden" name="filter_psa" value="<?= h($peopleFilters['psa']) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn--primary">Email profile link to all (<?= (int) $peopleBulkCount ?>)</button>
                </form>
            <?php endif; ?>
            <a href="export-staff.php" class="btn btn--secondary">Export CSV</a>
        </div>
    </div>

    <details class="staff-dir-email-preview">
        <summary>What does the bulk profile-link email say?</summary>
        <div class="staff-dir-email-preview__body">
            <p><strong>Subject:</strong> <?= h($siteName) ?> — complete your staff profile</p>
            <pre class="staff-dir-email-preview__sample">Dear [First name],

Please update your staff profile (address, bank details, and PSA licence photos) before your shift can be approved.

Option 1 — sign in with email and date of birth:
<?= h($staffPortalUrl) ?>


Option 2 — use your personal link:
[Unique link per person]

This is not an approval email.</pre>
            <p class="form-hint">Bulk send emails every registered staff member matching your filters (active accounts only). Blacklisted staff are skipped.</p>
        </div>
    </details>

    <div class="staff-dir-portal-banner">
        <div class="staff-dir-portal-banner__text">
            <strong>Link for all staff to update their profile</strong>
            <span class="form-hint">Email + date of birth — PSA photos, bank details, address</span>
        </div>
        <div class="copy-field staff-dir-portal-banner__copy">
            <input type="text" id="staff-portal-public-url" class="form-input copy-field__input" value="<?= h($staffPortalUrl) ?>" readonly>
            <button type="button" class="btn btn--small btn--secondary copy-field__btn" data-copy-target="staff-portal-public-url">Copy</button>
        </div>
    </div>

    <form method="get" class="filter-bar filter-bar--compact">
        <input type="hidden" name="tab" value="people">
        <div class="filter-bar__group">
            <input type="search" name="q" class="form-input" placeholder="Search name, email, mobile" value="<?= h($peopleFilters['q']) ?>">
        </div>
        <div class="filter-bar__group">
            <select name="role" class="form-select">
                <option value="">All roles</option>
                <option value="dsp"<?= $peopleFilters['role'] === 'dsp' ? ' selected' : '' ?>>DSP</option>
                <option value="static"<?= $peopleFilters['role'] === 'static' ? ' selected' : '' ?>>Static</option>
                <option value="steward"<?= $peopleFilters['role'] === 'steward' ? ' selected' : '' ?>>Steward</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select name="profile" class="form-select">
                <option value="">All profiles</option>
                <option value="complete"<?= $peopleFilters['profile'] === 'complete' ? ' selected' : '' ?>>Profile complete</option>
                <option value="incomplete"<?= $peopleFilters['profile'] === 'incomplete' ? ' selected' : '' ?>>Profile incomplete</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select name="psa" class="form-select">
                <option value="">All PSA status</option>
                <option value="verified"<?= $peopleFilters['psa'] === 'verified' ? ' selected' : '' ?>>PSA verified</option>
                <option value="issue"<?= $peopleFilters['psa'] === 'issue' ? ' selected' : '' ?>>PSA fake / expired / invalid</option>
                <option value="missing"<?= $peopleFilters['psa'] === 'missing' ? ' selected' : '' ?>>No PSA licence</option>
                <option value="unchecked"<?= $peopleFilters['psa'] === 'unchecked' ? ' selected' : '' ?>>PSA not checked yet</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select name="blacklisted" class="form-select">
                <option value="">All accounts</option>
                <option value="0"<?= $peopleFilters['blacklisted'] === false ? ' selected' : '' ?>>Active</option>
                <option value="1"<?= $peopleFilters['blacklisted'] === true ? ' selected' : '' ?>>Blacklisted</option>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="staff.php?tab=people" class="btn btn--secondary">Clear</a>
        </div>
    </form>

    <?php if ($peopleList === []): ?>
        <p class="form-hint">
            <?php if ($peopleFilters['q'] !== '' || $peopleFilters['role'] !== '' || $peopleFilters['profile'] !== '' || $peopleFilters['psa'] !== '' || $peopleFilters['blacklisted'] !== null): ?>
                No staff members match your filters.
            <?php else: ?>
                No staff members found.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="staff-psa-board">
            <?php foreach ($peopleList as $staff): ?>
                <?php
                $email = (string) $staff['email'];
                $profileOk = isStaffOnboardingComplete($staff);
                $name = trim((string) ($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? ''));
                if ($name === '') {
                    $name = 'Unnamed staff';
                }
                $reg = (int) ($staff['registration_count'] ?? 0);
                $pen = (int) ($staff['pending_count'] ?? 0);
                $app = (int) ($staff['approved_count'] ?? 0);
                $psaStatus = trim((string) ($staff['psa_register_status'] ?? ''));
                $tileClass = 'staff-psa-tile';
                if ($psaStatus === 'verified') {
                    $tileClass .= ' staff-psa-tile--verified';
                } elseif (psaRegisterStatusIsAlert($psaStatus)) {
                    $tileClass .= ' staff-psa-tile--issue';
                }
                $tileId = 'staff-card-' . (int) $staff['id'];
                $peopleRedirect = 'staff.php?tab=people' . ($peoplePaginationQuery !== [] ? '&' . http_build_query(array_filter(array_merge($peoplePaginationQuery, ['page' => $peoplePage > 1 ? $peoplePage : null]))) : ($peoplePage > 1 ? '&page=' . $peoplePage : ''));
                ?>
                <details class="<?= h($tileClass) ?>" id="<?= h($tileId) ?>">
                    <summary class="staff-psa-tile__front">
                        <span class="staff-psa-tile__who">
                            <strong><?= h($name) ?></strong>
                            <span class="staff-psa-tile__hint">Click to open</span>
                        </span>
                        <span class="list-card__badges">
                            <?= psaRegisterStatusBadgeHtml($psaStatus) ?>
                            <?php if ($pen > 0): ?>
                                <span class="badge badge--pending"><?= (int) $pen ?> pending</span>
                            <?php endif; ?>
                        </span>
                    </summary>
                    <div class="staff-psa-tile__body">
                        <dl class="staff-psa-tile__facts">
                            <div>
                                <dt>Email</dt>
                                <dd>
                                    <?php if ($email !== ''): ?>
                                        <a class="staff-dir-email" href="<?= h(buildStaffRegistrationsAdminUrl($email)) ?>"><?= h($email) ?></a>
                                    <?php else: ?>
                                        Not set
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt>Mobile</dt>
                                <dd><?= h((string) ($staff['mobile'] ?? '')) !== '' ? h((string) $staff['mobile']) : '—' ?></dd>
                            </div>
                            <div>
                                <dt>Role</dt>
                                <dd><?= h(formatStaffRoleLabel((string) $staff['staff_role'])) ?></dd>
                            </div>
                            <div>
                                <dt>PSA licence</dt>
                                <dd><?= trim((string) ($staff['psa_licence'] ?? '')) !== '' ? h((string) $staff['psa_licence']) : 'Not on file' ?></dd>
                            </div>
                            <div>
                                <dt>Profile</dt>
                                <dd><?= $profileOk ? 'Complete' : 'Incomplete' ?></dd>
                            </div>
                            <div>
                                <dt>Account</dt>
                                <dd><?= (int) ($staff['is_blacklisted'] ?? 0) === 1 ? 'Blocked' : 'Active' ?></dd>
                            </div>
                            <div>
                                <dt>Shifts</dt>
                                <dd>
                                    <a href="<?= h(buildStaffRegistrationsAdminUrl($email)) ?>"><?= $reg ?> all</a>
                                    ·
                                    <a href="<?= h(buildStaffRegistrationsAdminUrl($email, 'pending')) ?>"><?= $pen ?> pending</a>
                                    ·
                                    <a href="<?= h(buildStaffRegistrationsAdminUrl($email, 'approved')) ?>"><?= $app ?> approved</a>
                                </dd>
                            </div>
                        </dl>
                        <div class="staff-psa-tile__actions">
                            <a href="staff-edit.php?id=<?= (int) $staff['id'] ?>" class="btn btn--small btn--primary">Edit</a>
                            <a href="staff-inbox-thread.php?staff_id=<?= (int) $staff['id'] ?>" class="btn btn--small btn--secondary">Message</a>
                            <?php if ($pen > 0 && $email !== ''): ?>
                                <a href="<?= h(buildStaffRegistrationsAdminUrl($email, 'pending')) ?>" class="btn btn--small btn--success">Approve shifts</a>
                            <?php endif; ?>
                            <form method="post" action="staff-send-profile-link.php">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="staff_id" value="<?= (int) $staff['id'] ?>">
                                <input type="hidden" name="single_redirect" value="<?= h($peopleRedirect) ?>">
                                <button type="submit" class="btn btn--small btn--secondary">Send link</button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
        $peoplePagQuery = array_merge(['tab' => 'people'], $peoplePaginationQuery);
        renderAdminPagination($peoplePage, $peopleTotal, 'staff.php', $peoplePagQuery);
        ?>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!el) return;
        el.select();
        el.setSelectionRange(0, 99999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(el.value).catch(function () {});
        }
    });
});
</script>
