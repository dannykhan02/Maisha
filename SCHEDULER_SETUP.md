# Scheduler Setup — Daily Meal Plan Generation

## Overview

The daily meal-plan scheduler is **registered but NOT YET ACTIVE**. This document explains how to activate it when ready.

---

## Current State

**File:** `routes/console.php`

The scheduler is defined but requires a system cron entry to actually run. Currently:
- The Artisan command `maisha:generate-daily-meal-plans` exists and is testable manually
- The scheduler is registered to run daily at 06:00 AM (Africa/Nairobi timezone)
- **No cron entry is active** — the scheduler will not execute automatically

---

## Manual Testing (Before Activation)

To test the command without enabling the live cron:

```bash
php artisan maisha:generate-daily-meal-plans --test-user=1
```

Replace `1` with the actual user ID you want to test with. The command will:
- Check if the user exists
- Check if they've hit the daily limit (10 plans/day)
- Generate a meal plan using their `daily_budget_kes`
- Log success or failure

---

## Activation: System Cron Entry

To enable the live scheduler, add this entry to your system crontab:

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

**Steps:**

1. Open your crontab editor:
   ```bash
   crontab -e
   ```

2. Add the line above, replacing `/path-to-project` with the absolute path to your Laravel project (e.g., `/home/user/maisha-api`)

3. Save and exit

4. Verify the cron entry was added:
   ```bash
   crontab -l
   ```

---

## When to Activate

**Do NOT activate the cron entry until:**

1. ✅ **Item #3 (Flask Rate-Limit Enforcement)** is complete
   - Ensures the scheduler respects `MAX_UTAKULAA_PER_USER_PER_DAY` limits
   - Prevents hammering the Flask API

2. ✅ **Item #7 (Frontend Architecture Audit)** is complete
   - Ensures the frontend can handle scheduled meal plans
   - Confirms response shapes are stable

**Decision point:** After both items are done, review API cost implications and decide whether to activate.

---

## Monitoring & Logging

Once active, the scheduler logs to:
- **Success:** `storage/logs/laravel.log` with `Daily meal plan generated` entries
- **Failure:** `storage/logs/laravel.log` with `Daily meal plan generation failed` entries
- **Rate-limit skip:** `storage/logs/laravel.log` with `Daily meal plan generation skipped` entries

To monitor in real-time:

```bash
tail -f storage/logs/laravel.log | grep "Daily meal plan"
```

---

## Deactivation

If you need to disable the scheduler temporarily:

1. Remove the cron entry:
   ```bash
   crontab -e
   # Delete the line
   ```

2. The command remains available for manual testing:
   ```bash
   php artisan maisha:generate-daily-meal-plans --test-user=1
   ```

---

## Troubleshooting

**Cron not running?**
- Verify the cron entry exists: `crontab -l`
- Check system cron logs: `grep CRON /var/log/syslog` (Linux) or `log stream --predicate 'process == "cron"'` (macOS)
- Ensure the path to the project is correct

**Command fails with "User not found"?**
- The `--test-user` flag is hardcoded in the scheduler. Update `routes/console.php` to use a different user ID if needed.

**Rate-limit being hit?**
- The command checks `MAX_UTAKULAA_PER_USER_PER_DAY` (10 plans/day). If the user already has 10 plans today, the command skips them.
- This is intentional to prevent API cost overruns.

---

## Next Steps

1. Test the command manually: `php artisan maisha:generate-daily-meal-plans --test-user=1`
2. Verify logs are being written correctly
3. After Items #3 and #7 are complete, decide on activation
4. Add the cron entry when ready
5. Monitor logs for the first few days to ensure it's working as expected
