# Olasentra — Fresh Android App (v1.0.16+)

This is a **new clean Android project**. The old `../olasentra-staff/` folder is **left untouched**.

## Setup (one time)

1. Copy secrets from handover or old project:
   - `local.properties` → SDK path + `GOOGLE_WEB_CLIENT_ID=...`
   - `app/google-services.json` (Firebase) — already copied if present in handover
2. Open **this folder** (`android/olasentra-fresh`) in Android Studio, or build from CLI:

```powershell
$env:JAVA_HOME = "C:\Program Files\Microsoft\jdk-17.0.19.10-hotspot"
cd d:\event-staff-system\android\olasentra-fresh
.\gradlew.bat assembleDebug
```

APK: `app/build/outputs/apk/debug/app-debug.apk`

## API

Production only: `https://register.olasentra.com/api/mobile/v1`

## Timeline (realistic)

| Milestone | What you get | Estimate |
|-----------|--------------|----------|
| **Day 1–2** | Clean project, orange theme, splash, config API, debug APK | Done (scaffold) |
| **Week 1** | Google Sign-In, session, dashboard shell, device testing | 5–7 days |
| **Week 2–3** | Email OTP, profile, settings, notifications list | 10–15 days |
| **Week 4–5** | Shifts, GPS check-in, documents, offline sync | 15–25 days |
| **Week 6** | Play Store AAB, internal testing, polish | +5–7 days |

**Full v1.0.15 feature parity:** about **4–6 weeks** with steady work.  
**First usable staff login on device:** about **1 week**.

Staff can keep using the **live v1.0.15 APK** from register.olasentra.com while this is built.
