<?php
/**
 * Staff hub — Signups tab (registrations / approve queue).
 * Expects vars from staff.php: $filters, $pendingListMode, $flatEventList, $total, $page,
 * $listPerPage, $rows, $events, $queryString, $paginationQuery, $pendingListGap, $pendingDbCount, $pdo
 */
?>
<?php if ($pendingListGap): ?>
    <div class="alert alert--warning alert--visible">
        Database has <?= (int) $pendingDbCount ?> pending registration(s) but the list could not load them.
        <?php
        $rescueIds = [];
        try {
            $rescueIds = $pdo->query(
                'SELECT id, surname, first_name, email FROM staff_registrations WHERE ' . pendingRegistrationStatusSql() . ' ORDER BY id DESC LIMIT 20'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rescueIds = [];
        }
        ?>
        <?php if ($rescueIds !== []): ?>
            <ul style="margin:0.5rem 0 0 1.25rem">
                <?php foreach ($rescueIds as $rescue): ?>
                    <li>
                        <a href="view-staff.php?id=<?= (int) ($rescue['id'] ?? 0) ?>">
                            <?= h(trim(($rescue['first_name'] ?? '') . ' ' . ($rescue['surname'] ?? ''))) ?>
                            <?= trim((string) ($rescue['email'] ?? '')) !== '' ? '(' . h((string) $rescue['email']) . ')' : '' ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <a href="staff.php?tab=signups&amp;status=pending&amp;page=1">Reset filters</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Signups</h2>
            <p class="card__subtitle">
                <?php if ($pendingListMode && $total > 0): ?>
                    <?= (int) $total ?> pending registration<?= $total === 1 ? '' : 's' ?> — open a profile to approve each shift.
                <?php elseif ($pendingListMode): ?>
                    No pending registrations. Try <a href="staff.php?tab=signups">All statuses</a>.
                <?php elseif ($flatEventList): ?>
                    <?= (int) $total ?> registration<?= $total === 1 ? '' : 's' ?> for this event (<?= (int) $listPerPage ?> per page).
                <?php else: ?>
                    <?= (int) $total ?> staff — click a card to open. Valid PSA holders first.
                <?php endif; ?>
            </p>
        </div>
        <a href="export-staff.php<?= $queryString !== '' ? '?' . h($queryString) : '' ?>" class="btn btn--secondary">Export CSV</a>
    </div>

    <form method="get" class="filter-bar">
        <input type="hidden" name="tab" value="signups">
        <div class="filter-bar__group">
            <input class="form-input" type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search name, email, mobile">
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="status">
                <option value="">All statuses</option>
                <option value="pending"<?= $filters['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="approved"<?= $filters['status'] === 'approved' ? ' selected' : '' ?>>Approved</option>
                <option value="rejected"<?= $filters['status'] === 'rejected' ? ' selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="role">
                <option value="">All roles</option>
                <?php foreach (getKnownStaffRoles() as $role): ?>
                    <?php if ($role === 'both' || $role === 'security') { continue; } ?>
                    <option value="<?= h($role) ?>"<?= $filters['role'] === $role ? ' selected' : '' ?>><?= h(formatStaffRoleLabel($role)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="event_id">
                <option value="">All events</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= (int) $filters['event_id'] === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h($event['name'] . ' — ' . formatEventDateLabel((string) $event['event_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="per_page" aria-label="Rows per page">
                <?php foreach (adminStaffListPerPageOptions() as $perPageOpt): ?>
                    <option value="<?= (int) $perPageOpt ?>"<?= $listPerPage === $perPageOpt ? ' selected' : '' ?>><?= (int) $perPageOpt ?> per page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="staff.php?tab=signups" class="btn btn--secondary">Reset</a>
        </div>
    </form>

    <?php if ($total > $listPerPage): ?>
        <?php renderAdminPagination($page, $total, 'staff.php', $paginationQuery, $listPerPage); ?>
    <?php endif; ?>

    <?php if ($rows !== []): ?>
        <form method="post" action="bulk-status.php" class="bulk-toolbar" id="staff-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="redirect_query" value="<?= h($queryString) ?>">
            <input type="checkbox" id="staff-select-all" aria-label="Select all">
            <span class="bulk-toolbar__label"><span id="bulk-selected-count">0</span> selected</span>
            <button type="submit" name="status" value="approved" class="btn btn--small btn--success">Approve selected</button>
            <button type="submit" name="status" value="rejected" class="btn btn--small btn--danger">Reject selected</button>
            <button type="submit" name="status" value="pending" class="btn btn--small btn--secondary">Mark pending</button>
        </form>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="form-hint">No staff found.</p>
    <?php else: ?>
        <div class="list-card-board">
            <?php foreach ($rows as $row): ?>
                <?php
                $profileOk    = isStaffOnboardingComplete($row);
                $viewId         = (int) ($row['id'] ?? 0);
                $shiftCount     = ($pendingListMode || $flatEventList)
                    ? 1
                    : (int) ($row['registration_count'] ?? 1);
                $statusSummary  = ($pendingListMode || $flatEventList)
                    ? formatStatusLabel((string) ($row['status'] ?? 'pending'))
                    : formatRegistrantStatusSummary($row);
                $primaryStatus  = ($pendingListMode || $flatEventList)
                    ? (string) ($row['status'] ?? 'pending')
                    : registrantPrimaryStatus($row);
                $editStaffId    = (int) ($row['staff_id'] ?? 0);
                if ($editStaffId < 1 && trim((string) ($row['email'] ?? '')) !== '') {
                    $editStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) $row['email']) ?? 0);
                }
                $psaStatus = trim((string) ($row['psa_register_status'] ?? ''));
                $tone = $psaStatus === 'verified' ? 'ok' : (psaRegisterStatusIsAlert($psaStatus) ? 'issue' : ($primaryStatus === 'pending' ? 'pending' : ''));
                $personName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
                $signupRedirectBase = 'staff.php?' . ($queryString !== '' ? $queryString . '&' : 'tab=signups&') . 'page=' . (int) $page;
                ?>
                <details class="list-card<?= $tone !== '' ? ' list-card--' . h($tone) : '' ?>">
                    <summary class="list-card__front">
                        <span class="list-card__check">
                            <input type="checkbox" form="staff-bulk-form" name="<?= ($pendingListMode || $flatEventList) ? 'ids[]' : 'emails[]' ?>" value="<?= ($pendingListMode || $flatEventList) ? (int) $viewId : h((string) $row['email']) ?>" class="staff-row-check" aria-label="Select <?= h($personName) ?>">
                        </span>
                        <span class="list-card__who">
                            <strong><?= h($personName) ?></strong>
                            <span class="list-card__hint">Click to open</span>
                        </span>
                        <span class="list-card__badges">
                            <?= psaRegisterStatusBadgeHtml($psaStatus) ?>
                            <span class="badge badge--<?= h($primaryStatus) ?>"><?= h($statusSummary) ?></span>
                        </span>
                    </summary>
                    <div class="list-card__body">
                        <dl class="list-card__facts">
                            <div>
                                <dt>Email</dt>
                                <dd>
                                    <a class="staff-dir-email" href="<?= h(buildStaffRegistrationsAdminUrl((string) $row['email'])) ?>"><?= h((string) $row['email']) ?></a>
                                </dd>
                            </div>
                            <div>
                                <dt>Role</dt>
                                <dd><?= h(formatRoleLabel((string) ($row['staff_role'] ?? ''))) ?></dd>
                            </div>
                            <div>
                                <dt><?= ($pendingListMode || $flatEventList) ? 'Event' : 'Shifts' ?></dt>
                                <dd>
                                    <?php if ($pendingListMode || $flatEventList): ?>
                                        <?= h((string) ($row['event_name'] ?? '—')) ?>
                                    <?php else: ?>
                                        <?= $shiftCount === 1 ? '1 shift' : $shiftCount . ' shifts' ?>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt>Profile</dt>
                                <dd><?= $profileOk ? 'Complete' : 'Incomplete' ?></dd>
                            </div>
                            <div>
                                <dt>Last registered</dt>
                                <dd><?= h(formatSystemDateTime((string) ($row['last_registered'] ?? $row['created_at']), $pdo)) ?></dd>
                            </div>
                        </dl>
                        <div class="list-card__actions">
                            <a href="view-staff.php?id=<?= $viewId ?>" class="btn btn--small btn--secondary">Review</a>
                            <?php if ($editStaffId > 0): ?>
                                <a href="staff-edit.php?id=<?= $editStaffId ?>" class="btn btn--small btn--secondary">Edit profile</a>
                            <?php endif; ?>
                            <?php if ($pendingListMode && $primaryStatus === 'pending'): ?>
                                <form method="post" action="update-status.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="id" value="<?= $viewId ?>">
                                    <input type="hidden" name="status" value="approved">
                                    <input type="hidden" name="redirect" value="<?= h($signupRedirectBase) ?>">
                                    <button type="submit" class="btn btn--small btn--success">Approve</button>
                                </form>
                                <form method="post" action="update-status.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="id" value="<?= $viewId ?>">
                                    <input type="hidden" name="status" value="rejected">
                                    <input type="hidden" name="redirect" value="<?= h($signupRedirectBase) ?>">
                                    <button type="submit" class="btn btn--small btn--danger">Decline</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php renderAdminPagination($page, $total, 'staff.php', $paginationQuery, $listPerPage); ?>
</section>
