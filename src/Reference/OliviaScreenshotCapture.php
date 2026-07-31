<?php namespace ProcessWire;

/** Optional browser screenshot fallback for URL-only visual references. */
class OliviaScreenshotCapture extends Wire {

	public const TIMEOUT = 45;
	public const CONNECT_TIMEOUT = 8;
	public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
	public const ENDPOINT = 'https://api.screenshotone.com/take';

	public function capture(string $url): array {
		$config = $this->settings();
		$provider = strtolower(trim((string)($config['referenceScreenshotProvider'] ?? '')));
		$key = trim((string)($config['referenceScreenshotKey'] ?? ''));
		if($provider !== 'screenshotone' || $key === '') return ['ok' => false, 'reason' => 'capture_disabled', 'message' => 'URL screenshot capture is not configured.'];
		$url = $this->safeUrl($url);
		if($url === '') return ['ok' => false, 'reason' => 'unsafe_capture_url', 'message' => 'Reference URL is not safe to send to the screenshot provider.'];

		$body = json_encode([
			'access_key' => $key,
			'url' => $url,
			'format' => 'png',
			'full_page' => true,
			'full_page_max_height' => 10000,
			'viewport_width' => 1440,
			'viewport_height' => 1200,
			'block_cookie_banners' => true,
			'block_chats' => true,
			'block_ads' => true,
			'block_trackers' => true,
			'reduced_motion' => true,
		], JSON_UNESCAPED_SLASHES);
		if(!is_string($body)) return ['ok' => false, 'reason' => 'request_encode', 'message' => 'Could not encode screenshot request.'];
		$response = $this->requestScreenshot($body);
		if(empty($response['ok'])) return $response;
		$saved = $this->wire(new OliviaReferenceAnalyzer())->saveCapturedImage((string)($response['data'] ?? ''));
		if(empty($saved['ok'])) return ['ok' => false, 'reason' => 'capture_invalid', 'message' => (string)($saved['message'] ?? 'Screenshot provider returned an invalid image.')];
		return ['ok' => true, 'image' => $saved, 'provider' => 'screenshotone'];
	}

	protected function settings(): array {
		$config = $this->wire->modules->getModuleConfigData('Olivia');
		return is_array($config) ? $config : [];
	}

	protected function safeUrl(string $url): string {
		return $this->wire(new OliviaReferenceAnalyzer())->safeCaptureUrl($url);
	}

	protected function requestScreenshot(string $body): array {
		if(!function_exists('curl_init')) return ['ok' => false, 'reason' => 'curl_missing', 'message' => 'cURL is unavailable.'];
		$data = '';
		$tooLarge = false;
		$ch = curl_init(self::ENDPOINT);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: image/png'],
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_WRITEFUNCTION => function($curl, string $chunk) use (&$data, &$tooLarge): int {
				if(strlen($data) + strlen($chunk) > self::MAX_RESPONSE_BYTES) { $tooLarge = true; return 0; }
				$data .= $chunk;
				return strlen($chunk);
			},
		]);
		$ok = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if($tooLarge) return ['ok' => false, 'reason' => 'capture_too_large', 'message' => 'Screenshot response exceeded the byte limit.'];
		if($ok === false || $code < 200 || $code >= 300) return ['ok' => false, 'reason' => 'capture_http', 'message' => 'Screenshot provider failed' . ($code ? " (HTTP {$code})" : '') . ($error !== '' ? ': ' . mb_substr($error, 0, 120) : '.')];
		return ['ok' => true, 'data' => $data];
	}
}
