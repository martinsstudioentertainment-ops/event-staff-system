<?php

declare(strict_types=1);

require_once __DIR__ . '/google-form-event-integrations.php';
require_once __DIR__ . '/google-drive-oauth.php';
require_once __DIR__ . '/google-sheets-sync.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/date-format.php';

/**
 * @return list<string>
 */
function googleFormCreatedSheetHeaders(): array
{
    return [
        'Timestamp',
        'Email Address',
        'First name',
        'Surname',
        'Mobile number',
        'Full address',
        'Eircode',
        'Gender',
        'IBAN',
        'Role',
        'PSA licence number',
        'PPS number',
        'Date of birth',
        'PSA badge, front',
        'PSA badge, back',
    ];
}

function googleFormCreateNeedsReauth(string $body): bool
{
    $n = strtolower($body);

    return str_contains($n, 'access_token_scope_insufficient')
        || str_contains($n, 'insufficient_scope')
        || str_contains($n, 'insufficient permissions');
}

/**
 * @return array{code: int, body: string, json: array<string, mixed>}
 */
function googleFormApiRequest(string $token, string $method, string $url, ?array $payload = null): array
{
    $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $response = googleSheetsHttpRequest(
        $method,
        $url,
        googleSheetsAuthHeaders($token, null, $body !== null),
        is_string($body) ? $body : null
    );
    $json = json_decode((string) $response['body'], true);

    return [
        'code' => (int) $response['code'],
        'body' => (string) $response['body'],
        'json' => is_array($json) ? $json : [],
    ];
}

function googleFormCloudProjectId(): string
{
    $sa = loadGoogleServiceAccount();
    if (!is_array($sa)) {
        return '';
    }

    return trim((string) ($sa['project_id'] ?? ''));
}

function googleFormEnableConsoleUrl(): string
{
    $project = googleFormCloudProjectId() ?: 'event-staff-system-498114';

    return 'https://console.developers.google.com/apis/api/forms.googleapis.com/overview?project=' . rawurlencode($project);
}

/**
 * @return array{ok: bool, already?: bool, error?: string, http?: int}
 */
function googleFormEnableFormsApi(): array
{
    $project = googleFormCloudProjectId();
    if ($project === '') {
        return ['ok' => false, 'error' => 'Google Cloud project is not configured.'];
    }
    $sa = loadGoogleServiceAccount();
    if ($sa === null) {
        return ['ok' => false, 'error' => 'Service account is not configured.'];
    }
    $token = (string) (googleSheetsGetAccessToken($sa, [
        'https://www.googleapis.com/auth/cloud-platform',
    ]) ?? '');
    if ($token === '') {
        return [
            'ok'    => false,
            'error' => 'Open ' . googleFormEnableConsoleUrl() . ' and click Enable.',
        ];
    }
    $url = 'https://serviceusage.googleapis.com/v1/projects/'
        . rawurlencode($project)
        . '/services/forms.googleapis.com:enable';
    $response = googleSheetsHttpRequest('POST', $url, googleSheetsAuthHeaders($token, null, true), '{}');
    $code = (int) $response['code'];
    $body = strtolower((string) $response['body']);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'http' => $code];
    }
    if ($code === 409 || str_contains($body, 'already enabled') || str_contains($body, 'already exists')) {
        return ['ok' => true, 'already' => true, 'http' => $code];
    }

    return [
        'ok'    => false,
        'http'  => $code,
        'error' => 'Open ' . googleFormEnableConsoleUrl() . ' and click Enable.',
    ];
}

function googleFormCreateShareReader(string $token, string $fileId, string $email): array
{
    $payload = [
        'type'                  => 'user',
        'role'                  => 'reader',
        'emailAddress'          => $email,
        'sendNotificationEmail' => false,
    ];
    $url = 'https://www.googleapis.com/drive/v3/files/'
        . rawurlencode($fileId)
        . '/permissions?supportsAllDrives=true';

    return googleFormApiRequest($token, 'POST', $url, $payload);
}

function googleFormCreateServiceAccountCanReadSheet(string $sheetId): bool
{
    $sa = loadGoogleServiceAccount();
    if ($sa === null) {
        return false;
    }
    $token = (string) (googleSheetsGetAccessToken($sa, [
        'https://www.googleapis.com/auth/spreadsheets.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
    ]) ?? '');
    if ($token === '') {
        return false;
    }
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId) . '?fields=spreadsheetId';
    $response = googleSheetsHttpRequest('GET', $url, googleSheetsAuthHeaders($token, null, false));

    return $response['code'] === 200;
}

function googleFormRegistrationDescription(array $event): string
{
    $name = trim((string) ($event['name'] ?? 'Staff registration'));
    $date = formatEventDateLabel((string) ($event['event_date'] ?? ''));
    if ($date !== '' && stripos($name, $date) === false) {
        return $name . ' — ' . $date;
    }

    return $name;
}

/**
 * @return list<array<string, mixed>>
 */
function googleFormCreateItemRequests(bool $includePps = false, bool $includeDob = false): array
{
    $short = static function (string $title, string $help, bool $required) : array {
        $item = [
            'title' => $title,
            'questionItem' => [
                'question' => [
                    'required' => $required,
                    'textQuestion' => new stdClass(),
                ],
            ],
        ];
        if ($help !== '') {
            $item['description'] = $help;
        }

        return $item;
    };
    $para = static function (string $title, string $help, bool $required) : array {
        $item = [
            'title' => $title,
            'questionItem' => [
                'question' => [
                    'required' => $required,
                    'textQuestion' => ['paragraph' => true],
                ],
            ],
        ];
        if ($help !== '') {
            $item['description'] = $help;
        }

        return $item;
    };
    $choice = static function (string $title, array $options, string $type, bool $required) : array {
        return [
            'title' => $title,
            'questionItem' => [
                'question' => [
                    'required' => $required,
                    'choiceQuestion' => [
                        'type'    => $type,
                        'options' => array_map(static fn(string $v): array => ['value' => $v], $options),
                    ],
                ],
            ],
        ];
    };

    $items = [
        $short('First name', '', true),
        $short('Surname', '', true),
        $short('Email', '', true),
        $short('Mobile number', '', true),
        $para('Full address', '', true),
        $short('Eircode', '', true),
        $choice('Gender', ['Male', 'Female'], 'RADIO', true),
        $short('IBAN', 'Irish example IE29AIBK93115212345678', false),
        $choice('Role', ['DSP', 'Static'], 'DROP_DOWN', true),
        $short('PSA licence number', 'Format EM123456/00.', true),
    ];
    if ($includePps) {
        $items[] = $short('PPS number', '', true);
    }
    if ($includeDob) {
        $items[] = [
            'title' => 'Date of birth',
            'questionItem' => [
                'question' => [
                    'required' => true,
                    'dateQuestion' => [
                        'includeTime' => false,
                        'includeYear' => true,
                    ],
                ],
            ],
        ];
    }

    $requests = [];
    foreach ($items as $index => $item) {
        $requests[] = [
            'createItem' => [
                'item'     => $item,
                'location' => ['index' => $index],
            ],
        ];
    }

    return $requests;
}

function googleFormCreateTitle(array $event): string
{
    $name = trim((string) ($event['name'] ?? 'Staff registration'));
    $dateLabel = formatEventDateLabel((string) ($event['event_date'] ?? ''));
    if ($dateLabel !== '' && stripos($name, $dateLabel) === false) {
        return $name . ' — ' . $dateLabel . ' Staff Registration';
    }

    return $name . ' Staff Registration';
}

function googleFormCreateAddPsaPhotoQuestions(string $token, string $formId, int $startIndex, string $folderName = 'Olasentra PSA badge uploads'): void
{
    $folder = googleFormApiRequest($token, 'POST', 'https://www.googleapis.com/drive/v3/files', [
        'name'     => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
    ]);
    $folderId = (string) ($folder['json']['id'] ?? '');
    $specs = [
        [
            'title' => 'PSA badge, front',
            'help'  => 'Photo of the FRONT of your PSA badge.',
        ],
        [
            'title' => 'PSA badge, back',
            'help'  => 'Photo of the BACK of your PSA badge.',
        ],
    ];
    if ($folderId !== '') {
        $uploadRequests = [];
        foreach ($specs as $index => $spec) {
            $uploadRequests[] = [
                'createItem' => [
                    'item' => [
                        'title'       => $spec['title'],
                        'description' => $spec['help'],
                        'questionItem'=> [
                            'question' => [
                                'required' => true,
                                'fileUploadQuestion' => [
                                    'folderId'    => $folderId,
                                    'maxFiles'    => 1,
                                    'types'       => ['IMAGE'],
                                    'maxFileSize' => 'TEN_MB',
                                ],
                            ],
                        ],
                    ],
                    'location' => ['index' => $startIndex + $index],
                ],
            ];
        }
        $try = googleFormApiRequest(
            $token,
            'POST',
            'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . ':batchUpdate',
            ['requests' => $uploadRequests]
        );
        if ($try['code'] >= 200 && $try['code'] < 300) {
            return;
        }
    }

    $textRequests = [];
    foreach ($specs as $index => $spec) {
        $textRequests[] = [
            'createItem' => [
                'item' => [
                    'title'       => $spec['title'],
                    'description' => $spec['help'],
                    'questionItem'=> [
                        'question' => [
                            'required'     => true,
                            'textQuestion' => new stdClass(),
                        ],
                    ],
                ],
                'location' => ['index' => $startIndex + $index],
            ],
        ];
    }
    googleFormApiRequest(
        $token,
        'POST',
        'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . ':batchUpdate',
        ['requests' => $textRequests]
    );
}

/**
 * @return array<string, mixed>
 */
function googleFormCreateForEvent(PDO $pdo, int $eventId, array $options = []): array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Select a valid Olasentra event.'];
    }

    $aviva = googleFormIntakeFindAvivaEvent($pdo);
    if ($aviva !== null && (int) $aviva['id'] === $eventId) {
        return ['ok' => false, 'error' => 'The Aviva form is locked and cannot be recreated.'];
    }

    $existing = googleFormEventIntegrationByEventId($pdo, $eventId);
    if ($existing !== null) {
        return ['ok' => false, 'error' => 'This event already has a Google Form connection.'];
    }

    $includePps = !empty($options['include_pps']);
    $includeDob = !empty($options['include_dob']);

    $token = googleDriveGetUserAccessToken($pdo);
    if ($token === '') {
        $configured = googleDriveOAuthConfigured($pdo);

        return [
            'ok'           => false,
            'needs_reauth' => true,
            'oauth_configured' => $configured,
            'error'        => $configured
                ? 'Google login expired. Click Connect Google account as martinsstudioentertainment@gmail.com, approve Drive, Sheets, and Forms, then click Create Google Form again.'
                : 'Connect the Google account (martinsstudioentertainment@gmail.com) on Form import so Olasentra can create Forms.',
        ];
    }

    $title = googleFormCreateTitle($event);
    $created = googleFormApiRequest($token, 'POST', 'https://forms.googleapis.com/v1/forms', [
        'info' => ['title' => $title, 'documentTitle' => $title],
    ]);
    if ($created['code'] < 200 || $created['code'] >= 300 || empty($created['json']['formId'])) {
        $body = $created['body'];
        if (googleFormCreateNeedsReauth($body)) {
            return [
                'ok'           => false,
                'needs_reauth' => true,
                'error'        => 'Google needs one extra permission to create Forms. Click Connect Google account, then try again.',
            ];
        }
        if (str_contains(strtolower($body), 'has not been used') || str_contains(strtolower($body), 'is disabled')) {
            $enable = googleFormEnableFormsApi();
            if (!empty($enable['ok'])) {
                usleep(2500000);
                $created = googleFormApiRequest($token, 'POST', 'https://forms.googleapis.com/v1/forms', [
                    'info' => ['title' => $title, 'documentTitle' => $title],
                ]);
            }
            if ($created['code'] < 200 || $created['code'] >= 300 || empty($created['json']['formId'])) {
                return [
                    'ok'    => false,
                    'error' => 'Enable the Google Forms API, then generate the link again.',
                    'hint'  => googleFormEnableConsoleUrl(),
                    'enable'=> $enable ?? ['ok' => false],
                ];
            }
        } else {
            return ['ok' => false, 'error' => 'Could not create the Google Form.', 'hint' => substr(strip_tags($body), 0, 240)];
        }
    }

    $formId = (string) $created['json']['formId'];
    $description = googleFormRegistrationDescription($event);
    $setup = googleFormApiRequest($token, 'POST', 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . ':batchUpdate', [
        'requests' => array_merge(
            [[
                'updateFormInfo' => [
                    'info'       => ['title' => $title, 'description' => $description],
                    'updateMask' => 'title,description',
                ],
            ], [
                'updateSettings' => [
                    'settings'   => ['emailCollectionType' => 'DO_NOT_COLLECT'],
                    'updateMask' => 'emailCollectionType',
                ],
            ]],
            googleFormCreateItemRequests($includePps, $includeDob)
        ),
        'includeFormInResponse' => true,
    ]);
    if ($setup['code'] < 200 || $setup['code'] >= 300) {
        return [
            'ok'     => false,
            'error'  => 'The Form was created but questions could not be added.',
            'form_id'=> $formId,
            'hint'   => substr(strip_tags($setup['body']), 0, 240),
        ];
    }

    $questionCount = 10 + ($includePps ? 1 : 0) + ($includeDob ? 1 : 0);
    googleFormCreateAddPsaPhotoQuestions($token, $formId, $questionCount, $title . ' PSA badge uploads');

    $publish = googleFormApiRequest(
        $token,
        'POST',
        'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . ':setPublishSettings',
        [
            'publishSettings' => [
                'publishState' => [
                    'isPublished'           => true,
                    'isAcceptingResponses'  => true,
                ],
            ],
        ]
    );
    $published = $publish['code'] >= 200 && $publish['code'] < 300;
    $publishBody = strtolower($publish['body']);
    if (!$published && (str_contains($publishBody, 'already published') || str_contains($publishBody, 'ispublished'))) {
        $published = true;
    }
    if (!$published && googleFormCreateNeedsReauth($publish['body'])) {
        return [
            'ok'           => false,
            'needs_reauth' => true,
            'form_id'      => $formId,
            'error'        => 'Google needs Form permission to publish. Click Connect Google account, then try again.',
        ];
    }

    $got = googleFormApiRequest($token, 'GET', 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId));
    $form = $got['json'];
    $isPublished = !empty($form['publishSettings']['publishState']['isPublished']);
    if (!$isPublished && $published) {
        $isPublished = true;
    }
    $responderUri = (string) ($form['responderUri'] ?? '');
    if ($responderUri === '') {
        $responderUri = (string) ($created['json']['responderUri'] ?? '');
    }

    $sheetTitle = trim((string) $event['name']) . ' — Form Responses';
    $sheet = googleFormApiRequest($token, 'POST', 'https://sheets.googleapis.com/v4/spreadsheets', [
        'properties' => ['title' => $sheetTitle],
        'sheets'     => [['properties' => ['title' => 'Form Responses 1']]],
    ]);
    if ($sheet['code'] < 200 || $sheet['code'] >= 300 || empty($sheet['json']['spreadsheetId'])) {
        return [
            'ok'      => false,
            'form_id' => $formId,
            'error'   => 'The Form was created but the Responses Sheet could not be created.',
            'hint'    => substr(strip_tags($sheet['body']), 0, 240),
        ];
    }
    $sheetId = (string) $sheet['json']['spreadsheetId'];
    $headers = googleFormCreatedSheetHeaders();
    $lastCol = chr(ord('A') + count($headers) - 1);
    $headerUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($sheetId)
        . "/values/'Form Responses 1'!A1:{$lastCol}1?valueInputOption=RAW";
    googleFormApiRequest($token, 'PUT', $headerUrl, [
        'range'  => "'Form Responses 1'!A1:{$lastCol}1",
        'values' => [$headers],
    ]);

    $saEmail = googleFormEventIntegrationServiceAccountEmail();
    $share = googleFormCreateShareReader($token, $sheetId, $saEmail);
    $viewerOk = $share['code'] >= 200 && $share['code'] < 300;
    if ($viewerOk) {
        $viewerOk = googleFormCreateServiceAccountCanReadSheet($sheetId);
        if (!$viewerOk) {
            usleep(1500000);
            $viewerOk = googleFormCreateServiceAccountCanReadSheet($sheetId);
        }
    }
    googleFormCreateShareReader($token, $formId, $saEmail);

    $ready = $isPublished && $viewerOk;
    $creationError = null;
    if (!$isPublished) {
        $creationError = 'The Form was created but is not published yet.';
    } elseif (!$viewerOk) {
        $creationError = 'Share the Responses Sheet as Viewer with ' . $saEmail . '.';
    }

    try {
        $pdo->prepare(
            'INSERT INTO google_form_event_integrations (
                event_id, google_form_id, google_form_url, google_sheet_id, google_sheet_url,
                google_sheet_tab, enabled, processed_rows, last_permission_ok, is_locked, source,
                form_created_by_system, sheet_created_by_system, form_created_at, published_at,
                published, creation_status, creation_error, responder_uri, collect_pps, collect_dob
            ) VALUES (
                :event_id, :google_form_id, :google_form_url, :google_sheet_id, :google_sheet_url,
                :google_sheet_tab, :enabled, 0, :last_permission_ok, 0, :source,
                1, 1, :form_created_at, :published_at,
                :published, :creation_status, :creation_error, :responder_uri, :collect_pps, :collect_dob
            )'
        )->execute([
            'event_id'            => $eventId,
            'google_form_id'      => $formId,
            'google_form_url'     => googleFormEventIntegrationFormUrl($formId),
            'google_sheet_id'     => $sheetId,
            'google_sheet_url'    => googleFormEventIntegrationSheetUrl($sheetId),
            'google_sheet_tab'    => 'Form Responses 1',
            'enabled'             => $ready ? 1 : 0,
            'last_permission_ok'  => $viewerOk ? 1 : 0,
            'source'              => 'generated',
            'form_created_at'     => date('Y-m-d H:i:s'),
            'published_at'        => $isPublished ? date('Y-m-d H:i:s') : null,
            'published'           => $isPublished ? 1 : 0,
            'creation_status'     => $ready ? 'ready' : 'needs_attention',
            'creation_error'      => $creationError,
            'responder_uri'       => $responderUri !== '' ? $responderUri : null,
            'collect_pps'         => $includePps ? 1 : 0,
            'collect_dob'         => $includeDob ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] Google Form create insert: ' . $e->getMessage());

        return [
            'ok'              => false,
            'error'           => 'The Form and Sheet were created in Google, but Olasentra could not save the connection.',
            'google_form_id'  => $formId,
            'google_sheet_id' => $sheetId,
            'hint'            => $e->getMessage(),
        ];
    }

    $id = (int) $pdo->lastInsertId();
    $items = [];
    foreach (($form['items'] ?? []) as $item) {
        $items[] = (string) ($item['title'] ?? '');
    }

    return [
        'ok'             => true,
        'id'             => $id,
        'event_id'       => $eventId,
        'event'          => (string) $event['name'],
        'google_form_id' => $formId,
        'google_form_url'=> googleFormEventIntegrationFormUrl($formId),
        'responder_uri'  => $responderUri,
        'google_sheet_id'=> $sheetId,
        'google_sheet_url'=> googleFormEventIntegrationSheetUrl($sheetId),
        'published'      => $isPublished,
        'viewer_granted' => $viewerOk,
        'service_account'=> $saEmail,
        'questions'      => $items,
        'enabled'        => $ready,
    ];
}

/**
 * One-step: create (or reuse) an event from a name + date, then create its Google Form.
 *
 * @return array<string, mixed>
 */
function googleFormCreateQuick(PDO $pdo, string $name, string $date, array $options = []): array
{
    $name = trim($name);
    $date = substr(trim($date), 0, 10);
    if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'error' => 'Enter the event name and date.'];
    }

    $aviva = googleFormIntakeFindAvivaEvent($pdo);
    $avivaId = (int) ($aviva['id'] ?? 0);
    if ($avivaId > 0 && defined('GOOGLE_FORM_AVIVA_EVENT_DATE')
        && $date === GOOGLE_FORM_AVIVA_EVENT_DATE
        && stripos($name, 'AVIVA') !== false) {
        return ['ok' => false, 'error' => 'The Aviva form is locked. Use a different event name.'];
    }

    $find = $pdo->prepare(
        'SELECT * FROM events WHERE name = :name AND event_date = :event_date ORDER BY id DESC LIMIT 1'
    );
    $find->execute(['name' => $name, 'event_date' => $date]);
    $event = $find->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($event === null) {
        $eventId = createEvent($pdo, [
            'name'         => $name,
            'event_date'   => $date,
            'location'     => $name,
            'roles_needed' => ['DSP', 'Static'],
            'start_time'   => '09:00',
            'end_time'     => '23:00',
            'is_active'    => 1,
            'staff_needed' => 1,
        ]);
        $event = getEventById($pdo, $eventId);
    }
    if ($event === null) {
        return ['ok' => false, 'error' => 'Could not create the event.'];
    }
    $eventId = (int) $event['id'];
    if ($avivaId > 0 && $eventId === $avivaId) {
        return ['ok' => false, 'error' => 'The Aviva form is locked.'];
    }

    $existing = googleFormEventIntegrationByEventId($pdo, $eventId);
    if ($existing !== null) {
        googleFormRestyleGeneratedForm($pdo, $existing);
        $staffUrl = googleFormEventIntegrationStaffUrl($existing);

        return [
            'ok'            => true,
            'already'       => true,
            'id'            => (int) $existing['id'],
            'event_id'      => $eventId,
            'event'         => (string) $event['name'],
            'google_form_id'=> (string) ($existing['google_form_id'] ?? ''),
            'responder_uri' => $staffUrl,
            'google_sheet_id' => (string) ($existing['google_sheet_id'] ?? ''),
            'published'     => true,
            'viewer_granted'=> (int) ($existing['last_permission_ok'] ?? 0) === 1,
        ];
    }

    return googleFormCreateForEvent($pdo, $eventId, $options);
}

function googleFormRestyleGeneratedForm(PDO $pdo, array $integration): void
{
    $formId = trim((string) ($integration['google_form_id'] ?? ''));
    if ($formId === '' || googleFormEventIntegrationIsLocked($integration)) {
        return;
    }
    $token = googleDriveGetUserAccessToken($pdo);
    if ($token === '') {
        return;
    }
    $event = getEventById($pdo, (int) ($integration['event_id'] ?? 0));
    $title = $event !== null ? googleFormCreateTitle($event) : '';
    $description = $event !== null ? googleFormRegistrationDescription($event) : '';
    $requests = [
        [
            'updateSettings' => [
                'settings'   => ['emailCollectionType' => 'DO_NOT_COLLECT'],
                'updateMask' => 'emailCollectionType',
            ],
        ],
    ];
    if ($title !== '') {
        $requests[] = [
            'updateFormInfo' => [
                'info'       => ['title' => $title, 'description' => $description],
                'updateMask' => 'title,description',
            ],
        ];
    }
    $got = googleFormApiRequest($token, 'GET', 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId));
    $hasEmail = false;
    foreach (($got['json']['items'] ?? []) as $item) {
        $itemId = (string) ($item['itemId'] ?? '');
        $itemTitle = (string) ($item['title'] ?? '');
        if (strcasecmp($itemTitle, 'Email') === 0 || strcasecmp($itemTitle, 'Email Address') === 0) {
            $hasEmail = true;
        }
        if ($itemId === '') {
            continue;
        }
        $shortHelp = '';
        if ($itemTitle === 'PSA licence number') {
            $shortHelp = 'Format EM123456/00.';
        } elseif ($itemTitle === 'PSA badge, front') {
            $shortHelp = 'Photo of the FRONT of your PSA badge.';
        } elseif ($itemTitle === 'PSA badge, back') {
            $shortHelp = 'Photo of the BACK of your PSA badge.';
        } elseif ($itemTitle === 'IBAN') {
            $shortHelp = 'Irish example IE29AIBK93115212345678';
        }
        $updated = [
            'itemId'      => $itemId,
            'description' => $shortHelp,
        ];
        $requests[] = [
            'updateItem' => [
                'item'       => $updated,
                'updateMask' => 'description',
            ],
        ];
    }
    if (!$hasEmail) {
        $requests[] = [
            'createItem' => [
                'item' => [
                    'title' => 'Email',
                    'questionItem' => [
                        'question' => [
                            'required'     => true,
                            'textQuestion' => new stdClass(),
                        ],
                    ],
                ],
                'location' => ['index' => 2],
            ],
        ];
    }
    googleFormApiRequest(
        $token,
        'POST',
        'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . ':batchUpdate',
        ['requests' => $requests]
    );
}

/**
 * Pull new Google Form responses into the Responses Sheet for generated forms.
 *
 * @param array<string, mixed> $integration
 */
function googleFormIntakePullFormResponsesToSheet(PDO $pdo, array $integration): array
{
    $formId = trim((string) ($integration['google_form_id'] ?? ''));
    $sheetId = trim((string) ($integration['google_sheet_id'] ?? ''));
    $integrationId = (int) ($integration['id'] ?? 0);
    if ($formId === '' || $sheetId === '' || $integrationId < 1) {
        return ['ok' => false, 'error' => 'Missing form or sheet.', 'appended' => 0];
    }
    if ((int) ($integration['form_created_by_system'] ?? 0) !== 1
        && (string) ($integration['source'] ?? '') !== 'generated') {
        return ['ok' => true, 'appended' => 0, 'skipped' => true];
    }

    $token = googleDriveGetUserAccessToken($pdo);
    if ($token === '') {
        $sa = loadGoogleServiceAccount();
        if ($sa !== null) {
            $token = (string) (googleSheetsGetAccessToken($sa, [
                'https://www.googleapis.com/auth/forms.responses.readonly',
                'https://www.googleapis.com/auth/forms.body.readonly',
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/drive.readonly',
            ]) ?? '');
        }
    }
    if ($token === '') {
        return ['ok' => false, 'error' => 'Google account is not connected to pull form responses.', 'appended' => 0];
    }

    $form = googleFormApiRequest($token, 'GET', 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId));
    if ($form['code'] !== 200) {
        return ['ok' => false, 'error' => 'Could not read the Google Form.', 'http' => $form['code'], 'appended' => 0];
    }
    $titleByQuestion = [];
    foreach (($form['json']['items'] ?? []) as $item) {
        $title = (string) ($item['title'] ?? '');
        $qid = (string) ($item['questionItem']['question']['questionId'] ?? '');
        if ($title !== '' && $qid !== '') {
            $titleByQuestion[$qid] = $title;
        }
    }

    $list = googleFormApiRequest(
        $token,
        'GET',
        'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . '/responses'
    );
    if ($list['code'] !== 200) {
        return ['ok' => false, 'error' => 'Could not read form responses.', 'http' => $list['code'], 'appended' => 0];
    }

    $doneStmt = $pdo->prepare(
        'SELECT 1 FROM google_form_pulled_responses WHERE integration_id = :id AND response_id = :rid LIMIT 1'
    );
    $insertDone = $pdo->prepare(
        'INSERT IGNORE INTO google_form_pulled_responses (integration_id, response_id) VALUES (:id, :rid)'
    );

    $headers = googleFormCreatedSheetHeaders();
    $appended = 0;
    foreach (($list['json']['responses'] ?? []) as $response) {
        $rid = (string) ($response['responseId'] ?? '');
        if ($rid === '') {
            continue;
        }
        $doneStmt->execute(['id' => $integrationId, 'rid' => $rid]);
        if ($doneStmt->fetchColumn()) {
            continue;
        }
        $named = [];
        foreach (($response['answers'] ?? []) as $qid => $answer) {
            $title = $titleByQuestion[$qid] ?? '';
            if ($title === '') {
                continue;
            }
            $text = '';
            if (!empty($answer['textAnswers']['answers'][0]['value'])) {
                $text = (string) $answer['textAnswers']['answers'][0]['value'];
            } elseif (!empty($answer['fileUploadAnswers']['answers'][0]['fileId'])) {
                $fid = (string) $answer['fileUploadAnswers']['answers'][0]['fileId'];
                $text = 'https://drive.google.com/uc?export=download&id=' . $fid;
            } elseif (!empty($answer['dateAnswers']['answers'][0])) {
                $d = $answer['dateAnswers']['answers'][0];
                $text = sprintf(
                    '%04d-%02d-%02d',
                    (int) ($d['year'] ?? 0),
                    (int) ($d['month'] ?? 0),
                    (int) ($d['day'] ?? 0)
                );
            }
            $named[$title] = $text;
        }
        $row = [];
        foreach ($headers as $header) {
            if ($header === 'Timestamp') {
                $row[] = (string) ($response['lastSubmittedTime'] ?? $response['createTime'] ?? date('c'));
            } elseif ($header === 'Email Address') {
                $row[] = (string) ($response['respondentEmail'] ?? $named['Email'] ?? $named['Email Address'] ?? '');
            } else {
                $row[] = (string) ($named[$header] ?? '');
            }
        }
        $appendUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'
            . rawurlencode($sheetId)
            . "/values/'Form Responses 1'!A1:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS";
        $write = googleFormApiRequest($token, 'POST', $appendUrl, [
            'values' => [$row],
        ]);
        if ($write['code'] < 200 || $write['code'] >= 300) {
            return [
                'ok'       => false,
                'error'    => 'Could not write a response into the Sheet.',
                'http'     => $write['code'],
                'appended' => $appended,
            ];
        }
        $insertDone->execute(['id' => $integrationId, 'rid' => $rid]);
        $appended++;
    }

    return ['ok' => true, 'appended' => $appended];
}
