<?php namespace ProcessWire;

require_once __DIR__ . '/src/bootstrap.php';

/**
 * Olivia - AI Solution Architect for ProcessWire.
 *
 * This is the primary product module and configuration owner. The companion
 * ProcessOlivia module provides the Setup > Olivia admin interface.
 */
class Olivia extends WireData implements Module, ConfigurableModule {

	public const VERSION = 100;
	public const VERSION_STRING = '1.0.1';
	public const CONFIG_KEYS = [
		'generationMode',
		'generateImages',
		'fillContent',
		'telemetry',
		'referenceScreenshotProvider',
		'referenceScreenshotKey',
	];

	public static function getModuleInfo() {
		return [
			'title'       => 'Olivia',
			'summary'     => 'AI Solution Architect for ProcessWire - generate a site from a prompt.',
			'version'     => self::VERSION,
			'author'      => 'Maxim Semenov',
			'href'        => 'https://github.com/mxmsmnv/Olivia',
			'license'     => 'MIT',
			'hreflicense' => 'LICENSE',
			'icon'        => 'magic',
			'autoload'    => false,
			'singular'    => true,
			'mcpProvider' => true,
			'requires'    => ['PHP>=8.1.0', 'ProcessWire>=3.0.200', 'Squad>=1.9.0'],
			'installs'    => ['ProcessOlivia'],
		];
	}

	public function init() {
		oliviaRegisterSourceLoader($this->wire->classLoader, __DIR__ . '/src');
	}

	/** Return configuration for the admin companion and runtime services. */
	public function settings(): array {
		$config = $this->wire->modules->getModuleConfigData('Olivia') ?: [];
		$config['generationMode'] = (string)($config['generationMode'] ?? 'direct') ?: 'direct';
		return $config;
	}

	/** Describe Olivia to an optional provider-driven MCP gateway. */
	public function mcpProviderInfo(): array {
		return ['name' => 'olivia', 'title' => 'Olivia', 'version' => self::VERSION_STRING];
	}

	/**
	 * Expose only bounded, secret-free readiness information. Planning, builds,
	 * module installation, and Undo require separate reviewed write tools.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function mcpTools(): array {
		return [[
			'name' => 'lqrs_olivia_status',
			'title' => 'Olivia readiness',
			'description' => 'Read Olivia version, safe feature state, and required module readiness without prompts, content, credentials, or job payloads.',
			'handler' => [$this, 'mcpOliviaStatus'],
			'scope' => 'read',
			'read_only' => true,
			'destructive' => false,
			'idempotent' => true,
			'open_world' => false,
			'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
		]];
	}

	/** @return array<string,mixed> */
	public function mcpOliviaStatus(): array {
		$config = $this->settings();
		$modules = $this->wire->modules;
		$roles = 0;
		foreach(array_keys($config) as $key) if(preg_match('/^role_[a-z0-9_]+_model$/', (string)$key) && trim((string)$config[$key]) !== '') $roles++;
		return [
			'version' => self::VERSION_STRING,
			'generation_mode' => (string)$config['generationMode'],
			'features' => [
				'generate_images' => !empty($config['generateImages']),
				'fill_content' => !empty($config['fillContent']),
				'telemetry' => !empty($config['telemetry']),
				'reference_screenshots' => !empty($config['referenceScreenshotProvider']),
			],
			'configured_ai_roles' => $roles,
			'dependencies' => [
				'squad' => $modules->isInstalled('Squad'),
				'mercato' => $modules->isInstalled('Mercato'),
				'ichiban' => $modules->isInstalled('Ichiban'),
			],
			'write_tools' => false,
			'write_policy' => 'planning_build_install_and_undo_require_separate_reviewed_tools',
		];
	}

	/** Preserve settings from development builds where ProcessOlivia owned the config. */
	public function install() {
		$modules = $this->wire->modules;
		$current = $modules->getModuleConfigData('Olivia') ?: [];
		$legacy = $modules->getModuleConfigData('ProcessOlivia') ?: [];
		if($current || !$legacy) return;

		$migrated = [];
		foreach($legacy as $key => $value) {
			if(in_array($key, self::CONFIG_KEYS, true) || preg_match('/^role_[a-z0-9_]+_model$/', (string)$key)) {
				$migrated[$key] = $value;
			}
		}
		if($migrated) $modules->saveModuleConfigData('Olivia', $migrated);
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$f = $this->wire->modules->get('InputfieldRadios');
		$f->name = 'generationMode';
		$f->label = 'Default generation mode';
		$f->addOption('direct', 'Direct - one prompt, immediate plan, then build');
		$f->addOption('interview', 'Interview - gather requirements with questions first');
		$f->value = $this->generationMode ?: 'direct';
		$inputfields->add($f);

		$c = $this->wire->modules->get('InputfieldCheckbox');
		$c->name = 'generateImages';
		$c->label = 'Generate images';
		$c->label2 = 'Fill image fields using GrokImagine (cheap model, costs xAI credits)';
		$c->description = 'When on, Build generates images for empty image fields, using the cheap "grok-imagine-image" model. Requires an xAI API key in the GrokImagine module. Capped at ' . OliviaImageGenerator::MAX_IMAGES . ' images per build.';
		if($this->generateImages) $c->attr('checked', 'checked');
		$inputfields->add($c);

		$c = $this->wire->modules->get('InputfieldCheckbox');
		$c->name = 'fillContent';
		$c->label = 'Fill empty text with AI';
		$c->label2 = 'Write copy for empty text fields using Squad (costs provider credits)';
		$c->description = 'When on, Build uses Squad to write copy for any EMPTY text/textarea field on the created and existing pages (e.g. a meta description newly added in Change mode), using each page\'s other content as context. Only empty fields are touched; capped at ' . OliviaContentFiller::MAX_FILLS . ' fields per build.';
		if($this->fillContent) $c->attr('checked', 'checked');
		$inputfields->add($c);

		$c = $this->wire->modules->get('InputfieldCheckbox');
		$c->name = 'telemetry';
		$c->label = 'Help improve Olivia (anonymous)';
		$c->label2 = 'Share anonymous usage signals - structure only, never your content';
		$c->description = 'When on, Olivia records anonymous, content-free signals (plan shape, warnings, whether builds are kept or undone, and feedback) to a local log. No field values, page content, URLs or secrets are recorded. Off by default.';
		if($this->telemetry) $c->attr('checked', 'checked');
		$inputfields->add($c);

		$cfg = $this->wire->modules->getModuleConfigData('Olivia') ?: [];
		$fsCapture = $this->wire->modules->get('InputfieldFieldset');
		$fsCapture->label = 'Reference URL screenshots';
		$fsCapture->description = 'Optional browser-rendered capture for URL-only references. Any failure falls back to the fetched HTML brief and never blocks planning.';
		$fsCapture->collapsed = 1;
		$f = $this->wire->modules->get('InputfieldSelect');
		$f->name = 'referenceScreenshotProvider';
		$f->label = 'Screenshot provider';
		$f->addOption('', 'Off');
		$f->addOption('screenshotone', 'ScreenshotOne');
		$f->value = (string)($cfg['referenceScreenshotProvider'] ?? '');
		$fsCapture->add($f);
		$f = $this->wire->modules->get('InputfieldPassword');
		$f->name = 'referenceScreenshotKey';
		$f->label = 'ScreenshotOne access key';
		$f->description = 'Used server-side only for POST requests to api.screenshotone.com; support/debug never prints it.';
		$f->value = (string)($cfg['referenceScreenshotKey'] ?? '');
		$fsCapture->add($f);
		$inputfields->add($fsCapture);

		$roles = $this->wire(new OliviaRoles());
		$fs = $this->wire->modules->get('InputfieldFieldset');
		$fs->label = 'AI roles - a model per job';
		$fs->description = 'Assign a model to each role. Squad remains the provider gateway; leave a role blank to use Squad\'s default model.';
		$fs->collapsed = 1;
		foreach($roles->roles() as $role => $desc) {
			$f = $this->wire->modules->get('InputfieldText');
			$f->name = 'role_' . $role . '_model';
			$f->label = ucfirst($role);
			$f->description = $desc;
			$f->placeholder = 'default model';
			$f->columnWidth = 50;
			$f->value = (string)($cfg['role_' . $role . '_model'] ?? '');
			$fs->add($f);
		}
		$inputfields->add($fs);

		return $inputfields;
	}
}
