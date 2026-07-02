# Google Sign-In SHA-1 (Android OAuth client)

The native app uses **Sign in with Google** via Credential Manager. Google verifies the app using:

| Field | Value |
|-------|--------|
| Package name | `com.olasentra.staff` |
| Web client ID (in app) | Same as admin Google OAuth — `GOOGLE_WEB_CLIENT_ID` in `local.properties` |
| Android SHA-1 | Must match the keystore that signed the APK |

## Error: `[16] Account reauth failed`

This almost always means the **SHA-1 fingerprint is missing or wrong** in Google Cloud Console for `com.olasentra.staff`.

Each PC has its own **debug keystore** unless you share one. v1.0.11 was built on this PC — register its SHA-1 once.

### This PC (olabo build machine)

```
SHA-1: 3E:2B:29:32:9C:8A:40:49:09:8D:64:68:19:F4:76:B3:CF:1E:FA:16
```

Print again anytime:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\android-debug-sha1.ps1
```

### Register in Google Cloud Console

1. Open [Google Cloud Console](https://console.cloud.google.com/) → project used for Olasentra Google Sign-In
2. **APIs & Services** → **Credentials**
3. **Create credentials** → **OAuth client ID** → type **Android**
4. Package name: `com.olasentra.staff`
5. SHA-1: paste fingerprint above
6. Save (can take a few minutes to propagate)

Keep the existing **Web client** — the app passes that as `serverClientId`. Do not replace it with the Android client ID.

## Production release APK

When you ship a **release** keystore or **Play App Signing**, add **each** signing certificate SHA-1 as a separate Android OAuth client (debug, upload, Play signing).
