<?php namespace ProcessWire;

/**
 * OliviaJobs
 *
 * Tiny file-based job store for background generation. The admin request
 * creates a job and spawns a detached worker; the browser polls until the
 * worker writes the result. This sidesteps the FastCGI request timeout for
 * slow model calls (DeepSeek/MiniMax reasoning).
 *
 * Job shape:
 * - id/type/status are validated fixed strings.
 * - payload is a JSON-safe array.
 * - result is null or a JSON-safe array.
 * - error/created/started/finished are string or null.
 * - pid is positive integer-like or null.
 * - attempts is signed integer-like or null.
 */
class OliviaJobs extends Wire {

	protected const VALID_TYPES = ['plan', 'questions', 'change', 'audit', 'install', 'build'];
	protected const STATUS_PENDING = 'pending';
	protected const STATUS_RUNNING = 'running';
	protected const STATUS_DONE = 'done';
	protected const STATUS_ERROR = 'error';
	protected const VALID_STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_RUNNING,
		self::STATUS_DONE,
		self::STATUS_ERROR,
	];
	protected const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_ERROR];
	protected const DEADLINES = [
		'plan' => 330, 'change' => 330, 'questions' => 300,
		'audit' => 180, 'install' => 300, 'build' => 600,
	];
	protected const DEFAULT_DEADLINE = 180;
	protected const TMP_TTL = 3600;
	protected const MAX_ATTEMPTS = 100;
	protected const MAX_ERROR_LENGTH = 4000;
	protected const SINGLE_FLIGHT_SCAN_LIMIT = 1000;
	public const MAX_FILE_BYTES = 4 * 1024 * 1024;
	protected const DEFAULT_ERROR = 'Unknown job error';
	protected const STRING_FIELDS = ['error', 'created', 'started', 'finished'];
	protected const POSITIVE_INTEGER_FIELDS = ['pid'];
	protected const SIGNED_INTEGER_FIELDS = ['attempts'];

	protected $dir;
	protected static $tempsCleaned = false;

	public function wired() {
		$this->ensureDir();
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	public function create(string $type, array $payload): string {
		if(!$this->isValidType($type)) throw new \InvalidArgumentException('Invalid Olivia job type');
		$id = $this->newId();
		$this->write([
			'id'      => $id,
			'type'    => $type,
			'status'  => self::STATUS_PENDING,
			'payload' => $payload,
			'result'  => null,
			'error'   => '',
			'attempts' => 0,
			'pid' => null,
			'created' => date('c'),
			'started' => null,
			'finished' => null,
		]);
		return $id;
	}

	/**
	 * Atomically claim one job for a logical operation.
	 *
	 * A terminal job remains reusable until its result is picked up and deleted,
	 * closing the small finish-to-poll race that could otherwise spend twice.
	 */
	public function createSingleFlight(string $type, array $payload, string $scope): array {
		if(!$this->isValidType($type)) throw new \InvalidArgumentException('Invalid Olivia job type');
		$scope = trim($scope);
		if($scope === '') return ['id' => $this->create($type, $payload), 'created' => true];
		$this->ensureDir();
		$key = hash('sha256', $type . "\0" . $scope);
		$lock = @fopen($this->dir . '.singleflight.lock', 'c+');
		if(!$lock || !@flock($lock, LOCK_EX)) {
			if(is_resource($lock)) @fclose($lock);
			throw new \RuntimeException('Could not lock Olivia job creation');
		}
		try {
			$existing = $this->singleFlightJob($type, $key);
			if($existing) return ['id' => (string) $existing['id'], 'created' => false];
			$payload['_singleFlight'] = $key;
			return ['id' => $this->create($type, $payload), 'created' => true];
		} finally {
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
	}

	protected function singleFlightJob(string $type, string $key): ?array {
		$files = glob($this->dir . '*.json');
		if(!is_array($files) || !$files) return null;
		usort($files, static fn(string $a, string $b): int => ((int) @filemtime($b)) <=> ((int) @filemtime($a)));
		foreach(array_slice($files, 0, self::SINGLE_FLIGHT_SCAN_LIMIT) as $file) {
			$id = basename($file, '.json');
			$job = $this->get($id);
			if(!$job || ($job['type'] ?? '') !== $type) continue;
			if(($job['payload']['_singleFlight'] ?? '') === $key) return $job;
		}
		return null;
	}

	/** Latest recoverable background operation attached to a saved chat. */
	public function latestForChat(string $chatId, array $types = []): ?array {
		$chatId = trim($chatId);
		if(!preg_match('/^\d{8}-\d{6}-\d{3}-[a-f0-9]{4}$/', $chatId)) return null;
		$types = array_values(array_intersect($types ?: self::VALID_TYPES, self::VALID_TYPES));
		if(!$types) return null;
		$this->ensureDir();
		$files = glob($this->dir . '*.json');
		if(!is_array($files) || !$files) return null;
		usort($files, static fn(string $a, string $b): int => ((int) @filemtime($b)) <=> ((int) @filemtime($a)));
		foreach(array_slice($files, 0, self::SINGLE_FLIGHT_SCAN_LIMIT) as $file) {
			$job = $this->get(basename($file, '.json'));
			if(!$job || !in_array((string)($job['type'] ?? ''), $types, true)) continue;
			if((string)($job['payload']['chatId'] ?? '') === $chatId) return $job;
		}
		return null;
	}

	protected function newId(): string {
		$this->ensureDir();
		for($i = 0; $i < 5; $i++) {
			$id = date('Ymd-His') . '-' . $this->idSuffix();
			if(!is_file($this->dir . $id . '.json')) return $id;
		}
		throw new \RuntimeException('Could not allocate unique Olivia job id');
	}

	public function deadlineSeconds(string $type): int {
		return self::DEADLINES[$type] ?? self::DEFAULT_DEADLINE;
	}

	public function deadlineMap(): array {
		return self::DEADLINES + ['default' => self::DEFAULT_DEADLINE];
	}

	public function isTerminal(array $job): bool {
		return in_array($this->stringField($job, 'status'), self::TERMINAL_STATUSES, true);
	}

	public function get(string $id): ?array {
		$this->ensureDir();
		$id = $this->normalizeId($id);
		if($id === '') return null;
		$file = $this->dir . $id . '.json';
		if(!is_file($file)) return null;
		$size = @filesize($file);
		if($size === false || $size < 2 || $size > self::MAX_FILE_BYTES) return null;
		$raw = @file_get_contents($file);
		if(!is_string($raw)) return null;
		$data = json_decode($raw, true);
		if(!is_array($data)) return null;
		if($this->jobId($data) !== $id) return null;
		if($this->jobBodyShapeError($data) !== '') return null;
		$before = $data['error'] ?? null;
		$this->normalizeErrorField($data);
		if(($data['error'] ?? null) !== $before) $this->repairNormalizedJob($data);
		return $data;
	}

	public function start(string $id, ?int $pid = null): void {
		$job = $this->get($id);
		if(!$job) return;
		if($this->isTerminal($job)) return;
		$job['status'] = self::STATUS_RUNNING;
		$job['result'] = null;
		$job['error'] = '';
		$job['finished'] = null;
		$job['started'] = date('c');
		if($pid !== null && $pid > 0) $job['pid'] = $pid;
		$this->write($job);
	}

	public function finish(string $id, array $result): void {
		$job = $this->get($id);
		if(!$job) return;
		if($this->isTerminal($job)) return;
		$job['status'] = self::STATUS_DONE;
		$job['result'] = $result;
		$job['error'] = '';
		$job['pid'] = null;
		$job['finished'] = date('c');
		$this->write($job);
	}

	public function fail(string $id, string $error): void {
		$job = $this->get($id);
		if(!$job) return;
		if($this->isTerminal($job)) return;
		$job['status'] = self::STATUS_ERROR;
		$job['result'] = null;
		$job['error'] = $error;
		$this->normalizeErrorField($job);
		$job['pid'] = null;
		$job['finished'] = date('c');
		$this->write($job);
	}

	/** Best-effort stop for a worker that outlived its job budget. */
	public function stopWorker(array $job): bool {
		$pid = $this->positivePid($job['pid'] ?? null);
		$id = $this->normalizeId($this->stringField($job, 'id'));
		if($pid <= 0 || $id === '' || !$this->canStopWorker()) return false;
		if(!$this->isExpectedWorkerProcess($pid, $id)) return false;
		$term = defined('SIGTERM') ? constant('SIGTERM') : 15;
		$kill = defined('SIGKILL') ? constant('SIGKILL') : 9;
		if(!@posix_kill($pid, $term)) return false;
		usleep(200000);
		if(!@posix_kill($pid, 0)) return true;
		return @posix_kill($pid, $kill);
	}

	/** Whether this host can verify worker identity before sending a signal. */
	public function canStopWorker(): bool {
		return function_exists('posix_kill') && function_exists('exec') && is_executable('/bin/ps');
	}

	/** Refuse PID-reuse accidents: the process command must name this worker and job. */
	protected function isExpectedWorkerProcess(int $pid, string $jobId): bool {
		if($pid <= 0 || $this->normalizeId($jobId) === '' || !$this->canStopWorker()) return false;
		$out = [];
		$code = 1;
		@exec('/bin/ps -p ' . $pid . ' -o command= 2>/dev/null', $out, $code);
		if($code !== 0 || !$out) return false;
		$command = implode(' ', $out);
		if(strpos($command, 'olivia-worker.php') === false) return false;
		return (bool) preg_match('/(?:^|\s)' . preg_quote($jobId, '/') . '(?:\s|$)/', $command);
	}

	protected function positivePid($value): int {
		if(is_int($value) && $value > 0) return $value;
		if(is_string($value) && preg_match('/^[1-9]\d*$/', $value)) return (int) $value;
		return 0;
	}

	/** Seconds since the job last started (or since it was created if never started). */
	public function elapsedSeconds(array $job): int {
		$started = $this->stringField($job, 'started');
		$created = $this->stringField($job, 'created');
		$ref = $started !== '' ? $started : $created;
		$t = $ref !== '' ? @strtotime($ref) : 0;
		return $t ? max(0, time() - $t) : 0;
	}

	/** Record a watchdog restart: bump the attempt counter and reset the clock. */
	public function bumpAttempt(string $id): int {
		$job = $this->get($id);
		if(!$job) return 0;
		if($this->isTerminal($job)) return 0;
		$attempts = max(0, (int) $this->stringField($job, 'attempts'));
		$job['attempts'] = min($attempts, self::MAX_ATTEMPTS) + 1;
		$job['status']   = self::STATUS_RUNNING;
		$job['result']   = null;
		$job['error']    = '';
		$job['finished'] = null;
		$job['started']  = date('c');
		$this->write($job);
		return $job['attempts'];
	}

	public function delete(string $id): void {
		$this->ensureDir();
		$id = $this->normalizeId($id);
		if($id === '') return;
		$file = $this->dir . $id . '.json';
		if(is_file($file)) @unlink($file);
		$this->deleteTempsForId($id);
	}

	public function cleanupStaleTemps(): void {
		$this->ensureDir();
		$files = glob($this->dir . '*.json.*.tmp');
		if(!is_array($files)) return;
		$cutoff = time() - self::TMP_TTL;
		foreach($files as $file) {
			$name = basename((string) $file);
			if(!$this->isTempName($name)) continue;
			$mtime = @filemtime($file);
			if($mtime !== false && $mtime < $cutoff) @unlink($file);
		}
	}

	protected function deleteTempsForId(string $id): void {
		$files = glob($this->dir . $id . '.json.*.tmp');
		if(!is_array($files)) return;
		foreach($files as $file) {
			$name = basename((string) $file);
			if($this->isTempName($name, $id)) @unlink($file);
		}
	}

	protected function isTempName(string $name, string $id = ''): bool {
		$idPattern = $id !== '' ? preg_quote($id, '/') : '\d{8}-\d{6}-[a-f0-9]{8}';
		return (bool) preg_match('/^' . $idPattern . '\.json\.\d+\.[a-f0-9]+\.tmp$/', $name);
	}

	protected function ensureDir(): void {
		if(!$this->dir) $this->dir = $this->wire->config->paths->cache . 'Olivia/jobs/';
		if(!is_dir($this->dir)) $this->wire->files->mkdir($this->dir, true);
	}

	protected function normalizeId(string $id): string {
		$id = $this->wire->sanitizer->filename($id);
		return preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $id) ? $id : '';
	}

	protected function idSuffix(): string {
		try {
			return bin2hex(random_bytes(4));
		} catch(\Throwable $e) {
			return substr(md5(uniqid('', true)), 0, 8);
		}
	}

	protected function stringField(array $data, string $key): string {
		$value = $data[$key] ?? '';
		return is_scalar($value) ? (string) $value : '';
	}

	protected function limitString(string $value, int $max): string {
		if($max < 4 || strlen($value) <= $max) return $value;
		if(function_exists('mb_strcut')) return mb_strcut($value, 0, $max - 3, 'UTF-8') . '...';
		return $this->validUtf8Prefix(substr($value, 0, $max - 3)) . '...';
	}

	protected function validUtf8Prefix(string $value): string {
		while($value !== '' && !@preg_match('//u', $value)) {
			$value = substr($value, 0, -1);
		}
		return $value;
	}

	protected function normalizeErrorField(array &$job): void {
		if(!array_key_exists('error', $job) || !is_string($job['error'])) return;
		$error = trim($job['error']);
		if($this->stringField($job, 'status') === self::STATUS_ERROR && $error === '') $error = self::DEFAULT_ERROR;
		$job['error'] = $this->limitString($error, self::MAX_ERROR_LENGTH);
	}

	protected function repairNormalizedJob(array $job): bool {
		try {
			$this->write($job);
			return true;
		} catch(\Throwable $e) {
			$id = $this->jobId($job);
			if($id !== '') $this->wire->log->save('olivia', "job repair failed for {$id}: " . $this->limitString($e->getMessage(), 1000));
			return false;
		}
	}

	protected function isValidStatus(string $status): bool {
		return in_array($status, self::VALID_STATUSES, true);
	}

	protected function isValidType(string $type): bool {
		return in_array($type, self::VALID_TYPES, true);
	}

	protected function write(array $job): void {
		$this->ensureDir();
		$id = $this->jobId($job);
		if($id === '') throw new \InvalidArgumentException('Invalid Olivia job id');
		$this->normalizeErrorField($job);
		$shapeError = $this->jobBodyShapeError($job);
		if($shapeError !== '') throw new \InvalidArgumentException('Invalid Olivia job ' . $shapeError);
		$job['id'] = $id;
		$json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if(!is_string($json)) throw new \RuntimeException('Could not encode Olivia job JSON');
		if(strlen($json) > self::MAX_FILE_BYTES) throw new \RuntimeException('Olivia job JSON exceeds the 4 MB limit');
		$file = $this->dir . $id . '.json';
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $json);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia job JSON');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

	protected function isJsonArray(array $data): bool {
		foreach($data as $value) {
			if(is_array($value)) {
				if(!$this->isJsonArray($value)) return false;
				continue;
			}
			if(is_float($value) && !is_finite($value)) return false;
			if($value !== null && !is_scalar($value)) return false;
		}
		return true;
	}

	protected function jobBodyShapeError(array $job): string {
		if(!$this->isValidType($this->stringField($job, 'type'))) return 'type';
		if(!$this->isValidStatus($this->stringField($job, 'status'))) return 'status';
		if(!isset($job['payload']) || !is_array($job['payload']) || !$this->isJsonArray($job['payload'])) return 'payload';
		if(isset($job['result']) && $job['result'] !== null && (!is_array($job['result']) || !$this->isJsonArray($job['result']))) return 'result';
		if(!$this->hasStringOrNullFields($job, self::STRING_FIELDS)) return 'string fields';
		if(!$this->hasPositiveIntegerLikeOrNullFields($job, self::POSITIVE_INTEGER_FIELDS)) return 'positive integer fields';
		if(!$this->hasIntegerLikeOrNullFields($job, self::SIGNED_INTEGER_FIELDS)) return 'integer fields';
		return '';
	}

	protected function jobId(array $job): string {
		return $this->normalizeId($this->stringField($job, 'id'));
	}

	protected function hasStringOrNullFields(array $data, array $keys): bool {
		foreach($keys as $key) {
			if(isset($data[$key]) && !is_string($data[$key])) return false;
		}
		return true;
	}

	protected function hasIntegerLikeOrNullFields(array $data, array $keys): bool {
		foreach($keys as $key) {
			if(!isset($data[$key])) continue;
			$value = $data[$key];
			if(is_int($value)) continue;
			if(is_string($value) && preg_match('/^-?\d+$/', $value)) continue;
			return false;
		}
		return true;
	}

	protected function hasPositiveIntegerLikeOrNullFields(array $data, array $keys): bool {
		foreach($keys as $key) {
			if(!isset($data[$key])) continue;
			$value = $data[$key];
			if(is_int($value) && $value > 0) continue;
			if(is_string($value) && preg_match('/^[1-9]\d*$/', $value)) continue;
			return false;
		}
		return true;
	}

}
