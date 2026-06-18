<?php

require_once __DIR__ . '/../website-content.php';
require_once __DIR__ . '/../brand-logo.php';
require_once __DIR__ . '/../rich-text.php';

/**
 * @return array{error: string, success: string, content: array<string, mixed>}
 */
function processWebsitePost(PDO $pdo, string $expectedAction): array
{
    $error   = '';
    $success = '';
    $content = getWebsiteContent($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return compact('error', 'success', 'content');
    }

    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        return ['error' => 'Invalid request.', 'success' => '', 'content' => $content];
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action !== $expectedAction) {
        return ['error' => 'Invalid form action.', 'success' => '', 'content' => $content];
    }

    if ($action === 'website_global') {
        $email = trim((string) ($_POST['company_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid company email.';
        } else {
            $logoError = null;

            if (!empty($_POST['remove_company_logo'])) {
                deleteCompanyLogoFile($pdo);
            }
            if (!empty($_POST['remove_company_share_image'])) {
                deleteCompanyShareImageFile($pdo);
            }

            $logoResult = handleCompanyLogoUpload($pdo, $_FILES['company_logo'] ?? []);
            if ($logoResult !== true) {
                $logoError = (string) $logoResult;
            }

            $shareResult = handleCompanyShareImageUpload($pdo, $_FILES['company_share_image'] ?? []);
            if ($shareResult !== true && $logoError === null) {
                $logoError = (string) $shareResult;
            } elseif ($shareResult !== true && $logoError !== null) {
                $logoError .= ' ' . (string) $shareResult;
            }

            $toSave = syncCompanyBrandingSettings($pdo, [
                'company_name'           => trim((string) ($_POST['company_name'] ?? '')),
                'company_email'          => $email,
                'company_phone'          => trim((string) ($_POST['company_phone'] ?? '')),
                'company_whatsapp'       => trim((string) ($_POST['company_whatsapp'] ?? '')),
                'company_whatsapp_group' => trim((string) ($_POST['company_whatsapp_group'] ?? '')),
                'company_tagline'        => trim((string) ($_POST['company_tagline'] ?? '')),
                'company_about'          => richPost('company_about'),
            ]);
            saveSettings($pdo, $toSave);
            saveWebsiteSection($pdo, 'global', [
                'brand_tag'      => trim((string) ($_POST['brand_tag'] ?? '')),
                'footer_tagline' => trim((string) ($_POST['footer_tagline'] ?? '')),
                'notice_enabled' => !empty($_POST['notice_enabled']),
                'notice_items'   => array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['notice_items'] ?? ''))))),
            ]);

            if ($logoError !== null) {
                $success = 'Company details saved. Logo issue: ' . $logoError;
            } else {
                $success = 'Global website settings saved.';
            }
        }
    } elseif ($action === 'website_home') {
        $stats = [];
        for ($i = 0; $i < 4; $i++) {
            $stats[] = [
                'value' => trim((string) ($_POST['stat_value_' . $i] ?? '')),
                'label' => trim((string) ($_POST['stat_label_' . $i] ?? '')),
            ];
        }
        saveWebsiteSection($pdo, 'home', [
            'hero_eyebrow'   => trim((string) ($_POST['hero_eyebrow'] ?? '')),
            'hero_title'     => trim((string) ($_POST['hero_title'] ?? '')),
            'hero_lead'      => richPost('hero_lead'),
            'cta_primary'    => trim((string) ($_POST['cta_primary'] ?? '')),
            'cta_secondary'  => trim((string) ($_POST['cta_secondary'] ?? '')),
            'preview_title'  => trim((string) ($_POST['preview_title'] ?? '')),
            'preview_desc'   => richPost('preview_desc'),
            'cta_band_title' => trim((string) ($_POST['cta_band_title'] ?? '')),
            'cta_band_desc'  => richPost('cta_band_desc'),
            'stats'          => $stats,
        ]);
        $success = 'Homepage content saved.';
    } elseif ($action === 'website_roles') {
        saveWebsiteSection($pdo, 'roles', [
            'title'    => trim((string) ($_POST['roles_title'] ?? '')),
            'subtitle' => trim((string) ($_POST['roles_subtitle'] ?? '')),
            'intro'    => richPost('roles_intro'),
        ]);
        $success = 'Roles page saved.';
    } elseif ($action === 'website_events') {
        $steps = [];
        for ($i = 0; $i < 4; $i++) {
            $steps[] = [
                'title' => trim((string) ($_POST['event_step_title_' . $i] ?? '')),
                'desc'  => richPost('event_step_desc_' . $i),
            ];
        }
        saveWebsiteSection($pdo, 'events', [
            'title'    => trim((string) ($_POST['events_title'] ?? '')),
            'subtitle' => trim((string) ($_POST['events_subtitle'] ?? '')),
            'intro'    => richPost('events_intro'),
            'steps'    => $steps,
        ]);
        $success = 'Events page saved.';
    } elseif ($action === 'website_how') {
        $steps = [];
        for ($i = 0; $i < 4; $i++) {
            $steps[] = [
                'num'   => trim((string) ($_POST['how_num_' . $i] ?? '')),
                'title' => trim((string) ($_POST['how_title_' . $i] ?? '')),
                'desc'  => richPost('how_desc_' . $i),
            ];
        }
        saveWebsiteSection($pdo, 'how', [
            'title'    => trim((string) ($_POST['how_page_title'] ?? '')),
            'subtitle' => trim((string) ($_POST['how_subtitle'] ?? '')),
            'intro'    => richPost('how_intro'),
            'steps'    => $steps,
            'trust'    => array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['how_trust'] ?? ''))))),
        ]);
        $success = 'How it works page saved.';
    } elseif ($action === 'website_contact') {
        $email = trim((string) ($_POST['company_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid contact email.';
        } else {
            saveSettings($pdo, [
                'company_email'          => $email,
                'company_phone'          => trim((string) ($_POST['company_phone'] ?? '')),
                'company_whatsapp'       => trim((string) ($_POST['company_whatsapp'] ?? '')),
                'company_whatsapp_group' => trim((string) ($_POST['company_whatsapp_group'] ?? '')),
            ]);
            saveWebsiteSection($pdo, 'contact', [
                'title'    => trim((string) ($_POST['contact_title'] ?? '')),
                'subtitle' => trim((string) ($_POST['contact_subtitle'] ?? '')),
                'intro'    => richPost('contact_intro'),
                'hours'    => trim((string) ($_POST['contact_hours'] ?? '')),
            ]);
            $success = 'Contact page saved.';
        }
    } elseif ($action === 'website_faq') {
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $q = trim((string) ($_POST['faq_q_' . $i] ?? ''));
            $a = richPost('faq_a_' . $i);
            if ($q !== '' || $a !== '') {
                $items[] = ['q' => $q, 'a' => $a];
            }
        }
        saveWebsiteSection($pdo, 'faq', [
            'title'    => trim((string) ($_POST['faq_title'] ?? '')),
            'subtitle' => trim((string) ($_POST['faq_subtitle'] ?? '')),
            'items'    => $items,
        ]);
        $success = 'FAQ page saved.';
    }

    $content = getWebsiteContent($pdo);

    return compact('error', 'success', 'content');
}
