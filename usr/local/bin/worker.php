#!/usr/bin/env php
<?php

/**
 * Background worker for processing queued jobs.
 * Run this script in the background to process SMS and other deferred tasks.
 *
 * Usage: php worker.php
 * Or with nohup: nohup php worker.php > worker.log 2>&1 &
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/JobQueue.php';
require_once __DIR__ . '/sendSMS.php';
require_once __DIR__ . '/Functions.php';
require_once __DIR__ . '/Cliniko.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialize app (if needed for logging)
if (function_exists('app')) {
    app();
}

$queue = new JobQueue('jobs');

echo "Worker started at " . date('Y-m-d H:i:s') . "\n";
echo "Checking Redis connection...\n";

if (!$queue->isAvailable()) {
    echo "ERROR: Redis is not available. Worker cannot start.\n";
    echo "Make sure USE_REDIS=true in your .env and Redis is running.\n";
    exit(1);
}

echo "Redis connected successfully. Waiting for jobs...\n";
echo "Retry configuration: max_retries=" . $queue->getMaxRetries() . ", base_delay=" . $queue->getRetryDelay() . "s\n";

// Track last delayed job processing time
$lastDelayedCheck = time();

// Process jobs indefinitely
while (true) {
    try {
        // Process delayed jobs every 5 seconds
        if (time() - $lastDelayedCheck >= 5) {
            $movedCount = $queue->processDelayedJobs();
            if ($movedCount > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Moved {$movedCount} delayed job(s) to main queue\n";
            }
            $lastDelayedCheck = time();
        }

        // Wait up to 5 seconds for a job
        $job = $queue->pop(5);

        if ($job === null) {
            // No job available, continue waiting
            continue;
        }

        $retryInfo = ($job['retry_count'] ?? 0) > 0
            ? " (retry " . ($job['retry_count'] ?? 0) . "/" . ($job['max_retries'] ?? $queue->getMaxRetries()) . ")"
            : "";

        echo "[" . date('Y-m-d H:i:s') . "] Processing job: {$job['id']} (type: {$job['type']}){$retryInfo}\n";

        // Process job based on type
        $success = false;
        switch ($job['type']) {
            case 'send_sms':
                $success = processSmsJob($job['data']);
                break;

            case 'cancel_appointment':
                $success = processCancelAppointmentJob($job['data']);
                break;

            default:
                echo "Unknown job type: {$job['type']}\n";
                $success = false;
                break;
        }

        if ($success) {
            echo "[" . date('Y-m-d H:i:s') . "] Job {$job['id']} completed successfully\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Job {$job['id']} FAILED\n";

            // Attempt to retry the job
            if ($queue->retry($job)) {
                $nextRetry = ($job['retry_count'] ?? 0) + 1;
                $delay = $queue->getRetryDelay() * (2 ** $nextRetry - 1);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$job['id']} scheduled for retry #{$nextRetry} in {$delay}s\n";
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] Job {$job['id']} exceeded max retries or failed to re-queue\n";
            }
        }

    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        // Sleep a bit before retrying to avoid rapid error loops
        sleep(1);
    }
}

/**
 * Process an SMS sending job.
 *
 * @param array $data Job data containing message, phone, businessName, token.
 * @return bool True if successful, false otherwise.
 */
function processSmsJob(array $data): bool
{
    $message = $data['message'] ?? '';
    $phone = $data['phone'] ?? '';
    $businessName = $data['businessName'] ?? '';
    $token = $data['token'] ?? '';

    if (empty($message) || empty($phone) || empty($token)) {
        echo "ERROR: Missing required SMS data\n";
        return false;
    }

    $sms = new SendSMS();
    $success = $sms->send($message, $phone, $businessName, $token);

    if ($success) {
        echo "SMS sent successfully to {$phone}\n";
        return true;
    } else {
        echo "ERROR: Failed to send SMS to {$phone}\n";
        return false;
    }
}

/**
 * Process an appointment cancellation job.
 *
 * @param array $data Job data containing appointmentId, cancellationNote, cancellationReason, applyToRepeats.
 * @return bool True if successful, false otherwise.
 */
function processCancelAppointmentJob(array $data): bool
{
    $appointmentId = $data['appointmentId'] ?? '';
    $cancellationNote = $data['cancellationNote'] ?? '';
    $cancellationReason = $data['cancellationReason'] ?? 50;
    $applyToRepeats = $data['applyToRepeats'] ?? false;

    if (empty($appointmentId)) {
        echo "ERROR: Missing appointment ID\n";
        return false;
    }

    try {
        $cliniko = new Cliniko();

        $payloadData = json_encode([
            "cancellation_note" => $cancellationNote,
            "cancellation_reason" => $cancellationReason,
            "apply_to_repeats" => $applyToRepeats
        ]);

        $result = $cliniko->cancelAppointment($appointmentId, $payloadData);
        echo "Appointment {$appointmentId} cancelled successfully\n";
        return true;
    } catch (\Throwable $e) {
        echo "ERROR: Failed to cancel appointment {$appointmentId}: " . $e->getMessage() . "\n";
        return false;
    }
}
