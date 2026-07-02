# Deployment Notes

## Production baseline

| Field | Value |
|-------|-------|
| Version | 1.0.0 Stable |
| Build | 2026062600 |
| Label | v1.0-stable |
| Host | register.olasentra.com |

---

## Standard deploy (v1.0 / v1.1 bundles)

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\upload-safe-fix-bundle.ps1
```

This script:

1. Bumps `storage/version.json` (build number)
2. FTP-uploads the manifest file list to register.olasentra.com

### Full deploy (git + admin + apply)

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy.ps1
```

Uses allowlist in `scripts/upload-to-server.ps1` — not every file syncs automatically.

### Single-file hotfix

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\upload-one.ps1 -Local "path" -Remote "path"
```

---

## Never overwrite on server

- `config.php`
- `storage/google/service-account.json`
- `apply/admin/config/database.php`
- `apply/admin/config/eventstaff-database.php`

---

## Post-deploy verification (required)

```powershell
curl.exe -s -w "`nHTTP:%{http_code}" "https://register.olasentra.com/api/mobile/v1/config"
curl.exe -s -o NUL -w "HTTP:%{http_code}" "https://register.olasentra.com/steward"
```

Expected: Mobile config HTTP 200 with `"build"` matching deployed `storage/version.json`.

Admin: **System Health** — Mobile API, Auth policy, Database checks pass.

---

## v1.1 feature deploy checklist

When adding a new feature:

1. Add all new/changed paths to `scripts/upload-safe-fix-bundle.ps1` OR create `scripts/upload-feature-<name>.ps1`
2. Update `scripts/deploy-ui-api-manifest.json` if UI/API are paired
3. Run bundle deploy
4. Verify affected areas from `docs/V1.1-DEVELOPMENT-BASELINE.md` testing policy
5. Update `docs/CHANGELOG.md`

---

## Rollback

1. Note current `build` from `/api/mobile/v1/config` or admin System Health
2. Restore prior files from FTP backup or git tag `v1.0-stable`
3. Re-upload previous `storage/version.json` if needed
4. Re-run verification curls

See `RELEASE_CERTIFICATION.md` for minimum rollback file set.

---

## Development vs production version

| File | Environment |
|------|-------------|
| `storage/version.json` | Production — updated on deploy |
| `storage/version-dev.json` | Local v1.1 dev tracking — **not uploaded** |
