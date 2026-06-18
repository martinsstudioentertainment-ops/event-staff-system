<?php



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/job-record-repository.php';

require_once __DIR__ . '/../includes/audit-log.php';



requireAdminCapability('invoices');



if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {

    setAdminFlash('error', 'Only administrators and managers can manage job records.');

    header('Location: job-records.php');

    exit;

}



$pdo = getDB();



function jobRecordListUrl(?array $record = null, array $post = []): string

{

    if ($record !== null && isPersonalWorkLog($record)) {

        return 'personal-invoices.php#work-logs';

    }



    $type = normalizeJobRecordInvoiceType(

        (string) ($post['invoice_type'] ?? $record['invoice_type'] ?? 'staff_commission')

    );



    return $type === 'personal' ? 'personal-invoices.php' : 'job-records.php';

}



function jobRecordFormUrl(int $id, ?array $record = null, array $post = []): string

{

    if ($record !== null && isPersonalWorkLog($record)) {

        return 'personal-work-log-form.php?id=' . $id;

    }



    $type = normalizeJobRecordInvoiceType(

        (string) ($post['invoice_type'] ?? $record['invoice_type'] ?? 'staff_commission')

    );



    return ($type === 'personal' ? 'personal-invoice-form.php' : 'job-record-form.php') . '?id=' . $id;

}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: job-records.php');

    exit;

}



if (!verifyCsrf($_POST['csrf_token'] ?? null)) {

    setAdminFlash('error', 'Invalid request. Please try again.');

    header('Location: job-records.php');

    exit;

}



$adminUser = getAdminUser();

$action    = (string) ($_POST['action'] ?? 'save');

$id        = (int) ($_POST['id'] ?? 0);

$existing  = $id > 0 ? getJobRecordById($pdo, $id) : null;

$listUrl   = jobRecordListUrl($existing, $_POST);

$adminId   = (int) ($adminUser['id'] ?? 0);



if ($action === 'combine_work_logs') {

    $workLogIds = [];

    foreach ((array) ($_POST['work_log_ids'] ?? []) as $pickedId) {

        $pickedId = (int) $pickedId;

        if ($pickedId > 0) {

            $workLogIds[] = $pickedId;

        }

    }



    if ($workLogIds === []) {

        setAdminFlash('error', 'Select at least one saved job to invoice.');

        header('Location: personal-invoices.php#work-logs');

        exit;

    }



    $result = combinePersonalWorkLogsIntoInvoice($pdo, $workLogIds, $_POST, $adminId);

    if (empty($result['ok'])) {

        setAdminFlash('error', (string) ($result['message'] ?? 'Could not create invoice.'));

        header('Location: personal-invoices.php#work-logs');

        exit;

    }



    $newId = (int) ($result['id'] ?? 0);

    logAdminAudit($pdo, 'create_job_record', 'saved_job_record', $newId, (string) ($_POST['invoice_number'] ?? ''));

    setAdminFlash('success', (string) ($result['message'] ?? 'Invoice created.'));



    if (!empty($_POST['save_and_print'])) {

        header('Location: print-job-record.php?id=' . $newId);

        exit;

    }



    header('Location: personal-invoice-form.php?id=' . $newId);

    exit;

}



if ($action === 'void' && $id > 0) {

    if ($existing === null) {

        setAdminFlash('error', 'Job record not found.');

    } else {

        if (isPersonalInvoiceRecord($existing)) {

            releasePersonalInvoiceWorkLogs($pdo, $id);

        }

        saveJobRecord($pdo, array_merge($existing, ['status' => 'void']), $adminId, $id);

        logAdminAudit($pdo, 'void_job_record', 'saved_job_record', $id, (string) $existing['invoice_number']);

        if (isPersonalWorkLog($existing)) {

            $label = 'Saved job removed.';

        } elseif (isPersonalJobRecord($existing)) {

            $label = 'Personal invoice marked as void.';

        } else {

            $label = 'Job record marked as void.';

        }

        setAdminFlash('success', $label);

        $listUrl = jobRecordListUrl($existing);

    }

    header('Location: ' . $listUrl);

    exit;

}



$isPersonal = normalizeJobRecordInvoiceType((string) ($_POST['invoice_type'] ?? '')) === 'personal';

$saveAsWorkLog = $isPersonal && (string) ($_POST['save_as'] ?? '') === 'work_log';



if ($saveAsWorkLog) {

    $result = savePersonalWorkLog($pdo, $_POST, $adminId, $id > 0 ? $id : null);

} elseif ($isPersonal) {

    $result = savePersonalInvoiceWithLines($pdo, $_POST, $adminId, $id > 0 ? $id : null);

} else {

    $result = saveJobRecord($pdo, $_POST, $adminId, $id > 0 ? $id : null);

}



if (empty($result['ok'])) {

    setAdminFlash('error', (string) ($result['message'] ?? 'Could not save.'));

    if ($id > 0) {

        header('Location: ' . jobRecordFormUrl($id, $existing, $_POST));

    } else {

        if ($saveAsWorkLog) {

            header('Location: personal-work-log-form.php');

        } else {

            header('Location: ' . ($isPersonal ? 'personal-invoice-form.php' : 'job-record-form.php'));

        }

    }

    exit;

}



$newId   = (int) ($result['id'] ?? $id);

$saved   = getJobRecordById($pdo, $newId);

$formUrl = jobRecordFormUrl($newId, $saved, $_POST);



logAdminAudit($pdo, $id > 0 ? 'update_job_record' : 'create_job_record', 'saved_job_record', $newId, (string) ($_POST['invoice_number'] ?? ''));



if (!empty($_POST['save_and_print'])) {

    header('Location: print-job-record.php?id=' . $newId);

    exit;

}



setAdminFlash('success', (string) ($result['message'] ?? 'Saved.'));

header('Location: ' . $formUrl);

exit;

