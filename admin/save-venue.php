<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: venues.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Please try again.');
    header('Location: venues.php');
    exit;
}

$id     = (int) ($_POST['id'] ?? 0);
$isEdit = $id > 0;
$pdo    = getDB();
$post   = $_POST;

$errors = validateVenueData($post);

if ($errors !== []) {
    $_SESSION['venue_form_errors'] = array_values($errors);
    $_SESSION['venue_form_old']    = $post;
    header('Location: venue-form.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}

try {
    if ($isEdit) {
        if (!getVenueById($pdo, $id)) {
            setAdminFlash('error', 'Venue not found.');
            header('Location: venues.php');
            exit;
        }
        updateVenue($pdo, $id, $post);
        logAdminAudit($pdo, 'venue_save', 'venue', $id, 'Updated');
        setAdminFlash('success', 'Venue updated successfully.');
    } else {
        $newId = createVenue($pdo, $post);
        logAdminAudit($pdo, 'venue_save', 'venue', $newId, 'Created');
        setAdminFlash('success', 'Venue created successfully.');
    }
} catch (PDOException $e) {
    setAdminFlash('error', 'Unable to save venue. Please try again.');
}

header('Location: venues.php');
exit;
