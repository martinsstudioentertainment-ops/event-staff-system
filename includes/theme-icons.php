<?php
/**
 * Category icons for theme branding (Security, Stewards, Gig, Events).
 * Generic role imagery — not company-specific logos.
 */

/** @return array<string, string> */
function getThemeCategoryIcons(): array
{
    return [
        'security' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 1.5 3 5.25v6.09c0 5.03 3.8 9.74 9 11.16 5.2-1.42 9-6.13 9-11.16V5.25L12 1.5Zm0 2.18 7 2.92v4.74c0 3.88-2.92 7.52-7 8.78-4.08-1.26-7-4.9-7-8.78V6.6l7-2.92Zm-.75 4.07a.75.75 0 0 0-1.5 0v4.5a.75.75 0 0 0 .22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.72-2.72V7.75Z"/></svg>',
        'steward'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a4 4 0 0 1 4 4v1h1.25A2.75 2.75 0 0 1 20 9.75v8.5A2.75 2.75 0 0 1 17.25 21H6.75A2.75 2.75 0 0 1 4 18.25v-8.5A2.75 2.75 0 0 1 6.75 9H8V6a4 4 0 0 1 4-4Zm0 2a2 2 0 0 0-2 2v1h4V6a2 2 0 0 0-2-2Zm-5.25 7A1.25 1.25 0 0 0 5.5 12.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5A1.25 1.25 0 0 0 17.25 11H6.75ZM8 14.5h8v1.5H8v-1.5Z"/></svg>',
        'gig'      => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3a9 9 0 0 0-9 9v1.5a.75.75 0 0 0 1.5 0V12a7.5 7.5 0 0 1 15 0v1.5a.75.75 0 0 0 1.5 0V12a9 9 0 0 0-9-9Zm-4.28 8.47a.75.75 0 0 0-1.06 1.06l1.72 1.72v2.25a3 3 0 0 0 6 0v-2.25l1.72-1.72a.75.75 0 1 0-1.06-1.06l-1.47 1.47V15a1.5 1.5 0 0 1-3 0v-2.06l-1.47-1.47Z"/></svg>',
        'events'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 3a1 1 0 0 1 1 1v1h8V4a1 1 0 1 1 2 0v1h1.25A2.75 2.75 0 0 1 22 7.75v11.5A2.75 2.75 0 0 1 19.25 21H4.75A2.75 2.75 0 0 1 2 18.25V7.75A2.75 2.75 0 0 1 4.75 5H6V4a1 1 0 0 1 1-1Zm13.25 6.5H3.75v8.75c0 .69.56 1.25 1.25 1.25h14.5c.69 0 1.25-.56 1.25-1.25V9.5ZM8 12.75a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Zm4.25 0a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Zm4.25 0a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Z"/></svg>',
    ];
}

function renderThemeCategoryIcon(string $category): string
{
    $icons = getThemeCategoryIcons();
    $svg   = $icons[$category] ?? $icons['events'];

    return $svg;
}
