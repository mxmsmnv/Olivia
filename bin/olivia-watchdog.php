<?php namespace ProcessWire;

/** Fallback hard deadline for hosts where the CLI worker has no pcntl_alarm(). */

if(PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
@set_time_limit(0);
@ignore_user_abort(true);

$jobId = (string)($argv[1] ?? '');
$deadline = (int)($argv[2] ?? 0);
if(!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $jobId) || $deadline < 1 || $deadline > 3600) exit(1);

sleep(min(3600, $deadline + 10));

require_once __DIR__ . '/olivia-bootstrap.php';
oliviaCliBootstrap();

$jobs = wire(new OliviaJobs());
$job = $jobs->get($jobId);
if(!$job || $jobs->isTerminal($job) || $jobs->elapsedSeconds($job) < $deadline) exit(0);

$type = (string)($job['type'] ?? 'job');
$elapsed = $jobs->elapsedSeconds($job);
$stopped = $jobs->stopWorker($job);
$jobs->fail($jobId, "Timed out after about {$deadline}s while running {$type} job {$jobId}. The fallback watchdog stopped the stalled job; try again or choose a faster model.");
wire('log')->save('olivia', "fallback watchdog: failed stalled '{$type}' job {$jobId} elapsed={$elapsed}s deadline={$deadline}s worker_stop=" . ($stopped ? 'ok' : 'failed'));
