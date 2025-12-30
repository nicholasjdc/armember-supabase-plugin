# Permission Testing Guide

This guide helps you test that access control is working correctly for:
1. **Database Access** - Only users with paid plans can access locked databases
2. **Librarian Access** - Only listed librarians (and admins) can access the librarian editing page

---

## Prerequisites

Before testing, ensure:
- ✅ You have test users with different membership plans (Free, Paid, Dual)
- ✅ You have at least one locked database table
- ✅ You have at least one unlocked database table (optional, for comparison)
- ✅ You have librarian emails configured in **Supabase Sync → Librarians** tab
- ✅ You have a page with `[supabase_librarian]` shortcode

---

## Part 1: Testing Database Access Control

### Test 1.1: Free User Access to Locked Database

**Setup:**
1. Create or use a user with **Free** membership plan
2. Ensure at least one database table is **locked** (check in **Supabase Sync → Tables** tab)

**Test Steps:**
1. Log in as the Free user
2. Navigate to a page with `[supabase_table table="your_locked_table"]` shortcode
3. Navigate to the multi-database search page (`[supabase_multi_search]`)
4. Navigate to the library catalog page (`[supabase_library_catalog]`) if library table is locked

**Expected Results:**
- ❌ Should see: "This content is available to premium members only. Please upgrade your membership to access."
- ❌ Multi-search should show: "Database search is available to premium members only..."
- ❌ Library search should show: "Paid membership required" (in console/network tab)
- ✅ Should NOT see any database data

**Verify in Database:**
```sql
-- Check user meta (should be empty or false)
SELECT user_id, meta_value 
FROM wp_usermeta 
WHERE user_id = [FREE_USER_ID] 
AND meta_key = 'supabase_access';
-- Should return: empty or meta_value = false/0
```

---

### Test 1.2: Paid User Access to Locked Database

**Setup:**
1. Create or use a user with **Paid** or **Dual** membership plan
2. Sync the user: **Supabase Sync → Settings → "Sync All Users to Supabase"**
3. Verify the user has `supabase_access` meta set

**Test Steps:**
1. Log in as the Paid user
2. Navigate to a page with `[supabase_table table="your_locked_table"]` shortcode
3. Navigate to the multi-database search page
4. Navigate to the library catalog page

**Expected Results:**
- ✅ Should see database data/table
- ✅ Multi-search should work and show locked tables
- ✅ Library search should work
- ✅ No "premium members only" messages

**Verify in Database:**
```sql
-- Check user meta (should be true/1)
SELECT user_id, meta_value 
FROM wp_usermeta 
WHERE user_id = [PAID_USER_ID] 
AND meta_key = 'supabase_access';
-- Should return: meta_value = 1 or '1' or true
```

**Verify in Supabase:**
- Check `wp_users` table in Supabase
- User should have `has_database_access = true`
- User should have correct `membership_plan` (not "Unknown")

---

### Test 1.3: Unlocked Database Access

**Setup:**
1. Ensure at least one database table is **unlocked** (uncheck the lock checkbox in **Tables** tab)

**Test Steps:**
1. Log in as a **Free** user (or log out completely)
2. Navigate to a page with `[supabase_table table="your_unlocked_table"]` shortcode
3. Navigate to multi-database search page

**Expected Results:**
- ✅ Should see data from unlocked tables
- ✅ Unlocked tables should appear in multi-search for all users
- ✅ No access restrictions

---

### Test 1.4: Admin Access

**Test Steps:**
1. Log in as an **Administrator**
2. Navigate to all database pages (locked and unlocked)

**Expected Results:**
- ✅ Should have access to ALL databases (locked and unlocked)
- ✅ No restrictions should apply
- ✅ Can see all data

---

### Test 1.5: Logged Out User Access

**Test Steps:**
1. Log out completely
2. Navigate to a page with locked database table
3. Navigate to multi-database search page

**Expected Results:**
- ❌ Should see: "Please log in to view this content."
- ❌ Should see: "Please log in to search databases."
- ✅ Should NOT see any database data

---

## Part 2: Testing Librarian Access Control

### Test 2.1: Non-Librarian User Access

**Setup:**
1. Create or use a user who is **NOT** in the librarian list
2. User should have a WordPress account (can be Free, Paid, or Admin - but not listed as librarian)

**Test Steps:**
1. Log in as the non-librarian user
2. Navigate to a page with `[supabase_librarian]` shortcode

**Expected Results:**
- ❌ Should see: "You do not have permission to access the librarian interface. Please contact an administrator if you believe this is an error."
- ❌ Should NOT see the librarian CRUD interface
- ❌ Should NOT see the "Add New Record" button
- ❌ REST API calls should return 403 Forbidden

**Verify REST API:**
- Open browser Developer Tools → Network tab
- Try to load the librarian page
- Check REST API calls to `/supabase/v1/librarian-data`
- Should see: `403 Forbidden` or error message about permissions

---

### Test 2.2: Listed Librarian User Access

**Setup:**
1. Go to **Supabase Sync → Librarians** tab
2. Add a test user's email to the librarian list
3. Ensure that user has a WordPress account with that exact email

**Test Steps:**
1. Log in as the librarian user
2. Navigate to a page with `[supabase_librarian]` shortcode

**Expected Results:**
- ✅ Should see the full librarian interface
- ✅ Should see "Add New Record" button
- ✅ Should see the DataTable with library records
- ✅ Should be able to create, edit, and delete records
- ✅ REST API calls should return 200 OK

**Verify in WordPress Admin:**
- Go to **Supabase Sync → Librarians** tab
- Check that the user's email shows as "✓ Valid WordPress User"

---

### Test 2.3: Admin Access to Librarian Interface

**Test Steps:**
1. Log in as an **Administrator**
2. Navigate to a page with `[supabase_librarian]` shortcode

**Expected Results:**
- ✅ Should have full access to librarian interface
- ✅ Should see all CRUD functionality
- ✅ No restrictions (admins always have access)

---

### Test 2.4: Logged Out User Access to Librarian Interface

**Test Steps:**
1. Log out completely
2. Navigate to a page with `[supabase_librarian]` shortcode

**Expected Results:**
- ❌ Should see: "Please log in to access the librarian interface."
- ❌ Should NOT see the librarian interface
- ❌ REST API calls should return 401 Unauthorized

---

### Test 2.5: Librarian Email Case Sensitivity

**Setup:**
1. Add librarian email as: `TestUser@Example.com`
2. User's actual WordPress email is: `testuser@example.com`

**Test Steps:**
1. Log in as the user

**Expected Results:**
- ✅ Should work (emails are compared case-insensitively)
- ✅ User should have librarian access

---

## Part 3: Edge Cases and Additional Tests

### Test 3.1: User Plan Change

**Test Steps:**
1. Start with a Free user
2. Verify they cannot access locked databases
3. Change their plan to Paid in ARMember
4. Sync users: **Supabase Sync → Settings → "Sync All Users to Supabase"**
5. Log in as the user again

**Expected Results:**
- ✅ User should now have access to locked databases
- ✅ `supabase_access` meta should be set to true
- ✅ Supabase `wp_users` table should show `has_database_access = true`

---

### Test 3.2: Librarian Email Removed

**Test Steps:**
1. User has librarian access
2. Remove their email from librarian list in **Supabase Sync → Librarians**
3. Log in as the user

**Expected Results:**
- ❌ User should lose librarian access immediately
- ❌ Should see "You do not have permission" message

---

### Test 3.3: Multiple Plans (Dual Plan)

**Test Steps:**
1. User has multiple ARMember plans (e.g., both Paid and Dual)
2. Sync users
3. Check access

**Expected Results:**
- ✅ Should have database access if any plan is in the paid plans list
- ✅ Plan name should show correctly (not "Unknown")

---

### Test 3.4: REST API Direct Access

**Test Steps:**
1. As a Free user, try to access REST API directly:
   ```
   GET /wp-json/supabase/v1/table-data?table=your_locked_table
   ```

**Expected Results:**
- ❌ Should return: `403 Forbidden` or `401 Unauthorized`
- ❌ Should NOT return data

---

## Verification Checklist

Use this checklist to ensure all access controls are working:

### Database Access
- [ ] Free users cannot access locked databases
- [ ] Paid users can access locked databases
- [ ] Admins can access all databases
- [ ] Logged out users cannot access locked databases
- [ ] Unlocked databases are accessible to everyone
- [ ] `supabase_access` user meta is set correctly for paid users
- [ ] Supabase `wp_users` table has correct `has_database_access` values

### Librarian Access
- [ ] Non-librarian users cannot access librarian interface
- [ ] Listed librarian users can access librarian interface
- [ ] Admins can access librarian interface
- [ ] Logged out users cannot access librarian interface
- [ ] Librarian CRUD operations work for authorized users
- [ ] REST API returns correct status codes (200/403/401)

### General
- [ ] Plan changes sync correctly to Supabase
- [ ] User sync updates access permissions
- [ ] Error messages are user-friendly
- [ ] No sensitive data leaks in error messages

---

## Debugging Tips

### Check User Meta
```sql
-- See all users and their supabase_access status
SELECT u.ID, u.user_email, um.meta_value as supabase_access
FROM wp_users u
LEFT JOIN wp_usermeta um ON u.ID = um.user_id AND um.meta_key = 'supabase_access'
ORDER BY u.ID;
```

### Check Librarian Emails
```php
// In WordPress admin or via WP-CLI
$librarian_emails = get_option('supabase_librarian_emails', []);
print_r($librarian_emails);
```

### Check Table Lock Status
```php
// In WordPress admin or via WP-CLI
$tables = get_option('supabase_schema_tables', []);
foreach ($tables as $table) {
    echo $table['table_name'] . ': ' . ($table['is_locked'] ? 'LOCKED' : 'UNLOCKED') . "\n";
}
```

### Check Browser Console
- Open Developer Tools (F12)
- Check Console for JavaScript errors
- Check Network tab for REST API responses
- Look for 401/403 status codes

---

## Common Issues

### Issue: Paid users still can't access databases
**Solution:**
1. Go to **Supabase Sync → Settings**
2. Click "Sync All Users to Supabase"
3. Verify the user's plan is in the "Paid Plans" list
4. Check that `supabase_access` meta is set

### Issue: Librarian can't access interface
**Solution:**
1. Verify email is exactly correct (case-insensitive, but check for typos)
2. Ensure user has a WordPress account with that email
3. Check **Supabase Sync → Librarians** tab shows "✓ Valid WordPress User"
4. Try logging out and back in

### Issue: Plan shows as "Unknown"
**Solution:**
1. Check **Supabase Sync → Sync Logs** tab
2. Look for database query results
3. Verify ARMember plan exists and is active
4. Re-sync users after fixing plan issues

---

## Testing Script (Quick Test)

Run this quick test sequence:

1. **Free User Test:**
   - Log in as Free user → Try locked database → Should be blocked ✅

2. **Paid User Test:**
   - Log in as Paid user → Try locked database → Should work ✅

3. **Librarian Test:**
   - Log in as non-librarian → Try librarian page → Should be blocked ✅
   - Log in as librarian → Try librarian page → Should work ✅

4. **Admin Test:**
   - Log in as admin → Try everything → Should all work ✅

If all 4 tests pass, your permissions are working correctly! 🎉

