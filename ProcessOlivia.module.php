<?php namespace ProcessWire;

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Admin/OliviaComposerUi.php';
require_once __DIR__ . '/src/Admin/OliviaAdminViews.php';
require_once __DIR__ . '/src/Admin/OliviaSupportDebug.php';
require_once __DIR__ . '/src/Admin/OliviaAdminTables.php';

/**
 * Olivia — AI Solution Architect for ProcessWire
 *
 * v1: generate a website from a prompt.
 *   prompt -> plan (Squad) -> preview -> build (OliviaBuilder) -> undo
 *
 * Two generation modes (Olivia setting): Direct and Interview.
 * Both always show a plan preview before Build and record a rollback manifest.
 */
class ProcessOlivia extends Process {

	use OliviaComposerUi;
	use OliviaAdminViews;
	use OliviaSupportDebug;
	use OliviaAdminTables;

	public const WEB_POST_SOFT_LIMIT = 180;
	public const SUPPORT_DEBUG_SCHEMA_VERSION = 64;
	public const PLAN_DEBUG_TOP_LEVEL_KEY_LIMIT = 12;
	protected const DEBUG_SCALAR_STRING_LIMIT = 1000;
	protected const DEBUG_TOKEN_BUDGET_KEYS = ['maxtokens', 'maxoutputtokens', 'maxcompletiontokens', 'maxprompttokens', 'tokenlimit', 'outputtokenlimit'];

	public static function getModuleInfo() {
		return [
			'title'       => 'Olivia Admin',
			'summary'     => 'Admin interface for the Olivia AI Solution Architect.',
			'version'     => 100,
			'author'      => 'Maxim Semenov',
			'href'        => 'https://github.com/mxmsmnv/Olivia',
			'license'     => 'MIT',
			'hreflicense' => 'LICENSE',
			'icon'        => 'magic',
			'autoload'    => false,
			'singular'    => true,
			'requires'    => ['Olivia>=1.0.0'],
			'permission'  => 'olivia',
			'permissions' => ['olivia' => 'Use Olivia'],
			'page'        => ['name' => 'olivia', 'parent' => 'setup', 'title' => 'Olivia'],
		];
	}

	public function init() {
		parent::init();
		oliviaRegisterSourceLoader($this->wire->classLoader, __DIR__ . '/src');
		try {
			$olivia = $this->wire->modules->getModule('Olivia', ['noPermissionCheck' => true, 'noThrow' => true]);
			if($olivia instanceof Olivia) {
				$this->setArray($olivia->settings());
				return;
			}
		} catch(\Throwable $e) {
			// A request can arrive between replacing the files and refreshing the
			// module cache. Keep the existing admin usable until the next refresh.
		}
		$legacy = $this->wire->modules->getModuleConfigData('ProcessOlivia') ?: [];
		if($legacy) $this->setArray($legacy);
	}

	/* ------------------------------------------------------------ helpers */

	protected function builder(): OliviaBuilder         { return $this->wire(new OliviaBuilder()); }
	protected function planner(): OliviaPlanner         { return $this->wire(new OliviaPlanner()); }
	protected function store(): OliviaStore             { return $this->wire(new OliviaStore()); }
	protected function validator(): OliviaValidator     { return $this->wire(new OliviaValidator()); }
	protected function interviewer(): OliviaInterviewer { return $this->wire(new OliviaInterviewer()); }
	protected function jobs(): OliviaJobs               { return $this->wire(new OliviaJobs()); }
	protected function contribute(): OliviaContribute   { return $this->wire(new OliviaContribute()); }
	protected function telemetry(): OliviaTelemetry     { return $this->wire(new OliviaTelemetry()); }
	protected function modules(): OliviaModules         { return $this->wire(new OliviaModules()); }
	protected function operator(): OliviaOperator        { return $this->wire(new OliviaOperator()); }
	protected function chats(): OliviaChats             { return $this->wire(new OliviaChats()); }
	protected function references(): OliviaReferenceAnalyzer { return $this->wire(new OliviaReferenceAnalyzer()); }

	/** Spawn a detached background worker for a slow AI call; returns job id. */
	protected function spawn(string $type, array $payload): string {
		$id = $this->jobs()->create($type, $payload);
		$this->launchWorker($id);
		return $id;
	}

	/** Claim one logical operation before launching its worker. */
	protected function spawnSingleFlight(string $type, array $payload, string $scope): array {
		$claim = $this->jobs()->createSingleFlight($type, $payload, $scope);
		if(!empty($claim['created'])) $this->launchWorker((string) $claim['id']);
		return $claim;
	}

	/** Launch (or relaunch, from the watchdog) the detached CLI worker for a job id. */
	protected function launchWorker(string $id): void {
		$id = $this->wire->sanitizer->filename($id);
		$php = $this->phpBinary();
		$worker = __DIR__ . '/bin/olivia-worker.php';
		$siteRoot = rtrim((string) $this->wire->config->paths->root, '/\\');
		// Under mod_fastcgi the child inherits the FastCGI socket fd and keeps the
		// HTTP response open until it exits (setsid/fastcgi_finish_request are not
		// available here). So close inherited fds 3..255 in the shell before exec,
		// then fully detach. Paths contain no spaces; $id is a sanitized filename.
		// No nohup: it needs a controlling terminal and fails under FastCGI
		// ("can't detach from console"). Closing fds + redirecting stdio + "&" is
		// enough to detach here (no tty means no SIGHUP to worry about).
		$inner = 'for fd in $(seq 3 255); do eval "exec $fd<&- $fd>&-" 2>/dev/null; done; '
			. 'OLIVIA_SITE_ROOT=' . escapeshellarg($siteRoot) . ' exec ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($id);
		$cmd = '/bin/sh -c ' . escapeshellarg($inner) . ' < /dev/null > /dev/null 2>&1 &';
		@exec($cmd);
	}

	/** Find a CLI-capable PHP binary (web SAPI's PHP_BINARY is often php-cgi). */
	protected function phpBinary(): string {
		$candidates = glob('/Applications/MAMP/bin/php/php*/bin/php') ?: [];
		rsort($candidates); // highest version first
		if(defined('PHP_BINARY') && PHP_BINARY && basename(PHP_BINARY) === 'php') array_unshift($candidates, PHP_BINARY);
		foreach($candidates as $c) if(is_executable($c)) return $c;
		return 'php';
	}

	/**
	 * Validate a plan, surface warnings, and return the normalized plan.
	 * Returns null if there are hard errors (caller should not build).
	 */
	protected function validatePlan(array $plan, bool $blockOnError): ?array {
		$r = $this->validator()->validate($plan);
		foreach($r['warnings'] as $w) $this->warning($w);
		foreach($r['errors'] as $e) $this->error($e);
		if(!$r['ok'] && $blockOnError) return null;
		return $r['plan'];
	}

	protected function decodePlan(string $json): ?array {
		$json = trim($json);
		if($json === '') { $this->error('Plan is empty.'); return null; }
		$data = json_decode($json, true);
		if(!is_array($data)) { $this->error('Plan is not valid JSON.'); return null; }
		return $data;
	}

	/** Override the plan's site.theme from the Theme controls (font / colour). */
	protected function applyThemeOverride(array $plan): array {
		$overrideFont = (bool) $this->wire->input->post('olivia_theme_font_override');
		$overridePrimary = (bool) $this->wire->input->post('olivia_theme_primary_override');
		if(!$overrideFont && !$overridePrimary) return $plan;
		$font    = trim((string) $this->wire->input->post('olivia_theme_font'));
		$primary = trim((string) $this->wire->input->post('olivia_theme_primary'));
		$tm = $this->wire(new OliviaTheme());
		if(!isset($plan['site']) || !is_array($plan['site'])) $plan['site'] = [];
		$theme = (isset($plan['site']['theme']) && is_array($plan['site']['theme'])) ? $plan['site']['theme'] : [];
		if(!$theme) $theme = $tm->current() ?: [];
		if($overrideFont) $theme['font'] = $tm->validFont($font);
		if($overridePrimary) $theme['primary'] = $tm->validHex($primary, OliviaTheme::DEFAULT_PRIMARY);
		$plan['site']['theme'] = $theme;
		return $plan;
	}

	protected function reportVisualStatus(?array $visual): void {
		if($visual === null || !$this->visualStatusIsInformative($visual)) return;
		$images = max(0, min(OliviaVisualAnalyzer::MAX_IMAGES, (int)($visual['images'] ?? 0)));
		if(!empty($visual['ok'])) {
			$model = $this->clipText(trim((string)($visual['model'] ?? 'configured visual model')), 120);
			$source = (string)($visual['source'] ?? 'none');
			$sourceLabel = $source === 'screenshotone' ? 'browser capture' : ($source === 'uploaded' ? 'uploaded images' : 'visual references');
			$this->message('Visual direction extracted from ' . $sourceLabel . ' (' . $images . ' image' . ($images === 1 ? '' : 's') . ') with ' . $model . '.');
			return;
		}
		$reason = $this->clipText(trim((string)($visual['reason'] ?? 'vision unavailable')), 80);
		$this->warning('Visual analysis was unavailable (' . $reason . '); Olivia continued with the text and fetched reference brief.');
	}

	protected function visualStatusIsInformative(array $visual): bool {
		if(!empty($visual['ok'])) return true;
		$reason = trim((string)($visual['reason'] ?? ''));
		$source = trim((string)($visual['source'] ?? 'none'));
		$images = max(0, (int)($visual['images'] ?? 0));
		return !($reason === 'no_images' && $source === 'none' && $images === 0);
	}

	protected function reportWebStatus(?array $web): void {
		if($web === null || empty($web['enabled'])) return;
		if(!empty($web['ok'])) {
			$count = min(OliviaPlanner::WEB_SEARCH_MAX_RESULTS, count(is_array($web['sources'] ?? null) ? $web['sources'] : []));
			$this->message('Web research completed with ' . $count . ' public source' . ($count === 1 ? '' : 's') . '. Source links were saved with the plan.');
			return;
		}
		if(!empty($web['fallback'])) {
			$this->warning('Web research was unavailable; Olivia safely completed the plan without it.');
		}
	}

	protected function planJobResult(array $result): array {
		return [
			'plan' => is_array($result['plan'] ?? null) ? $result['plan'] : $result,
			'visual' => is_array($result['visual'] ?? null) ? $result['visual'] : null,
			'web' => is_array($result['web'] ?? null) ? $result['web'] : null,
		];
	}

	/* -------------------------------------------------------------- screen */

	public function ___execute() {
		$this->headline('Olivia');
		$input = $this->wire->input;

		// AI calls can be slow (reasoning models); don't let PHP kill the request
		if($input->requestMethod('POST')) @set_time_limit(self::WEB_POST_SOFT_LIMIT);

		$prompt   = (string) $input->post('olivia_prompt');
		$mode     = $input->post('olivia_mode') ?: ($this->generationMode ?: 'direct');
		$planJson = (string) $input->post('olivia_plan');
		$extra    = '';
		$chatId   = $this->wire->sanitizer->filename((string)($input->post('olivia_chat_id') ?: $input->get('chat')));
		$chat     = $chatId ? $this->chats()->load($chatId) : null;
		if($chatId !== '' && !$chat && !$input->requestMethod('POST')) {
			$this->warning('That saved chat was not found. Starting a fresh chat.');
			$chatId = '';
		}
		if(!$input->requestMethod('POST') && $chat) {
			$prompt = (string)($chat['prompt'] ?? '');
			$mode = (string)($chat['mode'] ?? $mode);
			$planJson = (string)($chat['planJson'] ?? '');
		}
		if($input->get('new')) {
			$chatId = '';
			$chat = null;
			$prompt = '';
			$planJson = '';
			$mode = $this->generationMode ?: 'direct';
		}

		$planJobId = (string) $input->get('olivia_planjob');
		$questionsJobId = (string) $input->get('olivia_qjob');
		$auditJobId = (string) $input->get('olivia_auditjob');
		$buildJobId = (string) $input->get('olivia_buildjob');
		$hasExplicitJob = $planJobId !== '' || $questionsJobId !== '' || $auditJobId !== '' || $buildJobId !== '';
		if(!$input->requestMethod('POST')
			&& $chat
			&& !$input->get('new')
			&& !$hasExplicitJob
			&& trim((string)$input->get('view')) === '') {
			$resumeJob = $this->jobs()->latestForChat($chatId, ['plan', 'change', 'questions', 'audit', 'build']);
			if($resumeJob) {
				$route = $this->resumableJobRoute($resumeJob);
				if($this->jobs()->isTerminal($resumeJob)) {
					if($route['param'] === 'olivia_planjob') $planJobId = (string)$resumeJob['id'];
					elseif($route['param'] === 'olivia_qjob') $questionsJobId = (string)$resumeJob['id'];
					elseif($route['param'] === 'olivia_auditjob') $auditJobId = (string)$resumeJob['id'];
					elseif($route['param'] === 'olivia_buildjob') $buildJobId = (string)$resumeJob['id'];
				} elseif($route['kind'] !== '') {
					$extra .= $this->renderGenerating((string)$resumeJob['id'], $route['kind'], $chatId);
				}
			}
		}

		// --- Manage saved chats ---
		if($input->post('submit_chat_rename')) {
			$id = $this->wire->sanitizer->filename((string) $input->post('olivia_chat_manage_id'));
			$title = trim((string) $input->post('olivia_chat_title'));
			if($id === '' || !$this->chats()->load($id)) {
				$this->error('Chat not found.');
			} elseif($title === '') {
				$this->error('Chat title cannot be empty.');
			} else {
				$this->chats()->updateState($id, ['title' => $title]);
				$this->message('Chat renamed.');
				if($id === $chatId) $chat = $this->chats()->load($id);
			}
		}
		if($input->post('submit_chat_delete')) {
			$id = $this->wire->sanitizer->filename((string) $input->post('olivia_chat_manage_id'));
			if($id === '' || !$this->chats()->load($id)) {
				$this->error('Chat not found.');
			} else {
				$this->chats()->delete($id);
				$this->message('Chat deleted.');
				if($id === $chatId) {
					$chatId = '';
					$chat = null;
					$prompt = '';
					$planJson = '';
					$mode = $this->generationMode ?: 'direct';
				}
			}
		}

		// --- Undo a previous build ---
		if($input->post('submit_undo')) {
			$id = $this->wire->sanitizer->filename((string) $input->post('olivia_undo_id'));
			$manifest = $this->store()->load($id);
			if(!$manifest) {
				$this->error("Build '$id' not found.");
			} else {
				$r = $this->builder()->rollback($manifest);
				$this->store()->delete($id);
				$this->message(sprintf('Undo %s: removed %d pages, %d templates, %d fields.',
					$id, count($r['pages']), count($r['templates']), count($r['fields'])));
				// modules installed by this build are intentionally left in place (they
				// may hold data or be in use); tell the user so they can remove manually.
				$inst = $manifest['installed_modules'] ?? [];
				if($inst) $this->warning('Left installed (remove manually if unwanted): ' . implode(', ', $inst) . '.');
				foreach($r['errors'] as $e) $this->warning($e);
				$this->telemetry()->event('build_undone', ['pages' => count($r['pages'])]);
			}
		}

		// --- Pick up a finished background plan job ---
		if($jid = $planJobId) {
			$job = $this->jobs()->get($this->wire->sanitizer->filename((string) $jid));
			if($job && $job['status'] === 'done') {
				$jobResult = $this->planJobResult(is_array($job['result'] ?? null) ? $job['result'] : []);
				$planResult = $jobResult['plan'];
				$visual = $jobResult['visual'];
				$web = $jobResult['web'];
				$planJson = json_encode($planResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') {
					$chatId = $jobChatId;
					$this->chats()->updateState($chatId, ['planJson' => $planJson, 'prompt' => (string)($job['payload']['displayPrompt'] ?? $job['payload']['prompt'] ?? $prompt), 'mode' => $mode]);
					$this->chats()->append($chatId, 'assistant', 'plan', 'Plan ready. Review it, then Preview or Build.', ['job' => $job['id'], 'visual' => $visual, 'web' => $web]);
				}
				$this->reportVisualStatus($visual);
				$this->reportWebStatus($web);
				$this->message('Plan ready. Review it below, then Preview or Build.');
				$this->jobs()->delete($job['id']);
			} elseif($job && $job['status'] === 'error') {
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') $this->chats()->append($jobChatId, 'assistant', 'error', 'Plan generation failed: ' . $job['error'], ['job' => $job['id']]);
				$this->error('Plan generation failed: ' . $job['error']);
				$this->jobs()->delete($job['id']);
			}
		}

		// --- Pick up a finished background questions job (Interview) ---
		if($jid = $questionsJobId) {
			$job = $this->jobs()->get($this->wire->sanitizer->filename((string) $jid));
			if($job && $job['status'] === 'done') {
				$visual = is_array($job['result']['visual'] ?? null) ? $job['result']['visual'] : null;
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') {
					$chatId = $jobChatId;
					$this->chats()->append($chatId, 'assistant', 'questions', 'Interview questions ready.', ['questions' => $job['result']['questions'] ?? [], 'job' => $job['id'], 'visual' => $visual]);
				}
				$this->reportVisualStatus($visual);
				$extra .= $this->renderInterview(
					$job['result']['questions'] ?? [],
					(string)($job['result']['planningPrompt'] ?? $job['payload']['displayPrompt'] ?? $job['payload']['prompt'] ?? ''),
					$chatId,
					!empty($job['payload']['webSearch'])
				);
				$this->message('Answer these, then Olivia will build the plan.');
				$this->jobs()->delete($job['id']);
			} elseif($job && $job['status'] === 'error') {
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') $this->chats()->append($jobChatId, 'assistant', 'error', 'Could not generate questions: ' . $job['error'], ['job' => $job['id']]);
				$this->error('Could not generate questions: ' . $job['error']);
				$this->jobs()->delete($job['id']);
			}
		}

		// --- Pick up a finished background audit job (Operate / Improve) ---
		if($jid = $auditJobId) {
			$job = $this->jobs()->get($this->wire->sanitizer->filename((string) $jid));
			if($job && $job['status'] === 'done') {
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') {
					$chatId = $jobChatId;
					$this->chats()->append($chatId, 'assistant', 'audit', 'Audit ready.', ['audit' => $job['result'] ?? [], 'job' => $job['id']]);
				}
				$extra .= $this->renderAudit($job['result'] ?? []);
				$this->message('Audit ready — prioritized improvements below.');
				$this->telemetry()->event('audit', ['findings' => count($job['result']['findings'] ?? [])]);
				$this->jobs()->delete($job['id']);
			} elseif($job && $job['status'] === 'error') {
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') $this->chats()->append($jobChatId, 'assistant', 'error', 'Audit failed: ' . $job['error'], ['job' => $job['id']]);
				$this->error('Audit failed: ' . $job['error']);
				$this->jobs()->delete($job['id']);
			}
		}

		// --- Generate plan from prompt (runs in background to dodge FastCGI timeout) ---
		if($input->post('submit_generate')) {
			$webSearch = (bool)$input->post('olivia_web_search');
			$ref = $this->references()->fromInput($input);
			foreach($ref['warnings'] as $w) $this->warning($w);
			$planningPrompt = $this->references()->augmentPrompt($prompt, $ref);
			$workerReference = $this->references()->workerReference($ref);
			if(!empty($ref['fetch']['ok'])) {
				$this->message('Reference URL read: ' . (int)($ref['fetch']['pages'] ?? 1) . ' page(s) summarized for planning.');
			}
			if(!empty($workerReference['images'])) {
				$this->message(count($workerReference['images']) . ' reference image(s) saved for visual analysis.');
			}

			if(trim($prompt) === '' && !$this->references()->hasContext($ref) && $mode !== 'operate') {
				$this->error('Please describe the website first.');
			} else {
				$chat = $this->chats()->ensure($chatId ?: null, $prompt, $mode);
				$chatId = $chat['id'];
				$this->chats()->updateState($chatId, ['prompt' => $prompt, 'mode' => $mode, 'planJson' => $planJson]);
				if($mode === 'operate') {
					$jobType = 'audit';
					$renderType = 'audit';
					$payload = ['prompt' => $prompt, 'displayPrompt' => $prompt, 'chatId' => $chatId];
				} elseif($mode === 'interview') {
					$jobType = 'questions';
					$renderType = 'questions';
					$payload = ['prompt' => $planningPrompt, 'displayPrompt' => $prompt, 'chatId' => $chatId, 'reference' => $workerReference, 'webSearch' => $webSearch];
				} elseif($mode === 'change') {
					$jobType = 'change';
					$renderType = 'plan';
					$payload = ['prompt' => $planningPrompt, 'displayPrompt' => $prompt, 'chatId' => $chatId, 'reference' => $workerReference, 'webSearch' => $webSearch];
				} else {
					$jobType = 'plan';
					$renderType = 'plan';
					$payload = ['prompt' => $planningPrompt, 'displayPrompt' => $prompt, 'chatId' => $chatId, 'reference' => $workerReference, 'webSearch' => $webSearch];
				}
				$claim = $this->spawnSingleFlight($jobType, $payload, $chatId);
				$jobId = (string) $claim['id'];
				if(!empty($claim['created'])) {
					$this->chats()->append($chatId, 'user', $mode === 'operate' ? 'audit_request' : 'prompt', $prompt !== '' ? $prompt : 'Reference-only request', ['mode' => $mode]);
				} else {
					$this->message('That operation is already running for this chat; Olivia is following the existing job.');
				}
				$extra .= $this->renderGenerating($jobId, $renderType, $chatId);
			}
		}

		// --- Interview answers -> plan (background) ---
		if($input->post('submit_answers')) {
			$webSearch = (bool)$input->post('olivia_web_search');
			$basePrompt = (string) $input->post('olivia_base_prompt');
			$count = (int) $input->post('olivia_qcount');
			$qa = [];
			for($i = 0; $i < $count; $i++) {
				$q = (string) $input->post("olivia_qtext_$i");
				$a = (string) $input->post("olivia_q_$i");
				if(trim($q) !== '') $qa[] = ['q' => $q, 'a' => $a];
			}
			$aug = $this->interviewer()->augmentPrompt($basePrompt, $qa);
			$claim = $this->spawnSingleFlight('plan', ['prompt' => $aug, 'displayPrompt' => $basePrompt, 'chatId' => $chatId, 'webSearch' => $webSearch], $chatId);
			$jobId = (string) $claim['id'];
			if($chatId !== '' && !empty($claim['created'])) {
				$this->chats()->updateState($chatId, ['prompt' => $basePrompt, 'mode' => 'interview']);
				$this->chats()->append($chatId, 'user', 'answers', 'Answered interview questions.', ['qa' => $qa]);
			}
			if(empty($claim['created'])) $this->message('A plan is already running for this chat; Olivia is following the existing job.');
			$prompt = $basePrompt;
			$extra .= $this->renderGenerating($jobId, 'plan', $chatId);
		}

		// --- Load a curated blueprint or the fixed sample (works without a key) ---
		if($input->post('submit_sample')) {
			$chat = $this->chats()->ensure($chatId ?: null, 'Loaded blueprint', $mode);
			$chatId = $chat['id'];
			$bpId = $this->wire->sanitizer->name((string) $input->post('olivia_blueprint'));
			$bp = $bpId ? $this->wire(new OliviaBlueprints())->get($bpId) : null;
			$plan = $bp ?: $this->planner()->samplePlan();
			$planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			$this->chats()->updateState($chatId, ['prompt' => $prompt, 'mode' => $mode, 'planJson' => $planJson, 'title' => $bp ? 'Blueprint: ' . $bpId : 'Sample dental clinic']);
			$this->chats()->append($chatId, 'assistant', 'plan', $bp ? 'Blueprint loaded.' : 'Sample plan loaded.');
			$this->message($bp ? 'Blueprint loaded. Preview or Build it.' : 'Sample plan loaded. Preview or Build it.');
		}

		// --- Install a recommended module (download + install runs in background) ---
		if($input->post('submit_install_module')) {
			$class = $this->wire->sanitizer->name((string) $input->post('olivia_install_class'));
			if($class === '') {
				$this->error('No module specified.');
			} else {
				$jobId = $this->spawn('install', ['class' => $class]);
				$extra .= $this->renderGenerating($jobId, 'install', $chatId);
			}
		}

		// --- Pick up a finished background module install ---
		if($jid = $input->get('olivia_installjob')) {
			$job = $this->jobs()->get($this->wire->sanitizer->filename((string) $jid));
			if($job && $job['status'] === 'done') {
				$r = $job['result'] ?? [];
				if(!empty($r['ok'])) $this->message($r['message'] ?? 'Module installed.');
				else $this->error($r['message'] ?? 'Install failed.');
				$this->jobs()->delete($job['id']);
			} elseif($job && $job['status'] === 'error') {
				$this->error('Install failed: ' . $job['error']);
				$this->jobs()->delete($job['id']);
			}
		}

		// --- Refresh module skills (record each installed module's AGENTS.md) ---
		if($input->post('submit_skills')) {
			$recorded = $this->wire(new OliviaSkills())->collectInstalled();
			$this->message($recorded
				? 'Skills refreshed for: ' . implode(', ', $recorded)
				: 'No module skills found (no installed modules with AGENTS.md/README).');
		}

		// --- Teach Olivia a module from its repo's AGENTS.md (no install needed) ---
		if($input->post('submit_learn_module')) {
			$class = $this->wire->sanitizer->name((string) $input->post('olivia_learn_class'));
			if($class === '') {
				$this->error('No module specified.');
			} else {
				$body = $this->modules()->fetchAgents($class);
				if($body === null) {
					$this->error("Couldn't find an AGENTS.md for {$class} in its repository — it may not be Olivia Ready yet.");
				} else {
					$entry = $this->modules()->fetchEntry($class) ?: [];
					$this->wire(new OliviaSkills())->recordRemote($class, $body, $entry);
					$this->message("Learned {$class} from its AGENTS.md — Olivia can now recommend and integrate it.");
					$this->telemetry()->event('module_learned', ['class' => $class]);
				}
			}
		}

		// --- Share the current plan as a content-free blueprint ---
		if($input->post('submit_share')) {
			$plan = $this->decodePlan($planJson);
			if($plan) {
				$share = $this->contribute()->shareableBlueprint($plan);
				$extra .= $this->renderShare($share);
				$this->telemetry()->event('blueprint_shared', $this->telemetry()->planShape($plan));
				$this->message('Share-safe blueprint below — structure only, no content. Copy it to contribute.');
			}
		}

		// --- 👍/👎 feedback on the last result ---
		if($input->post('submit_feedback_up'))   { $this->telemetry()->feedback('up');   $this->message('Thanks for the feedback!'); }
		if($input->post('submit_feedback_down')) { $this->telemetry()->feedback('down'); $this->message('Thanks — noted, we’ll use it to improve.'); }

		// --- Preview (dry run) ---
		if($input->post('submit_preview')) {
			$plan = $this->decodePlan($planJson);
			if($plan) {
				$plan = $this->applyThemeOverride($plan);
				$plan = $this->validatePlan($plan, false); // preview shows issues but still previews
				if($plan) {
					$planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
					if($chatId !== '') {
						$this->chats()->updateState($chatId, ['prompt' => $prompt, 'mode' => $mode, 'planJson' => $planJson]);
						$this->chats()->append($chatId, 'assistant', 'preview', 'Preview generated.');
					}
					$extra .= $this->renderPreview($this->builder()->preview($plan));
				}
			}
		}

		// --- Build (validate now, execute in background worker) ---
		if($input->post('submit_build')) {
			$plan = $this->decodePlan($planJson);
			if($plan) $plan = $this->applyThemeOverride($plan);
			if($plan) $plan = $this->validatePlan($plan, true); // sync validation; block on hard errors, show warnings
			if($plan) {
				// modules the user explicitly opted to have Olivia install before building
				$installModules = [];
				if($input->post('olivia_install_modules')) {
					foreach($this->modules()->recommend($plan['modules'] ?? []) as $r) {
						if(!empty($r['installable'])) $installModules[] = $r['name'];
					}
				}
				$requiredModules = $this->wire(new OliviaSiteTypes())->requiredModules($plan);
				$missingRequired = $this->missingRequiredModules($plan, $installModules);
				if($missingRequired) {
					$this->error(
						'Build blocked: ' . implode(', ', $missingRequired)
						. ' provides the required commerce runtime. Enable “Let Olivia install…” and review the module before building.'
					);
				} else {
				$buildPayload = [
					'plan'           => $plan,
					'prompt'         => $prompt,
					'chatId'         => $chatId,
					'generateImages' => (bool) $this->generateImages,
					'fillContent'    => (bool) $this->fillContent,
					'installModules' => $installModules,
					'requiredModules'=> $requiredModules,
				];
				$buildPlanJson = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
				$buildScope = $chatId !== '' ? $chatId : 'plan:' . hash('sha256', is_string($buildPlanJson) ? $buildPlanJson : '');
				$claim = $this->spawnSingleFlight('build', $buildPayload, $buildScope);
				$jobId = (string) $claim['id'];
				if($chatId !== '' && !empty($claim['created'])) {
					$this->chats()->updateState($chatId, ['prompt' => $prompt, 'mode' => $mode, 'planJson' => json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
					$this->chats()->append($chatId, 'user', 'build_request', 'Build requested.');
				}
				if(empty($claim['created'])) $this->message('That Build is already running; Olivia is following the existing job.');
				$extra .= $this->renderGenerating($jobId, 'build', $chatId);
				}
			}
		}

		// --- Pick up a finished background build job ---
		if($jid = $buildJobId) {
			$job = $this->jobs()->get($this->wire->sanitizer->filename((string) $jid));
			if($job && $job['status'] === 'done') {
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') $chatId = $jobChatId;
				$c = $job['result']['counts'] ?? [];
				$msg = sprintf('Built %s: %d fields, %d templates, %d pages, %d images.',
					$job['result']['buildId'] ?? '?',
					$c['fields'] ?? 0, $c['templates'] ?? 0, $c['pages'] ?? 0, $c['images'] ?? 0);
				$views = max(0, (int)($c['views'] ?? ((int)($c['files'] ?? 0) + (int)($c['updated_files'] ?? 0))));
				if($views > 0) $msg .= sprintf(' %d view%s written or updated.', $views, $views === 1 ? '' : 's');
				if(!empty($c['filled'])) $msg .= sprintf(' %d text fields written by AI.', $c['filled']);
				$inst = $job['result']['installed_modules'] ?? [];
				if($inst) $msg .= ' Installed module' . (count($inst) > 1 ? 's' : '') . ': ' . implode(', ', $inst) . '.';
				$this->message($msg);
				foreach(($job['result']['errors'] ?? []) as $e) $this->warning($e);
				$this->telemetry()->event('build', $c + ['errors' => count($job['result']['errors'] ?? [])]);
				$extra .= $this->renderFeedback();
				if($chatId !== '') $this->chats()->append($chatId, 'assistant', 'build', $msg, ['result' => $job['result'] ?? []]);
				$this->jobs()->delete($job['id']);
			} elseif($job && $job['status'] === 'error') {
				$jobChatId = $this->wire->sanitizer->filename((string)($job['payload']['chatId'] ?? ''));
				if($jobChatId !== '') $this->chats()->append($jobChatId, 'assistant', 'error', 'Build failed: ' . $job['error'], ['job' => $job['id']]);
				$this->error('Build failed: ' . $job['error']);
				$this->jobs()->delete($job['id']);
			}
		}

		if($chatId !== '') {
			$chat = $this->chats()->load($chatId);
			if($chat) {
				$prompt = (string)($prompt !== '' ? $prompt : ($chat['prompt'] ?? ''));
				$mode = (string)($mode ?: ($chat['mode'] ?? 'direct'));
				$planJson = (string)($planJson !== '' ? $planJson : ($chat['planJson'] ?? ''));
			}
		}

		$view = $this->wire->sanitizer->name((string) $input->get('view'));
		if(in_array($view, ['history', 'skills', 'debug'], true)) {
			return $this->oliviaStyles()
				. '<div id="olivia-app">'
				. $this->renderUtilityNav($view)
				. ($view === 'history' ? $this->renderBuilds(true) : ($view === 'skills' ? $this->renderSkills(true) : $this->renderDebug(true, $chatId, $mode, $planJson)))
				. '</div>';
		}

		return $this->oliviaStyles()
			. '<div id="olivia-app">'
			. $this->renderForm($prompt, $mode, $planJson, $chatId, $extra)
			. '</div>';
	}

	/** Required runtime modules that are neither installed nor approved for this Build. */
	protected function missingRequiredModules(array $plan, array $scheduledInstalls = []): array {
		$scheduledInstalls = array_map(
			fn($class) => $this->wire->sanitizer->name((string)$class),
			$scheduledInstalls
		);
		$missing = [];
		foreach($this->wire(new OliviaSiteTypes())->requiredModules($plan) as $requiredModule) {
			if($this->wire->modules->isInstalled($requiredModule)) continue;
			if(in_array($requiredModule, $scheduledInstalls, true)) continue;
			$missing[] = $requiredModule;
		}
		return $missing;
	}

	/** AJAX: report a job's status. URL: setup/olivia/job/?id=... */
	public function ___executeJob() {
		$id = (string) $this->wire->input->get('id');
		$job = $this->jobs()->get($id);
		if($job) $job = $this->superviseJob($job);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('X-Content-Type-Options: nosniff');
		echo json_encode(['status' => $job['status'] ?? 'missing'], JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}

	/**
	 * Watchdog (runs on each poll): a job stuck in pending/running past its budget
	 * means the detached worker stalled or died silently — an OOM/fatal, a killed
	 * process, the machine sleeping, or a host whose CLI has a non-zero
	 * max_execution_time. Never auto-retry model jobs here: a hung provider call
	 * may still be running, and starting another one can duplicate spend/work.
	 * Fail visibly so the browser stops polling and the user can try again or
	 * switch models. build/install are also not retried because they have side
	 * effects.
	 */
	protected function superviseJob(array $job): array {
		if($this->jobs()->isTerminal($job)) return $job;

		$type  = (string) ($job['type'] ?? '');
		$stale = $this->jobs()->deadlineSeconds($type);
		$elapsed = $this->jobs()->elapsedSeconds($job);
		if($elapsed < $stale) return $job; // still within budget

		$workerStopped = $this->jobs()->stopWorker($job);
		$this->jobs()->fail($job['id'],
			"Timed out after about {$stale}s. The background worker stalled or the model did not answer in time. Job: {$type}/{$job['id']}. Elapsed: {$elapsed}s/deadline {$stale}s. Try again or choose a faster model.");
		if(getenv('OLIVIA_SMOKE_TEST') !== '1') {
			$this->wire->log->save('olivia', "watchdog: failed stalled '{$type}' job {$job['id']} elapsed={$elapsed}s deadline={$stale}s worker_stop=" . ($workerStopped ? 'ok' : 'failed'));
		}
		return $this->jobs()->get($job['id']) ?: $job;
	}

	/** Map a chat-owned job to its existing pickup query and activity copy. */
	protected function resumableJobRoute(array $job): array {
		return match((string)($job['type'] ?? '')) {
			'plan', 'change' => ['param' => 'olivia_planjob', 'kind' => 'plan'],
			'questions' => ['param' => 'olivia_qjob', 'kind' => 'questions'],
			'audit' => ['param' => 'olivia_auditjob', 'kind' => 'audit'],
			'build' => ['param' => 'olivia_buildjob', 'kind' => 'build'],
			default => ['param' => '', 'kind' => ''],
		};
	}

}
