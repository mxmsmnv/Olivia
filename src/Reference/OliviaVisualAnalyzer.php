<?php namespace ProcessWire;

/** Convert reference screenshots into a bounded, reusable design profile. */
class OliviaVisualAnalyzer extends Wire {

	public const MAX_IMAGES = 4;
	public const MAX_TOKENS = 5000;
	public const TIMEOUT = 120;

	protected const SYSTEM_PROMPT = <<<'TXT'
You are a senior web design-system analyst. Inspect the supplied reference screenshots and return ONLY valid JSON.
Extract reusable visual rules, not copyrighted copy, logos, photographs, or brand identity. Be concrete enough for another system to implement the visual direction.

Exact schema:
{
  "summary": "short visual direction",
  "theme": {
    "font": "one of Inter|Poppins|Montserrat|DM Sans|Space Grotesk|Sora|Manrope|Work Sans|Playfair Display|Lora",
    "primary": "#rrggbb",
    "background": "#rrggbb",
    "surface": "#rrggbb",
    "text": "#rrggbb",
    "muted": "#rrggbb",
    "radius": 0,
    "container": 1200
  },
  "layout": {"density":"compact|balanced|spacious","header":"description","hero":"description","grid":"description","footer":"description"},
  "components": ["reusable component descriptions"],
  "mobile": ["responsive behavior observations"],
  "avoid": ["reference-specific elements that must not be copied"]
}

Use integer radius in pixels from 0 to 24 and container width from 960 to 1600. Infer the nearest allowed font by visual character if the exact font is unknown.
TXT;

	public function analyze(array $reference): array {
		$files = [];
		$source = 'none';
		foreach($this->images($reference) as $image) {
			$file = (string)($image['file'] ?? '');
			if(!empty($image['ok']) && $file !== '' && is_file($file)) $files[] = $file;
			if(count($files) >= self::MAX_IMAGES) break;
		}
		if($files) $source = 'uploaded';
		if(!$files && trim((string)($reference['url'] ?? '')) !== '') {
			$capture = $this->wire(new OliviaScreenshotCapture())->capture((string)$reference['url']);
			if(!empty($capture['ok']) && !empty($capture['image']['file'])) { $files[] = (string)$capture['image']['file']; $source = 'screenshotone'; }
			elseif(($capture['reason'] ?? '') !== 'capture_disabled') return ['ok' => false, 'reason' => (string)($capture['reason'] ?? 'capture_failed'), 'message' => (string)($capture['message'] ?? 'URL screenshot capture failed.')];
		}
		if(!$files) return ['ok' => false, 'reason' => 'no_images', 'message' => 'No saved reference images are available for visual analysis.'];
		try { $installed = $this->wire->modules->isInstalled('Squad'); }
		catch(\Throwable $e) { $installed = false; }
		if(!$installed) return ['ok' => false, 'reason' => 'squad_missing', 'message' => 'Squad is not installed.'];

		try { $ai = $this->wire->modules->get('Squad'); }
		catch(\Throwable $e) { return ['ok' => false, 'reason' => 'squad_load', 'message' => $this->clip($e->getMessage())]; }
		if(!$ai || !method_exists($ai, 'vision')) return ['ok' => false, 'reason' => 'vision_unavailable', 'message' => 'Installed Squad does not expose vision().'];

		$options = [
			'systemPrompt' => self::SYSTEM_PROMPT,
			'maxTokens' => self::MAX_TOKENS,
			'timeout' => self::TIMEOUT,
			'temperature' => 0.1,
			'detail' => 'high',
		];
		$options = array_merge($options, $this->wire(new OliviaRoles())->options('visual'));
		if(empty($options['model']) && method_exists($ai, 'getDefaultProviderKey')) {
			try { $provider = $ai->getDefaultProviderKey(); }
			catch(\Throwable $e) { $provider = ''; }
			if($provider === 'openrouter') $options['model'] = 'google/gemini-2.5-flash';
		}
		$notes = trim((string)($reference['notes'] ?? ''));
		$prompt = 'Analyze these website references as one coherent direction. Resolve conflicts by favoring repeated patterns.';
		if($notes !== '') $prompt .= "\nUser priorities: " . mb_substr($notes, 0, 1500);

		try { $result = $ai->vision($prompt, $files, $options); }
		catch(\Throwable $e) { return ['ok' => false, 'reason' => 'vision_error', 'message' => $this->clip($e->getMessage())]; }
		if(empty($result['success'])) return ['ok' => false, 'reason' => 'vision_error', 'message' => $this->clip((string)($result['message'] ?? 'Vision provider failed.'))];
		$profile = $this->extractJson((string)($result['content'] ?? ''));
		if(!$profile) return ['ok' => false, 'reason' => 'invalid_profile', 'message' => 'Vision model did not return a valid design profile.'];
		return ['ok' => true, 'profile' => $this->normalize($profile), 'model' => (string)($options['model'] ?? 'default'), 'images' => count($files), 'source' => $source];
	}

	public function augmentPrompt(string $prompt, array $reference): string {
		return $this->augmentPromptResult($prompt, $reference)['prompt'];
	}

	/** Return the augmented prompt plus a compact, persistence-safe status. */
	public function augmentPromptResult(string $prompt, array $reference): array {
		$result = $this->analyze($reference);
		if(empty($result['ok'])) {
			$reason = (string)($result['reason'] ?? 'unknown');
			if($reason !== 'no_images' && getenv('OLIVIA_SMOKE_TEST') !== '1') {
				$this->wire->log->save('olivia', 'visual reference skipped: ' . $reason . ' ' . ($result['message'] ?? ''));
			}
			return ['prompt' => $prompt, 'visual' => $this->metadata($result)];
		}
		$json = json_encode($result['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		return [
			'prompt' => $prompt . "\n\nVISUAL DESIGN PROFILE extracted from the supplied references:\n" . $json
				. "\nUse profile.theme as site.theme, and use the layout/components/mobile observations when selecting page components. Do not copy protected reference content or branding.",
			'visual' => $this->metadata($result),
		];
	}

	protected function metadata(array $result): array {
		$source = strtolower((string)($result['source'] ?? 'none'));
		if(!in_array($source, ['none', 'uploaded', 'screenshotone'], true)) $source = 'none';
		return [
			'ok' => !empty($result['ok']),
			'model' => mb_substr((string)($result['model'] ?? ''), 0, 120),
			'images' => max(0, min(self::MAX_IMAGES, (int)($result['images'] ?? 0))),
			'source' => $source,
			'reason' => mb_substr((string)($result['reason'] ?? ''), 0, 80),
			'message' => $this->clip((string)($result['message'] ?? '')),
		];
	}

	protected function images(array $reference): array {
		$images = is_array($reference['images'] ?? null) ? $reference['images'] : [];
		if(!$images && is_array($reference['image'] ?? null)) $images[] = $reference['image'];
		return $images;
	}

	protected function extractJson(string $text): ?array {
		$text = trim($text);
		$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		if($start === false || $end === false || $end < $start) return null;
		$data = json_decode(substr($text, $start, $end - $start + 1), true);
		return is_array($data) ? $data : null;
	}

	protected function normalize(array $profile): array {
		$layoutInput = is_array($profile['layout'] ?? null) ? $profile['layout'] : [];
		$themeInput = is_array($profile['theme'] ?? null) ? $profile['theme'] : [];
		$themeInput['density'] = $layoutInput['density'] ?? ($themeInput['density'] ?? '');
		$theme = $this->wire(new OliviaTheme())->normalizeReferenceTheme($themeInput);
		$list = fn($value, $limit) => array_slice(array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 240), is_array($value) ? $value : []))), 0, $limit);
		$layout = [];
		foreach(['density', 'header', 'hero', 'grid', 'footer'] as $key) $layout[$key] = mb_substr(trim((string)($layoutInput[$key] ?? '')), 0, 240);
		$layout['density'] = $theme['density'];
		return [
			'summary' => mb_substr(trim((string)($profile['summary'] ?? '')), 0, 500),
			'theme' => $theme,
			'layout' => $layout,
			'components' => $list($profile['components'] ?? [], 16),
			'mobile' => $list($profile['mobile'] ?? [], 12),
			'avoid' => $list($profile['avoid'] ?? [], 12),
		];
	}

	protected function clip(string $message): string { return mb_substr(trim($message), 0, 300); }
}
