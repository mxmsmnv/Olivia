<?php namespace ProcessWire;

trait OliviaSupportDebug {

	protected function renderDebug(bool $standalone = false, string $chatId = '', string $mode = '', string $planJson = ''): string {
		$bundle = $this->debugBundle($chatId, $mode, $planJson);
		$json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$h = fn($s) => $this->wire->sanitizer->entities((string) $s);
		$latestBuildSummary = !empty($bundle['state']['latest_build_present'])
			? ($bundle['state']['latest_build_id'] ?? 'unknown')
				. ' · ' . (int)($bundle['state']['latest_build_age_seconds'] ?? 0) . 's ago'
				. ' · errors: ' . (int)($bundle['state']['latest_build_errors'] ?? 0)
			: 'none';
		$chatSummary = ($bundle['state']['chat_id'] ?? '') === ''
			? 'none'
			: (!empty($bundle['state']['chat_found']) ? 'found' : 'missing')
				. ' · messages: ' . (int)($bundle['state']['chat_messages'] ?? 0);
		$features = is_array($bundle['olivia']['features'] ?? null) ? $bundle['olivia']['features'] : [];
		$featureSummary = 'images: ' . (!empty($features['generate_images']) ? 'on' : 'off')
			. ' · content: ' . (!empty($features['fill_content']) ? 'on' : 'off')
			. ' · web research: ' . (!empty($features['web_research']) ? 'available' : 'unavailable')
			. ' · telemetry: ' . (!empty($features['telemetry']) ? 'on' : 'off');
		$capture = is_array($bundle['olivia']['reference_capture'] ?? null) ? $bundle['olivia']['reference_capture'] : [];
		$captureSummary = 'provider: ' . (string)($capture['provider'] ?? 'off')
			. ' · key: ' . (!empty($capture['key_configured']) ? 'set' : 'missing')
			. ' · curl: ' . (!empty($capture['curl_available']) ? 'yes' : 'no')
			. ' · ready: ' . (!empty($capture['ready']) ? 'yes' : 'no');
		$limits = is_array($bundle['olivia']['limits'] ?? null) ? $bundle['olivia']['limits'] : [];
		$limitSummary = 'images/build: ' . (int)($limits['max_images_per_build'] ?? 0)
			. ' · content fills/build: ' . (int)($limits['max_content_fills_per_build'] ?? 0)
			. ' · reference pages: ' . (int)($limits['reference_max_pages'] ?? 0)
			. ' · JSON caps job/chat/build: '
			. round(((int)($limits['job_file_byte_cap'] ?? 0)) / 1048576, 1) . '/'
			. round(((int)($limits['chat_file_byte_cap'] ?? 0)) / 1048576, 1) . '/'
			. round(((int)($limits['manifest_file_byte_cap'] ?? 0)) / 1048576, 1) . ' MB';
		$budgets = is_array($bundle['olivia']['model_call_budgets'] ?? null) ? $bundle['olivia']['model_call_budgets'] : [];
		$budgetSummary = 'planner: ' . (int)($budgets['planner']['timeout_seconds'] ?? 0) . 's'
			. ' · interview: ' . (int)($budgets['interviewer']['timeout_seconds'] ?? 0) . 's'
			. ' · image: ' . (int)($budgets['image']['timeout_seconds'] ?? 0) . 's'
			. ' · reference: ' . (int)($budgets['reference_fetch']['timeout_seconds'] ?? 0) . 's';
		$deadlines = is_array($bundle['job_timeouts_seconds'] ?? null) ? $bundle['job_timeouts_seconds'] : [];
		$deadlineSummary = 'plan: ' . (int)($deadlines['plan'] ?? 0) . 's'
			. ' · build: ' . (int)($deadlines['build'] ?? 0) . 's'
			. ' · web post: ' . (int)($deadlines['web_post_soft_limit'] ?? 0) . 's';
		$healthJob = is_array($bundle['state']['recent_job_health_job'] ?? null) ? $bundle['state']['recent_job_health_job'] : [];
		$deadlineUseSummary = !empty($healthJob)
			? (string)($healthJob['id'] ?? 'unknown')
				. ' · ' . (string)($healthJob['type'] ?? 'job')
				. '/' . (string)($healthJob['status'] ?? 'unknown')
				. ': ' . (int)($healthJob['deadline_used_percent'] ?? 0) . '%'
				. ' · overrun: ' . (int)($healthJob['overrun_seconds'] ?? 0) . 's'
			: 'none';
		$modules = is_array($bundle['modules'] ?? null) ? $bundle['modules'] : [];
		$moduleSummary = 'Squad: ' . (!empty($modules['Squad']['installed']) ? 'on' : 'off')
			. ' · GrokImagine: ' . (!empty($modules['GrokImagine']['installed']) ? 'on' : 'off')
			. ' · Context: ' . (!empty($modules['Context']['installed']) ? 'on' : 'off')
			. ' · Ichiban: ' . (!empty($modules['Ichiban']['installed']) ? 'on' : 'off');
		$runtimeSummary = 'sapi: ' . (string)($bundle['environment']['php_sapi'] ?? '')
			. ' · site debug: ' . (!empty($bundle['environment']['site_debug']) ? 'on' : 'off');
		$workerControlSummary = 'stop: ' . (!empty($bundle['environment']['worker_stop_available']) ? 'on' : 'off')
			. ' · alarm: ' . (!empty($bundle['environment']['worker_alarm_available']) ? 'on' : 'off');
		$workerDeadlineGuardSummary = !empty($bundle['environment']['worker_deadline_guard_ready'])
			? (!empty($bundle['environment']['worker_alarm_available']) ? 'pcntl alarm' : 'fallback watchdog')
			: 'unavailable';
		$workerLaunchSummary = basename((string)($bundle['environment']['worker_php_binary'] ?? 'php'))
			. ' · ' . basename((string)($bundle['environment']['worker_script'] ?? 'olivia-worker.php'));
		$workerLaunchCheckSummary = 'php executable: ' . (!empty($bundle['environment']['worker_php_binary_executable']) ? 'yes' : 'no')
			. ' · script exists: ' . (!empty($bundle['environment']['worker_script_exists']) ? 'yes' : 'no');
		$workerReadySummary = !empty($bundle['environment']['worker_launch_ready']) ? 'yes' : 'no';
		$jobCountSummary = 'active: ' . (int)($bundle['state']['recent_job_active_count'] ?? 0)
			. ' · running: ' . (int)($bundle['state']['recent_job_running_count'] ?? 0)
			. ' · errors: ' . (int)($bundle['state']['recent_job_error_count'] ?? 0)
			. ' · over-deadline: ' . (int)($bundle['state']['recent_job_over_deadline_count'] ?? 0);
		$rows = [
			'Support ID' => $bundle['support']['id'] ?? '',
			'Generated at' => $bundle['support']['generated_at'] ?? '',
			'Debug schema' => $bundle['support']['schema_version'] ?? '',
			'Olivia version' => $bundle['olivia']['version'] ?? '',
			'ProcessWire' => $bundle['environment']['processwire_version'] ?? '',
			'PHP' => $bundle['environment']['php_version'] ?? '',
			'Runtime' => $runtimeSummary,
			'Worker controls' => $workerControlSummary,
			'Worker deadline guard' => $workerDeadlineGuardSummary,
			'Worker launch' => $workerLaunchSummary,
			'Worker launch check' => $workerLaunchCheckSummary,
			'Worker ready' => $workerReadySummary,
			'Feature flags' => $featureSummary,
			'Reference capture' => $captureSummary,
			'Build caps' => $limitSummary,
			'Model budgets' => $budgetSummary,
			'Job deadlines' => $deadlineSummary,
			'Module status' => $moduleSummary,
			'Squad provider' => $bundle['squad']['provider'] ?? 'unknown',
			'Squad default model' => $bundle['squad']['default_model'] ?? 'unknown',
			'Active mode' => $bundle['olivia']['active_mode'] ?? '',
			'Active chat' => $bundle['state']['chat_id'] ?? 'none',
			'Chat state' => $chatSummary,
			'Plan status' => $bundle['state']['plan_status'] ?? 'none',
			'Plan summary' => $bundle['state']['plan_summary'] ?? '',
			'Job health' => $bundle['state']['recent_job_health_summary'] ?? '',
			'Job action' => $bundle['state']['recent_job_health_action'] ?? '',
			'Job target' => ($bundle['state']['recent_job_health_job_id'] ?? '') !== ''
				? ($bundle['state']['recent_job_health_job_id'] ?? '')
					. ' · ' . ($bundle['state']['recent_job_health_job_type'] ?? '')
					. '/' . ($bundle['state']['recent_job_health_job_status'] ?? '')
				: 'none',
			'Job PID' => (int)($bundle['state']['recent_job_health_job_pid'] ?? 0) > 0 ? (int)($bundle['state']['recent_job_health_job_pid'] ?? 0) : 'none',
			'Job error' => ($bundle['state']['recent_job_health_job_error'] ?? '') !== '' ? ($bundle['state']['recent_job_health_job_error'] ?? '') : 'none',
			'Job counts' => $jobCountSummary,
			'Job issue mix' => ($bundle['state']['recent_job_health_issue_summary'] ?? '') !== '' ? $bundle['state']['recent_job_health_issue_summary'] : 'none',
			'Job deadline use' => $deadlineUseSummary,
			'Builds' => $bundle['state']['build_count'] ?? 0,
			'Latest build' => $latestBuildSummary,
		];
		$table = "<div class='ol-table-wrap'><table class='ol-data-table' aria-label='Olivia support parameters' aria-describedby='ol-debug-desc'><tbody>";
		foreach($rows as $label => $value) {
			$table .= "<tr><th scope='row'>{$h($label)}</th><td><code>{$h($value)}</code></td></tr>";
		}
		$table .= "</tbody></table></div>";
		return "<section class='ol-page' aria-labelledby='ol-debug-title'>"
			. "<div class='ol-page-head'><div class='ol-page-heading'><span class='ol-page-icon' aria-hidden='true'><i class='ri-bug-line'></i></span><div><p class='ol-page-kicker'>Diagnostics</p><h1 id='ol-debug-title' class='ol-page-title'>Support info</h1>"
			. "<p id='ol-debug-desc' class='detail'>Copy this when debugging Olivia. It includes versions, model routing, roles, feature flags and recent job/build ids. API keys and secrets are masked.</p></div></div></div>"
			. "<div class='ol-stat-strip' aria-label='Olivia runtime summary'>"
			. "<div><span>Olivia</span><strong>v{$h($bundle['olivia']['version'] ?? '')}</strong></div>"
			. "<div><span>ProcessWire</span><strong>{$h($bundle['environment']['processwire_version'] ?? '')}</strong></div>"
			. "<div><span>Worker</span><strong class='" . (!empty($bundle['environment']['worker_launch_ready']) ? "is-ok" : "is-warning") . "'>" . (!empty($bundle['environment']['worker_launch_ready']) ? "Ready" : "Check setup") . "</strong></div>"
			. "<div><span>Recent jobs</span><strong>" . (int)($bundle['state']['recent_job_active_count'] ?? 0) . " active</strong></div>"
			. "<div><span>Builds</span><strong>" . (int)($bundle['state']['build_count'] ?? 0) . "</strong></div>"
			. "</div>"
			. "<div class='ol-debug-layout'><div class='ol-debug-parameters'><h2>Runtime parameters</h2>{$table}</div>"
			. "<aside class='ol-debug-bundle' aria-labelledby='ol-debug-bundle-title'><div class='ol-debug-bundle-head'><div><p class='ol-page-kicker'>Share with support</p><h2 id='ol-debug-bundle-title'>Debug bundle</h2></div>"
			. "<button type='button' class='ol-btn ol-primary' id='ol-copy-debug' title='Copy support debug bundle' aria-label='Copy support debug bundle' aria-controls='ol-debug-json' aria-describedby='ol-copy-debug-status'><i class='ri-file-copy-line' aria-hidden='true'></i> Copy</button></div>"
			. "<p class='detail'>Secret-free JSON for a support ticket or bug report.</p><span id='ol-copy-debug-status' class='ol-copy-status' role='status' aria-live='polite' aria-atomic='true'></span>"
			. "<textarea id='ol-debug-json' class='ol-debug-json' rows='30' readonly title='Copyable Olivia support debug JSON' aria-label='Copyable Olivia support debug JSON' onclick='this.select()'>{$h($json)}</textarea></aside></div>"
			. "<script>(function(){var b=document.getElementById('ol-copy-debug');var t=document.getElementById('ol-debug-json');var s=document.getElementById('ol-copy-debug-status');if(b&&t){function done(){b.textContent='Copied';b.setAttribute('aria-label','Support debug bundle copied');b.setAttribute('title','Support debug bundle copied');b.setAttribute('aria-controls','ol-debug-json');b.setAttribute('aria-describedby','ol-copy-debug-status');if(s)s.textContent='Copied';setTimeout(function(){b.innerHTML='<i class=\"ri-file-copy-line\" aria-hidden=\"true\"></i> Copy debug bundle';b.setAttribute('aria-label','Copy support debug bundle');b.setAttribute('title','Copy support debug bundle');b.setAttribute('aria-controls','ol-debug-json');b.setAttribute('aria-describedby','ol-copy-debug-status');if(s&&s.textContent==='Copied')s.textContent='';},1800);}b.addEventListener('click',function(){t.select();if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).then(done).catch(function(){document.execCommand('copy');done();});}else{document.execCommand('copy');done();}});}})();</script>"
			. "</section>";
	}

	protected function debugBundle(string $chatId = '', string $mode = '', string $planJson = ''): array {
		$info = self::getModuleInfo();
		$roles = $this->wire(new OliviaRoles());
		$roleMap = [];
		foreach($roles->roles() as $role => $desc) {
			$model = $roles->model($role);
			$roleMap[$role] = [
				'description' => $desc,
				'model' => $model !== '' ? $model : '(Squad default)',
				'explicit' => $model !== '',
			];
		}
		$chat = $chatId !== '' ? $this->chats()->load($chatId) : null;
		$builds = $this->store()->all();
		$latestBuildState = $this->latestBuildDebugState(is_array($builds[0] ?? null) ? $builds[0] : []);
		$squadInfo = $this->moduleDebugInfo('Squad');
		$squadConfig = $squadInfo['config'] ?? [];
		$squadProvider = $this->debugScalarString($squadConfig['provider'] ?? $squadConfig['defaultProvider'] ?? 'unknown');
		$squadDefaultModel = $this->debugScalarString($squadConfig['model'] ?? $squadConfig['defaultModel'] ?? $squadConfig['openrouterModel'] ?? '');
		if($squadDefaultModel === '' || $squadDefaultModel === 'unknown') $squadDefaultModel = $this->squadDefaultModel($squadProvider);
		foreach($roleMap as $role => $r) {
			$roleMap[$role]['effective_model'] = !empty($r['explicit']) ? $r['model'] : ($squadDefaultModel !== '' ? $squadDefaultModel : '(unknown Squad default)');
		}
		$planState = $this->planDebugState($planJson);
		$recentJobs = $this->recentJobsDebug();
		$recentJobSummary = $this->recentJobSummary($recentJobs);
		$recentJobHealthJob = is_array($recentJobSummary['health_job'] ?? null) ? $recentJobSummary['health_job'] : [];
		$recentJobHealthRaw = [];
		$recentJobHealthJobId = $this->debugScalarString($recentJobHealthJob['id'] ?? null);
		foreach($recentJobs as $recentJob) {
			if($recentJobHealthJobId !== '' && $this->debugScalarString($recentJob['id'] ?? null) === $recentJobHealthJobId) {
				$recentJobHealthRaw = $recentJob;
				break;
			}
		}
		$generatedAt = date('c');
		$oliviaConfig = $this->wire->modules->getModuleConfigData('Olivia');
		if(!is_array($oliviaConfig)) $oliviaConfig = [];
		$referenceCaptureProvider = strtolower(trim((string)($oliviaConfig['referenceScreenshotProvider'] ?? '')));
		if($referenceCaptureProvider === '') $referenceCaptureProvider = 'off';
		$referenceCaptureSupported = in_array($referenceCaptureProvider, ['off', 'screenshotone'], true);
		$referenceCaptureKeyConfigured = trim((string)($oliviaConfig['referenceScreenshotKey'] ?? '')) !== '';
		$referenceCaptureReady = $referenceCaptureProvider === 'screenshotone'
			&& $referenceCaptureKeyConfigured && function_exists('curl_init');
		return [
			'support' => [
				'schema_version' => self::SUPPORT_DEBUG_SCHEMA_VERSION,
				'id' => 'olivia-' . date('Ymd-His') . '-' . substr(hash('sha256', microtime(true) . random_int(0, PHP_INT_MAX)), 0, 6),
				'generated_at' => $generatedAt,
			],
			'olivia' => [
				'version' => $this->debugScalarString($info['version'] ?? null),
				'generation_mode_default' => (string)($this->generationMode ?: 'direct'),
				'active_mode' => $mode,
				'roles' => $roleMap,
				'features' => [
					'generate_images' => (bool) $this->generateImages,
					'fill_content' => (bool) $this->fillContent,
					'telemetry' => (bool) $this->telemetry,
					'web_research' => true,
					'reference_fetch' => true,
					'reference_screenshot_upload' => true,
					'reference_visual_analysis' => true,
					'reference_url_capture' => $referenceCaptureReady,
					'chat_history' => true,
				],
				'reference_capture' => [
					'provider' => $referenceCaptureProvider,
					'provider_supported' => $referenceCaptureSupported,
					'key_configured' => $referenceCaptureKeyConfigured,
					'curl_available' => function_exists('curl_init'),
					'ready' => $referenceCaptureReady,
					'endpoint_host' => (string)(parse_url(OliviaScreenshotCapture::ENDPOINT, PHP_URL_HOST) ?: ''),
					'timeout_seconds' => OliviaScreenshotCapture::TIMEOUT,
					'response_byte_cap' => OliviaScreenshotCapture::MAX_RESPONSE_BYTES,
				],
				'image_model_default' => class_exists('\\ProcessWire\\OliviaImageGenerator') ? OliviaImageGenerator::MODEL : 'grok-imagine-image',
				'limits' => [
					'max_images_per_build' => OliviaImageGenerator::MAX_IMAGES,
					'gallery_images_per_field' => OliviaImageGenerator::GALLERY_IMAGES,
					'max_content_fills_per_build' => OliviaContentFiller::MAX_FILLS,
					'web_search_max_results' => OliviaPlanner::WEB_SEARCH_MAX_RESULTS,
					'reference_max_pages' => OliviaReferenceAnalyzer::MAX_PAGES,
					'reference_link_cap' => OliviaReferenceAnalyzer::MAX_LINKS,
					'reference_url_char_cap' => OliviaReferenceAnalyzer::MAX_URL_CHARS,
					'reference_text_char_cap' => OliviaReferenceAnalyzer::MAX_TEXT_CHARS,
					'reference_notes_char_cap' => OliviaReferenceAnalyzer::MAX_NOTES_CHARS,
					'reference_message_char_cap' => OliviaReferenceAnalyzer::MAX_MESSAGE_CHARS,
					'reference_screenshot_byte_cap' => OliviaReferenceAnalyzer::MAX_IMAGE_BYTES,
					'reference_image_limit' => OliviaVisualAnalyzer::MAX_IMAGES,
					'reference_image_dimension_cap' => OliviaReferenceAnalyzer::MAX_IMAGE_DIMENSION,
					'reference_image_pixel_cap' => OliviaReferenceAnalyzer::MAX_IMAGE_PIXELS,
					'reference_retention_seconds' => OliviaReferenceAnalyzer::REFERENCE_TTL,
					'reference_cleanup_delete_cap' => OliviaReferenceAnalyzer::MAX_CLEANUP_FILES,
					'job_file_byte_cap' => OliviaJobs::MAX_FILE_BYTES,
					'chat_file_byte_cap' => OliviaChats::MAX_FILE_BYTES,
					'manifest_file_byte_cap' => OliviaStore::MAX_FILE_BYTES,
					'view_backup_file_byte_cap' => OliviaViewGenerator::MAX_BACKUP_FILE_BYTES,
					'view_backup_total_byte_cap' => OliviaViewGenerator::MAX_BACKUP_TOTAL_BYTES,
				],
				'model_call_budgets' => [
					'planner' => [
						'max_tokens' => OliviaPlanner::MAX_TOKENS,
						'timeout_seconds' => OliviaPlanner::TIMEOUT,
						'web_search_timeout_seconds' => OliviaPlanner::WEB_SEARCH_TIMEOUT,
						'web_fallback_timeout_seconds' => OliviaPlanner::WEB_FALLBACK_TIMEOUT,
					],
					'interviewer' => ['max_tokens' => OliviaInterviewer::MAX_TOKENS, 'timeout_seconds' => OliviaInterviewer::TIMEOUT],
					'visual' => ['max_tokens' => OliviaVisualAnalyzer::MAX_TOKENS, 'timeout_seconds' => OliviaVisualAnalyzer::TIMEOUT],
					'reference_screenshot' => ['timeout_seconds' => OliviaScreenshotCapture::TIMEOUT, 'connect_timeout_seconds' => OliviaScreenshotCapture::CONNECT_TIMEOUT, 'response_byte_cap' => OliviaScreenshotCapture::MAX_RESPONSE_BYTES],
					'artdirector' => ['max_tokens' => OliviaImageGenerator::ART_DIRECTOR_MAX_TOKENS, 'timeout_seconds' => OliviaImageGenerator::ART_DIRECTOR_TIMEOUT],
					'image' => ['timeout_seconds' => OliviaImageGenerator::IMAGE_TIMEOUT],
					'reference_fetch' => [
						'timeout_seconds' => OliviaReferenceAnalyzer::FETCH_TIMEOUT,
						'connect_timeout_seconds' => OliviaReferenceAnalyzer::FETCH_CONNECT_TIMEOUT,
						'max_redirects' => OliviaReferenceAnalyzer::FETCH_MAX_REDIRECTS,
						'html_byte_cap' => OliviaReferenceAnalyzer::FETCH_HTML_BYTES,
					],
				],
				'view_version' => OliviaViewGenerator::VIEW_VERSION,
				'home_view_version' => OliviaViewGenerator::HOME_VERSION,
			],
			'squad' => [
				'installed' => (bool)($squadInfo['installed'] ?? false),
				'version' => $this->debugScalarString($squadInfo['version'] ?? null),
				'provider' => $squadProvider,
				'default_model' => $squadDefaultModel !== '' ? $squadDefaultModel : 'unknown',
				'config' => $squadConfig,
			],
			'modules' => [
				'Squad' => $squadInfo,
				'GrokImagine' => $this->moduleDebugInfo('GrokImagine'),
				'Context' => $this->moduleDebugInfo('Context'),
				'Atlas' => $this->moduleDebugInfo('Atlas'),
				'Ichiban' => $this->moduleDebugInfo('Ichiban'),
			],
			'environment' => [
				'processwire_version' => $this->processWireVersion(),
				'php_version' => PHP_VERSION,
				'php_sapi' => PHP_SAPI,
				'site_debug' => (bool) $this->wire->config->debug,
				'worker_stop_available' => $this->jobs()->canStopWorker(),
				'worker_alarm_available' => function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('pcntl_alarm'),
				'worker_fallback_watchdog' => dirname(__DIR__, 2) . '/bin/olivia-watchdog.php',
				'worker_fallback_watchdog_exists' => is_file(dirname(__DIR__, 2) . '/bin/olivia-watchdog.php'),
				'worker_deadline_guard_ready' => (
					function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('pcntl_alarm')
				) || (
					function_exists('exec') && is_executable($this->phpBinary()) && is_file(dirname(__DIR__, 2) . '/bin/olivia-watchdog.php')
				),
				'worker_php_binary' => $this->phpBinary(),
				'worker_script' => dirname(__DIR__, 2) . '/bin/olivia-worker.php',
				'worker_php_binary_executable' => is_executable($this->phpBinary()),
				'worker_script_exists' => is_file(dirname(__DIR__, 2) . '/bin/olivia-worker.php'),
				'worker_launch_ready' => is_executable($this->phpBinary()) && is_file(dirname(__DIR__, 2) . '/bin/olivia-worker.php'),
				'admin_url' => (string) $this->wire->page->url,
			],
			'state' => [
				'chat_id' => $chatId ?: '',
				'chat_found' => $chatId !== '' && is_array($chat),
				'chat_title' => $chat['title'] ?? '',
				'chat_messages' => is_array($chat['messages'] ?? null) ? count($chat['messages']) : 0,
				'plan_present' => $planState['present'],
				'plan_bytes' => $planState['bytes'],
				'plan_json_valid' => $planState['json_valid'],
				'plan_json_error' => $planState['json_error'],
				'plan_schema_ok' => $planState['schema_ok'],
				'plan_schema_errors' => $planState['schema_errors'],
				'plan_status' => $planState['status'],
				'plan_summary' => $planState['summary'],
				'plan_counts' => $planState['counts'],
				'plan_top_level_keys' => $planState['top_level_keys'],
				'build_count' => count($builds),
				'latest_build_present' => $latestBuildState['present'],
				'latest_build_id' => $latestBuildState['id'],
				'latest_build_ts' => $latestBuildState['ts'],
				'latest_build_created_at' => $latestBuildState['created_at'],
				'latest_build_age_seconds' => $latestBuildState['age_seconds'],
				'latest_build_counts' => $latestBuildState['counts'],
				'latest_build_reused_counts' => $latestBuildState['reused_counts'],
				'latest_build_errors' => $latestBuildState['errors'],
				'latest_build_errors_shape_valid' => $latestBuildState['errors_shape_valid'],
				'latest_build_has_errors' => $latestBuildState['has_errors'],
				'recent_job_types' => $recentJobSummary['types'],
				'recent_job_health_status' => $recentJobSummary['health_status'],
				'recent_job_health_level' => $recentJobSummary['health_level'],
				'recent_job_health_has_issue' => $recentJobSummary['health_has_issue'],
				'recent_job_health_issue_count' => $recentJobSummary['health_issue_count'],
				'recent_job_health_issue_status_counts' => $recentJobSummary['health_issue_status_counts'],
				'recent_job_health_issue_type_counts' => $recentJobSummary['health_issue_type_counts'],
				'recent_job_health_issue_key_counts' => $recentJobSummary['health_issue_key_counts'],
				'recent_job_health_issue_summary' => $recentJobSummary['health_issue_summary'],
				'recent_job_health_primary_issue_status' => $recentJobSummary['health_primary_issue_status'],
				'recent_job_health_primary_issue_type' => $recentJobSummary['health_primary_issue_type'],
				'recent_job_health_primary_issue_key' => $recentJobSummary['health_primary_issue_key'],
				'recent_job_health_reason' => $recentJobSummary['health_reason'],
				'recent_job_health_action_code' => $recentJobSummary['health_action_code'],
				'recent_job_health_action' => $recentJobSummary['health_action'],
				'recent_job_health_summary' => $recentJobSummary['health_summary'],
				'recent_job_health_job' => $recentJobSummary['health_job'],
				'recent_job_health_job_id' => $this->debugScalarString($recentJobHealthJob['id'] ?? null),
				'recent_job_health_job_type' => $this->debugScalarString($recentJobHealthJob['type'] ?? null),
				'recent_job_health_job_status' => $this->debugScalarString($recentJobHealthJob['status'] ?? null),
				'recent_job_health_job_pid' => is_numeric($recentJobHealthRaw['pid'] ?? null) ? max(0, (int) $recentJobHealthRaw['pid']) : 0,
				'recent_job_health_job_error' => $this->debugScalarString($recentJobHealthJob['error'] ?? null),
				'recent_job_health_job_elapsed_seconds' => is_numeric($recentJobHealthJob['elapsed_seconds'] ?? null) ? max(0, (int) $recentJobHealthJob['elapsed_seconds']) : 0,
				'recent_job_health_job_deadline_seconds' => is_numeric($recentJobHealthJob['deadline_seconds'] ?? null) ? max(0, (int) $recentJobHealthJob['deadline_seconds']) : 0,
				'recent_job_health_job_deadline_used_percent' => is_numeric($recentJobHealthJob['deadline_used_percent'] ?? null) ? max(0, (int) $recentJobHealthJob['deadline_used_percent']) : 0,
				'recent_job_health_job_overrun_seconds' => is_numeric($recentJobHealthJob['overrun_seconds'] ?? null) ? max(0, (int) $recentJobHealthJob['overrun_seconds']) : 0,
				'recent_job_health_job_over_deadline' => (bool)($recentJobHealthJob['over_deadline'] ?? false),
				'recent_job_health_checked_at' => $generatedAt,
				'recent_job_statuses' => $recentJobSummary['statuses'],
				'recent_job_status_counts' => $recentJobSummary['status_counts'],
				'recent_job_error_count' => $recentJobSummary['error_count'],
				'recent_job_error_type_counts' => $recentJobSummary['error_type_counts'],
				'recent_job_latest_error' => $recentJobSummary['latest_error'],
				'recent_job_done_count' => $recentJobSummary['done_count'],
				'recent_job_done_type_counts' => $recentJobSummary['done_type_counts'],
				'recent_job_latest_done' => $recentJobSummary['latest_done'],
				'recent_jobs_done' => $recentJobSummary['done_jobs'],
				'recent_job_terminal_count' => $recentJobSummary['terminal_count'],
				'recent_job_terminal_status_counts' => $recentJobSummary['terminal_status_counts'],
				'recent_job_terminal_type_counts' => $recentJobSummary['terminal_type_counts'],
				'recent_jobs_terminal' => $recentJobSummary['terminal_jobs'],
				'recent_job_pending_count' => $recentJobSummary['pending_count'],
				'recent_job_pending_type_counts' => $recentJobSummary['pending_type_counts'],
				'recent_jobs_pending' => $recentJobSummary['pending_jobs'],
				'recent_job_running_count' => $recentJobSummary['running_count'],
				'recent_job_running_type_counts' => $recentJobSummary['running_type_counts'],
				'recent_jobs_running' => $recentJobSummary['running_jobs'],
				'recent_job_running_max_elapsed_seconds' => $recentJobSummary['running_max_elapsed_seconds'],
				'recent_job_over_deadline_count' => $recentJobSummary['over_deadline_count'],
				'recent_job_max_overrun_seconds' => $recentJobSummary['max_overrun_seconds'],
				'recent_job_over_deadline_type_counts' => $recentJobSummary['over_deadline_type_counts'],
				'recent_job_latest_over_deadline' => $recentJobSummary['latest_over_deadline'],
				'recent_jobs_over_deadline' => $recentJobSummary['over_deadline_jobs'],
				'recent_job_active_count' => $recentJobSummary['active_count'],
				'recent_job_active_max_elapsed_seconds' => $recentJobSummary['active_max_elapsed_seconds'],
				'recent_job_active_type_counts' => $recentJobSummary['active_type_counts'],
				'recent_job_latest_active' => $recentJobSummary['latest_active'],
				'recent_jobs_active' => $recentJobSummary['active_jobs'],
				'recent_jobs' => $recentJobs,
			],
			'job_timeouts_seconds' => $this->jobs()->deadlineMap() + ['web_post_soft_limit' => self::WEB_POST_SOFT_LIMIT],
		];
	}

	protected function processWireVersion(): string {
		$info = $this->wire->modules->getModuleInfo('ProcessWire');
		if(is_array($info)) {
			$version = $this->debugScalarString($info['versionStr'] ?? $info['version'] ?? null);
			if($version !== '') return $version;
		}
		return $this->debugScalarString($this->wire->config->version ?? null);
	}

	protected function squadDefaultModel(string $provider): string {
		$provider = trim($provider);
		if($provider === '') return '';
		$file = $this->wire->config->paths->siteModules . 'Squad/models.json';
		if(!is_file($file)) return '';
		$raw = @file_get_contents($file);
		if(!is_string($raw)) return '';
		$data = json_decode($raw, true);
		return is_array($data) ? (string)($data[$provider]['defaultModel'] ?? '') : '';
	}

	protected function planDebugState(string $planJson): array {
		$trimmed = trim($planJson);
		$data = $trimmed !== '' ? json_decode($trimmed, true) : null;
		$valid = $trimmed !== '' && is_array($data) && !array_is_list($data);
		$jsonError = '';
		if($trimmed !== '') {
			if(json_last_error() !== JSON_ERROR_NONE) {
				$jsonError = json_last_error_msg();
			} elseif(!$valid) {
				$jsonError = 'JSON root must be an object plan.';
			}
		}
		$schemaErrors = [];
		if($valid) {
			foreach(['site', 'fields', 'templates', 'pages'] as $key) {
				if(!array_key_exists($key, $data)) {
					$schemaErrors[] = $key . ' missing';
				} elseif(!is_array($data[$key])) {
					$schemaErrors[] = $key . ' must be an array';
				}
			}
		}
		$schemaOk = $valid
			&& is_array($data['site'] ?? null)
			&& is_array($data['fields'] ?? null)
			&& is_array($data['templates'] ?? null)
			&& is_array($data['pages'] ?? null);
		$status = 'none';
		if($trimmed !== '') {
			$status = $valid ? ($schemaOk ? 'ok' : 'schema_error') : 'json_error';
		}
		$counts = $schemaOk ? [
			'fields' => count($data['fields']),
			'templates' => count($data['templates']),
			'pages' => count($data['pages']),
			'modules' => is_array($data['modules'] ?? null) ? count($data['modules']) : 0,
		] : [];
		$summary = 'No plan JSON is loaded.';
		if($status === 'json_error') {
			$summary = $jsonError !== '' ? 'Plan JSON is invalid: ' . $jsonError : 'Plan JSON is invalid.';
		} elseif($status === 'schema_error') {
			$summary = 'Plan JSON is valid, but the Olivia plan schema is incomplete: ' . implode('; ', $schemaErrors);
		} elseif($status === 'ok') {
			$summary = 'Plan schema OK: '
				. $counts['fields'] . ' fields, '
				. $counts['templates'] . ' templates, '
				. $counts['pages'] . ' pages, '
				. $counts['modules'] . ' module recommendations.';
		}

		return [
			'present' => $trimmed !== '',
			'bytes' => strlen($planJson),
			'json_valid' => $valid,
			'json_error' => $jsonError,
			'schema_ok' => $schemaOk,
			'schema_errors' => $schemaErrors,
			'status' => $status,
			'summary' => $summary,
			'counts' => $counts,
			'top_level_keys' => $valid ? array_values(array_slice(array_keys($data), 0, self::PLAN_DEBUG_TOP_LEVEL_KEY_LIMIT)) : [],
		];
	}

	protected function latestBuildDebugState(array $build): array {
		if(!$build) return [
			'present' => false,
			'id' => '',
			'ts' => 0.0,
			'created_at' => '',
			'age_seconds' => 0,
			'counts' => [],
			'reused_counts' => [],
			'errors' => 0,
			'errors_shape_valid' => true,
			'has_errors' => false,
		];
		$errorsShapeValid = !array_key_exists('errors', $build) || is_array($build['errors']);
		$errors = is_array($build['errors'] ?? null) ? count($build['errors']) : 0;
		$reused = is_array($build['reused'] ?? null) ? $build['reused'] : [];
		$updatedFiles = is_array($build['updated_files'] ?? null) ? count($build['updated_files']) : max(0, (int)($build['updated_files_count'] ?? 0));
		$ts = is_numeric($build['ts'] ?? null) ? max(0.0, (float)$build['ts']) : 0.0;
		return [
			'present' => true,
			'id' => is_scalar($build['id'] ?? null) ? (string)$build['id'] : '',
			'ts' => $ts,
			'created_at' => $ts > 0 ? date('c', (int)$ts) : '',
			'age_seconds' => $ts > 0 ? max(0, time() - (int)$ts) : 0,
			'counts' => [
				'fields' => is_array($build['fields'] ?? null) ? count($build['fields']) : 0,
				'templates' => is_array($build['templates'] ?? null) ? count($build['templates']) : 0,
				'pages' => is_array($build['pages'] ?? null) ? count($build['pages']) : 0,
				'files' => is_array($build['files'] ?? null) ? count($build['files']) : 0,
				'template_fields' => is_array($build['template_fields'] ?? null) ? count($build['template_fields']) : 0,
				'images' => max(0, (int)($build['images'] ?? 0)),
				'updated_files' => $updatedFiles,
			],
			'reused_counts' => [
				'fields' => is_array($reused['fields'] ?? null) ? count($reused['fields']) : 0,
				'templates' => is_array($reused['templates'] ?? null) ? count($reused['templates']) : 0,
				'pages' => is_array($reused['pages'] ?? null) ? count($reused['pages']) : 0,
			],
			'errors' => $errors,
			'errors_shape_valid' => $errorsShapeValid,
			'has_errors' => $errors > 0,
		];
	}

	protected function moduleDebugInfo(string $class): array {
		$installed = (bool) $this->wire->modules->isInstalled($class);
		$info = $this->wire->modules->getModuleInfo($class) ?: [];
		return [
			'installed' => $installed,
			'version' => $installed ? $this->debugScalarString($info['version'] ?? null) : '',
			'title' => $this->debugScalarString($info['title'] ?? null) ?: $class,
			'config' => $this->maskSecrets($this->wire->modules->getModuleConfigData($class) ?: []),
		];
	}

	protected function maskSecrets($value, string $key = '') {
		if(is_array($value)) {
			$out = [];
			foreach($value as $k => $v) $out[$k] = $this->maskSecrets($v, (string) $k);
			return $out;
		}
		$lk = strtolower($key);
		$normalizedKey = str_replace(['_', '-'], '', $lk);
		if(in_array($normalizedKey, self::DEBUG_TOKEN_BUDGET_KEYS, true)) return $value;
		if($lk !== '' && preg_match('/(api_?key|key|token|secret|password|passwd|auth|credential)/', $lk)) {
			return $value === '' || $value === null ? '' : '[set:masked]';
		}
		if(is_string($value)) return $this->clipText($value, 300, '...');
		if(is_object($value)) return '[object ' . get_class($value) . ']';
		return $value;
	}

	protected function recentJobsDebug(): array {
		$dir = $this->wire->config->paths->cache . 'Olivia/jobs/';
		$store = $this->jobs();
		$jobs = [];
		foreach(glob($dir . '*.json') ?: [] as $file) {
			$id = pathinfo((string) $file, PATHINFO_FILENAME);
			$j = $store->get($id);
			if(!$j) continue;
			$type = $this->debugScalarString($j['type'] ?? null);
			$elapsed = $store->elapsedSeconds($j);
			$deadline = $store->deadlineSeconds($type);
			$jobs[] = [
				'id' => $this->debugScalarString($j['id'] ?? null),
				'type' => $type,
				'status' => $this->debugScalarString($j['status'] ?? null),
				'attempts' => (int)($j['attempts'] ?? 0),
				'created' => $this->debugScalarString($j['created'] ?? null),
				'started' => $this->debugScalarString($j['started'] ?? null),
				'finished' => $this->debugScalarString($j['finished'] ?? null),
				'pid' => is_numeric($j['pid'] ?? null) ? max(0, (int) $j['pid']) : 0,
				'elapsed_seconds' => $elapsed,
				'deadline_seconds' => $deadline,
				'deadline_used_percent' => $deadline > 0 ? max(0, (int) round(($elapsed / $deadline) * 100)) : 0,
				'overrun_seconds' => $deadline > 0 ? max(0, $elapsed - $deadline) : 0,
				'over_deadline' => $deadline > 0 && $elapsed > $deadline,
				'terminal' => $store->isTerminal($j),
				'error' => $this->debugScalarString($j['error'] ?? null),
			];
		}
		usort($jobs, fn($a, $b) => strcmp($b['created'], $a['created']));
		return array_slice($jobs, 0, 8);
	}

	protected function recentJobSummary(array $jobs): array {
		$jobs = array_values(array_filter($jobs, 'is_array'));
		$statusCounts = [];
		$errorCount = 0;
		$errorTypeCounts = [];
		$healthIssueKeyCounts = [];
		$latestError = null;
		$doneCount = 0;
		$doneTypeCounts = [];
		$doneJobs = [];
		$latestDone = null;
		$terminalCount = 0;
		$terminalStatusCounts = [];
		$terminalTypeCounts = [];
		$terminalJobs = [];
		$pendingCount = 0;
		$pendingTypeCounts = [];
		$pendingJobs = [];
		$runningCount = 0;
		$runningJobs = [];
		$runningTypeCounts = [];
		$runningMaxElapsed = 0;
		$overDeadlineCount = 0;
		$maxOverrunSeconds = 0;
		$overDeadlineJobs = [];
		$overDeadlineTypeCounts = [];
		$latestOverDeadline = null;
		$activeCount = 0;
		$activeMaxElapsed = 0;
		$activeJobs = [];
		$activeTypeCounts = [];
		$latestActive = null;
		foreach($jobs as $job) {
			$status = $this->debugScalarString($job['status'] ?? null);
			if($status === '') continue;
			$statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
			$type = $this->debugScalarString($job['type'] ?? null);
			if($status === 'error') {
				$errorCount++;
				if($type !== '') $errorTypeCounts[$type] = ($errorTypeCounts[$type] ?? 0) + 1;
				if($type !== '') $healthIssueKeyCounts['error:' . $type] = ($healthIssueKeyCounts['error:' . $type] ?? 0) + 1;
				if($latestError === null) {
					$latestError = $this->recentJobCompactEntry($job, $status) + ['error' => $this->debugScalarString($job['error'] ?? null)];
				}
			}
			if($status === 'done') {
				$doneCount++;
				if($type !== '') $doneTypeCounts[$type] = ($doneTypeCounts[$type] ?? 0) + 1;
				$doneJob = $this->recentJobCompactEntry($job, $status);
				$doneJobs[] = $doneJob;
				if($latestDone === null) $latestDone = $doneJob;
			}
			if($job['terminal'] ?? false) {
				$terminalCount++;
				$terminalStatusCounts[$status] = ($terminalStatusCounts[$status] ?? 0) + 1;
				if($type !== '') $terminalTypeCounts[$type] = ($terminalTypeCounts[$type] ?? 0) + 1;
				$terminalJobs[] = $this->recentJobCompactEntry($job, $status);
			}
			if($status === 'pending') {
				$pendingCount++;
				if($type !== '') $pendingTypeCounts[$type] = ($pendingTypeCounts[$type] ?? 0) + 1;
				$pendingJobs[] = $this->recentJobCompactEntry($job, $status);
			}
			if(!($job['terminal'] ?? false)) {
				$activeCount++;
				if(is_numeric($job['elapsed_seconds'] ?? null)) {
					$activeMaxElapsed = max($activeMaxElapsed, (int) $job['elapsed_seconds']);
				}
				if($type !== '') $activeTypeCounts[$type] = ($activeTypeCounts[$type] ?? 0) + 1;
				$activeJob = $this->recentJobCompactEntry($job, $status);
				$activeJobs[] = $activeJob;
				if($latestActive === null) $latestActive = $activeJob;
			}
			if($status === 'running') {
				$runningCount++;
				if($type !== '') $runningTypeCounts[$type] = ($runningTypeCounts[$type] ?? 0) + 1;
				$runningJobs[] = $this->recentJobCompactEntry($job, $status);
				if(is_numeric($job['elapsed_seconds'] ?? null)) {
					$runningMaxElapsed = max($runningMaxElapsed, (int) $job['elapsed_seconds']);
				}
			}
			if(!($job['terminal'] ?? false)
				&& is_numeric($job['elapsed_seconds'] ?? null)
				&& is_numeric($job['deadline_seconds'] ?? null)
				&& (int) $job['deadline_seconds'] > 0
				&& (int) $job['elapsed_seconds'] > (int) $job['deadline_seconds']) {
				$overDeadlineCount++;
				$maxOverrunSeconds = max($maxOverrunSeconds, (int) $job['elapsed_seconds'] - (int) $job['deadline_seconds']);
				if($type !== '') $overDeadlineTypeCounts[$type] = ($overDeadlineTypeCounts[$type] ?? 0) + 1;
				if($type !== '') $healthIssueKeyCounts['over_deadline:' . $type] = ($healthIssueKeyCounts['over_deadline:' . $type] ?? 0) + 1;
				$overDeadlineJob = $this->recentJobCompactEntry($job, $status);
				$overDeadlineJobs[] = $overDeadlineJob;
				if($latestOverDeadline === null) $latestOverDeadline = $overDeadlineJob;
			}
		}
		$healthStatus = 'ok';
		$healthLevel = 0;
		$healthReason = 'no recent active, failed, or over-deadline jobs';
		$healthActionCode = 'none';
		$healthAction = 'no action needed';
		$healthJob = null;
		if($overDeadlineCount > 0) {
			$healthStatus = 'over_deadline';
			$healthLevel = 3;
			$healthReason = $this->recentJobCountLabel($overDeadlineCount, 'over-deadline job');
			$healthActionCode = 'stop_stuck_worker';
			$healthAction = 'inspect and stop the stuck worker if still running';
			$healthJob = $latestOverDeadline;
		} elseif($errorCount > 0) {
			$healthStatus = 'error';
			$healthLevel = 2;
			$healthReason = $this->recentJobCountLabel($errorCount, 'recent error job');
			$healthActionCode = 'inspect_error';
			$healthAction = 'inspect the latest worker error and provider response';
			$healthJob = $latestError;
		} elseif($activeCount > 0) {
			$healthStatus = 'active';
			$healthLevel = 1;
			$healthReason = $this->recentJobCountLabel($activeCount, 'active job');
			$healthActionCode = 'wait';
			$healthAction = 'wait for polling unless the job exceeds its deadline';
			$healthJob = $latestActive;
		}
		$healthHasIssue = $healthLevel >= 2;
		$healthIssueCount = $errorCount + $overDeadlineCount;
		$healthIssueStatusCounts = [];
		if($errorCount > 0) $healthIssueStatusCounts['error'] = $errorCount;
		if($overDeadlineCount > 0) $healthIssueStatusCounts['over_deadline'] = $overDeadlineCount;
		$healthPrimaryIssueStatus = '';
		if($overDeadlineCount > 0 && $overDeadlineCount >= $errorCount) {
			$healthPrimaryIssueStatus = 'over_deadline';
		} elseif($errorCount > 0) {
			$healthPrimaryIssueStatus = 'error';
		}
		$healthIssueTypeCounts = $errorTypeCounts;
		foreach($overDeadlineTypeCounts as $type => $count) {
			$healthIssueTypeCounts[$type] = ($healthIssueTypeCounts[$type] ?? 0) + $count;
		}
		$healthPrimaryIssueType = '';
		$healthPrimaryIssueCount = 0;
		foreach($healthIssueTypeCounts as $type => $count) {
			if($count > $healthPrimaryIssueCount) {
				$healthPrimaryIssueType = (string) $type;
				$healthPrimaryIssueCount = (int) $count;
			}
		}
		$healthPrimaryIssueKey = '';
		$healthPrimaryIssueKeyCount = 0;
		$healthPrimaryIssueKeyPriority = -1;
		foreach($healthIssueKeyCounts as $key => $count) {
			$keyStatus = explode(':', (string) $key, 2)[0] ?? '';
			$keyPriority = $keyStatus === 'over_deadline' ? 2 : ($keyStatus === 'error' ? 1 : 0);
			if((int) $count > $healthPrimaryIssueKeyCount
				|| ((int) $count === $healthPrimaryIssueKeyCount && $keyPriority > $healthPrimaryIssueKeyPriority)) {
				$healthPrimaryIssueKey = (string) $key;
				$healthPrimaryIssueKeyCount = (int) $count;
				$healthPrimaryIssueKeyPriority = $keyPriority;
			}
		}
		if($healthPrimaryIssueKey !== '') {
			[$healthPrimaryIssueStatus, $healthPrimaryIssueType] = array_pad(explode(':', $healthPrimaryIssueKey, 2), 2, '');
		}
		$healthIssueSummaryParts = [];
		foreach($healthIssueKeyCounts as $key => $count) {
			$healthIssueSummaryParts[] = $key . '=' . $count;
		}
		$healthIssueSummary = implode(', ', $healthIssueSummaryParts);
		$healthSummary = $healthStatus . ': ' . $healthReason . ' (' . $healthActionCode . ')';
		return [
			'types' => array_values(array_unique(array_filter(array_map(fn($job) => $this->debugScalarString($job['type'] ?? null), $jobs)))),
			'health_status' => $healthStatus,
			'health_level' => $healthLevel,
			'health_has_issue' => $healthHasIssue,
			'health_issue_count' => $healthIssueCount,
			'health_issue_status_counts' => $healthIssueStatusCounts,
			'health_issue_type_counts' => $healthIssueTypeCounts,
			'health_issue_key_counts' => $healthIssueKeyCounts,
			'health_issue_summary' => $healthIssueSummary,
			'health_primary_issue_status' => $healthPrimaryIssueStatus,
			'health_primary_issue_type' => $healthPrimaryIssueType,
			'health_primary_issue_key' => $healthPrimaryIssueKey,
			'health_reason' => $healthReason,
			'health_action_code' => $healthActionCode,
			'health_action' => $healthAction,
			'health_summary' => $healthSummary,
			'health_job' => $healthJob,
			'statuses' => array_values(array_unique(array_filter(array_map(fn($job) => $this->debugScalarString($job['status'] ?? null), $jobs)))),
			'status_counts' => $statusCounts,
			'error_count' => $errorCount,
			'error_type_counts' => $errorTypeCounts,
			'latest_error' => $latestError,
			'done_count' => $doneCount,
			'done_type_counts' => $doneTypeCounts,
			'latest_done' => $latestDone,
			'done_jobs' => $doneJobs,
			'terminal_count' => $terminalCount,
			'terminal_status_counts' => $terminalStatusCounts,
			'terminal_type_counts' => $terminalTypeCounts,
			'terminal_jobs' => $terminalJobs,
			'pending_count' => $pendingCount,
			'pending_type_counts' => $pendingTypeCounts,
			'pending_jobs' => $pendingJobs,
			'running_count' => $runningCount,
			'running_type_counts' => $runningTypeCounts,
			'running_jobs' => $runningJobs,
			'running_max_elapsed_seconds' => $runningMaxElapsed,
			'over_deadline_count' => $overDeadlineCount,
			'max_overrun_seconds' => $maxOverrunSeconds,
			'over_deadline_type_counts' => $overDeadlineTypeCounts,
			'latest_over_deadline' => $latestOverDeadline,
			'over_deadline_jobs' => $overDeadlineJobs,
			'active_count' => $activeCount,
			'active_max_elapsed_seconds' => $activeMaxElapsed,
			'active_type_counts' => $activeTypeCounts,
			'latest_active' => $latestActive,
			'active_jobs' => $activeJobs,
		];
	}

	protected function recentJobCountLabel(int $count, string $singular): string {
		return $count . ' ' . ($count === 1 ? $singular : $singular . 's');
	}

	protected function debugScalarString($value): string {
		return is_scalar($value) ? $this->clipText((string) $value, self::DEBUG_SCALAR_STRING_LIMIT, '...') : '';
	}

	protected function recentJobCompactEntry(array $job, string $status): array {
		$elapsed = is_numeric($job['elapsed_seconds'] ?? null) ? max(0, (int) $job['elapsed_seconds']) : 0;
		$deadline = is_numeric($job['deadline_seconds'] ?? null) ? max(0, (int) $job['deadline_seconds']) : 0;
		$deadlineUsed = $deadline > 0 ? max(0, (int) round(($elapsed / $deadline) * 100)) : 0;
		return [
			'id' => $this->debugScalarString($job['id'] ?? null),
			'type' => $this->debugScalarString($job['type'] ?? null),
			'status' => $status,
			'elapsed_seconds' => $elapsed,
			'deadline_seconds' => $deadline,
			'deadline_used_percent' => $deadlineUsed,
			'overrun_seconds' => $deadline > 0 ? max(0, $elapsed - $deadline) : 0,
			'over_deadline' => $deadline > 0 && $elapsed > $deadline,
		];
	}

}
