# Modules

Self-contained domain modules (ERP, library, CRM, HR expansions).

## Guidelines

- One subdirectory per module: `modules/<name>/`
- Include `README.md` with routes, permissions, and deploy file list
- Do not modify v1.0 core — hook via existing includes and feature flags
- See `docs/V1.1-EXTENSION-GUIDE.md`

## Example structure

```
modules/library/
  README.md
  includes/library-repository.php
  admin/index.php
  api/books.php
```
