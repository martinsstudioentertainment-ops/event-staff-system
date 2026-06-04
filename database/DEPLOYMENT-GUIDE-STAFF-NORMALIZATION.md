# Staff Table Normalization - Deployment Guide

## Overview
This migration normalizes the database structure by separating staff personal information from event registrations. This eliminates data duplication and makes it easier to manage staff data.

**Current Problem:**
- Staff personal data (name, address, email, phone, PPS, bank details) is duplicated for every event registration
- If a staff member registers for 5 events, their data is stored 5 times
- Hard to get a single view of all staff details

**Solution:**
- Create a new `staff` table to store staff personal information once
- Add `staff_id` to `staff_registrations` to link to the staff table
- Keep old columns for backward compatibility (dual-write approach)

## Migration Strategy
This migration uses a **dual-write approach** to ensure zero downtime:
1. New `staff` table is created (doesn't affect existing data)
2. Existing data is migrated to the new table
3. Code is updated to write to both old and new structures
4. Code reads from new table with fallback to old columns
5. Old columns are kept for safety (can be removed later if desired)

## Pre-Deployment Checklist

### 1. Backup Your Database
```bash
# Via cPanel
- Go to phpMyAdmin
- Select your database
- Click "Export"
- Choose "Quick" export method
- Click "Go" to download the backup

# Or via command line
mysqldump -u username -p database_name > backup_before_staff_normalization.sql
```

### 2. Test on Local/Staging Environment First
**CRITICAL:** Do NOT run this on your live site without testing first.

1. Copy your live database to a local/staging environment
2. Run all migration scripts in order
3. Test registration form submission
4. Test admin pages
5. Verify data integrity
6. Only proceed to live if everything works

## Deployment Steps

### Step 1: Deploy Code Changes
Push the following files to your server:
- `includes/staff-repository.php` (updated with new functions)
- `includes/validation.php` (updated for dual-write)
- `database/migrate-phase35-staff-table.sql`
- `database/migrate-phase36-populate-staff-table.sql`
- `database/migrate-phase37-add-staff-id-to-registrations.sql`
- `database/migrate-phase38-link-registrations-to-staff.sql`
- `database/rollback-phase35-38-staff-table.sql`

### Step 2: Run Migration Scripts (in order)

**Phase 35: Create staff table**
```sql
-- Run in phpMyAdmin or via MySQL client
SOURCE database/migrate-phase35-staff-table.sql;
```

**Phase 36: Populate staff table**
```sql
SOURCE database/migrate-phase36-populate-staff-table.sql;
```

**Phase 37: Add staff_id column**
```sql
SOURCE database/migrate-phase37-add-staff-id-to-registrations.sql;
```

**Phase 38: Link registrations to staff**
```sql
SOURCE database/migrate-phase38-link-registrations-to-staff.sql;
```

### Step 3: Verify the Migration

**Check staff table was created:**
```sql
SELECT COUNT(*) FROM staff;
```

**Check staff_id was populated:**
```sql
SELECT COUNT(*) FROM staff_registrations WHERE staff_id IS NOT NULL;
SELECT COUNT(*) FROM staff_registrations WHERE staff_id IS NULL;
```

**Verify data integrity:**
```sql
-- Check that all registrations have matching staff records
SELECT sr.id, sr.email, sr.staff_id, s.id as staff_table_id
FROM staff_registrations sr
LEFT JOIN staff s ON sr.email = s.email
WHERE sr.staff_id IS NULL AND s.id IS NOT NULL;
```

### Step 4: Test the Application

1. **Test Registration Form**
   - Submit a new registration
   - Verify it creates a staff record
   - Verify staff_id is set in staff_registrations
   - Verify old columns are still populated (backward compatibility)

2. **Test Admin Pages**
   - View staff registrations list
   - Verify staff details display correctly
   - Test search and filter functions
   - Test status updates

3. **Test Existing Registrations**
   - View existing registrations
   - Verify they still display correctly
   - Verify staff data is merged from staff table

## Rollback Plan (if issues occur)

If you encounter any issues after deployment, run the rollback script:

```sql
SOURCE database/rollback-phase35-38-staff-table.sql;
```

This will:
1. Remove the staff_id column from staff_registrations
2. Drop the staff table
3. Restore the database to its original state

After rollback, also revert the code changes:
- Revert `includes/staff-repository.php`
- Revert `includes/validation.php`

## Post-Deployment (Optional Future Steps)

Once the migration is stable (after 1-2 weeks of normal operation), you can optionally:

1. **Make staff_id required:**
   ```sql
   ALTER TABLE staff_registrations MODIFY COLUMN staff_id INT UNSIGNED NOT NULL;
   ```

2. **Remove old columns from staff_registrations** (only after extensive testing):
   ```sql
   ALTER TABLE staff_registrations
   DROP COLUMN surname,
   DROP COLUMN first_name,
   DROP COLUMN full_address,
   DROP COLUMN eircode,
   DROP COLUMN location_lat,
   DROP COLUMN location_lng,
   DROP COLUMN email,
   DROP COLUMN mobile,
   DROP COLUMN date_of_birth,
   DROP COLUMN gender,
   DROP COLUMN pps_number,
   DROP COLUMN bank_iban,
   DROP COLUMN staff_role;
   ```

**WARNING:** Do NOT remove old columns without extensive testing and a full backup!

## Troubleshooting

### Issue: Migration script fails
**Solution:** Check the error message. Common issues:
- Missing permissions
- Table already exists
- Column already exists
- Foreign key constraint errors

### Issue: staff_id is NULL for some registrations
**Solution:** This is expected for registrations that couldn't be matched by email. Run Phase 38 again:
```sql
SOURCE database/migrate-phase38-link-registrations-to-staff.sql;
```

### Issue: Admin pages show no data
**Solution:** Check that the mergeRegistrationWithStaff function is being called. Check error logs for any exceptions.

### Issue: Registration form fails
**Solution:** The code has fallback to old structure if staff table operations fail. Check error logs for details.

## Support

If you encounter issues:
1. Check error logs: `storage/logs/*.log`
2. Run the rollback script to restore original state
3. Contact support with error details

## Summary

This migration:
- ✅ Eliminates data duplication
- ✅ Makes staff management easier
- ✅ Uses dual-write for zero downtime
- ✅ Maintains backward compatibility
- ✅ Includes rollback capability
- ✅ Can be deployed safely to live site

**Estimated downtime:** 0 minutes (dual-write approach)
**Risk level:** Low (with rollback plan)
**Testing required:** Yes (staging environment first)
