<?php namespace ProcessWire;

/**
 * OliviaImageGenerator
 *
 * Fills image fields on generated pages using xAI's image API (Grok Imagine).
 * Reuses the API key configured in the GrokImagine module, but always uses the
 * cheap/standard model `grok-imagine-image` (not the quality model).
 *
 * Cost control: only runs when explicitly enabled, only fills empty image
 * fields, and stops after MAX_IMAGES per build.
 */
class OliviaImageGenerator extends Wire {

	const MODEL    = 'grok-imagine-image'; // cheap "Standard" model only
	const ENDPOINT = 'https://api.x.ai/v1/images/generations';
	const MAX_IMAGES = 10;                 // per build, hard cap on paid calls
	const GALLERY_IMAGES = 3;              // images to generate for a gallery-intent field
	const IMAGE_TIMEOUT = 90;
	const ART_DIRECTOR_MAX_TOKENS = 1500;
	const ART_DIRECTOR_TIMEOUT = 45;
	protected const TMP_TTL = 3600;
	protected static $tempsCleaned = false;

	/** @var string last API error detail, for diagnostics */
	protected $lastError = '';

	public function wired() {
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	/**
	 * Enabled when images can be generated: Squad has an image-capable provider,
	 * OR (transition / legacy) GrokImagine still holds an xAI key Olivia can use.
	 */
	public function isEnabled(): bool {
		try {
			if($this->grokKey() !== '') return true;
			$ai = $this->wire->modules->isInstalled('Squad') ? $this->wire->modules->get('Squad') : null;
			return (bool)($ai && method_exists($ai, 'image') && method_exists($ai, 'getDefaultImageProvider') && $ai->getDefaultImageProvider());
		} catch(\Throwable $e) {
			return false;
		}
	}

	/** The xAI key from GrokImagine, if installed (transition fallback). */
	protected function grokKey(): string {
		try {
			$g = $this->wire->modules->isInstalled('GrokImagine') ? $this->wire->modules->get('GrokImagine') : null;
			return $g ? (string)($g->grokApiKey ?? '') : '';
		} catch(\Throwable $e) {
			return '';
		}
	}

	/**
	 * Generate images for the pages created in this build.
	 *
	 * @param array $plan
	 * @param array $manifest (passed by ref; page ids in $manifest['pages'])
	 * @return int number of images created
	 */
	public function generateForBuild(array $plan, array &$manifest): int {
		if(!$this->isEnabled()) {
			$manifest['errors'][] = 'images: GrokImagine has no xAI API key — skipped.';
			return 0;
		}
		$siteType = trim((string)($plan['site']['type'] ?? ''));
		$count = 0;

		// created pages plus reused pages (so "Build again" fills empty image fields)
		$pageIds = array_merge(
			$manifest['pages'] ?? [],
			$manifest['reused']['pages'] ?? []
		);

		foreach($pageIds as $pid) {
			if($count >= self::MAX_IMAGES) {
				$manifest['errors'][] = 'images: hit per-build cap (' . self::MAX_IMAGES . ') — remaining pages left without images.';
				break;
			}
			$page = $this->wire->pages->get((int) $pid);
			if(!$page->id) continue;

			$field = $this->firstImageField($page);
			if(!$field) continue;
			if($page->get($field->name) && $page->get($field->name)->count()) continue; // already has image(s)

			// gallery-intent fields get several images (rendered as a grid); a single
			// hero field gets one. Always bounded by the per-build cap.
			$want = $this->isGalleryField($field) ? min(self::GALLERY_IMAGES, self::MAX_IMAGES - $count) : 1;
			$basePrompt = $this->artDirectorPrompt($page, $siteType) ?: $this->buildPrompt($page, $siteType);

			for($i = 0; $i < $want; $i++) {
				if($count >= self::MAX_IMAGES) break;
				$prompt = $want > 1 ? $basePrompt . '. unique composition, angle and framing variation ' . ($i + 1) : $basePrompt;
				try {
					$tmp = $this->generateImageFile($prompt);
				} catch(\Throwable $e) {
					$this->lastError = mb_substr($e->getMessage(), 0, 300);
					$tmp = null;
				}
				if(!$tmp && $this->lastError !== '') {
					usleep(1500000); // one retry for transient errors (rate limits, hiccups)
					try { $tmp = $this->generateImageFile($prompt); }
					catch(\Throwable $e) { $this->lastError = mb_substr($e->getMessage(), 0, 300); $tmp = null; }
				}
				if(!$tmp) { $manifest['errors'][] = "images: failed for '{$page->title}' — " . ($this->lastError ?: 'unknown'); continue; }

				try {
					$page->of(false);
					$page->get($field->name)->add($tmp);
					$page->save($field->name);
					$count++;
				} catch(\Throwable $e) {
					$manifest['errors'][] = "images: attach failed for '{$page->title}': " . $e->getMessage();
				} finally {
					if(is_file($tmp)) @unlink($tmp);
				}
			}
		}

		$manifest['images'] = $count;
		return $count;
	}

	/** A field whose name implies it holds multiple images (a gallery). */
	protected function isGalleryField(Field $f): bool {
		return (bool) preg_match('/(gallery|galery|photos|portfolio|images|slides|carousel)/i', $f->name);
	}

	/** First image-type field on the page's template, or null. */
	protected function firstImageField(Page $page): ?Field {
		foreach($page->template->fieldgroup as $f) {
			if(strpos((string) $f->type, 'Image') !== false) return $f;
		}
		return null;
	}

	/**
	 * Art-director role: have a text model write the image prompt from the page's
	 * context. Only runs when an artdirector model is configured; else '' (the
	 * heuristic buildPrompt() is used).
	 */
	protected function artDirectorPrompt(Page $page, string $siteType): string {
		$roles = $this->wire(new OliviaRoles());
		try {
			if(!$roles->enabled('artdirector') || !$this->wire->modules->isInstalled('Squad')) return '';
		} catch(\Throwable $e) {
			return '';
		}
		/** @var Squad $ai */
		try { $ai = $this->wire->modules->get('Squad'); }
		catch(\Throwable $e) { return ''; }
		if(!$ai || !method_exists($ai, 'ask')) return '';
		$ctx = $page->title;
		foreach(['summary', 'tagline', 'headline'] as $cn) {
			if($page->template->fieldgroup->hasField($cn)) { $v = trim((string) $page->get($cn)); if($v !== '') { $ctx .= ' — ' . $v; break; } }
		}
		$sys = 'You are an art director. Write ONE concise image-generation prompt (a single sentence) for a website '
			. 'photo on this page: describe subject, setting, mood, lighting and style. No text/logos in the image. Return ONLY the prompt.';
		$msg = "Page: {$ctx}" . ($siteType !== '' ? " (site: {$siteType})" : '');
		try {
			$res = $ai->ask($msg, array_merge(['systemPrompt' => $sys, 'temperature' => 0.6, 'maxTokens' => self::ART_DIRECTOR_MAX_TOKENS, 'timeout' => self::ART_DIRECTOR_TIMEOUT], $roles->options('artdirector')));
		} catch(\Throwable $e) {
			return '';
		}
		if(empty($res['success'])) return '';
		$p = trim((string)($res['content'] ?? ''));
		return $p !== '' ? $p . '. high-quality professional photography, no text, no words, no watermark, no logo' : '';
	}

	protected function buildPrompt(Page $page, string $siteType): string {
		$bits = [$page->title];
		// add a descriptive field for better subject context
		foreach(['summary', 'tagline', 'excerpt', 'description_field'] as $fn) {
			if($page->template->fieldgroup->hasField($fn)) {
				$v = trim((string) $page->get($fn));
				if($v !== '') { $bits[] = $v; break; }
			}
		}
		if($siteType !== '') $bits[] = $siteType;
		$bits[] = 'high-quality professional photography, natural light, clean composition';
		$bits[] = 'no text, no words, no watermark, no logo';
		return implode('. ', $bits);
	}

	/**
	 * Generate an image and return a path to a downloaded temp file, or null.
	 * Routes through Squad's image() gateway; falls back to a direct xAI call for
	 * older Squad without image support.
	 */
	protected function generateImageFile(string $prompt, string $aspect = '16:9'): ?string {
		$model = $this->wire(new OliviaRoles())->model('illustrator') ?: self::MODEL;
		$this->lastError = '';

		list($url, $b64) = $this->requestImage($prompt, $aspect, $model);
		if($url === '' && $b64 === '') return null;

		$dir = $this->wire->config->paths->cache . 'Olivia/';
		if(!is_dir($dir)) $this->wire->files->mkdir($dir, true);
		$tmp = $dir . 'img_' . getmypid() . '_' . substr(md5($prompt . microtime(true) . uniqid('', true)), 0, 16) . '.jpg';

		if($url !== '') {
			$http = new WireHttp(); $this->wire($http);
			if($http->download($url, $tmp) === false) {
				if(is_file($tmp)) @unlink($tmp);
				$this->lastError = 'image download failed';
				return null;
			}
		} else {
			$bytes = base64_decode($b64, true);
			if(!is_string($bytes)) { $this->lastError = 'invalid image data'; return null; }
			if(file_put_contents($tmp, $bytes) === false) {
				if(is_file($tmp)) @unlink($tmp);
				$this->lastError = 'image write failed';
				return null;
			}
		}
		return is_file($tmp) ? $tmp : null;
	}

	public function cleanupStaleTemps(): void {
		$files = glob($this->wire->config->paths->cache . 'Olivia/img_*.jpg');
		if(!is_array($files)) return;
		$cutoff = time() - self::TMP_TTL;
		foreach($files as $file) {
			$name = basename((string) $file);
			if(!$this->isTempName($name)) continue;
			$mtime = @filemtime($file);
			if($mtime !== false && $mtime < $cutoff) @unlink($file);
		}
	}

	protected function isTempName(string $name): bool {
		return (bool) preg_match('/^img_(?:\d+_)?[a-f0-9]{10,16}\.jpg$/', $name);
	}

	/** [url, b64] from Squad->image() (preferred), else the legacy direct xAI call. */
	protected function requestImage(string $prompt, string $aspect, string $model): array {
		try { $ai = $this->wire->modules->isInstalled('Squad') ? $this->wire->modules->get('Squad') : null; }
		catch(\Throwable $e) { $ai = null; }
		if($ai && method_exists($ai, 'image')) {
			$opts = ['model' => $model, 'aspect' => $aspect, 'resolution' => '1k', 'timeout' => self::IMAGE_TIMEOUT];
			// transition: if Squad has no image key yet, hand it the GrokImagine one
			if(!method_exists($ai, 'getDefaultImageProvider') || !$ai->getDefaultImageProvider()) {
				$k = $this->grokKey();
				if($k !== '') { $opts['provider'] = 'xai'; $opts['key'] = $k; }
			}
			try { $res = $ai->image($prompt, $opts); }
			catch(\Throwable $e) { $this->lastError = mb_substr($e->getMessage(), 0, 300); return ['', '']; }
			if(!empty($res['success'])) return [(string)($res['url'] ?? ''), (string)($res['b64'] ?? '')];
			$this->lastError = (string)($res['message'] ?? 'image failed');
			return ['', ''];
		}
		return $this->legacyImage($prompt, $aspect, $model);
	}

	/** Direct xAI image call — fallback for Squad builds without image(). */
	protected function legacyImage(string $prompt, string $aspect, string $model): array {
		$key = $this->grokKey();
		if($key === '') { $this->lastError = 'no image provider/key'; return ['', '']; }
		$ch = curl_init(self::ENDPOINT);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
			CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'prompt' => $prompt, 'n' => 1, 'aspect_ratio' => $aspect, 'resolution' => '1k'], JSON_INVALID_UTF8_SUBSTITUTE),
			CURLOPT_TIMEOUT        => self::IMAGE_TIMEOUT,
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		if($response === false) { $this->lastError = "curl: $curlErr"; return ['', '']; }
		if($httpCode >= 400) { $this->lastError = "HTTP $httpCode: " . substr((string)$response, 0, 200); return ['', '']; }
		$item = (json_decode($response, true)['data'][0] ?? null);
		if(!is_array($item)) { $this->lastError = 'unexpected response'; return ['', '']; }
		return [(string)($item['url'] ?? ''), (string)($item['b64_json'] ?? '')];
	}
}
