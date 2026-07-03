# Maisha Queue Worker Setup Guide

## Overview

The WhatsApp message processing job (`ProcessIncomingWhatsAppMessage`) now runs **asynchronously** via Laravel's database queue. This document explains how to set up and run the queue worker.

---

## Current Status

- ✅ **Jobs table migration:** Exists (`2026_06_11_150612_create_jobs_table.php`)
- ✅ **Failed jobs table:** Exists (`2026_06_11_150618_create_failed_jobs_table.php`)
- ✅ **Queue driver:** Changed to `database` in `.env`
- ✅ **Job class:** `ProcessIncomingWhatsAppMessage` ready to dispatch

---

## Quick Start (Development)

### 1. Ensure migrations are run
```bash
cd backend/maisha-api
php artisan migrate
```

### 2. Start the queue worker (foreground)
```bash
php artisan queue:work database --queue=default --tries=3 --timeout=90
```

This will:
- Listen to the `default` queue in the `jobs` table
- Retry failed jobs up to 3 times
- Kill jobs that take longer than 90 seconds
- Display output in your terminal

### 3. Test it
Send a WhatsApp message to your configured number. You should see:
```
Processing: App\Jobs\ProcessIncomingWhatsAppMessage
Processed:  App\Jobs\ProcessIncomingWhatsAppMessage
```

---

## Production Setup

For production, you need a **process manager** to keep the worker running persistently.

### Option A: Supervisor (Recommended for Linux)

#### Install Supervisor
```bash
sudo apt-get update
sudo apt-get install supervisor
```

#### Copy config file
```bash
sudo cp backend/maisha-api/config/supervisor-maisha-queue.conf /etc/supervisor/conf.d/maisha-queue.conf
```

#### Start the worker
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start maisha-queue:*
```

#### Monitor
```bash
# Check status
sudo supervisorctl status maisha-queue:*

# View logs
tail -f /var/log/maisha-queue.log

# Restart if needed
sudo supervisorctl restart maisha-queue:*
```

---

### Option B: Systemd (Modern Linux)

#### Copy service file
```bash
sudo cp backend/maisha-api/config/systemd-maisha-queue.service /etc/systemd/system/maisha-queue.service
```

#### Enable and start
```bash
sudo systemctl daemon-reload
sudo systemctl enable maisha-queue
sudo systemctl start maisha-queue
```

#### Monitor
```bash
# Check status
sudo systemctl status maisha-queue

# View logs
journalctl -u maisha-queue -f

# Restart if needed
sudo systemctl restart maisha-queue
```

---

## Queue:Work Command Explained

```bash
php artisan queue:work database --queue=default --tries=3 --timeout=90 --max-jobs=1000 --max-time=3600 --sleep=3
```

| Flag | Value | Purpose |
|------|-------|---------|
| `database` | Driver | Use MySQL `jobs` table as queue |
| `--queue=default` | Queue name | Listen to "default" queue |
| `--tries=3` | Retry count | Retry failed jobs 3 times |
| `--timeout=90` | Seconds | Kill job if it takes >90 seconds |
| `--max-jobs=1000` | Count | Restart worker after 1000 jobs (prevents memory leaks) |
| `--max-time=3600` | Seconds | Restart worker after 1 hour (prevents zombie processes) |
| `--sleep=3` | Seconds | Sleep 3 seconds between polling (reduces CPU) |

---

## Monitoring & Debugging

### Check queued jobs
```bash
mysql -u root -ppassword123 maisha -e "SELECT id, queue, attempts, created_at FROM jobs ORDER BY created_at DESC LIMIT 10;"
```

### Check failed jobs
```bash
mysql -u root -ppassword123 maisha -e "SELECT id, connection, queue, failed_at FROM failed_jobs ORDER BY failed_at DESC LIMIT 10;"
```

### Retry failed jobs
```bash
php artisan queue:retry all
```

### Clear all jobs
```bash
php artisan queue:flush
```

### Monitor in real-time
```bash
# Terminal 1: Start worker
php artisan queue:work database --queue=default --tries=3 --timeout=90

# Terminal 2: Watch jobs table
watch -n 1 "mysql -u root -ppassword123 maisha -e 'SELECT COUNT(*) as pending_jobs FROM jobs;'"
```

---

## Troubleshooting

### Worker not processing jobs
1. Check if worker is running: `ps aux | grep "queue:work"`
2. Check logs: `tail -f /var/log/maisha-queue.log` (Supervisor) or `journalctl -u maisha-queue -f` (Systemd)
3. Verify `QUEUE_CONNECTION=database` in `.env`
4. Ensure migrations are run: `php artisan migrate`

### Jobs stuck in queue
1. Check if worker crashed: `sudo supervisorctl status maisha-queue:*`
2. Restart worker: `sudo supervisorctl restart maisha-queue:*`
3. Check for timeout issues: Increase `--timeout` value

### High CPU usage
1. Increase `--sleep` value (default 3 seconds)
2. Reduce `--max-jobs` to restart worker more frequently
3. Consider using Redis queue instead of database

### Memory leaks
1. Ensure `--max-jobs=1000` is set (restarts worker after 1000 jobs)
2. Ensure `--max-time=3600` is set (restarts worker after 1 hour)

---

## Data Flow

```
Meta WhatsApp
    ↓
POST /webhook/whatsapp
    ↓
WhatsAppWebhookController::handle()
    ├─ Store message in WhatsappMessage table
    └─ ProcessIncomingWhatsAppMessage::dispatch()
        └─ Insert job into jobs table
    ↓
Return 200 OK immediately (< 100ms)
    ↓
Queue Worker (separate process)
    ├─ Poll jobs table every 3 seconds
    ├─ Pick up ProcessIncomingWhatsAppMessage job
    ├─ Extract phone & message text
    ├─ Call Flask /api/intent
    ├─ Route to handler (utakulaa, budget, history, help)
    ├─ Call Flask /api/utakulaa (if needed)
    ├─ Send WhatsApp reply via Meta API
    ├─ Log conversation
    └─ Mark job as complete (delete from jobs table)
    ↓
User receives WhatsApp message
```

---

## Environment Variables

Add these to `.env` if you want to customize queue behavior:

```bash
# Queue driver (already set to 'database')
QUEUE_CONNECTION=database

# Database queue settings
DB_QUEUE_CONNECTION=mysql          # Which DB connection to use
DB_QUEUE_TABLE=jobs                # Table name (default: jobs)
DB_QUEUE=default                   # Queue name (default: default)
DB_QUEUE_RETRY_AFTER=90            # Retry after 90 seconds
```

---

## Next Steps

1. **Development:** Run `php artisan queue:work database --queue=default --tries=3 --timeout=90` in a separate terminal
2. **Staging:** Use Supervisor or Systemd to keep worker running
3. **Production:** Monitor worker health with `supervisorctl` or `systemctl`
4. **Optional:** Switch to Redis queue for better performance at scale

---

## References

- [Laravel Queue Documentation](https://laravel.com/docs/11.x/queues)
- [Supervisor Documentation](http://supervisord.org/)
- [Systemd Documentation](https://www.freedesktop.org/software/systemd/man/systemd.service.html)
