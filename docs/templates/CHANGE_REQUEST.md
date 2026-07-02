# Change Request

Copy this template for every v1.1+ change.

---

## Summary

**Title:**  
**Date:**  
**Author:**  

## Classification

One of: Critical Bug Fix · Security Update · Performance Improvement · Feature Addition · UI Improvement · Documentation · Refactor · Breaking Change (v2.0 only)

**Target version:** v1.0.x / v1.1.x / v2.0

---

## Reason

What problem does this solve? Link issue/ticket if applicable.

---

## Files affected

| File | Change type (add/modify/delete) |
|------|--------------------------------|
| | |

**Protected module?** Yes / No — if yes, see `docs/PROTECTED-MODULES.md`

---

## Regression risk

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| | Low/Med/High | Low/Med/High | |

---

## Rollback plan

1. Files to restore:
2. Version/build to revert to:
3. Verification after rollback:

---

## Verification checklist

- [ ] Registration
- [ ] Google auth
- [ ] Email OTP
- [ ] PPS (if applicable)
- [ ] Attendance / GPS / BIB
- [ ] Mobile API `/config`
- [ ] Admin dashboard
- [ ] System Health
- [ ] Notifications
- [ ] Short links
- [ ] Android (if mobile-facing)

---

## Deployment

- [ ] Manifest updated (`upload-safe-fix-bundle.ps1` or feature script)
- [ ] `storage/version.json` bumped on deploy
- [ ] `docs/CHANGELOG.md` updated
- [ ] Production verification passed

---

## Documentation

- [ ] Architecture / health report
- [ ] API docs (if new endpoints)
- [ ] Release notes (when shipping)
