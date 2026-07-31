<?php namespace ProcessWire;

/**
 * Olivia background worker (CLI).
 *
 * Usage:  php bin/olivia-worker.php <jobId>
 *
 * Runs the slow AI call (plan or questions) outside the web request, so the
 * FastCGI/Apache request timeout doesn't kill it. Writes the result back to
 * the job file for the browser to pick up by polling.
 */

if(php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

// A model call can legitimately run for a couple of minutes; never let PHP's
// execution limit kill the worker mid-call (on hosts whose CLI sets one), and
// keep running even after the spawning request has closed.
@set_time_limit(0);
@ignore_user_abort(true);

$jobId = $argv[1] ?? '';
if($jobId === '') { fwrite(STDERR, "no job id\n"); exit(1); }

require_once __DIR__ . '/olivia-bootstrap.php';
oliviaCliBootstrap();

$jobs = wire(new OliviaJobs());
$job = $jobs->get($jobId);
if(!$job) { fwrite(STDERR, "job not found: $jobId\n"); exit(1); }
$jobs->start($jobId, getmypid());

$deadline = $jobs->deadlineSeconds((string) ($job['type'] ?? ''));
$jobType = (string) ($job['type'] ?? 'job');

$alarmAvailable = function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('pcntl_alarm');
if($alarmAvailable) {
	pcntl_async_signals(true);
	$alarm = defined('SIGALRM') ? constant('SIGALRM') : 14;
	pcntl_signal($alarm, function() use ($jobs, $jobId, $deadline, $jobType) {
		$jobs->fail($jobId, "Timed out after about {$deadline}s while running {$jobType} job {$jobId}. The background worker stopped the stalled job; try again or choose a faster model.");
		exit(124);
	});
	pcntl_alarm($deadline + 5);
} else {
	// Do not depend on browser polling for a hard deadline. A tiny detached CLI
	// guard re-checks the persisted job after its budget and exits harmlessly
	// when the worker already recorded done/error.
	$watchdog = __DIR__ . '/olivia-watchdog.php';
	if(function_exists('exec') && is_file($watchdog) && is_executable(PHP_BINARY)) {
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($watchdog) . ' '
			. escapeshellarg($jobId) . ' ' . (int)$deadline . ' >/dev/null 2>&1 &';
		@exec($cmd);
	}
}

// If a fatal (OOM, killed process, etc.) stops the worker, the try/catch below
// never runs and the job would be stuck "running" forever while the browser
// polls endlessly. Convert any such fatal into a visible failure.
register_shutdown_function(function() use ($jobs, $jobId, $jobType) {
	$e = error_get_last();
	if(!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
	$job = $jobs->get($jobId);
	if($job && !$jobs->isTerminal($job)) {
		$jobs->fail($jobId, "Worker stopped unexpectedly while running {$jobType} job {$jobId}: " . $e['message']);
	}
});

try {
	$prompt = (string)($job['payload']['prompt'] ?? '');
	$reference = is_array($job['payload']['reference'] ?? null) ? $job['payload']['reference'] : [];
	$webSearch = !empty($job['payload']['webSearch']);
	$visual = null;
	if($reference && in_array($job['type'], ['plan', 'questions', 'change'], true)) {
		$visualResult = wire(new OliviaVisualAnalyzer())->augmentPromptResult($prompt, $reference);
		$prompt = (string)($visualResult['prompt'] ?? $prompt);
		$visual = is_array($visualResult['visual'] ?? null) ? $visualResult['visual'] : null;
	}
	if($job['type'] === 'plan') {
		$planner = wire(new OliviaPlanner());
		$web = ['enabled' => $webSearch, 'ok' => false, 'fallback' => false, 'sources' => []];
		try {
			$plan = $planner->plan($prompt, $webSearch ? [
				'webSearch' => true,
				'webSearchMaxResults' => OliviaPlanner::WEB_SEARCH_MAX_RESULTS,
				'timeout' => OliviaPlanner::WEB_SEARCH_TIMEOUT,
			] : []);
			$web['ok'] = $webSearch;
		} catch(\Throwable $searchError) {
			if(!$webSearch) throw $searchError;
			$planner = wire(new OliviaPlanner());
			$plan = $planner->plan($prompt, ['timeout' => OliviaPlanner::WEB_FALLBACK_TIMEOUT]);
			$web['fallback'] = true;
			$web['reason'] = 'search_failed';
		}
		$web['sources'] = $planner->sources();
		$jobs->finish($jobId, [
			'plan' => $plan,
			'visual' => $visual,
			'web' => $web,
		]);
	} elseif($job['type'] === 'questions') {
		$questions = wire(new OliviaInterviewer())->questions($prompt);
		$jobs->finish($jobId, ['questions' => $questions, 'planningPrompt' => $prompt, 'visual' => $visual]);
	} elseif($job['type'] === 'change') {
		$planner = wire(new OliviaPlanner());
		$web = ['enabled' => $webSearch, 'ok' => false, 'fallback' => false, 'sources' => []];
		try {
			$plan = $planner->planChange($prompt, $webSearch ? [
				'webSearch' => true,
				'webSearchMaxResults' => OliviaPlanner::WEB_SEARCH_MAX_RESULTS,
				'timeout' => OliviaPlanner::WEB_SEARCH_TIMEOUT,
			] : []);
			$web['ok'] = $webSearch;
		} catch(\Throwable $searchError) {
			if(!$webSearch) throw $searchError;
			$planner = wire(new OliviaPlanner());
			$plan = $planner->planChange($prompt, ['timeout' => OliviaPlanner::WEB_FALLBACK_TIMEOUT]);
			$web['fallback'] = true;
			$web['reason'] = 'search_failed';
		}
		$web['sources'] = $planner->sources();
		$jobs->finish($jobId, [
			'plan' => $plan,
			'visual' => $visual,
			'web' => $web,
		]);
	} elseif($job['type'] === 'audit') {
		$audit = wire(new OliviaOperator())->audit([], $prompt);
		$jobs->finish($jobId, $audit);
	} elseif($job['type'] === 'install') {
		// downloading + installing third-party code (with install hooks) — act as superuser
		$su = wire('users')->get('template=user, roles=superuser, include=all');
		if($su && $su->id) wire('users')->setCurrentUser($su);
		$class = (string)($job['payload']['class'] ?? '');
		$res = wire(new OliviaModules())->install($class);
		if(!empty($res['ok'])) wire(new OliviaSkills())->record($class); // learn its skill immediately
		$jobs->finish($jobId, $res);
	} elseif($job['type'] === 'build') {
		// act as a superuser so object/file creation is unrestricted in CLI
		$su = wire('users')->get('template=user, roles=superuser, include=all');
		if($su && $su->id) wire('users')->setCurrentUser($su);
		$plan = $job['payload']['plan'] ?? [];
		$genImages = !empty($job['payload']['generateImages']);
		$fillContent = !empty($job['payload']['fillContent']);

		// install the recommended modules the user opted into, BEFORE building, so
		// the plan can wire them into views. This runs third-party install hooks —
		// it only happens because the user explicitly confirmed the listed modules.
		$installModules = (array)($job['payload']['installModules'] ?? []);
		$installedModules = [];
		if($installModules) {
			$mod = wire(new OliviaModules());
			foreach($installModules as $cls) {
				$cls = wire('sanitizer')->name((string) $cls);
				if($cls === '') continue;
				$res = $mod->install($cls);
				if(!empty($res['ok'])) { $installedModules[] = $cls; wire(new OliviaSkills())->record($cls); }
			}
			if($installedModules) wire('modules')->refresh();
		}
		$missingRequired = [];
		foreach((array)($job['payload']['requiredModules'] ?? []) as $requiredModule) {
			$requiredModule = wire('sanitizer')->name((string)$requiredModule);
			if($requiredModule !== '' && !wire('modules')->isInstalled($requiredModule)) {
				$missingRequired[] = $requiredModule;
			}
		}
		if($missingRequired) {
			throw new WireException(
				'Build stopped because required module installation failed: ' . implode(', ', $missingRequired)
			);
		}

		$builder = wire(new OliviaBuilder());
		$manifest = $builder->build($plan, $prompt, true, $genImages, $fillContent);
		$manifest['installed_modules'] = $installedModules;
		$id = $builder->saveManifestOrRollback(wire(new OliviaStore()), $manifest);
		$createdViews = count($manifest['files'] ?? []);
		$updatedViews = count($manifest['updated_files'] ?? []);
		$jobs->finish($jobId, [
			'buildId' => $id,
			'counts'  => [
				'fields'    => count($manifest['fields']),
				'templates' => count($manifest['templates']),
				'pages'     => count($manifest['pages']),
				'images'    => (int)($manifest['images'] ?? 0),
				'filled'    => (int)($manifest['filled_count'] ?? 0),
				'modules'   => count($installedModules),
				'files'     => $createdViews,
				'updated_files' => $updatedViews,
				'views'     => $createdViews + $updatedViews,
			],
			'installed_modules' => $installedModules,
			'errors'  => $manifest['errors'],
		]);
	} else {
		$jobs->fail($jobId, 'unknown job type: ' . $job['type']);
	}
} catch(\Throwable $e) {
	$jobs->fail($jobId, $e->getMessage());
} finally {
	if(function_exists('pcntl_alarm')) @pcntl_alarm(0);
}
