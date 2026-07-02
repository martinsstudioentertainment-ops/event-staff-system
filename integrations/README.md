# Integrations

Third-party connectors: webhooks, external APIs, Google Workspace extensions.

## Guidelines

- Keep credentials in settings table or server-only config — never commit secrets
- Isolate entry points: `integrations/<provider>/webhook.php`
- Validate signatures and authenticate inbound requests
- See `docs/V1.1-EXTENSION-GUIDE.md`
