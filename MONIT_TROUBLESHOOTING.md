# Monit Troubleshooting Guide for Jelastic

## Problem
Monit service is running but custom services (nginx, php-fpm, booking_queue, redis) are not showing up.

## Root Causes

1. **Path mismatch**: The manifest was downloading config files from incorrect GitHub paths:
   - Wrong: `etc/monit/nginx` → Correct: `etc/monit.d/nginx`
   - Wrong: `etc/monit/booking_queue` → Correct: `etc/monit.d/booking_queue`
   - Missing: `etc/monit.d/phpfpm` was not being downloaded

2. **Missing include directive**: The main `/etc/monitrc` file might not include `/etc/monit.d/*`

3. **Monit not reloaded**: After copying config files, monit needs to reload its configuration

## Fix for Existing Jelastic Instance

Connect to your Jelastic node via SSH and run these commands as root:

### Step 1: Verify Current State
```bash
# Check if monit is running
systemctl status monit

# Check what services monit knows about
monit summary

# Check if config directory exists
ls -la /etc/monit.d/

# Check main monit config for include directive
grep "include /etc/monit.d" /etc/monitrc
```

### Step 2: Download Missing Config Files
```bash
# Download monit configs from correct paths
curl -fsSL https://raw.githubusercontent.com/ProxiBlue/booking_agent/main/etc/monit.d/nginx -o /etc/monit.d/nginx
curl -fsSL https://raw.githubusercontent.com/ProxiBlue/booking_agent/main/etc/monit.d/phpfpm -o /etc/monit.d/phpfpm
curl -fsSL https://raw.githubusercontent.com/ProxiBlue/booking_agent/main/etc/monit.d/booking_queue -o /etc/monit.d/booking_queue

# Verify files were downloaded
ls -la /etc/monit.d/
```

### Step 3: Add Include Directive to monitrc
```bash
# Check if include directive already exists
if ! grep -q "include /etc/monit.d/\*" /etc/monitrc; then
    echo "include /etc/monit.d/*" >> /etc/monitrc
    echo "Include directive added"
else
    echo "Include directive already exists"
fi
```

### Step 4: Validate and Reload Monit
```bash
# Test monit configuration syntax
monit -t

# Reload monit to pick up new configs
monit reload

# Wait a few seconds for reload to complete
sleep 5

# Verify services are now loaded
monit summary
```

### Step 5: Verify Worker Paths
The booking_queue config expects files at specific paths. Verify they exist:

```bash
# Check worker script exists
ls -la /var/www/webroot/ROOT/worker.php

# Check redis-queue monitoring script
ls -la /usr/local/bin/check-redis-queue.sh
chmod +x /usr/local/bin/check-redis-queue.sh
```

### Step 6: Start Services via Monit
```bash
# Start all configured services
monit start all

# Check status
monit status
```

## Expected Output

After following the steps above, `monit summary` should show:

```
The Monit daemon 5.x uptime: Xh Xm

Process 'nginx'                     Running
Process 'php-fpm'                   Running
Process 'booking_queue'             Running
Process 'redis-server'              Running
Program 'redis-queue-length'        Status ok
```

## Troubleshooting Common Issues

### Issue: booking_queue not starting
**Symptom**: `monit status booking_queue` shows "Not monitored" or "Does not exist"

**Checks**:
```bash
# Verify worker.php exists
ls -la /var/www/webroot/ROOT/worker.php

# Check if worker is already running (outside monit)
ps aux | grep worker.php | grep -v grep

# If running, kill it so monit can manage it
pkill -f "php.*worker.php"

# Start via monit
monit start booking_queue
```

### Issue: redis-server not starting
**Symptom**: `monit status redis-server` shows errors

**Checks**:
```bash
# Check Redis config
ls -la /etc/redis/redis.conf

# Check Redis PID file location
grep "pidfile" /etc/redis/redis.conf

# Verify Redis is running
systemctl status redis

# Check Redis port
redis-cli ping
```

### Issue: Config syntax errors
**Symptom**: `monit -t` shows syntax errors

**Fix**:
```bash
# Check config syntax in detail
monit -t -v

# Check specific config file
cat /etc/monit.d/booking_queue

# Verify paths in config match actual system paths
```

## Monitoring Monit

### View Monit Logs
```bash
# Monit logs
tail -f /var/log/monit.log

# System logs for monit service
journalctl -u monit -f
```

### Access Monit Web Interface
Monit runs a web interface on port 2812:
```
http://YOUR_JELASTIC_IP:2812
Username: admin
Password: (see manifest globals.MONIT_PASS or check install logs)
```

## Permanent Fix (Manifest)

The manifest.jps has been updated with:
1. ✅ Correct GitHub paths for monit configs (with `.d`)
2. ✅ Added missing phpfpm config download
3. ✅ Added include directive to monitrc
4. ✅ Added monit reload after config setup

These changes will apply to new installations automatically.

## Verification Checklist

- [ ] Monit service is running: `systemctl status monit`
- [ ] Config directory exists: `ls /etc/monit.d/`
- [ ] All config files present: nginx, phpfpm, booking_queue
- [ ] Include directive in monitrc: `grep "include /etc/monit.d" /etc/monitrc`
- [ ] Monit config valid: `monit -t`
- [ ] All services show in summary: `monit summary`
- [ ] Worker process running: `ps aux | grep worker.php`
- [ ] Redis responding: `redis-cli ping`
- [ ] Queue monitoring script executable: `ls -la /usr/local/bin/check-redis-queue.sh`

## Quick Fix Script

Run this one-liner to apply all fixes:

```bash
curl -fsSL https://raw.githubusercontent.com/ProxiBlue/booking_agent/main/etc/monit.d/nginx -o /etc/monit.d/nginx && \
curl -fsSL https://raw.githubusercontent.com/ProxiBlue/booking_agent/main/etc/monit.d/phpfpm -o /etc/monit.d/phpfpm && \
curl -fsSL https://raw.githubusercontent.com/ProxiBlue/booking_agent/main/etc/monit.d/booking_queue -o /etc/monit.d/booking_queue && \
grep -q "include /etc/monit.d/\*" /etc/monitrc || echo "include /etc/monit.d/*" >> /etc/monitrc && \
monit -t && monit reload && sleep 5 && monit summary
```
