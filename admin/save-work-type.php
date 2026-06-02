<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-types-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: work-types.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Please try again.');
    header('Location: work-types.php');
    exit;
}

$id     = (int) ($_POST['id'] ?? 0);
$isEdit = $id > 0;
$pdo    = getDB();
$post   = $_POST;

$errors = validateWorkTypeData($post, $isEdit ? $id : null);

if ($errors !== []) {
    $_SESSION['work_type_form_errors'] = array_values($errors);
    $_SESSION['work_type_form_old']    = $post;
    header('Location: work-type-form.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}

try {
    if ($isEdit) {
        if (!getWorkTypeById($pdo, $id)) {
            setAdminFlash('error', 'Work type not found.');
            header('Location: work-types.php');
            exit;
        }
        updateWorkType($pdo, $id, $post);
        logAdminAudit($pdo, 'work_type_save', 'work_type', $id, 'Updated');
        setAdminFlash('success', 'Work type updated successfully.');
    } else {
        $newId = createWorkType($pdo, $post);
        logAdminAudit($pdo, 'work_type_save', 'work_type', $newId, 'Created');
        setAdminFlash('success', 'Work type created. Assign it to events and tick it on Registration forms.');
    }
} catch (PDOException $e) {
    setAdminFlash('error', 'Unable to save work type. Please try again.');
}

header('Location: work-types.php');
exit;
