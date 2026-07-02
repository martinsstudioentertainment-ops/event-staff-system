# Services

Reusable business logic callable from admin pages, cron jobs, and APIs.

## Guidelines

- Stateless or PDO-injected services preferred
- Namespace by domain: `services/payroll/`, `services/reporting/`
- Log failures with `error_log('[EventStaff] service/…')` — no secrets
- See `docs/V1.1-EXTENSION-GUIDE.md`
