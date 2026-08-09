# Olasentra (Native Android)

Native staff app for **Olasentra** — consumes `https://register.olasentra.com/api/mobile/v1` only.

## Requirements

- Android Studio Ladybug or newer
- JDK 17
- Android SDK 35

## Setup

1. Open `android/olasentra-staff` in Android Studio.
2. Copy `app/google-services.json.example` to `app/google-services.json` and fill in Firebase values.
3. Copy `local.properties.example` to `local.properties` and set `GOOGLE_WEB_CLIENT_ID` (same Web client as admin ERP).
4. Sync Gradle (Android Studio will create the Gradle wrapper if missing).
5. Run **productionDebug**.

## Architecture

Multi-module MVVM + Clean Architecture. See [Phase 2A Report](../../docs/android/PHASE-2A-REPORT.md), [Phase 2B Report](../../docs/android/PHASE-2B-REPORT.md), and [Phase 2C Report](../../docs/android/PHASE-2C-REPORT.md).

## Phase status

- **2A:** Foundation, navigation shell, API layer, secure tokens, Room offline queue.
- **2B:** Google Sign-In, JWT auth, live dashboard & profile, offline cache.
- **2C (current):** Shifts, GPS status (read-only), messages, notifications — awaiting approval before 2D.
- **2D (next):** Live GPS check-in/check-out (after approval).
