<?php namespace ProcessWire;

trait OliviaComposerUi {

	/* -------------------------------------------------------------- render */

	protected function composerModeValues(): array {
		return ['direct', 'interview', 'change', 'operate'];
	}

	protected function composerModeLabels(): array {
		return ['direct' => 'Direct', 'interview' => 'Interview', 'change' => 'Change site', 'operate' => 'Improve'];
	}

	protected function composerModeCopy(): array {
		return [
			'direct' => [
				'title' => 'Ask Olivia to build a website',
				'placeholder' => 'Example: Build a premium cabinetry studio site like modenza.org, with projects, services, gallery and contact.',
				'submit' => 'Generate',
			],
			'interview' => [
				'title' => 'Let Olivia interview you first',
				'placeholder' => 'Example: I need a site for a private dental clinic. Ask me what you need before planning it.',
				'submit' => 'Ask questions',
			],
			'change' => [
				'title' => 'Describe what should change',
				'placeholder' => 'Example: Add a financing page, make the home page calmer, and update the contact section.',
				'submit' => 'Plan change',
			],
			'operate' => [
				'title' => 'Ask Olivia to improve the site',
				'placeholder' => 'Example: Audit this site and tell me the next fixes that would make it feel more premium.',
				'submit' => 'Improve',
			],
		];
	}

	protected function composerModeDescriptions(): array {
		return [
			'direct' => 'Generate a plan from one prompt.',
			'interview' => 'Ask questions first, then plan.',
			'change' => 'Modify the current generated site.',
			'operate' => 'Audit and suggest next fixes.',
		];
	}

	protected function textChars(string $text): ?array {
		if(preg_match_all('/./us', $text, $m) === false) return null;
		return $m[0];
	}

	protected function textLength(string $text): int {
		if(function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
		$chars = $this->textChars($text);
		return is_array($chars) ? count($chars) : strlen($text);
	}

	protected function clipText(string $text, int $limit, string $suffix = '…'): string {
		if($limit <= 0) return '';
		if(function_exists('mb_strlen') && function_exists('mb_substr')) {
			if($this->textLength($text) <= $limit) return $text;
			$suffixLen = $this->textLength($suffix);
			if($suffixLen >= $limit) return mb_substr($suffix, 0, $limit, 'UTF-8');
			return mb_substr($text, 0, max(0, $limit - $suffixLen), 'UTF-8') . $suffix;
		}
		$textChars = $this->textChars($text);
		$suffixChars = $this->textChars($suffix);
		if(is_array($textChars) && is_array($suffixChars)) {
			if(count($textChars) <= $limit) return $text;
			$suffixLen = count($suffixChars);
			if($suffixLen >= $limit) return implode('', array_slice($suffixChars, 0, $limit));
			return implode('', array_slice($textChars, 0, max(0, $limit - $suffixLen))) . $suffix;
		}
		if(strlen($text) <= $limit) return $text;
		$safeSuffix = preg_match('/^[\x00-\x7F]*$/', $suffix) ? $suffix : '...';
		$suffixLen = strlen($safeSuffix);
		if($suffixLen >= $limit) return substr($safeSuffix, 0, $limit);
		return substr($text, 0, max(0, $limit - $suffixLen)) . $safeSuffix;
	}

	protected function renderForm(string $prompt, string $mode, string $planJson, string $chatId = '', string $resultHtml = ''): string {
		$san = $this->wire->sanitizer;
		$p  = $san->entities($prompt);
		$pj = $san->entities($planJson);
		$chatIdE = $san->entities($chatId);
		$refUrlRaw = (string)$this->wire->input->post('olivia_reference_url');
		$refNotesRaw = (string)$this->wire->input->post('olivia_reference_notes');
		$webSearch = (bool)$this->wire->input->post('olivia_web_search');
		$webSearchChecked = $webSearch ? ' checked' : '';
		$refUrl = $san->entities($refUrlRaw);
		$refNotes = $san->entities($refNotesRaw);
		$promptTrim = trim($prompt);
		$promptLen = $this->textLength($promptTrim);
		$refCount = (trim($refUrlRaw) !== '' ? 1 : 0) + (trim($refNotesRaw) !== '' ? 1 : 0);
		$promptEmpty = $promptLen === 0 && $refCount === 0;
		$promptCountE = $promptLen . ' char' . ($promptLen === 1 ? '' : 's');
		$promptReadinessE = $promptLen > 0 ? ($promptLen < 24 ? 'Add a little more detail' : 'Ready to generate') : ($refCount ? 'Reference ready; add a goal or generate' : 'Describe what to build');
		$refParts = [];
		$refUrlTrim = trim($refUrlRaw);
		if($refUrlTrim !== '') {
			$refParseValue = preg_match('~^[a-z][a-z0-9+.-]*://~i', $refUrlTrim) ? $refUrlTrim : 'https://' . $refUrlTrim;
			$refHost = parse_url($refParseValue, PHP_URL_HOST);
			$refHost = is_string($refHost) && $refHost !== '' ? preg_replace('~^www\.~i', '', $refHost) : 'URL';
			$refParts[] = $refHost ?: 'URL';
		}
		if(trim($refNotesRaw) !== '') $refParts[] = 'notes';
		if($webSearch) $refParts[] = 'web research';
		$refSummaryText = implode(', ', $refParts);
		$refHasSummary = $refSummaryText !== '';
		$refSummaryLabel = $refHasSummary ? 'Edit reference: ' . $refSummaryText : 'Add reference';
		$refOpenTitle = $refHasSummary ? 'Edit reference: ' . $refSummaryText . ' — Command/Ctrl + Shift + R' : 'Add reference - Command/Ctrl + Shift + R';
		$refButtonLabel = $refHasSummary ? 'Reference added' : 'Reference';
		$refButtonClass = $refHasSummary ? 'ol-ref-open has-reference' : 'ol-ref-open';
		$refSummaryHidden = $refHasSummary ? '' : ' hidden';
		$refDetailText = $refHasSummary ? 'Using ' . $refSummaryText . '.' : 'No reference added.';
		$refClearDisabled = $refHasSummary ? '' : ' disabled';
		$refClearAriaDisabled = $refHasSummary ? 'false' : 'true';
		$refClearLabel = $refHasSummary ? 'Clear all reference context' : 'No reference to clear';
		$refSummaryTextE = $san->entities($refSummaryText);
		$refSummaryLabelE = $san->entities($refSummaryLabel);
		$refOpenTitleE = $san->entities($refOpenTitle);
		$refButtonLabelE = $san->entities($refButtonLabel);
		$refDetailTextE = $san->entities($refDetailText);
		$refClearLabelE = $san->entities($refClearLabel);
		$refConfig = $this->wire->modules->getModuleConfigData('Olivia');
		if(!is_array($refConfig)) $refConfig = [];
		$refCaptureReady = (string)($refConfig['referenceScreenshotProvider'] ?? '') === 'screenshotone'
			&& trim((string)($refConfig['referenceScreenshotKey'] ?? '')) !== '' && function_exists('curl_init');
		$refCaptureReadyAttr = $refCaptureReady ? '1' : '0';
		$refCaptureInitial = $refUrlTrim !== ''
			? ($refCaptureReady ? 'Browser capture configured for this URL.' : 'HTML brief only; attach images for pixel-level analysis.')
			: ($refCaptureReady ? 'Browser capture is configured for URL-only references.' : 'Attach images for pixel-level analysis.');
		$refCaptureInitialE = $san->entities($refCaptureInitial);
		$clearPromptClass = $promptLen === 0 ? 'ol-clear-prompt is-empty' : 'ol-clear-prompt';
		$clearPromptDisabled = $promptLen === 0 ? ' disabled' : '';
		$clearPromptAriaDisabled = $promptLen === 0 ? 'true' : 'false';
		$clearPromptTitle = $promptLen === 0 ? 'Prompt is empty' : 'Clear prompt draft — Command/Ctrl + Backspace';
		$clearPromptTitleE = $san->entities($clearPromptTitle);
		$clearPromptLabelE = $promptLen === 0 ? 'Prompt is empty' : 'Clear prompt draft';
		$user = $this->wire->user;
		$userLabel = trim((string)($user->title ?: $user->name));
		if($userLabel === '') $userLabel = 'there';
		$userLabel = $san->entities(ucfirst($userLabel));
		$hour = (int) date('G');
		$daypart = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
		$d  = $mode === 'direct' ? 'checked' : '';
		$i  = $mode === 'interview' ? 'checked' : '';
		$ch = $mode === 'change' ? 'checked' : '';
		$op = $mode === 'operate' ? 'checked' : '';
		if($d === '' && $i === '' && $ch === '' && $op === '') $d = 'checked';
		$dChecked = $d !== '' ? 'true' : 'false';
		$iChecked = $i !== '' ? 'true' : 'false';
		$chChecked = $ch !== '' ? 'true' : 'false';
		$opChecked = $op !== '' ? 'true' : 'false';
		$dTab = $d !== '' ? '0' : '-1';
		$iTab = $i !== '' ? '0' : '-1';
		$chTab = $ch !== '' ? '0' : '-1';
		$opTab = $op !== '' ? '0' : '-1';
		$modeLabels = $this->composerModeLabels();
		$modeCopy = $this->composerModeCopy();
		$modeDescriptions = $this->composerModeDescriptions();
		$modeKey = isset($modeCopy[$mode]) ? $mode : 'direct';
		$modeLabelE = $san->entities($modeLabels[$modeKey]);
		$modeTitleE = $san->entities($modeCopy[$modeKey]['title']);
		$modePlaceholderE = $san->entities($modeCopy[$modeKey]['placeholder']);
		$modeSubmitE = $san->entities($modeCopy[$modeKey]['submit']);
		$modeCard = [];
		foreach($this->composerModeValues() as $idx => $modeValue) {
			$label = $modeLabels[$modeValue] ?? ucfirst($modeValue);
			$desc = $modeDescriptions[$modeValue] ?? '';
			$descTitle = rtrim(lcfirst($desc), '.');
			$text = $label . ($descTitle !== '' ? ': ' . $descTitle : '') . ' - Command/Ctrl + ' . ($idx + 1);
			$modeCard[$modeValue] = [
				'label' => $san->entities($label),
				'desc' => $san->entities($desc),
				'action' => $san->entities($text),
			];
		}
		$sendClass = $promptEmpty ? 'ol-send is-empty' : 'ol-send';
		$sendTitle = $promptEmpty ? 'Add a prompt or reference first - Command/Ctrl + Enter' : $modeCopy[$modeKey]['submit'] . ' - Command/Ctrl + Enter';
		$sendLabel = $promptEmpty ? $modeCopy[$modeKey]['submit'] . ': add a prompt or reference first' : $modeCopy[$modeKey]['submit'];
		$sendTitleE = $san->entities($sendTitle);
		$sendLabelE = $san->entities($sendLabel);
		$jsJsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$activeChatIdJs = json_encode($chatId, $jsJsonFlags);
		$modeValuesJs = json_encode($this->composerModeValues(), $jsJsonFlags);
		$modeLabelsJs = json_encode($modeLabels, $jsJsonFlags);
		$modeCopyJs = json_encode($modeCopy, $jsJsonFlags);

		$blueprints = $this->wire(new OliviaBlueprints())->listAll();
		$bpOpts = "<option value=''>Sample (dental clinic)</option>";
		foreach($blueprints as $id => $label) {
			$bpOpts .= "<option value='" . $san->entities($id) . "'>" . $san->entities($label) . "</option>";
		}
		$advOpen = $planJson !== '' ? 'open' : '';
		$advHidden = $advOpen !== '' ? 'false' : 'true';
		$advExpanded = $advOpen !== '' ? 'true' : 'false';
		$advAction = $advOpen !== '' ? 'Collapse' : 'Expand';

		// Theme controls (font + colour) — preselect from the loaded plan's proposed theme
		$tm = $this->wire(new OliviaTheme());
		$pd = json_decode($planJson, true);
		$curTheme = (is_array($pd) && isset($pd['site']['theme']) && is_array($pd['site']['theme'])) ? $pd['site']['theme'] : [];
		$curFont = (string)($curTheme['font'] ?? '');
		$curPrimaryE = $san->entities($tm->validHex((string)($curTheme['primary'] ?? ''), OliviaTheme::DEFAULT_PRIMARY));
		$fontOpts = "<option value=''>Auto — let Olivia choose</option>";
		foreach($tm->fonts() as $fname => $fdesc) {
			$selF = (strcasecmp($fname, $curFont) === 0) ? ' selected' : '';
			$fontOpts .= "<option value='" . $san->entities($fname) . "'$selF>" . $san->entities($fname . ' — ' . $fdesc) . "</option>";
		}
		$swatchHtml = '';
		foreach($tm->palettes() as $plabel => $phex) {
			$swatchLabel = 'Use brand colour ' . $plabel . ' ' . strtoupper($phex);
			$swatchActive = strcasecmp($phex, $curPrimaryE) === 0;
			$swatchClass = $swatchActive ? 'ol-swatch is-active' : 'ol-swatch';
			$swatchPressed = $swatchActive ? 'true' : 'false';
			$swatchHtml .= "<button type='button' class='{$swatchClass}' data-color='" . $san->entities($phex) . "' title='" . $san->entities($swatchLabel) . "' aria-label='" . $san->entities($swatchLabel) . "' aria-describedby='ol-theme-swatches-help' aria-controls='ol-theme-primary' aria-pressed='{$swatchPressed}' style='background:" . $san->entities($phex) . "'></button>";
		}
		$advFontLabel = $curFont !== '' ? $curFont : 'Auto theme';
		$advPlanLabel = $planJson !== '' ? 'Plan loaded' : 'No JSON';
		$advStatus = $advFontLabel . ' · ' . strtoupper($curPrimaryE) . ' · Sample · ' . $advPlanLabel;
		$advStatusE = $san->entities($advStatus);

		// modules this plan needs that Olivia can install for the user before building
		$modInstallHtml = '';
		if(is_array($pd) && !empty($pd['modules'])) {
			$installModulesPresent = (bool)$this->wire->input->post('olivia_install_modules_present');
			$installModulesChecked = $installModulesPresent && (bool)$this->wire->input->post('olivia_install_modules');
			$installModulesCheckedAttr = $installModulesChecked ? ' checked' : '';
			$items = '';
			$n = 0;
			foreach($this->modules()->recommend($pd['modules']) as $r) {
				if(empty($r['installable'])) continue;
				$n++;
				$items .= "<li><strong>" . $san->entities($r['purpose'] ?: $r['name']) . "</strong> <span style='color:#94a3b8'>(" . $san->entities($r['name']) . ")</span></li>";
			}
			if($n) {
				$modInstallHtml = "<input type='hidden' name='olivia_install_modules_present' value='1'>"
					. "<label class='ol-modinstall'><input type='checkbox' name='olivia_install_modules' value='1'{$installModulesCheckedAttr} aria-label='Install recommended modules before build' title='Install recommended modules before build' aria-describedby='ol-install-modules-list' aria-controls='ol-install-modules-list'> "
					. "Let Olivia install the {$n} module" . ($n > 1 ? 's' : '') . " this site needs, then build</label>"
					. "<ul id='ol-install-modules-list' class='ol-modlist' aria-label='Modules Olivia will install before build'>{$items}</ul>";
			}
		}

		// label sets for the instant client-side "Olivia is thinking" overlay
		$sets = [];
		foreach($this->thinkingLabels() as $k => $v) {
			if($k === 'install') continue; // install uses the server-rendered worker placeholder after confirm
			$sets[$k] = ['main' => $v[1], 'subs' => $v[2]];
		}
		$thinkJs = json_encode($sets, $jsJsonFlags);

		$chips = [
			['label' => 'Landing page', 'meta' => 'One offer, proof, CTA and lead form', 'fill' => 'A focused landing page for one professional service with benefits, proof, a clear call to action and a lead form', 'icon' => 'ri-layout-top-line'],
			['label' => 'Business website', 'meta' => 'Services, company, proof and contact', 'fill' => 'A complete multi-page business website with detailed services, about, proof, team and contact', 'icon' => 'ri-building-4-line'],
			['label' => 'Catalog', 'meta' => 'Search, categories, products and enquiries', 'fill' => 'A searchable product catalog with categories, detailed products, image galleries and per-product enquiries', 'icon' => 'ri-book-open-line'],
			['label' => 'Online store', 'meta' => 'Products, cart, checkout and operations', 'fill' => 'A complete online store with products, collections, cart, checkout, Stripe payments, orders, shipping, discounts and inventory', 'icon' => 'ri-shopping-bag-3-line'],
			['label' => 'Restaurant', 'meta' => 'Menu, story, gallery and reservations', 'fill' => 'A cozy Italian restaurant with a menu, about, gallery and a reservations contact page', 'icon' => 'ri-restaurant-line'],
		];
		$chipHtml = '';
		foreach($chips as $item) {
			$chipLabel = 'Load example: ' . $item['label'] . ' — ' . $item['meta'];
			$chipActive = $prompt !== '' && $prompt === $item['fill'];
			$chipClass = $chipActive ? 'ol-chip is-active' : 'ol-chip';
			$chipPressed = $chipActive ? 'true' : 'false';
			$chipHtml .= "<button type='button' class='{$chipClass}' role='listitem' data-fill=\"" . $san->entities($item['fill']) . "\" aria-pressed='{$chipPressed}' aria-controls='ol-main-prompt' aria-label=\"" . $san->entities($chipLabel) . "\" title=\"" . $san->entities($chipLabel) . "\">"
				. "<i class='ol-chip-icon " . $san->entities($item['icon']) . "' aria-hidden='true'></i>"
				. "<span class='ol-chip-copy'><span class='ol-chip-title'>" . $san->entities($item['label']) . "</span>"
				. "<span class='ol-chip-meta'>" . $san->entities($item['meta']) . "</span></span>"
				. "</button>";
		}
		$chatList = $this->renderChatList($chatId);
		$chatTrail = $this->renderChatTrail($chatId);
		$mainPanelClass = $chatTrail !== '' ? 'ol-main-panel has-chat' : 'ol-main-panel';
		if(trim($resultHtml) !== '') $mainPanelClass .= ' has-result';
		$mainContent = $chatTrail !== '' ? $chatTrail : <<<HTML
		<section class="ol-welcome">
			<div class="ol-logo-mark"><i class="ri-sparkling-2-line" aria-hidden="true"></i></div>
			<h1>Built to build.<br><span>Designed to stay reversible.</span></h1>
			<p>Describe the site, add a reference if needed, then review the plan before Olivia changes ProcessWire.</p>
			<div class="ol-chips" role="list" aria-label="Prompt examples">{$chipHtml}</div>
		</section>
HTML;
		$formAction = $chatId !== '' ? './?chat=' . rawurlencode($chatId) : './';
		$debugHref = './?view=debug' . ($chatId !== '' ? '&chat=' . rawurlencode($chatId) : '');
		$formActionE = $san->entities($formAction);
		$debugHrefE = $san->entities($debugHref);
		$modeShortcutText = $san->entities(implode(', ', array_map(fn($m) => $modeLabels[$m] ?? ucfirst($m), $this->composerModeValues())));

		return <<<HTML
<div class="ol-app-shell" id="ol-app-shell">
	{$chatList}
	<main class="{$mainPanelClass}" aria-label="Olivia composer workspace">
		<div class="ol-panel-top">
			<span>Olivia AI v1.0</span>
			<div class="ol-panel-tools">
				<button type="button" class="ol-help-open" data-help-open aria-controls="ol-help-modal" aria-describedby="ol-help-desc ol-help-note" aria-expanded="false" aria-haspopup="dialog" aria-label="Open keyboard shortcuts - ?" aria-keyshortcuts="?" title="Open keyboard shortcuts - ?"><i class="ri-keyboard-line" aria-hidden="true"></i> Shortcuts</button>
				<a href="{$debugHrefE}" title="Open support debug bundle" aria-label="Open support debug bundle"><i class="ri-bug-line" aria-hidden="true"></i> Support info</a>
			</div>
		</div>
		<input type="checkbox" id="ol-help-toggle" class="ol-help-toggle" tabindex="-1" aria-hidden="true">
		<div class="ol-help-modal" id="ol-help-modal" aria-hidden="true">
			<label class="ol-help-backdrop" for="ol-help-toggle" aria-hidden="true"></label>
			<div class="ol-help-dialog" role="dialog" aria-modal="true" aria-labelledby="ol-help-title" aria-describedby="ol-help-desc ol-help-note">
				<div class="ol-help-head">
					<div>
						<strong id="ol-help-title">Shortcuts</strong>
						<span id="ol-help-desc">Use the keyboard to move through Olivia faster.</span>
					</div>
					<button type="button" class="ol-icon-btn ol-help-close" aria-controls="ol-help-modal" aria-describedby="ol-help-desc ol-help-note" aria-label="Close keyboard shortcuts" aria-keyshortcuts="Escape" title="Close keyboard shortcuts"><i class="ri-close-line" aria-hidden="true"></i></button>
				</div>
				<div class="ol-shortcuts" role="list" aria-label="Keyboard shortcuts">
					<div class="ol-shortcut" role="listitem"><kbd>⌘ K</kbd><span>Focus the prompt</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ Enter</kbd><span>Generate from the current prompt</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⌫</kbd><span>Clear the prompt draft</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ 1-4</kbd><span>Switch {$modeShortcutText}</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>← → ↑ ↓ Home End</kbd><span>Move between mode cards</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>Enter / Space</kbd><span>Select the focused mode card</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ Backslash</kbd><span>Collapse or expand the sidebar</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ N</kbd><span>Start a new chat</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ F</kbd><span>Search saved chats</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ R</kbd><span>Open reference URL and screenshot</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ A</kbd><span>Toggle blueprints and advanced settings</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ C</kbd><span>Copy the editable build plan JSON</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ P</kbd><span>Format valid build plan JSON</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>⌘ ⇧ Z</kbd><span>Undo the last JSON edit</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>?</kbd><span>Show this help</span></div>
					<div class="ol-shortcut" role="listitem"><kbd>Esc</kbd><span>Close open panels</span></div>
				</div>
				<p id="ol-help-note" class="ol-help-note">Ctrl works too when Command is not available. Global shortcuts pause while editing Reference, JSON, search, or other secondary fields.</p>
			</div>
		</div>
{$mainContent}
<div class="ol-activity-slot" data-activity-slot aria-live="polite"></div>
<form method="post" action="{$formActionE}" class="ol-composer" enctype="multipart/form-data" aria-label="Olivia site generation composer">
	<input type="hidden" name="olivia_chat_id" value="{$chatIdE}">
	<input type="hidden" name="olivia_theme_font_override" value="0">
	<input type="hidden" name="olivia_theme_primary_override" value="0">
	<div class="ol-inputwrap">
		<div class="ol-prompt-head">
			<span class="ol-prompt-title"><i class="ri-sparkling-2-line" aria-hidden="true"></i><span id="ol-prompt-title" data-prompt-title>{$modeTitleE}</span></span>
			<span class="ol-prompt-state"><span id="ol-draft-status" class="ol-draft-status" data-draft-status role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-main-prompt"></span><button type="button" class="{$clearPromptClass}" title="{$clearPromptTitleE}" aria-label="{$clearPromptLabelE}" aria-disabled="{$clearPromptAriaDisabled}" aria-keyshortcuts="Meta+Backspace Control+Backspace Meta+Delete Control+Delete" aria-controls="ol-main-prompt"{$clearPromptDisabled}><i class="ri-close-line" aria-hidden="true"></i></button><span class="ol-mode-badge" data-mode-badge role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-main-prompt" aria-label="Current mode: {$modeLabelE}" title="Current mode: {$modeLabelE}">{$modeLabelE}</span></span>
		</div>
		<textarea id="ol-main-prompt" name="olivia_prompt" class="ol-prompt" rows="3" aria-labelledby="ol-prompt-title" aria-describedby="ol-prompt-readiness ol-prompt-count ol-draft-status" aria-keyshortcuts="Meta+K Control+K" placeholder="{$modePlaceholderE}">{$p}</textarea>
		<div class="ol-prompt-foot"><span id="ol-prompt-readiness" data-prompt-readiness role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-main-prompt">{$promptReadinessE}</span><button type="button" data-ref-summary aria-controls="ol-ref-modal" aria-describedby="ol-ref-desc ol-ref-detail" aria-expanded="false" aria-haspopup="dialog" aria-keyshortcuts="Meta+Shift+R Control+Shift+R" title="{$refSummaryLabelE}" aria-label="{$refSummaryLabelE}"{$refSummaryHidden}>Reference: {$refSummaryTextE}</button><span id="ol-prompt-count" data-prompt-count aria-controls="ol-main-prompt">{$promptCountE}</span></div>
		<input type="checkbox" id="ol-ref-toggle" class="ol-ref-toggle" tabindex="-1" aria-hidden="true">
		<div class="ol-ref-modal" id="ol-ref-modal" aria-hidden="true">
			<label class="ol-ref-backdrop" for="ol-ref-toggle" aria-hidden="true"></label>
			<div class="ol-ref-dialog" role="dialog" aria-modal="true" aria-labelledby="ol-ref-title" aria-describedby="ol-ref-desc ol-ref-detail">
				<div class="ol-ref-head">
					<div>
						<strong id="ol-ref-title">Reference</strong>
						<span id="ol-ref-desc">Optional site URL, screenshot and notes for style or structure.</span>
					</div>
					<label for="ol-ref-toggle" class="ol-icon-btn ol-ref-close" role="button" tabindex="0" aria-controls="ol-ref-modal" aria-describedby="ol-ref-desc ol-ref-detail" aria-label="Close reference dialog" aria-keyshortcuts="Escape Meta+Enter Control+Enter" title="Close reference dialog"><i class="ri-close-line" aria-hidden="true"></i></label>
				</div>
				<label for="ol-ref-url" class="ol-ref-label">Website URL</label>
				<input id="ol-ref-url" type="url" name="olivia_reference_url" value="{$refUrl}" class="ol-refurl" placeholder="https://example.com" title="Reference website URL for Olivia" aria-describedby="ol-ref-desc ol-ref-detail" aria-controls="ol-ref-detail">
				<div class="ol-capture-state" data-capture-state data-capture-ready="{$refCaptureReadyAttr}" role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-ref-url ol-ref-image" aria-describedby="ol-ref-desc"><i class="ri-camera-lens-line" aria-hidden="true"></i><span>{$refCaptureInitialE}</span></div>
				<div class="ol-ref-label-row"><label for="ol-ref-image" class="ol-ref-label">Reference images</label><span class="ol-file-count" data-file-count aria-live="polite" aria-atomic="true" aria-controls="ol-ref-image">0 / 4</span></div>
				<div class="ol-file-row" data-ref-drop><label class="ol-file-pill" for="ol-ref-image" role="button" tabindex="0" title="Attach or drop up to four reference images" aria-label="Attach or drop up to four reference images" aria-describedby="ol-ref-desc ol-ref-detail" aria-controls="ol-ref-image ol-ref-detail"><input id="ol-ref-image" type="file" name="olivia_reference_image[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple tabindex="-1" aria-hidden="true"><i class="ri-attachment-2" aria-hidden="true"></i> <span data-file-label>Attach or drop references</span></label><button type="button" class="ol-file-clear" title="Remove reference images" aria-label="Remove reference images" aria-describedby="ol-ref-detail" aria-controls="ol-ref-image ol-ref-detail" hidden><i class="ri-close-line" aria-hidden="true"></i> Remove all</button></div>
				<div class="ol-ref-previews" data-ref-previews role="list" aria-label="Selected reference images" aria-describedby="ol-ref-detail" hidden></div>
				<label for="ol-ref-notes" class="ol-ref-label">Notes</label>
				<textarea id="ol-ref-notes" name="olivia_reference_notes" class="ol-refnotes" rows="3" title="Reference notes for Olivia" aria-describedby="ol-ref-desc ol-ref-detail" aria-controls="ol-ref-detail" placeholder="What should Olivia notice: layout, mood, navigation, sections, typography?">{$refNotes}</textarea>
				<label class="ol-web-research" for="ol-web-search">
					<span class="ol-web-research-copy"><i class="ri-global-line" aria-hidden="true"></i><span><strong>Web research</strong><small>Ground the final plan in up to five current public sources. Adds provider cost and latency.</small></span></span>
					<input id="ol-web-search" type="checkbox" name="olivia_web_search" value="1"{$webSearchChecked} aria-describedby="ol-ref-desc ol-ref-detail">
					<span class="ol-switch" aria-hidden="true"></span>
				</label>
				<div id="ol-ref-detail" class="ol-ref-detail" data-ref-detail role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-ref-url ol-ref-image ol-ref-notes">{$refDetailTextE}</div>
				<div class="ol-ref-actions"><button type="button" class="ol-ref-clear" title="{$refClearLabelE}" aria-label="{$refClearLabelE}" aria-disabled="{$refClearAriaDisabled}" aria-describedby="ol-ref-detail" aria-controls="ol-ref-url ol-ref-image ol-ref-notes ol-ref-detail"{$refClearDisabled}><i class="ri-close-circle-line" aria-hidden="true"></i> Clear reference</button><button type="button" class="ol-ref-done" title="Done editing reference" aria-label="Done editing reference" aria-keyshortcuts="Escape Meta+Enter Control+Enter" aria-controls="ol-ref-modal" aria-describedby="ol-ref-desc ol-ref-detail">Done</button></div>
			</div>
		</div>
		<div class="ol-bar">
			<div class="ol-modes" role="radiogroup" aria-label="Generation mode" aria-orientation="horizontal" aria-controls="ol-main-prompt">
				<label class="ol-mode" role="radio" aria-checked="{$dChecked}" tabindex="{$dTab}" title="{$modeCard['direct']['action']}" aria-label="{$modeCard['direct']['action']}" aria-keyshortcuts="Meta+1 Control+1" aria-controls="ol-main-prompt"><input type="radio" name="olivia_mode" value="direct" tabindex="-1" aria-hidden="true" {$d}><span class="ol-mode-title"><i class="ri-compass-3-line" aria-hidden="true"></i> {$modeCard['direct']['label']}</span><span class="ol-mode-desc">{$modeCard['direct']['desc']}</span></label>
				<label class="ol-mode" role="radio" aria-checked="{$iChecked}" tabindex="{$iTab}" title="{$modeCard['interview']['action']}" aria-label="{$modeCard['interview']['action']}" aria-keyshortcuts="Meta+2 Control+2" aria-controls="ol-main-prompt"><input type="radio" name="olivia_mode" value="interview" tabindex="-1" aria-hidden="true" {$i}><span class="ol-mode-title"><i class="ri-question-answer-line" aria-hidden="true"></i> {$modeCard['interview']['label']}</span><span class="ol-mode-desc">{$modeCard['interview']['desc']}</span></label>
				<label class="ol-mode" role="radio" aria-checked="{$chChecked}" tabindex="{$chTab}" title="{$modeCard['change']['action']}" aria-label="{$modeCard['change']['action']}" aria-keyshortcuts="Meta+3 Control+3" aria-controls="ol-main-prompt"><input type="radio" name="olivia_mode" value="change" tabindex="-1" aria-hidden="true" {$ch}><span class="ol-mode-title"><i class="ri-tools-line" aria-hidden="true"></i> {$modeCard['change']['label']}</span><span class="ol-mode-desc">{$modeCard['change']['desc']}</span></label>
				<label class="ol-mode" role="radio" aria-checked="{$opChecked}" tabindex="{$opTab}" title="{$modeCard['operate']['action']}" aria-label="{$modeCard['operate']['action']}" aria-keyshortcuts="Meta+4 Control+4" aria-controls="ol-main-prompt"><input type="radio" name="olivia_mode" value="operate" tabindex="-1" aria-hidden="true" {$op}><span class="ol-mode-title"><i class="ri-search-eye-line" aria-hidden="true"></i> {$modeCard['operate']['label']}</span><span class="ol-mode-desc">{$modeCard['operate']['desc']}</span></label>
			</div>
			<div class="ol-actions">
				<label for="ol-ref-toggle" class="{$refButtonClass}" role="button" tabindex="0" title="{$refOpenTitleE}" aria-label="{$refSummaryLabelE}" aria-keyshortcuts="Meta+Shift+R Control+Shift+R" aria-controls="ol-ref-modal" aria-describedby="ol-ref-desc ol-ref-detail" aria-expanded="false" aria-haspopup="dialog"><i class="ri-links-line" aria-hidden="true"></i> <span data-ref-label>{$refButtonLabelE}</span></label>
				<button type="submit" name="submit_generate" value="1" class="{$sendClass}" title="{$sendTitleE}" aria-label="{$sendLabelE}" aria-keyshortcuts="Meta+Enter Control+Enter" aria-controls="ol-main-prompt"><span data-submit-label>{$modeSubmitE}</span> <i class="ri-arrow-up-line" aria-hidden="true"></i></button>
			</div>
		</div>
	</div>
	<details class="ol-adv" {$advOpen}>
		<summary class="ol-adv-summary" aria-controls="ol-advanced-panel" aria-describedby="ol-advanced-status" aria-expanded="{$advExpanded}" aria-keyshortcuts="Meta+Shift+A Control+Shift+A" title="{$advAction} blueprints and advanced settings - Command/Ctrl + Shift + A" aria-label="{$advAction} blueprints and advanced settings - Command/Ctrl + Shift + A"><span id="ol-advanced-title" class="ol-adv-title">Blueprints &amp; advanced</span><span id="ol-advanced-status" class="ol-adv-status" data-adv-status role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-theme-font ol-theme-primary ol-blueprint-select ol-plan-json">{$advStatusE}</span></summary>
		<div id="ol-advanced-panel" class="ol-adv-body" role="region" aria-labelledby="ol-advanced-title" aria-describedby="ol-advanced-status" aria-hidden="{$advHidden}">
			<span id="ol-theme-label" class="ol-lbl">Theme — font &amp; brand colour <span style="font-weight:500;color:#9aa0a6">(Olivia proposes one when it plans; override here, then Preview or Build)</span></span>
			<div class="ol-advrow" role="group" aria-label="Theme override controls" aria-describedby="ol-theme-label" aria-controls="ol-theme-font ol-theme-primary">
				<select id="ol-theme-font" name="olivia_theme_font" class="ol-select" aria-label="Olivia theme font override" aria-describedby="ol-theme-label" aria-controls="ol-advanced-status" title="Olivia theme font override">{$fontOpts}</select>
				<input id="ol-theme-primary" type="color" name="olivia_theme_primary" value="{$curPrimaryE}" class="ol-color" title="Olivia brand colour override" aria-label="Olivia brand colour override" aria-describedby="ol-theme-label" aria-controls="ol-advanced-status">
				<span class="ol-swatches" role="group" aria-label="Brand colour presets" aria-describedby="ol-theme-label ol-theme-swatches-help" aria-controls="ol-theme-primary"><span id="ol-theme-swatches-help" class="ol-sr-only">Preset colour buttons update the brand colour override picker.</span>{$swatchHtml}</span>
			</div>
			<div class="ol-design-system" data-design-system role="region" aria-labelledby="ol-design-system-title" aria-describedby="ol-design-system-status" hidden>
				<div class="ol-design-head"><span id="ol-design-system-title" class="ol-lbl"><i class="ri-palette-line" aria-hidden="true"></i> Design system</span><button type="button" class="ol-icon-btn ol-copy-theme" title="Copy design system JSON" aria-label="Copy design system JSON" aria-controls="ol-design-tokens" aria-describedby="ol-design-system-status"><i class="ri-file-copy-line" aria-hidden="true"></i></button></div>
				<div id="ol-design-tokens" class="ol-design-tokens" role="list" aria-label="Current plan design tokens"></div>
				<span id="ol-design-system-status" class="ol-copy-status" data-copy-theme-status role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-design-tokens"></span>
			</div>
			<div class="ol-advrow" role="group" aria-label="Blueprint and module utility actions" aria-describedby="ol-blueprint-help" aria-controls="ol-blueprint-select ol-plan-json">
				<span id="ol-blueprint-help" class="ol-sr-only">Load a deterministic blueprint into the editable plan, refresh Olivia's module skills, or create a shareable blueprint from the current plan.</span>
				<select id="ol-blueprint-select" name="olivia_blueprint" class="ol-select" aria-label="Olivia blueprint to load" aria-describedby="ol-blueprint-help" aria-controls="ol-advanced-status" title="Olivia blueprint to load">{$bpOpts}</select>
				<button type="submit" name="submit_sample" value="1" class="ol-btn" title="Load the selected blueprint into the editable plan" aria-label="Load the selected blueprint into the editable plan" aria-describedby="ol-blueprint-help" aria-controls="ol-plan-json">Load blueprint</button>
				<button type="submit" name="submit_skills" value="1" class="ol-btn ol-ghost" title="Refresh Olivia module skills library" aria-label="Refresh Olivia module skills library" aria-describedby="ol-blueprint-help" aria-controls="ol-advanced-status">Refresh skills</button>
				<button type="submit" name="submit_share" value="1" class="ol-btn ol-ghost" title="Create a shareable blueprint from the current plan" aria-label="Create a shareable blueprint from the current plan" aria-describedby="ol-blueprint-help" aria-controls="ol-plan-json">Share</button>
			</div>
			<div class="ol-json-head"><label id="ol-plan-json-label" class="ol-lbl" for="ol-plan-json">Build plan (JSON) — editable, always reviewed before building</label><span id="ol-json-diagnostics" class="ol-json-badges" role="group" aria-label="Build plan JSON diagnostics" aria-describedby="ol-json-diagnostics-help" aria-controls="ol-plan-json"><span id="ol-json-diagnostics-help" class="ol-sr-only">Shows JSON validity, site summary, character count, plan shape, and missing schema hints for the editable build plan.</span><span class="ol-json-status ol-json-jump" data-json-status role="button" tabindex="0" aria-controls="ol-plan-json" aria-describedby="ol-json-diagnostics-help" aria-live="polite" aria-atomic="true" title="No build plan JSON loaded" aria-label="No build plan JSON loaded; focus editable JSON">No JSON</span><span class="ol-json-site ol-json-jump" data-json-site role="button" tabindex="0" aria-controls="ol-plan-json" aria-describedby="ol-json-diagnostics-help" title="Plan site summary; focus editable JSON" aria-label="Plan site summary; focus editable JSON" hidden></span><span class="ol-json-size ol-json-jump" data-json-size role="button" tabindex="0" aria-controls="ol-plan-json" aria-describedby="ol-json-diagnostics-help" title="0 characters in build plan JSON; focus editable JSON" aria-label="0 characters in build plan JSON; focus editable JSON">0 chars</span><span class="ol-json-shape ol-json-jump" data-json-shape role="button" tabindex="0" aria-controls="ol-plan-json" aria-describedby="ol-json-diagnostics-help" title="Plan shape summary; focus editable JSON" aria-label="Plan shape summary; focus editable JSON" hidden></span><span class="ol-json-hint ol-json-jump" data-json-hint role="button" tabindex="0" aria-controls="ol-plan-json" aria-describedby="ol-json-diagnostics-help" title="Plan schema hints; focus editable JSON" aria-label="Plan schema hints; focus editable JSON" hidden></span></span><span class="ol-json-actions" role="group" aria-label="Build plan JSON actions" aria-describedby="ol-json-action-help ol-json-action-status" aria-controls="ol-plan-json"><span id="ol-json-action-help" class="ol-sr-only">Undo the last JSON edit, format valid JSON, or copy the editable build plan JSON.</span><button type="button" class="ol-btn ol-ghost ol-undo-plan" title="No JSON edit to undo" aria-label="No JSON edit to undo" aria-disabled="true" aria-keyshortcuts="Meta+Shift+Z Control+Shift+Z" aria-controls="ol-plan-json" aria-describedby="ol-json-action-help ol-json-action-status" disabled><i class="ri-arrow-go-back-line" aria-hidden="true"></i> Undo edit</button><button type="button" class="ol-btn ol-ghost ol-format-plan" title="Format valid build plan JSON - Command/Ctrl + Shift + P" aria-label="Format valid build plan JSON - Command/Ctrl + Shift + P" aria-disabled="true" aria-keyshortcuts="Meta+Shift+P Control+Shift+P" aria-controls="ol-plan-json" aria-describedby="ol-json-action-help ol-json-action-status" disabled><i class="ri-code-box-line" aria-hidden="true"></i> Format</button><button type="button" class="ol-btn ol-ghost ol-copy-plan" title="Copy editable build plan JSON - Command/Ctrl + Shift + C" aria-label="Copy editable build plan JSON - Command/Ctrl + Shift + C" aria-disabled="true" aria-keyshortcuts="Meta+Shift+C Control+Shift+C" aria-controls="ol-plan-json" aria-describedby="ol-json-action-help ol-json-action-status" disabled><i class="ri-file-copy-line" aria-hidden="true"></i> Copy JSON</button></span><span id="ol-json-action-status" class="ol-copy-status" data-copy-plan-status role="status" aria-live="polite" aria-atomic="true" aria-controls="ol-plan-json" aria-describedby="ol-json-action-help"></span></div>
			<textarea id="ol-plan-json" name="olivia_plan" class="ol-json" rows="14" spellcheck="false" aria-labelledby="ol-plan-json-label" aria-describedby="ol-json-diagnostics-help ol-json-action-help ol-json-action-status" title="Editable build plan JSON">{$pj}</textarea>
			{$modInstallHtml}
			<div class="ol-advrow ol-advrow-end" role="group" aria-label="Plan review and build actions" aria-describedby="ol-plan-action-help" aria-controls="ol-plan-json">
				<span id="ol-plan-action-help" class="ol-sr-only">Preview runs a dry review of the editable JSON plan before building. Build creates the reviewed plan in ProcessWire.</span>
				<button type="submit" name="submit_preview" value="1" class="ol-btn ol-ghost ol-preview-plan" title="Preview the current plan before building" aria-label="Preview the current plan before building" aria-controls="ol-plan-json ol-plan-preview" aria-describedby="ol-plan-action-help ol-json-diagnostics-help">Preview</button>
				<button type="submit" name="submit_build" value="1" class="ol-btn ol-primary ol-build-plan" title="Build the reviewed plan in ProcessWire" aria-label="Build the reviewed plan in ProcessWire" aria-controls="ol-plan-json" aria-describedby="ol-plan-action-help ol-json-diagnostics-help"><i class="ri-flashlight-line" aria-hidden="true"></i> Build</button>
			</div>
		</div>
	</details>
</form>
		<div class="ol-result-slot" data-result-slot aria-live="polite">{$resultHtml}</div>
	</main>
</div>
<script>
(function(){
	var ACTIVE_CHAT_ID = {$activeChatIdJs};
	var shell = document.querySelector('.ol-app-shell');
	var sidebarToggle = document.querySelector('.ol-sidebar-toggle');
	var advancedPanel = document.querySelector('.ol-adv');
	var advancedSummary = document.querySelector('.ol-adv-summary');
	var advancedBody = document.querySelector('.ol-adv-body');
	var SIDEBAR_KEY = 'olivia.sidebar.collapsed';
	var SIDEBAR_MOBILE_KEY = 'olivia.sidebar.mobile.collapsed';
	var sidebarMobileQuery = window.matchMedia ? window.matchMedia('(max-width:700px)') : null;
	var ADVANCED_KEY = 'olivia.advanced.open';
	function sidebarStorageKey(){
		return sidebarMobileQuery && sidebarMobileQuery.matches ? SIDEBAR_MOBILE_KEY : SIDEBAR_KEY;
	}
	function setSidebarCollapsed(value, save){
		if(!shell) return;
		shell.classList.toggle('is-sidebar-collapsed', !!value);
		if(sidebarToggle){
			sidebarToggle.setAttribute('aria-pressed', value ? 'true' : 'false');
			sidebarToggle.setAttribute('aria-expanded', value ? 'false' : 'true');
			sidebarToggle.setAttribute('aria-label', value ? 'Expand sidebar' : 'Collapse sidebar');
			sidebarToggle.setAttribute('title', (value ? 'Expand' : 'Collapse') + ' sidebar - Command/Ctrl + Backslash');
			sidebarToggle.setAttribute('aria-keyshortcuts', 'Meta+Backslash Control+Backslash');
			sidebarToggle.setAttribute('aria-controls', 'ol-app-shell');
		}
		if(save !== false) {
			try { if(window.localStorage) window.localStorage.setItem(sidebarStorageKey(), value ? '1' : '0'); } catch(e) {}
		}
	}
	try {
		var savedSidebar = window.localStorage && window.localStorage.getItem(sidebarStorageKey());
		setSidebarCollapsed(savedSidebar === null ? !!(sidebarMobileQuery && sidebarMobileQuery.matches) : savedSidebar === '1', false);
	} catch(e) {
		setSidebarCollapsed(!!(sidebarMobileQuery && sidebarMobileQuery.matches), false);
	}
	if(sidebarToggle){
		sidebarToggle.addEventListener('click', function(){
			setSidebarCollapsed(!(shell && shell.classList.contains('is-sidebar-collapsed')));
		});
	}
	function syncAdvancedPanelState(save){
		if(!advancedPanel) return;
		var open = !!advancedPanel.open;
		if(advancedSummary){
			advancedSummary.setAttribute('aria-expanded', open ? 'true' : 'false');
			advancedSummary.setAttribute('title', (open ? 'Collapse' : 'Expand') + ' blueprints and advanced settings - Command/Ctrl + Shift + A');
			advancedSummary.setAttribute('aria-label', (open ? 'Collapse' : 'Expand') + ' blueprints and advanced settings - Command/Ctrl + Shift + A');
			advancedSummary.setAttribute('aria-keyshortcuts', 'Meta+Shift+A Control+Shift+A');
			advancedSummary.setAttribute('aria-controls', 'ol-advanced-panel');
			advancedSummary.setAttribute('aria-describedby', 'ol-advanced-status');
		}
		if(advancedBody) advancedBody.setAttribute('aria-hidden', open ? 'false' : 'true');
		if(!open && advancedSummary && advancedPanel.contains(document.activeElement)){
			advancedSummary.focus();
		}
		if(save){
			try { if(window.localStorage) window.localStorage.setItem(ADVANCED_KEY, open ? '1' : '0'); } catch(e) {}
		}
	}
	if(advancedPanel){
		try {
			var savedAdvanced = window.localStorage && window.localStorage.getItem(ADVANCED_KEY);
			if(savedAdvanced === '1' || (savedAdvanced === '0' && !advancedPanel.open)) advancedPanel.open = savedAdvanced === '1';
		} catch(e) {}
		advancedPanel.addEventListener('toggle', function(){ syncAdvancedPanelState(true); });
		syncAdvancedPanelState(false);
	}
	function toggleAdvancedPanel(){
		if(!advancedPanel) return;
		advancedPanel.open = !advancedPanel.open;
		syncAdvancedPanelState(true);
		if(advancedSummary) advancedSummary.focus();
	}
	function openAdvancedPanel(){
		if(!advancedPanel) return;
		if(!advancedPanel.open) advancedPanel.open = true;
		syncAdvancedPanelState(true);
	}
	if(ACTIVE_CHAT_ID && window.history && window.history.replaceState){
		var u = new URL(window.location.href);
		if(!u.searchParams.get('chat')){
			u.searchParams.set('chat', ACTIVE_CHAT_ID);
			u.searchParams.delete('new');
			window.history.replaceState(null, '', u.toString());
		}
	} else if(!ACTIVE_CHAT_ID && window.history && window.history.replaceState) {
		var clean = new URL(window.location.href);
		if(clean.searchParams.get('chat')){
			clean.searchParams.delete('chat');
			window.history.replaceState(null, '', clean.toString());
		}
	}
	var ta = document.querySelector('.ol-composer .ol-prompt');
	var draftStatus = document.querySelector('[data-draft-status]');
	var promptReadiness = document.querySelector('[data-prompt-readiness]');
	var promptCount = document.querySelector('[data-prompt-count]');
	var submitButton = document.querySelector('.ol-composer button[name="submit_generate"]');
	var clearPromptBtn = document.querySelector('.ol-clear-prompt');
	var DRAFT_KEY = 'olivia.draft.' + (ACTIVE_CHAT_ID || 'new');
	var draftTimer = null;
	var draftStatusTimer = null;
	function storageGet(key){
		try { if(window.localStorage) return window.localStorage.getItem(key) || ''; } catch(e) {}
		var name = encodeURIComponent(key) + '=';
		var parts = document.cookie ? document.cookie.split('; ') : [];
		for(var i = 0; i < parts.length; i++){
			if(parts[i].indexOf(name) === 0) return decodeURIComponent(parts[i].slice(name.length));
		}
		return '';
	}
	function storageSet(key, value){
		try { if(window.localStorage){ window.localStorage.setItem(key, value); return; } } catch(e) {}
		document.cookie = encodeURIComponent(key) + '=' + encodeURIComponent(value.slice(0, 3900)) + '; path=/; SameSite=Lax';
	}
	function storageRemove(key){
		try { if(window.localStorage) window.localStorage.removeItem(key); } catch(e) {}
		document.cookie = encodeURIComponent(key) + '=; path=/; max-age=0; SameSite=Lax';
	}
	function draftText(text){
		if(!draftStatus) return;
		if(draftStatusTimer) clearTimeout(draftStatusTimer);
		draftStatus.setAttribute('aria-controls', 'ol-main-prompt');
		draftStatus.textContent = text || '';
		if(text) {
			draftStatusTimer = setTimeout(function(){
				if(draftStatus && draftStatus.textContent === text) draftStatus.textContent = '';
			}, 2400);
		}
	}
	function autoSizePrompt(){
		if(!ta) return;
		ta.style.height = 'auto';
		ta.style.height = Math.min(170, Math.max(58, ta.scrollHeight)) + 'px';
	}
	function referenceContextCount(){
		var hasUrl = refUrlInput && refUrlInput.value.trim() !== '';
		var hasNotes = refNotesInput && refNotesInput.value.trim() !== '';
		var fileCount = refFileInput && refFileInput.files ? refFileInput.files.length : 0;
		return (hasUrl ? 1 : 0) + (hasNotes ? 1 : 0) + fileCount;
	}
	function submitActionText(){
		var label = document.querySelector('[data-submit-label]');
		return label && label.textContent ? label.textContent : 'Generate';
	}
	function preservePromptTarget(el){
		if(el) el.setAttribute('aria-controls', 'ol-main-prompt');
	}
	function syncPromptStatus(){
		if(!ta) return;
		var len = ta.value.trim().length;
		var refs = referenceContextCount();
		var empty = len === 0 && refs === 0;
		if(promptCount){
			promptCount.textContent = len + ' char' + (len === 1 ? '' : 's');
			preservePromptTarget(promptCount);
		}
		if(promptReadiness){
			promptReadiness.textContent = len ? (len < 24 ? 'Add a little more detail' : 'Ready to generate') : (refs ? 'Reference ready; add a goal or generate' : 'Describe what to build');
			preservePromptTarget(promptReadiness);
		}
		if(submitButton){
			var action = submitActionText();
			submitButton.classList.toggle('is-empty', empty);
			submitButton.setAttribute('aria-label', empty ? action + ': add a prompt or reference first' : action);
			submitButton.setAttribute('title', empty ? 'Add a prompt or reference first - Command/Ctrl + Enter' : action + ' - Command/Ctrl + Enter');
			preservePromptTarget(submitButton);
		}
		if(clearPromptBtn){
			clearPromptBtn.classList.toggle('is-empty', len === 0);
			clearPromptBtn.disabled = len === 0;
			clearPromptBtn.setAttribute('aria-disabled', len === 0 ? 'true' : 'false');
			clearPromptBtn.setAttribute('aria-label', len ? 'Clear prompt draft' : 'Prompt is empty');
			clearPromptBtn.setAttribute('title', len ? 'Clear prompt draft — Command/Ctrl + Backspace' : 'Prompt is empty');
			preservePromptTarget(clearPromptBtn);
		}
	}
	function saveDraft(){
		if(!ta) return;
		try {
			storageSet(DRAFT_KEY, ta.value);
			draftText(ta.value.trim() ? 'Draft saved' : '');
		} catch(e) {}
	}
	function promptChanged(){
		autoSizePrompt();
		syncPromptStatus();
		syncActiveChip();
		if(draftTimer) clearTimeout(draftTimer);
		draftTimer = setTimeout(saveDraft, 350);
	}
	function clearPrompt(){
		if(!ta) return;
		ta.value = '';
		ta.dispatchEvent(new Event('input', {bubbles:true}));
		if(draftTimer) clearTimeout(draftTimer);
		storageRemove(DRAFT_KEY);
		draftText('Draft cleared');
		ta.focus();
	}
	if(ta){
		ta.addEventListener('input', promptChanged);
		try {
			var savedDraft = storageGet(DRAFT_KEY);
			if(savedDraft && !ta.value.trim()){
				ta.value = savedDraft;
				ta.dispatchEvent(new Event('input', {bubbles:true}));
				if(draftTimer) clearTimeout(draftTimer);
				draftText('Draft restored');
			}
		} catch(e) {}
		autoSizePrompt();
		syncPromptStatus();
	}
	if(clearPromptBtn) clearPromptBtn.addEventListener('click', clearPrompt);
	function syncActiveChip(){
		var value = ta ? ta.value : '';
		document.querySelectorAll('.ol-chip').forEach(function(c){
			var active = value !== '' && value === (c.getAttribute('data-fill') || '');
			c.classList.toggle('is-active', active);
			c.setAttribute('aria-pressed', active ? 'true' : 'false');
			c.setAttribute('aria-controls', 'ol-main-prompt');
		});
	}
	document.querySelectorAll('.ol-chip').forEach(function(c){
		c.addEventListener('click', function(){
			if(!ta) return;
			ta.value = c.getAttribute('data-fill');
			ta.dispatchEvent(new Event('input', {bubbles:true}));
			ta.focus();
			if(draftTimer) clearTimeout(draftTimer);
			saveDraft();
			draftText('Example loaded');
		});
	});
	syncActiveChip();
	document.querySelectorAll('.ol-msg-use').forEach(function(btn){
		btn.setAttribute('aria-controls', 'ol-main-prompt');
		btn.addEventListener('click', function(){
			if(!ta) return;
			var restoreMode = btn.getAttribute('data-mode') || '';
			if(restoreMode) chooseMode(restoreMode);
			ta.value = btn.getAttribute('data-fill') || '';
			ta.dispatchEvent(new Event('input', {bubbles:true}));
			ta.focus();
			if(draftTimer) clearTimeout(draftTimer);
			saveDraft();
			draftText('Prompt restored to ' + (MODE_LABELS[restoreMode] || 'Direct'));
			ta.scrollIntoView({block:'center', behavior:'smooth'});
		});
	});

	var refOpen = document.querySelector('.ol-ref-open');
	var helpOpeners = document.querySelectorAll('[data-help-open]');
	var helpOpen = helpOpeners[0] || null;
	var helpToggle = document.getElementById('ol-help-toggle');
	var helpModal = document.getElementById('ol-help-modal');
	var helpClose = document.querySelector('.ol-help-close');
	var helpBackdrop = document.querySelector('.ol-help-backdrop');
	var refLabel = document.querySelector('[data-ref-label]');
	var refSummary = document.querySelector('[data-ref-summary]');
	var refToggle = document.getElementById('ol-ref-toggle');
	var refModal = document.getElementById('ol-ref-modal');
	var refClose = document.querySelector('.ol-ref-close');
	var refBackdrop = document.querySelector('.ol-ref-backdrop');
	var fileLabel = document.querySelector('[data-file-label]');
	var filePill = document.querySelector('.ol-file-pill');
	var fileClear = document.querySelector('.ol-file-clear');
	var fileDrop = document.querySelector('[data-ref-drop]');
	var fileCountLabel = document.querySelector('[data-file-count]');
	var filePreviews = document.querySelector('[data-ref-previews]');
	var filePreviewUrls = [];
	var refUrlInput = document.querySelector('input[name="olivia_reference_url"]');
	var refNotesInput = document.querySelector('textarea[name="olivia_reference_notes"]');
	var webSearchInput = document.getElementById('ol-web-search');
	var refFileInput = document.getElementById('ol-ref-image');
	var refClear = document.querySelector('.ol-ref-clear');
	var refDone = document.querySelector('.ol-ref-done');
	var refDetail = document.querySelector('[data-ref-detail]');
	var captureState = document.querySelector('[data-capture-state]');
	var lastReferenceTrigger = refOpen;
	function compactName(name){
		name = name || '';
		return name.length > 30 ? name.slice(0, 14) + '...' + name.slice(-12) : name;
	}
	function referenceUrlLabel(value){
		value = (value || '').trim();
		if(!value) return '';
		try {
			var url = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : 'https://' + value);
			return compactName(url.hostname.replace(/^www\./, '') || value);
		} catch(e) {
			return 'URL';
		}
	}
	function emitInput(el){
		if(el) el.dispatchEvent(new Event('input', {bubbles:true}));
	}
	function emitChange(el){
		if(el) el.dispatchEvent(new Event('change', {bubbles:true}));
	}
	function acceptedReferenceFiles(files){
		return Array.prototype.slice.call(files || []).filter(function(file){ return /^image\/(png|jpeg|webp|gif)$/i.test(file.type || ''); }).slice(0, 4);
	}
	function setReferenceFiles(files){
		if(!refFileInput || typeof DataTransfer === 'undefined') return false;
		var transfer = new DataTransfer();
		acceptedReferenceFiles(files).forEach(function(file){ transfer.items.add(file); });
		refFileInput.files = transfer.files;
		emitChange(refFileInput);
		return true;
	}
	function renderReferencePreviews(){
		filePreviewUrls.forEach(function(url){ try { URL.revokeObjectURL(url); } catch(e) {} });
		filePreviewUrls = [];
		if(!filePreviews || !refFileInput) return;
		var files = Array.prototype.slice.call(refFileInput.files || []);
		filePreviews.innerHTML = '';
		files.forEach(function(file, index){
			var item = document.createElement('span'); item.className = 'ol-ref-preview'; item.setAttribute('role','listitem');
			var url = URL.createObjectURL(file); filePreviewUrls.push(url);
			var img = document.createElement('img'); img.src = url; img.alt = ''; img.setAttribute('aria-hidden','true'); item.appendChild(img);
			var name = document.createElement('span'); name.className = 'ol-ref-preview-name'; name.textContent = compactName(file.name); item.appendChild(name);
			var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'ol-ref-preview-remove'; remove.innerHTML = '<i class="ri-close-line" aria-hidden="true"></i>'; remove.setAttribute('title','Remove ' + file.name); remove.setAttribute('aria-label','Remove reference image ' + file.name); remove.setAttribute('aria-controls','ol-ref-image ol-ref-detail');
			remove.addEventListener('click', function(){ setReferenceFiles(files.filter(function(_, i){ return i !== index; })); }); item.appendChild(remove);
			filePreviews.appendChild(item);
		});
		filePreviews.hidden = files.length === 0;
		if(fileCountLabel) fileCountLabel.textContent = files.length + ' / 4';
	}
	function syncReferenceState(){
		var hasUrl = refUrlInput && refUrlInput.value.trim() !== '';
		var hasNotes = refNotesInput && refNotesInput.value.trim() !== '';
		var hasWebSearch = !!(webSearchInput && webSearchInput.checked);
		var fileCount = refFileInput && refFileInput.files ? refFileInput.files.length : 0;
		var fileName = fileCount ? refFileInput.files[0].name : '';
		var fileSummary = fileCount > 1 ? fileCount + ' images' : (fileName ? compactName(fileName) : '');
		var count = (hasUrl ? 1 : 0) + (hasNotes ? 1 : 0) + (hasWebSearch ? 1 : 0) + fileCount;
		if(captureState){
			var captureReady = captureState.getAttribute('data-capture-ready') === '1';
			var captureText = fileCount ? 'Pixel analysis: ' + fileCount + ' uploaded image' + (fileCount === 1 ? '' : 's') + '.'
				: (hasUrl ? (captureReady ? 'Browser capture configured for this URL.' : 'HTML brief only; attach images for pixel-level analysis.')
					: (captureReady ? 'Browser capture is configured for URL-only references.' : 'Attach images for pixel-level analysis.'));
			var captureLabel = captureState.querySelector('span'); if(captureLabel) captureLabel.textContent = captureText;
			captureState.setAttribute('aria-controls','ol-ref-url ol-ref-image');
			captureState.setAttribute('aria-describedby','ol-ref-desc');
		}
		if(refUrlInput) refUrlInput.setAttribute('aria-controls', 'ol-ref-detail');
		if(refNotesInput) refNotesInput.setAttribute('aria-controls', 'ol-ref-detail');
		if(refLabel) refLabel.textContent = count ? 'Reference added' : 'Reference';
		if(refOpen) refOpen.classList.toggle('has-reference', count > 0);
		if(fileLabel) fileLabel.textContent = fileSummary || 'Attach or drop references';
		renderReferencePreviews();
		if(filePill){
			var filePillLabel = fileCount ? 'Reference images: ' + fileSummary : 'Attach up to four reference images';
			filePill.setAttribute('aria-label', filePillLabel);
			filePill.setAttribute('title', filePillLabel);
			filePill.setAttribute('aria-describedby', 'ol-ref-desc ol-ref-detail');
			filePill.setAttribute('aria-controls', 'ol-ref-image ol-ref-detail');
		}
		if(fileClear) fileClear.hidden = fileCount === 0;
		if(fileClear && fileCount){
			fileClear.setAttribute('aria-label', 'Remove reference images: ' + fileSummary);
			fileClear.setAttribute('title', 'Remove reference images: ' + fileSummary);
			fileClear.setAttribute('aria-describedby', 'ol-ref-detail');
			fileClear.setAttribute('aria-controls', 'ol-ref-image ol-ref-detail');
		}
		if(refClear){
			refClear.disabled = count === 0;
			refClear.setAttribute('aria-disabled', count === 0 ? 'true' : 'false');
			refClear.setAttribute('aria-label', count ? 'Clear all reference context' : 'No reference to clear');
			refClear.setAttribute('title', count ? 'Clear all reference context' : 'No reference to clear');
			refClear.setAttribute('aria-describedby', 'ol-ref-detail');
			refClear.setAttribute('aria-controls', 'ol-ref-url ol-ref-image ol-ref-notes ol-ref-detail');
		}
		var parts = [];
		if(hasUrl) parts.push(referenceUrlLabel(refUrlInput.value));
		if(fileCount) parts.push(fileCount === 1 ? 'image' : fileCount + ' images');
		if(hasNotes) parts.push('notes');
		if(hasWebSearch) parts.push('web research');
		var summary = parts.join(', ');
		if(refOpen){
			refOpen.setAttribute('title', parts.length ? 'Edit reference: ' + summary + ' — Command/Ctrl + Shift + R' : 'Add reference — Command/Ctrl + Shift + R');
			refOpen.setAttribute('aria-label', parts.length ? 'Edit reference: ' + summary : 'Add reference');
			refOpen.setAttribute('aria-controls', 'ol-ref-modal');
			refOpen.setAttribute('aria-describedby', 'ol-ref-desc ol-ref-detail');
		}
		if(refSummary){
			refSummary.textContent = parts.length ? 'Reference: ' + summary : '';
			refSummary.hidden = parts.length === 0;
			refSummary.setAttribute('aria-label', parts.length ? 'Edit reference: ' + summary : 'Add reference');
			refSummary.setAttribute('title', parts.length ? 'Edit reference: ' + summary : 'Add reference');
			refSummary.setAttribute('aria-controls', 'ol-ref-modal');
			refSummary.setAttribute('aria-describedby', 'ol-ref-desc ol-ref-detail');
		}
		if(refDetail){
			refDetail.textContent = parts.length ? 'Using ' + summary + '.' : 'No reference added.';
			refDetail.setAttribute('aria-controls', 'ol-ref-url ol-ref-image ol-ref-notes');
		}
		syncPromptStatus();
	}
	function syncReferenceOpenState(){
		var open = !!(refToggle && refToggle.checked);
		if(refModal) refModal.setAttribute('aria-hidden', open ? 'false' : 'true');
		if(refOpen){
			refOpen.setAttribute('aria-expanded', open ? 'true' : 'false');
			refOpen.setAttribute('aria-haspopup', 'dialog');
			refOpen.setAttribute('aria-controls', 'ol-ref-modal');
			refOpen.setAttribute('aria-describedby', 'ol-ref-desc ol-ref-detail');
		}
		if(refSummary){
			refSummary.setAttribute('aria-expanded', open ? 'true' : 'false');
			refSummary.setAttribute('aria-haspopup', 'dialog');
			refSummary.setAttribute('aria-controls', 'ol-ref-modal');
			refSummary.setAttribute('aria-describedby', 'ol-ref-desc ol-ref-detail');
		}
	}
	function normalizeReferenceUrl(){
		if(!refUrlInput) return false;
		var value = refUrlInput.value.trim();
		if(!value || /^[a-z][a-z0-9+.-]*:\/\//i.test(value)) return false;
		if(/^[a-z0-9.-]+\.[a-z]{2,}([/:?#].*)?$/i.test(value)){
			refUrlInput.value = 'https://' + value;
			emitInput(refUrlInput);
			return true;
		}
		return false;
	}
	[refUrlInput, refNotesInput].forEach(function(el){
		if(el) el.addEventListener('input', syncReferenceState);
	});
	if(webSearchInput) webSearchInput.addEventListener('change', syncReferenceState);
	if(refUrlInput) refUrlInput.addEventListener('blur', function(){
		if(normalizeReferenceUrl()) syncReferenceState();
	});
	if(refFileInput) refFileInput.addEventListener('change', syncReferenceState);
	if(fileDrop){
		['dragenter','dragover'].forEach(function(type){ fileDrop.addEventListener(type, function(e){ e.preventDefault(); fileDrop.classList.add('is-dragover'); }); });
		['dragleave','drop'].forEach(function(type){ fileDrop.addEventListener(type, function(e){ e.preventDefault(); fileDrop.classList.remove('is-dragover'); }); });
		fileDrop.addEventListener('drop', function(e){ if(e.dataTransfer && e.dataTransfer.files) setReferenceFiles(Array.prototype.slice.call(refFileInput.files || []).concat(Array.prototype.slice.call(e.dataTransfer.files))); });
	}
	if(refModal) refModal.addEventListener('paste', function(e){
		var files = e.clipboardData && e.clipboardData.files ? acceptedReferenceFiles(e.clipboardData.files) : [];
		if(files.length){ e.preventDefault(); setReferenceFiles(Array.prototype.slice.call(refFileInput.files || []).concat(files)); }
	});
	if(filePill) filePill.addEventListener('keydown', function(e){
		if(e.key === 'Enter' || e.key === ' '){
			e.preventDefault();
			if(refFileInput) refFileInput.click();
		}
	});
	if(fileClear) fileClear.addEventListener('click', function(){
		if(refFileInput) refFileInput.value = '';
		emitChange(refFileInput);
		if(filePill) filePill.focus();
	});
	if(refToggle) refToggle.addEventListener('change', syncReferenceOpenState);
	if(refClear) refClear.addEventListener('click', function(){
		if(refUrlInput) refUrlInput.value = '';
		if(refNotesInput) refNotesInput.value = '';
		if(refFileInput) refFileInput.value = '';
		if(webSearchInput) webSearchInput.checked = false;
		emitInput(refUrlInput);
		emitInput(refNotesInput);
		emitChange(refFileInput);
		emitChange(webSearchInput);
		if(refUrlInput) refUrlInput.focus();
	});
	function closeReferencePanel(){
		if(normalizeReferenceUrl()) syncReferenceState();
		setChecked('ol-ref-toggle', false);
		syncReferenceOpenState();
		if(lastReferenceTrigger) setTimeout(function(){ lastReferenceTrigger.focus(); }, 0);
	}
	if(refDone) refDone.addEventListener('click', closeReferencePanel);
	[refClose, refBackdrop].forEach(function(el){
		if(el) el.addEventListener('click', function(e){
			e.preventDefault();
			closeReferencePanel();
		});
	});
	if(refClose) refClose.addEventListener('keydown', function(e){
		if(e.key === 'Enter' || e.key === ' '){
			e.preventDefault();
			closeReferencePanel();
		}
	});
	syncReferenceState();
	syncReferenceOpenState();
	function focusReferenceFirstField(){
		var target = (refUrlInput && !refUrlInput.value.trim()) ? refUrlInput : refNotesInput;
		if(target) setTimeout(function(){ target.focus(); if(target.select && target.value) target.select(); }, 0);
	}
	function openReferencePanel(trigger){
		if(trigger) lastReferenceTrigger = trigger;
		setChecked('ol-ref-toggle', true);
		syncReferenceOpenState();
		focusReferenceFirstField();
	}
	function isReferenceOpen(){
		var el = document.getElementById('ol-ref-toggle');
		return !!(el && el.checked);
	}
	if(refOpen) refOpen.addEventListener('click', function(e){
		e.preventDefault();
		openReferencePanel(refOpen);
	});
	if(refOpen) refOpen.addEventListener('keydown', function(e){
		if(e.key === 'Enter' || e.key === ' '){
			e.preventDefault();
			openReferencePanel(refOpen);
		}
	});
	if(refSummary) refSummary.addEventListener('click', function(){ openReferencePanel(refSummary); });

	var lastHelpTrigger = helpOpen;
	function syncHelpOpenState(){
		var open = !!(helpToggle && helpToggle.checked);
		if(helpModal) helpModal.setAttribute('aria-hidden', open ? 'false' : 'true');
		helpOpeners.forEach(function(opener){
			opener.setAttribute('aria-expanded', open ? 'true' : 'false');
			opener.setAttribute('aria-haspopup', 'dialog');
			opener.setAttribute('aria-controls', 'ol-help-modal');
			opener.setAttribute('aria-describedby', 'ol-help-desc ol-help-note');
		});
	}
	function closeHelpPanel(){
		var wasOpen = !!(helpToggle && helpToggle.checked);
		setChecked('ol-help-toggle', false);
		syncHelpOpenState();
		if(wasOpen && lastHelpTrigger) setTimeout(function(){ lastHelpTrigger.focus(); }, 0);
	}
	function openHelpPanel(trigger){
		if(trigger) lastHelpTrigger = trigger;
		setChecked('ol-help-toggle', true);
		syncHelpOpenState();
		if(helpClose) setTimeout(function(){ helpClose.focus(); }, 0);
	}
	if(helpToggle) helpToggle.addEventListener('change', syncHelpOpenState);
	helpOpeners.forEach(function(opener){
		opener.addEventListener('click', function(e){
			e.preventDefault();
			openHelpPanel(opener);
		});
	});
	[helpClose, helpBackdrop].forEach(function(el){
		if(el) el.addEventListener('click', function(e){
			e.preventDefault();
			closeHelpPanel();
		});
	});
	syncHelpOpenState();

	function isTypingTarget(el){
		if(!el) return false;
		var tag = (el.tagName || '').toLowerCase();
		return el.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select';
	}
	function isComposerShortcutContext(el){
		return !isTypingTarget(el)
			|| el === ta
			|| el === planTextarea
			|| (el && el.closest && (el.closest('.ol-modes') || el.closest('.ol-json-actions') || el.closest('.ol-json-badges')));
	}
	function isMainShortcutContext(el){
		return !isTypingTarget(el) || el === ta;
	}
	function isModeShortcutContext(el){
		return isMainShortcutContext(el) || (el && el.closest && el.closest('.ol-modes'));
	}
	function isMod(e){ return e.metaKey || e.ctrlKey; }
	function setChecked(id, value){
		var el = document.getElementById(id);
		if(el) el.checked = !!value;
	}
	function focusPrompt(selectText){
		if(!ta) return;
		ta.focus();
		if(selectText && ta.select) ta.select();
	}
	var MODE_LABELS = {$modeLabelsJs};
	var MODE_COPY = {$modeCopyJs};
	var MODE_VALUES = {$modeValuesJs};
	var MODE_BY_KEY = {};
	MODE_VALUES.forEach(function(mode, idx){ MODE_BY_KEY[String(idx + 1)] = mode; });
	function syncModeBadge(){
		var checked = document.querySelector('.ol-composer input[name="olivia_mode"]:checked');
		var badge = document.querySelector('[data-mode-badge]');
		var mode = checked ? checked.value : 'direct';
		var copy = MODE_COPY[mode] || MODE_COPY.direct;
		if(badge){
			var modeLabel = MODE_LABELS[mode] || mode;
			badge.textContent = modeLabel;
			badge.setAttribute('aria-label', 'Current mode: ' + modeLabel);
			badge.setAttribute('title', 'Current mode: ' + modeLabel);
			badge.setAttribute('aria-controls', 'ol-main-prompt');
		}
		var promptTitle = document.querySelector('[data-prompt-title]');
		if(promptTitle) promptTitle.textContent = copy.title;
		if(ta){
			ta.setAttribute('placeholder', copy.placeholder);
		}
		var submitLabel = document.querySelector('[data-submit-label]');
		if(submitLabel) submitLabel.textContent = copy.submit;
		if(submitButton){
			submitButton.setAttribute('aria-label', copy.submit);
			submitButton.setAttribute('title', copy.submit + ' — Command/Ctrl + Enter');
			preservePromptTarget(submitButton);
		}
		syncPromptStatus();
		document.querySelectorAll('.ol-mode').forEach(function(card){
			var input = card.querySelector('input[name="olivia_mode"]');
			var active = !!(input && input.checked);
			card.classList.toggle('is-active', active);
			card.setAttribute('aria-checked', active ? 'true' : 'false');
			card.setAttribute('tabindex', active ? '0' : '-1');
			preservePromptTarget(card);
		});
	}
	function chooseMode(mode){
		var el = document.querySelector('.ol-composer input[name="olivia_mode"][value="' + mode + '"]');
		if(!el) return false;
		el.checked = true;
		el.dispatchEvent(new Event('change', {bubbles:true}));
		return true;
	}
	document.querySelectorAll('.ol-composer input[name="olivia_mode"]').forEach(function(el){
		el.addEventListener('change', syncModeBadge);
	});
	var modesWrap = document.querySelector('.ol-modes');
	if(modesWrap){
		modesWrap.addEventListener('click', function(e){
			var card = e.target && e.target.closest ? e.target.closest('.ol-mode') : null;
			if(!card) return;
			var cardInput = card.querySelector('input[name="olivia_mode"]');
			if(cardInput && chooseMode(cardInput.value)){
				e.preventDefault();
				card.focus();
			}
		});
		modesWrap.addEventListener('keydown', function(e){
			var card = e.target && e.target.closest ? e.target.closest('.ol-mode') : null;
			if((e.key === 'Enter' || e.key === ' ') && card){
				var cardInput = card.querySelector('input[name="olivia_mode"]');
				if(cardInput && chooseMode(cardInput.value)){
					e.preventDefault();
					card.focus();
				}
				return;
			}
			var keys = ['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown', 'Home', 'End'];
			if(keys.indexOf(e.key) === -1) return;
			var values = MODE_VALUES;
			var checked = document.querySelector('.ol-composer input[name="olivia_mode"]:checked');
			var current = checked ? values.indexOf(checked.value) : 0;
			var next = current + ((e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1);
			if(e.key === 'Home') next = 0;
			if(e.key === 'End') next = values.length - 1;
			if(next < 0) next = values.length - 1;
			if(next >= values.length) next = 0;
			if(chooseMode(values[next])){
				e.preventDefault();
				var focused = document.querySelector('.ol-composer input[name="olivia_mode"][value="' + values[next] + '"]');
				if(focused && focused.closest('.ol-mode')) focused.closest('.ol-mode').focus();
			}
		});
	}
	syncModeBadge();
	function syncChatMenuState(menu){
		if(!menu) return;
		var summary = menu.querySelector('summary');
		var popover = menu.querySelector('.ol-chat-menu-pop');
		if(summary) {
			summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
			summary.setAttribute('aria-haspopup', 'true');
			if(popover && popover.id) summary.setAttribute('aria-controls', popover.id);
		}
	}
	function closeOpenChatMenus(except){
		document.querySelectorAll('.ol-chat-menu[open]').forEach(function(menu){
			if(menu !== except){
				menu.open = false;
				syncChatMenuState(menu);
			}
		});
	}
	document.querySelectorAll('.ol-chat-menu').forEach(function(menu){
		syncChatMenuState(menu);
		menu.addEventListener('toggle', function(){
			if(menu.open) closeOpenChatMenus(menu);
			syncChatMenuState(menu);
		});
	});
	document.addEventListener('click', function(e){
		if(e.target && e.target.closest && e.target.closest('.ol-chat-menu')) return;
		closeOpenChatMenus();
	});
	document.addEventListener('keydown', function(e){
		var key = e.key || '';
		var typing = isTypingTarget(e.target);
		if(key === 'Escape'){
			closeOpenChatMenus();
			closeHelpPanel();
			if(isReferenceOpen()) closeReferencePanel();
			return;
		}
		if(!isMod(e) && !typing && (key === '?' || (key === '/' && e.shiftKey))){
			e.preventDefault();
			openHelpPanel(document.activeElement || helpOpen);
			return;
		}
		if(!isMod(e)) return;
		var lower = key.toLowerCase();
		if((key === 'Backspace' || key === 'Delete') && !e.shiftKey && !e.altKey && (!typing || e.target === ta)){
			e.preventDefault();
			clearPrompt();
			return;
		}
		if(MODE_BY_KEY[key] && !e.shiftKey && !e.altKey && isModeShortcutContext(e.target)){
			if(chooseMode(MODE_BY_KEY[key])){
				e.preventDefault();
				return;
			}
		}
		if(key === '\\\\' && !e.shiftKey && !e.altKey && isMainShortcutContext(e.target)){
			e.preventDefault();
			setSidebarCollapsed(!(shell && shell.classList.contains('is-sidebar-collapsed')));
			return;
		}
		if(lower === 'enter'){
			if(isReferenceOpen()){
				e.preventDefault();
				closeReferencePanel();
				return;
			}
			if(!isMainShortcutContext(e.target)) return;
			var form = document.querySelector('.ol-composer');
			var btn = form ? form.querySelector('button[name="submit_generate"]') : null;
			if(form && btn){
				e.preventDefault();
				if(form.requestSubmit) form.requestSubmit(btn);
				else btn.click();
			}
			return;
		}
		if(lower === 'k'){
			e.preventDefault();
			focusPrompt(!typing);
			return;
		}
		if(!e.shiftKey) return;
		if(lower === 'n' && isMainShortcutContext(e.target)){
			e.preventDefault();
			window.location = './?new=1';
			return;
		}
		if(lower === 'f' && isMainShortcutContext(e.target)){
			var search = document.querySelector('.ol-chat-search-input');
			if(search){ e.preventDefault(); search.focus(); if(search.select) search.select(); }
			return;
		}
		if(lower === 'r' && isMainShortcutContext(e.target)){
			e.preventDefault();
			openReferencePanel(refOpen);
			return;
		}
		if(lower === 'a' && isMainShortcutContext(e.target)){
			e.preventDefault();
			toggleAdvancedPanel();
			return;
		}
		if(lower === 'c' && isComposerShortcutContext(e.target)){
			if(copyPlanJson()){
				e.preventDefault();
				return;
			}
		}
		if(lower === 'p' && isComposerShortcutContext(e.target)){
			if(formatPlanJson()){
				e.preventDefault();
				return;
			}
		}
		if(lower === 'z' && isComposerShortcutContext(e.target)){
			if(undoPlanEdit()){
				e.preventDefault();
				return;
			}
		}
	}, true);

	var chatSearch = document.querySelector('.ol-chat-search-input');
	var chatSearchWrap = document.querySelector('.ol-chat-search');
	var chatClearSearch = document.querySelector('.ol-chat-clear-search');
	if(chatSearchWrap){
		chatSearchWrap.addEventListener('click', function(){
			if(shell && shell.classList.contains('is-sidebar-collapsed')){
				setSidebarCollapsed(false);
				if(chatSearch) setTimeout(function(){ chatSearch.focus(); }, 0);
			}
		});
	}
	if(chatSearch){
		function filterChats(){
			var q = chatSearch.value.trim().toLowerCase();
			var shown = 0;
			var total = 0;
			document.querySelectorAll('.ol-chatrow').forEach(function(item){
				total++;
				var ok = !q || (item.getAttribute('data-search') || '').indexOf(q) !== -1;
				item.hidden = !ok;
				if(ok) shown++;
			});
			document.querySelectorAll('.ol-chat-group').forEach(function(group){
				var next = group.nextElementSibling;
				var visible = false;
				while(next && !next.classList.contains('ol-chat-group')){
					if(next.classList && next.classList.contains('ol-chatrow') && !next.hidden){ visible = true; break; }
					next = next.nextElementSibling;
				}
				group.hidden = !visible;
			});
			var empty = document.querySelector('.ol-chat-noresults');
			if(empty) empty.hidden = !q || shown !== 0;
			var queryLabel = document.querySelector('[data-chat-query]');
			if(queryLabel) queryLabel.textContent = q ? '"' + chatSearch.value.trim() + '"' : '';
			var totalLabel = total + ' ' + (total === 1 ? 'chat' : 'chats');
			var count = document.querySelector('[data-chat-count]');
			if(count){
				var countLabel = q ? (shown + ' of ' + totalLabel) : totalLabel;
				count.textContent = q ? shown + '/' + total : (count.getAttribute('data-chat-count') || total);
				count.setAttribute('title', countLabel);
				count.setAttribute('aria-label', countLabel);
				count.setAttribute('aria-controls', 'ol-saved-chats');
				count.setAttribute('aria-describedby', 'ol-chatlist-title ol-chat-search-status');
			}
			var searchStatus = document.querySelector('[data-chat-search-status]');
			var matchVerb = shown === 1 ? 'matches' : 'match';
			if(searchStatus) searchStatus.textContent = q ? (shown + ' of ' + totalLabel + ' ' + matchVerb + ' "' + chatSearch.value.trim() + '".') : ('Showing ' + totalLabel + '.');
		}
		chatSearch.addEventListener('input', filterChats);
		chatSearch.addEventListener('keydown', function(e){
			if(e.key === 'Escape' && chatSearch.value){
				e.preventDefault();
				chatSearch.value = '';
				filterChats();
			}
		});
		if(chatClearSearch) chatClearSearch.addEventListener('click', function(){
			chatSearch.value = '';
			filterChats();
			chatSearch.focus();
		});
		if(chatClearSearch) chatClearSearch.addEventListener('keydown', function(e){
			if(e.key === 'Escape'){
				e.preventDefault();
				chatSearch.value = '';
				filterChats();
				chatSearch.focus();
			}
		});
		if(chatClearSearch) chatClearSearch.setAttribute('aria-describedby', 'ol-chat-search-status');
		filterChats();
	}

	// theme palette swatches set the colour input
	var colorInput = document.querySelector('input[name="olivia_theme_primary"]');
	var fontSelect = document.querySelector('select[name="olivia_theme_font"]');
	var themeFontOverrideInput = document.querySelector('input[name="olivia_theme_font_override"]');
	var themePrimaryOverrideInput = document.querySelector('input[name="olivia_theme_primary_override"]');
	var blueprintSelect = document.querySelector('select[name="olivia_blueprint"]');
	var planTextarea = document.querySelector('textarea[name="olivia_plan"]');
	var advStatus = document.querySelector('[data-adv-status]');
	var copyPlanButton = document.querySelector('.ol-copy-plan');
	var formatPlanButton = document.querySelector('.ol-format-plan');
	var undoPlanButton = document.querySelector('.ol-undo-plan');
	var previewPlanButton = document.querySelector('.ol-preview-plan');
	var buildPlanButton = document.querySelector('.ol-build-plan');
	var copyPlanStatus = document.querySelector('[data-copy-plan-status]');
	var jsonStatus = document.querySelector('[data-json-status]');
	var jsonSite = document.querySelector('[data-json-site]');
	var jsonSize = document.querySelector('[data-json-size]');
	var jsonShape = document.querySelector('[data-json-shape]');
	var jsonHint = document.querySelector('[data-json-hint]');
	var designSystem = document.querySelector('[data-design-system]');
	var designTokens = document.getElementById('ol-design-tokens');
	var copyThemeButton = document.querySelector('.ol-copy-theme');
	var copyThemeStatus = document.querySelector('[data-copy-theme-status]');
	var currentDesignTheme = null;
	var planJsonValid = false;
	var planSchemaHintActive = false;
	var planSchemaMissingKeys = [];
	var planUndoValue = null;
	var planLastValue = planTextarea ? planTextarea.value : '';
	var planBeforeInputPending = false;
	function preserveJsonJumpTarget(el){
		if(!el) return;
		el.setAttribute('aria-controls', 'ol-plan-json');
		el.setAttribute('aria-describedby', 'ol-json-diagnostics-help');
	}
	function preserveJsonActionReferences(button){
		if(!button) return;
		button.setAttribute('aria-controls', 'ol-plan-json');
		button.setAttribute('aria-describedby', 'ol-json-action-help ol-json-action-status');
	}
	function preserveJsonActionStatusReferences(){
		if(!copyPlanStatus) return;
		copyPlanStatus.setAttribute('aria-controls', 'ol-plan-json');
		copyPlanStatus.setAttribute('aria-describedby', 'ol-json-action-help');
	}
	function selectText(select, fallback){
		if(!select) return fallback;
		var opt = select.options[select.selectedIndex];
		var text = opt ? opt.textContent : '';
		text = text.split(' — ')[0].trim();
		return text || fallback;
	}
	function syncAdvancedStatus(){
		if(!advStatus) return;
		var font = selectText(fontSelect, 'Auto theme');
		if(font === 'Auto') font = 'Auto theme';
		var color = colorInput && colorInput.value ? colorInput.value.toUpperCase() : 'No colour';
		var blueprint = selectText(blueprintSelect, 'Sample');
		var plan = planTextarea && planTextarea.value.trim() ? 'Plan loaded' : 'No JSON';
		advStatus.textContent = font + ' · ' + color + ' · ' + blueprint + ' · ' + plan;
		advStatus.setAttribute('aria-controls', 'ol-theme-font ol-theme-primary ol-blueprint-select ol-plan-json');
		[fontSelect, colorInput, blueprintSelect].forEach(function(el){
			if(el) el.setAttribute('aria-controls', 'ol-advanced-status');
		});
		if(copyPlanButton){
			copyPlanButton.disabled = !(planTextarea && planTextarea.value.trim());
			copyPlanButton.setAttribute('aria-disabled', copyPlanButton.disabled ? 'true' : 'false');
			copyPlanButton.setAttribute('title', copyPlanButton.disabled ? 'No build plan JSON to copy' : 'Copy editable build plan JSON - Command/Ctrl + Shift + C');
			copyPlanButton.setAttribute('aria-label', copyPlanButton.disabled ? 'No build plan JSON to copy' : 'Copy editable build plan JSON - Command/Ctrl + Shift + C');
			copyPlanButton.setAttribute('aria-keyshortcuts', 'Meta+Shift+C Control+Shift+C');
			preserveJsonActionReferences(copyPlanButton);
		}
	}
	function syncJsonStatus(){
		if(!jsonStatus || !planTextarea) return;
		var text = planTextarea.value.trim();
		jsonStatus.classList.remove('is-ok', 'is-bad');
		if(!text){
			planJsonValid = false;
			jsonStatus.textContent = 'No JSON';
			jsonStatus.setAttribute('title', 'No build plan JSON loaded');
			jsonStatus.setAttribute('aria-label', 'No build plan JSON loaded; focus editable JSON');
			preserveJsonJumpTarget(jsonStatus);
			syncJsonSite(null);
			syncJsonShape(null);
			syncJsonHint(null);
			syncDesignSystem(null);
			syncFormatPlanButton();
			return;
		}
		try {
			var plan = JSON.parse(text);
			planJsonValid = true;
			jsonStatus.textContent = 'Valid JSON';
			jsonStatus.classList.add('is-ok');
			jsonStatus.setAttribute('title', 'Build plan JSON parses successfully');
			jsonStatus.setAttribute('aria-label', 'Build plan JSON parses successfully; focus editable JSON');
			preserveJsonJumpTarget(jsonStatus);
			syncJsonSite(plan);
			syncJsonShape(plan);
			syncJsonHint(plan);
			syncDesignSystem(plan);
		} catch(e) {
			planJsonValid = false;
			var detail = e && e.message ? e.message : 'syntax error';
			jsonStatus.textContent = 'Invalid JSON';
			jsonStatus.classList.add('is-bad');
			jsonStatus.setAttribute('title', 'Build plan JSON has a syntax error: ' + detail);
			jsonStatus.setAttribute('aria-label', 'Build plan JSON has a syntax error: ' + detail + '; focus editable JSON');
			preserveJsonJumpTarget(jsonStatus);
			syncJsonSite(null);
			syncJsonShape(null);
			syncJsonHint(null);
			syncDesignSystem(null);
		}
		syncFormatPlanButton();
	}
	function syncDesignSystem(plan){
		if(!designSystem || !designTokens) return;
		var theme = plan && plan.site && typeof plan.site === 'object' && plan.site.theme && typeof plan.site.theme === 'object' && !Array.isArray(plan.site.theme) ? plan.site.theme : null;
		if(!theme){
			currentDesignTheme = null;
			designSystem.hidden = true;
			designTokens.innerHTML = '';
			return;
		}
		var colorKeys = ['primary','background','surface','text','muted'];
		var clean = {};
		if(theme.font) clean.font = String(theme.font);
		colorKeys.forEach(function(key){ if(/^#[0-9a-f]{6}$/i.test(String(theme[key] || ''))) clean[key] = String(theme[key]).toLowerCase(); });
		if(Number.isFinite(Number(theme.radius))) clean.radius = Math.max(0, Math.min(24, Math.round(Number(theme.radius))));
		if(Number.isFinite(Number(theme.container))) clean.container = Math.max(960, Math.min(1600, Math.round(Number(theme.container))));
		if(['compact','balanced','spacious'].indexOf(String(theme.density || '').toLowerCase()) !== -1) clean.density = String(theme.density).toLowerCase();
		if(!Object.keys(clean).length){ currentDesignTheme = null; designSystem.hidden = true; designTokens.innerHTML = ''; return; }
		currentDesignTheme = clean;
		designTokens.innerHTML = '';
		Object.keys(clean).forEach(function(key){
			var item = document.createElement('span');
			item.className = 'ol-design-token';
			item.setAttribute('role', 'listitem');
			if(colorKeys.indexOf(key) !== -1){ var swatch = document.createElement('span'); swatch.className = 'ol-design-color'; swatch.style.backgroundColor = clean[key]; swatch.setAttribute('aria-hidden', 'true'); item.appendChild(swatch); }
			var label = document.createElement('span'); label.className = 'ol-design-key'; label.textContent = key; item.appendChild(label);
			var value = document.createElement('code'); value.textContent = key === 'radius' ? clean[key] + 'px' : (key === 'container' ? clean[key] + 'px' : clean[key]); item.appendChild(value);
			designTokens.appendChild(item);
		});
		designSystem.hidden = false;
	}
	function copyDesignSystem(){
		if(!currentDesignTheme || !copyThemeButton) return;
		var text = JSON.stringify(currentDesignTheme, null, 2);
		function done(){ copyThemeButton.setAttribute('title','Design system copied'); copyThemeButton.setAttribute('aria-label','Design system copied'); if(copyThemeStatus) copyThemeStatus.textContent = 'Copied'; setTimeout(function(){ copyThemeButton.setAttribute('title','Copy design system JSON'); copyThemeButton.setAttribute('aria-label','Copy design system JSON'); if(copyThemeStatus && copyThemeStatus.textContent === 'Copied') copyThemeStatus.textContent = ''; }, 1800); }
		if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done).catch(function(){ if(copyThemeStatus) copyThemeStatus.textContent = 'Copy failed'; });
		else if(copyThemeStatus) copyThemeStatus.textContent = 'Copy unavailable';
	}
	function syncJsonHint(plan){
		if(!jsonHint) return;
		if(!plan || typeof plan !== 'object'){
			planSchemaHintActive = false;
			planSchemaMissingKeys = [];
			jsonHint.hidden = true;
			jsonHint.textContent = '';
			return;
		}
		var missing = [];
		if(!plan.site || typeof plan.site !== 'object') missing.push('site');
		if(!Array.isArray(plan.fields)) missing.push('fields');
		if(!Array.isArray(plan.templates)) missing.push('templates');
		if(!Array.isArray(plan.pages)) missing.push('pages');
		if(!missing.length){
			planSchemaHintActive = false;
			planSchemaMissingKeys = [];
			jsonHint.hidden = true;
			jsonHint.textContent = '';
			return;
		}
		planSchemaHintActive = true;
		planSchemaMissingKeys = missing;
		var label = 'Missing: ' + missing.join(', ');
		jsonHint.hidden = false;
		jsonHint.textContent = label;
		jsonHint.setAttribute('title', 'Plan JSON is valid but missing expected top-level keys: ' + missing.join(', '));
		jsonHint.setAttribute('aria-label', 'Plan JSON is valid but missing expected top-level keys: ' + missing.join(', ') + '; focus editable JSON');
		preserveJsonJumpTarget(jsonHint);
	}
	function compactText(value, max){
		value = (value || '').toString().trim();
		if(value.length <= max) return value;
		return value.slice(0, Math.max(0, max - 1)) + '…';
	}
	function syncJsonSite(plan){
		if(!jsonSite) return;
		var site = plan && typeof plan === 'object' && plan.site && typeof plan.site === 'object' ? plan.site : null;
		var title = site && site.title ? site.title.toString().trim() : '';
		var type = site && site.type ? site.type.toString().trim() : '';
		if(!title && !type){
			jsonSite.hidden = true;
			jsonSite.textContent = '';
			return;
		}
		var label = compactText(title || 'Untitled site', 24);
		if(type) label += ' · ' + compactText(type, 18);
		var full = (title || 'Untitled site') + (type ? ' · ' + type : '');
		jsonSite.hidden = false;
		jsonSite.textContent = label;
		jsonSite.setAttribute('title', 'Plan site: ' + full);
		jsonSite.setAttribute('aria-label', 'Plan site: ' + full + '; focus editable JSON');
		preserveJsonJumpTarget(jsonSite);
	}
	function syncJsonShape(plan){
		if(!jsonShape) return;
		if(!plan || typeof plan !== 'object'){
			jsonShape.hidden = true;
			jsonShape.textContent = '';
			return;
		}
		function pageCount(items){
			if(!Array.isArray(items)) return 0;
			return items.reduce(function(total, page){
				return total + 1 + pageCount(page && page.children);
			}, 0);
		}
		var fields = Array.isArray(plan.fields) ? plan.fields.length : 0;
		var templates = Array.isArray(plan.templates) ? plan.templates.length : 0;
		var pages = pageCount(plan.pages);
		var modules = Array.isArray(plan.modules) ? plan.modules.length : 0;
		var label = fields + 'F · ' + templates + 'T · ' + pages + 'P · ' + modules + 'M';
		jsonShape.hidden = false;
		jsonShape.textContent = label;
		jsonShape.setAttribute('title', fields + ' fields, ' + templates + ' templates, ' + pages + ' pages, ' + modules + ' modules');
		jsonShape.setAttribute('aria-label', 'Plan shape: ' + fields + ' fields, ' + templates + ' templates, ' + pages + ' pages, ' + modules + ' modules; focus editable JSON');
		preserveJsonJumpTarget(jsonShape);
	}
	function syncJsonSize(){
		if(!jsonSize || !planTextarea) return;
		var len = planTextarea.value.length;
		var label = len >= 1000 ? ((len / 1000).toFixed(len >= 10000 ? 0 : 1) + 'k chars') : (len + ' char' + (len === 1 ? '' : 's'));
		jsonSize.textContent = label;
		jsonSize.setAttribute('title', len + ' character' + (len === 1 ? '' : 's') + ' in build plan JSON');
		jsonSize.setAttribute('aria-label', len + ' character' + (len === 1 ? '' : 's') + ' in build plan JSON; focus editable JSON');
		preserveJsonJumpTarget(jsonSize);
	}
	function syncFormatPlanButton(){
		if(!formatPlanButton) return;
		formatPlanButton.disabled = !planJsonValid;
		formatPlanButton.setAttribute('aria-disabled', formatPlanButton.disabled ? 'true' : 'false');
		formatPlanButton.setAttribute('title', planJsonValid ? 'Format valid build plan JSON - Command/Ctrl + Shift + P' : 'Format requires valid build plan JSON');
		formatPlanButton.setAttribute('aria-label', planJsonValid ? 'Format valid build plan JSON - Command/Ctrl + Shift + P' : 'Format requires valid build plan JSON');
		formatPlanButton.setAttribute('aria-keyshortcuts', 'Meta+Shift+P Control+Shift+P');
		preserveJsonActionReferences(formatPlanButton);
	}
	function syncUndoPlanButton(){
		if(!undoPlanButton) return;
		undoPlanButton.disabled = planUndoValue === null;
		undoPlanButton.setAttribute('aria-disabled', undoPlanButton.disabled ? 'true' : 'false');
		undoPlanButton.setAttribute('title', planUndoValue === null ? 'No JSON edit to undo' : 'Undo last JSON edit - Command/Ctrl + Shift + Z');
		undoPlanButton.setAttribute('aria-label', planUndoValue === null ? 'No JSON edit to undo' : 'Undo last JSON edit - Command/Ctrl + Shift + Z');
		undoPlanButton.setAttribute('aria-keyshortcuts', 'Meta+Shift+Z Control+Shift+Z');
		preserveJsonActionReferences(undoPlanButton);
	}
	function syncPlanActionButton(button, cleanTitle, cleanLabel, warnTitle, warnLabel, controls){
		if(!button || !planTextarea) return;
		var hasJson = !!planTextarea.value.trim();
		var syntaxWarn = hasJson && !planJsonValid;
		var schemaWarn = hasJson && planJsonValid && planSchemaHintActive;
		var warn = syntaxWarn || schemaWarn;
		var title = cleanTitle;
		var label = cleanLabel;
		if(syntaxWarn){
			title = warnTitle;
			label = warnLabel;
		} else if(schemaWarn) {
			var missing = planSchemaMissingKeys.length ? planSchemaMissingKeys.join(', ') : 'expected top-level keys';
			title = cleanTitle + '; plan JSON is missing expected top-level keys: ' + missing;
			label = cleanLabel + '; plan JSON is missing expected top-level keys: ' + missing;
		}
		button.classList.toggle('has-json-warning', warn);
		button.setAttribute('title', title);
		button.setAttribute('aria-label', label);
		button.setAttribute('aria-controls', controls || 'ol-plan-json');
		button.setAttribute('aria-describedby', 'ol-plan-action-help ol-json-diagnostics-help');
	}
	function syncPlanActionButtons(){
		syncPlanActionButton(previewPlanButton, 'Preview the current plan before building', 'Preview the current plan before building', 'Preview will use server validation; current JSON has a syntax error', 'Preview the current plan; current JSON has a syntax error', 'ol-plan-json ol-plan-preview');
		syncPlanActionButton(buildPlanButton, 'Build the reviewed plan in ProcessWire', 'Build the reviewed plan in ProcessWire', 'Build will use server validation; current JSON has a syntax error', 'Build the reviewed plan in ProcessWire; current JSON has a syntax error', 'ol-plan-json');
	}
	function syncJsonTools(){
		syncAdvancedStatus();
		syncJsonStatus();
		syncJsonSize();
		syncUndoPlanButton();
		syncPlanActionButtons();
	}
	function syncSwatches(){
		var activeColor = colorInput && colorInput.value ? colorInput.value.toLowerCase() : '';
		document.querySelectorAll('.ol-swatch').forEach(function(s){
			var on = (s.getAttribute('data-color') || '').toLowerCase() === activeColor;
			s.classList.toggle('is-active', on);
			s.setAttribute('aria-pressed', on ? 'true' : 'false');
			s.setAttribute('aria-controls', 'ol-theme-primary');
			s.setAttribute('aria-describedby', 'ol-theme-swatches-help');
		});
	}
	document.querySelectorAll('.ol-swatch').forEach(function(s){
		s.addEventListener('click', function(){
			if(colorInput) colorInput.value = s.getAttribute('data-color');
			emitChange(colorInput);
		});
	});
	document.querySelectorAll('.ol-json-jump').forEach(function(badge){
		function focusJson(){
			openAdvancedPanel();
			if(planTextarea) planTextarea.focus();
		}
		badge.addEventListener('click', focusJson);
		badge.addEventListener('keydown', function(e){
			if(e.key === 'Enter' || e.key === ' '){
				e.preventDefault();
				focusJson();
			}
		});
	});
	[fontSelect, blueprintSelect, colorInput, planTextarea].forEach(function(el){
		if(el) el.addEventListener(el === planTextarea ? 'input' : 'change', function(){
			if(el === fontSelect && themeFontOverrideInput) themeFontOverrideInput.value = '1';
			if(el === colorInput && themePrimaryOverrideInput) themePrimaryOverrideInput.value = '1';
			if(el === colorInput) syncSwatches();
			if(el === planTextarea){
				if(planTextarea.value !== planLastValue && !planBeforeInputPending) planUndoValue = planLastValue;
				planLastValue = planTextarea.value;
				planBeforeInputPending = false;
				syncJsonTools();
			}
			else syncAdvancedStatus();
		});
	});
	if(planTextarea) planTextarea.addEventListener('beforeinput', function(){
		planUndoValue = planTextarea.value;
		planLastValue = planTextarea.value;
		planBeforeInputPending = true;
		syncUndoPlanButton();
	});
	if(planTextarea) planTextarea.addEventListener('focus', openAdvancedPanel);
	function copyPlanJson(){
		if(!planTextarea) return false;
		var text = planTextarea.value || '';
		if(!text.trim()) return false;
		function copied(){
			preserveJsonActionStatusReferences();
			if(copyPlanStatus) copyPlanStatus.textContent = 'Copied';
			if(copyPlanButton){
				copyPlanButton.textContent = 'Copied';
				copyPlanButton.setAttribute('aria-label', 'Build plan JSON copied');
				copyPlanButton.setAttribute('title', 'Build plan JSON copied');
				preserveJsonActionReferences(copyPlanButton);
			}
			setTimeout(function(){
				if(copyPlanButton){
					copyPlanButton.innerHTML = '<i class="ri-file-copy-line" aria-hidden="true"></i> Copy JSON';
					syncAdvancedStatus();
				}
				preserveJsonActionStatusReferences();
				if(copyPlanStatus && copyPlanStatus.textContent === 'Copied') copyPlanStatus.textContent = '';
			}, 1800);
		}
		if(navigator.clipboard && navigator.clipboard.writeText){
			navigator.clipboard.writeText(text).then(copied).catch(function(){ planTextarea.select(); document.execCommand('copy'); copied(); });
		} else {
			planTextarea.select();
			document.execCommand('copy');
			copied();
		}
		return true;
	}
	function formatPlanJson(){
		if(!planTextarea || !planJsonValid) return false;
		var before = planTextarea.value;
		planUndoValue = before;
		var cursorRatio = before.length ? ((planTextarea.selectionStart || 0) / before.length) : 0;
		var scrollRatio = planTextarea.scrollHeight ? (planTextarea.scrollTop / planTextarea.scrollHeight) : 0;
		planTextarea.value = JSON.stringify(JSON.parse(before), null, 2);
		emitInput(planTextarea);
		var nextCursor = Math.min(planTextarea.value.length, Math.max(0, Math.round(planTextarea.value.length * cursorRatio)));
		if(planTextarea.setSelectionRange) planTextarea.setSelectionRange(nextCursor, nextCursor);
		planTextarea.scrollTop = Math.max(0, Math.round(planTextarea.scrollHeight * scrollRatio));
		preserveJsonActionStatusReferences();
		if(copyPlanStatus) copyPlanStatus.textContent = 'Formatted';
		setTimeout(function(){
			preserveJsonActionStatusReferences();
			if(copyPlanStatus && copyPlanStatus.textContent === 'Formatted') copyPlanStatus.textContent = '';
		}, 1800);
		planTextarea.focus();
		return true;
	}
	function undoPlanEdit(){
		if(!planTextarea || planUndoValue === null) return false;
		var current = planTextarea.value;
		planTextarea.value = planUndoValue;
		planUndoValue = current !== planTextarea.value ? current : null;
		emitInput(planTextarea);
		preserveJsonActionStatusReferences();
		if(copyPlanStatus) copyPlanStatus.textContent = 'Undo applied';
		setTimeout(function(){
			preserveJsonActionStatusReferences();
			if(copyPlanStatus && copyPlanStatus.textContent === 'Undo applied') copyPlanStatus.textContent = '';
		}, 1800);
		planTextarea.focus();
		return true;
	}
	if(copyPlanButton) copyPlanButton.addEventListener('click', copyPlanJson);
	if(copyThemeButton) copyThemeButton.addEventListener('click', copyDesignSystem);
	if(formatPlanButton) formatPlanButton.addEventListener('click', formatPlanJson);
	if(undoPlanButton) undoPlanButton.addEventListener('click', undoPlanEdit);
	syncSwatches();
	syncJsonTools();

	var SETS = {$thinkJs};
	var reducedMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

	// rotate the sub-message of any thinking card already on the page
	function rotate(el){
		if(reducedMotionQuery && reducedMotionQuery.matches) return;
		var subs; try { subs = JSON.parse(el.getAttribute('data-subs') || '[]'); } catch(e){ subs = []; }
		if(!subs || subs.length < 2) return;
		var i = 0;
		var timer = setInterval(function(){
			if(!document.documentElement.contains(el)){ clearInterval(timer); return; }
			if(reducedMotionQuery && reducedMotionQuery.matches) return;
			i = (i + 1) % subs.length;
			el.style.opacity = '0';
			setTimeout(function(){
				if(!document.documentElement.contains(el)) return;
				if(reducedMotionQuery && reducedMotionQuery.matches) return;
				el.textContent = subs[i]; el.style.opacity = '1';
			}, 250);
		}, 2600);
	}
	document.querySelectorAll('.ol-thinking-sub').forEach(rotate);

	// Overlay-backed submit actions get duplicate-submit protection. Fast or
	// confirm-backed actions stay in NO_OVERLAY_BTN so cancelled/quick submits
	// do not show a full-screen waiting state.
	var KIND_BY_BTN = {
		submit_build: 'build', submit_preview: 'preview',
		submit_answers: 'plan', submit_skills: 'skills',
		submit_learn_module: 'learn'
	};
	var NO_OVERLAY_BTN = {
		submit_chat_rename: true, submit_chat_delete: true,
		submit_install_module: true, submit_undo: true,
		submit_feedback_up: true, submit_feedback_down: true,
		submit_sample: true, submit_share: true
	};
	function kindFor(name, mode){
		if(NO_OVERLAY_BTN[name]) return null;
		if(name === 'submit_generate') return mode === 'operate' ? 'audit' : (mode === 'interview' ? 'questions' : 'plan');
		if(KIND_BY_BTN[name]) return KIND_BY_BTN[name];
		// Future slow submit actions still get conservative instant feedback.
		if(name && name.indexOf('submit_') === 0) return 'working';
		return null;
	}

	// instant feedback: show the overlay the moment a slow action is submitted,
	// bridging the gap until the server returns the polling placeholder.
	var app = document.getElementById('olivia-app');
	document.addEventListener('submit', function(e){
		if(!app || !app.contains(e.target)) return;
		var btn = e.submitter; if(!btn || !btn.name) return;
		var modeEl = document.querySelector('.ol-composer input[name="olivia_mode"]:checked');
		var kind = kindFor(btn.name, modeEl ? modeEl.value : 'direct');
		if(!kind || !SETS[kind]) return;
		if(normalizeReferenceUrl()) syncReferenceState();
		saveDraft();
		var set = SETS[kind];
		if(app.querySelector('.ol-thinking-overlay')) {
			e.preventDefault();
			e.stopPropagation();
			return;
		}
		app.setAttribute('aria-busy', 'true');
		btn.setAttribute('aria-disabled', 'true');
		btn.setAttribute('data-thinking-submit', 'true');
		var overlayMainId = 'ol-thinking-overlay-main';
		var overlaySubId = 'ol-thinking-overlay-sub';
		var ov = document.createElement('div');
		ov.className = 'ol-thinking-overlay';
		ov.setAttribute('role', 'region');
		ov.setAttribute('aria-labelledby', overlayMainId);
		ov.setAttribute('aria-describedby', overlaySubId);
		ov.setAttribute('aria-busy', 'true');
		var sub = document.createElement('div');
		sub.id = overlaySubId;
		sub.className = 'ol-thinking-sub';
		sub.setAttribute('data-subs', JSON.stringify(set.subs));
		sub.textContent = set.subs[0] || '';
		var card = document.createElement('div');
		card.className = 'ol-thinking';
		card.setAttribute('role', 'status');
		card.setAttribute('aria-live', 'polite');
		card.setAttribute('aria-atomic', 'true');
		card.setAttribute('aria-labelledby', overlayMainId);
		card.setAttribute('aria-describedby', overlaySubId);
		card.innerHTML = '<span class="ol-thinking-spark"><i class="ri-loader-4-line" aria-hidden="true"></i></span>';
		var col = document.createElement('div');
		var main = document.createElement('div');
		main.id = overlayMainId;
		main.className = 'ol-thinking-main'; main.textContent = set.main;
		col.appendChild(main); col.appendChild(sub); card.appendChild(col);
		ov.appendChild(card); app.appendChild(ov);
		rotate(sub);
	}, true);
})();
</script>
HTML;
	}

	/** Scoped admin theme for the Olivia screen. */
	protected function oliviaStyles(): string {
		$moduleUrl = $this->wire->sanitizer->entities((string)$this->wire->config->urls($this));
		return <<<HTML
<link href="{$moduleUrl}assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<style>
/* this page only: hide admin breadcrumb + module title so the Olivia hero is the top */
#pw-content-head .uk-breadcrumb{display:none}
#pw-content-title{display:none}
#main{max-width:none!important;padding-left:0!important;padding-right:0!important}
#pw-content-body{max-width:none!important;width:100%!important;margin:0!important;padding-left:0!important;padding-right:0!important}
#content{width:calc(100% + 30px);max-width:none;margin-left:-15px;margin-right:-15px;background:#e9edf2}
#olivia-app{width:100%;max-width:none;margin:0;padding:14px 18px 18px;font-family:'Inter',ui-sans-serif,system-ui,-apple-system,sans-serif;color:#101218;--ol-control-radius:8px;--ol-control-height:38px;--ol-control-height-compact:32px;--ol-icon-control-size:32px}
#olivia-app *{box-sizing:border-box}
#olivia-app .ol-sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
#olivia-app .ol-app-shell{display:grid;grid-template-columns:230px minmax(0,1fr);gap:0;min-height:calc(100vh - 72px);max-width:1480px;margin:0 auto;background:#fff;border:1px solid #dde3ec;border-radius:16px;overflow:hidden;box-shadow:0 18px 55px rgba(15,23,42,.08)}
#olivia-app .ol-app-shell.is-sidebar-collapsed{grid-template-columns:72px minmax(0,1fr)}
#olivia-app .ol-sidebar{display:flex;flex-direction:column;min-height:100%;background:#f8fafc;border-right:1px solid #edf1f6;padding:20px 14px}
#olivia-app .ol-side-brand{display:flex;align-items:center;gap:10px;font-size:19px;color:#0f172a;margin:0 6px 28px}
#olivia-app .ol-side-logo{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:#111827;color:#fff;font-size:14px}
#olivia-app .ol-sidebar-toggle{margin-left:auto;display:inline-grid;place-items:center;width:var(--ol-icon-control-size);height:var(--ol-icon-control-size);border:1px solid #e2e7ef;border-radius:var(--ol-control-radius);background:#fff;color:#4b5563;cursor:pointer;font-size:15px;padding:0}
#olivia-app .ol-side-actions{display:grid;gap:4px;margin-bottom:26px}
#olivia-app .ol-side-actions a,#olivia-app .ol-side-actions button,#olivia-app .ol-side-actions label,#olivia-app .ol-side-muted{display:flex;align-items:center;gap:9px;min-height:var(--ol-control-height);border-radius:var(--ol-control-radius);padding:0 10px;color:#0f172a;text-decoration:none;font-size:13px;font-weight:700}
#olivia-app .ol-side-actions button{border:0;background:transparent;text-align:left;font-family:inherit;cursor:pointer}
#olivia-app .ol-sidebar-toggle:focus-visible,#olivia-app .ol-side-actions a:focus-visible,#olivia-app .ol-side-actions button:focus-visible,#olivia-app .ol-side-actions label:focus-visible,#olivia-app .ol-panel-top a:focus-visible,#olivia-app .ol-help-open:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-side-actions i{font-size:16px;flex:none}
#olivia-app .ol-side-actions label{cursor:pointer}
#olivia-app .ol-side-actions .ol-side-primary{background:#2f6fed;color:#fff}
#olivia-app .ol-side-muted{color:#0f172a;background:transparent}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-sidebar{padding:20px 10px}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-brand{justify-content:center;margin:0 0 28px;gap:0}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-brand strong,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-actions span,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-chat-search input,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-chatlist{display:none}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-sidebar-toggle{margin-left:0}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-logo{display:none}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-actions{margin-bottom:0}
#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-actions a,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-actions button,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-actions label{justify-content:center;padding:0;width:var(--ol-control-height);margin:0 auto}
#olivia-app .ol-chat-search{display:flex;align-items:center;gap:9px;min-height:var(--ol-control-height);border-radius:var(--ol-control-radius);padding:0 10px;color:#0f172a;background:transparent}
#olivia-app .ol-chat-search:focus-within{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-chat-search input{width:100%;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:13px;font-weight:700;color:#0f172a;padding:0}
#olivia-app .ol-chat-search input::placeholder{color:#0f172a;opacity:1}
#olivia-app .ol-main-panel{position:relative;display:flex;flex-direction:column;min-width:0;min-height:calc(100vh - 72px);background:#fff;padding:22px 32px 28px}
#olivia-app .ol-panel-top{display:flex;align-items:center;justify-content:space-between;color:#9aa3af;font-size:12px}
#olivia-app .ol-panel-tools{display:flex;align-items:center;gap:8px}
#olivia-app .ol-panel-top a,#olivia-app .ol-help-open{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);color:#111827;text-decoration:none;border:1px solid #e5eaf1;border-radius:var(--ol-control-radius);padding:0 12px;font-family:inherit;font-size:12px;font-weight:750;background:#fff;cursor:pointer}
#olivia-app .ol-welcome{display:flex;flex:1;min-height:360px;align-items:center;justify-content:center;flex-direction:column;text-align:center;padding:40px 0 320px}
#olivia-app .ol-logo-mark{display:inline-flex;align-items:center;justify-content:center;width:54px;height:54px;border-radius:14px;background:#eef4ff;color:#2f6fed;font-size:30px;margin-bottom:22px}
#olivia-app .ol-welcome h1{margin:0;color:#0f172a;font-size:50px;line-height:1.08;font-weight:780;letter-spacing:0}
#olivia-app .ol-welcome h1 span{color:#a6adba;font-weight:720}
#olivia-app .ol-welcome p{margin:14px auto 24px;max-width:560px;color:#717b8c;font-size:14px;line-height:1.55}
#olivia-app .ol-hero{position:relative;max-width:860px;margin:18px auto 14px;text-align:center;padding:0 12px;border:0;background:transparent;box-shadow:none}
#olivia-app .ol-brand{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:var(--ol-control-height);font-weight:750;font-size:13px;color:#5e6572;background:#fff;border:1px solid #eceff4;border-radius:var(--ol-control-radius);padding:0 12px;box-shadow:0 10px 30px rgba(16,24,40,.04)}
#olivia-app .ol-toplinks{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:12px auto 0}
#olivia-app .ol-toplinks a{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);color:#4b5563;background:#fff;border:1px solid #eceff4;border-radius:var(--ol-control-radius);padding:0 12px;font-size:12px;font-weight:750;text-decoration:none;box-shadow:none}
#olivia-app .ol-greet{margin:18px auto 8px;max-width:760px;font-size:40px;line-height:1.1;font-weight:800;color:#0f1117;background:none;-webkit-background-clip:initial;background-clip:initial;letter-spacing:0}
#olivia-app .ol-greet span{color:#7c3aed}
#olivia-app .ol-sub{margin:0 auto;max-width:620px;color:#687080;font-size:15px;line-height:1.6}
#olivia-app .ol-status{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:14px auto 0;max-width:520px}
#olivia-app .ol-status span{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;background:#fff;border:1px solid #eceff4;border-radius:8px;padding:7px 11px}
#olivia-app .ol-chatlist{min-height:0}
#olivia-app .ol-chatlist-head{display:flex;align-items:center;justify-content:space-between;color:#0f172a;font-size:15px;font-weight:850;margin:0 6px 10px}
#olivia-app .ol-chatlist-head em{font-style:normal;color:#2f6fed;background:#eaf1ff;border-radius:8px;padding:1px 6px;font-size:11px}
#olivia-app .ol-chat-group{margin:14px 0 6px 12px;color:#0f172a;font-size:12px;font-weight:850}
#olivia-app .ol-chatrow{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 26px;align-items:center;margin:0 0 0 12px;border-left:1px solid #e9edf3}
#olivia-app .ol-chatrow.is-active{background:#edf4ff;border-left-color:#2f6fed;border-radius:8px;color:#0f172a}
#olivia-app .ol-chatitem{display:block;min-width:0;text-decoration:none;color:#243044;padding:5px 4px 6px 18px;background:transparent}
#olivia-app .ol-chatitem:focus-visible,#olivia-app .ol-chat-menu summary:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-chat-title{display:block;font-size:13px;font-weight:800;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-chat-meta{display:block;margin-top:3px;font-size:11px;color:#7b8494}
#olivia-app .ol-chat-empty{font-size:12px;line-height:1.4;color:#7b8494;background:#f7f8fb;border-radius:8px;padding:12px}
#olivia-app .ol-chat-empty strong{color:#111827;font-weight:850}
#olivia-app .ol-chat-clear-search{display:inline-flex;align-items:center;justify-content:center;min-height:var(--ol-control-height-compact);margin-top:9px;border:1px solid #e1e7f0;border-radius:var(--ol-control-radius);background:#fff;color:#2f6fed;font:inherit;font-size:12px;font-weight:850;padding:0 10px;cursor:pointer}
#olivia-app .ol-chat-clear-search:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-chat-menu{position:relative;justify-self:end;margin-right:3px}
#olivia-app .ol-chat-menu summary{display:flex;align-items:center;justify-content:center;width:var(--ol-icon-control-size);height:var(--ol-icon-control-size);border-radius:var(--ol-control-radius);color:#64748b;cursor:pointer;list-style:none}
#olivia-app .ol-chat-menu summary::-webkit-details-marker{display:none}
#olivia-app .ol-chat-menu-pop{position:absolute;z-index:20;right:0;top:28px;width:188px;background:#fff;border:1px solid #e3e7ef;border-radius:8px;padding:8px;box-shadow:0 16px 36px rgba(15,23,42,.14)}
#olivia-app .ol-chat-menu-pop form{margin:0}
#olivia-app .ol-chat-title-input{width:100%;border:1px solid #e3e7ef;border-radius:7px;padding:7px 8px;font:inherit;font-size:12px;color:#111827;margin-bottom:6px}
#olivia-app .ol-menu-btn{display:flex;align-items:center;gap:7px;width:100%;min-height:var(--ol-control-height-compact);border:0;background:#fff;color:#111827;border-radius:var(--ol-control-radius);padding:0 9px;font:inherit;font-size:12px;font-weight:750;cursor:pointer;text-align:left}
#olivia-app .ol-chat-title-input:focus-visible,#olivia-app .ol-menu-btn:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-menu-btn.ol-danger{color:#b42318}
#olivia-app .ol-composer{position:absolute;left:50%;bottom:28px;transform:translateX(-50%);width:min(720px,calc(100% - 64px));max-width:720px;margin:0;z-index:5}
#olivia-app .ol-main-panel::after{content:'';position:absolute;left:0;right:0;bottom:0;height:180px;background:linear-gradient(to bottom,rgba(255,255,255,0),#fff 42%);pointer-events:none;z-index:3}
#olivia-app .ol-main-panel.has-chat::after,#olivia-app .ol-main-panel.has-result::after{display:none}
#olivia-app .ol-main-panel.has-chat .ol-composer,#olivia-app .ol-main-panel.has-result .ol-composer{position:relative;left:auto;right:auto;bottom:auto;transform:none;flex:none;width:min(720px,100%);max-width:720px;margin:16px auto 0}
#olivia-app .ol-main-panel.has-result:not(.has-chat) .ol-welcome{flex:none;min-height:0;padding:38px 0 24px}
#olivia-app .ol-result-slot{flex:none;width:min(960px,100%);margin:18px auto 0;padding:20px;border:1px solid #e3e7ef;border-radius:8px;background:#fff;color:#111827}
#olivia-app .ol-result-slot:empty,#olivia-app .ol-result-slot:not(:has(> :not(script))){display:none}
#olivia-app .ol-result-slot>h2:first-child,#olivia-app .ol-result-slot>section:first-child>h2:first-child,#olivia-app .ol-result-slot #ol-plan-preview>h2:first-child{margin-top:0}
#olivia-app .ol-result-slot table{width:100%}
#olivia-app .ol-result-slot th{width:170px;vertical-align:top}
#olivia-app .ol-result-slot td{overflow-wrap:anywhere}
@media (min-width:701px){
	#olivia-app .ol-main-panel:not(.has-chat):has(.ol-adv[open])::after{display:none}
	#olivia-app .ol-main-panel:not(.has-chat):has(.ol-adv[open]) .ol-welcome{flex:none;min-height:0;padding:38px 0 24px}
	#olivia-app .ol-main-panel:not(.has-chat):has(.ol-adv[open]) .ol-composer{position:relative;left:auto;right:auto;bottom:auto;transform:none;flex:none;width:min(720px,100%);max-width:720px;margin:92px auto 0}
}
#olivia-app .ol-inputwrap{position:relative;background:#fff;border:1px solid #e3e7ef;border-radius:12px;padding:16px 14px 13px;box-shadow:0 18px 45px rgba(15,23,42,.08)}
#olivia-app .ol-inputwrap:focus-within{border-color:#e3e7ef;box-shadow:0 18px 45px rgba(15,23,42,.08)}
#olivia-app .ol-prompt-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:12px;font-weight:700;color:#697386}
#olivia-app .ol-prompt-title{display:inline-flex;align-items:center;gap:8px;min-width:0}
#olivia-app .ol-prompt-title span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-prompt-state{display:inline-flex;align-items:center;gap:7px;min-width:0;flex:none}
#olivia-app .ol-draft-status{display:inline-block;max-width:92px;min-height:16px;color:#8a94a6;font-size:11px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-clear-prompt{display:inline-grid;place-items:center;width:var(--ol-icon-control-size);height:var(--ol-icon-control-size);border:1px solid #e4e8f0;border-radius:var(--ol-control-radius);background:#fff;color:#7b8494;cursor:pointer;font-size:14px;padding:0;flex:none}
#olivia-app .ol-clear-prompt.is-empty{visibility:hidden;pointer-events:none}
#olivia-app .ol-mode-badge{display:inline-flex;align-items:center;justify-content:center;max-width:128px;min-height:24px;border:1px solid #e4e8f0;border-radius:8px;background:#f8fafc;color:#4b5563;font-size:11px;font-weight:850;padding:3px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-prompt{width:100%;border:0;outline:0;resize:vertical;font:inherit;font-size:15px;line-height:1.5;color:#151922;background:transparent;min-height:58px;max-height:170px}
#olivia-app .ol-prompt::placeholder{color:#8b93a1}
#olivia-app .ol-prompt-foot{display:flex;align-items:center;justify-content:space-between;gap:8px 12px;margin-top:6px;color:#8a94a6;font-size:11px;font-weight:800}
#olivia-app .ol-prompt-foot span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-prompt-foot [data-prompt-readiness]{min-width:0;flex:1 1 auto}
#olivia-app .ol-prompt-foot [data-ref-summary]{display:inline-flex;align-items:center;max-width:240px;border:0;color:#2458c9;background:#eef4ff;border-radius:8px;padding:2px 7px;font:inherit;font-weight:850;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-prompt-foot [data-ref-summary]:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-prompt-foot [data-prompt-count]{flex:none}
#olivia-app .ol-prompt-foot [hidden]{display:none!important}
#olivia-app .ol-ref-toggle,#olivia-app .ol-help-toggle{position:absolute;opacity:0;pointer-events:none}
#olivia-app .ol-ref-modal,#olivia-app .ol-help-modal{display:none;position:fixed;inset:0;z-index:10000;align-items:center;justify-content:center;padding:20px}
#olivia-app .ol-ref-toggle:checked + .ol-ref-modal{display:flex}
#olivia-app .ol-help-toggle:checked + .ol-help-modal{display:flex}
#olivia-app .ol-ref-backdrop,#olivia-app .ol-help-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.34);cursor:pointer}
#olivia-app .ol-ref-dialog,#olivia-app .ol-help-dialog{position:relative;width:min(520px,100%);background:#fff;border:1px solid #e3e7ef;border-radius:8px;padding:18px;box-shadow:0 24px 80px rgba(15,23,42,.18)}
#olivia-app .ol-help-dialog{max-height:calc(100vh - 40px);overflow-y:auto;overscroll-behavior:contain}
#olivia-app .ol-ref-head,#olivia-app .ol-help-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
#olivia-app .ol-ref-head strong,#olivia-app .ol-help-head strong{display:block;font-size:16px;color:#111827}
#olivia-app .ol-ref-head span,#olivia-app .ol-help-head span{display:block;margin-top:3px;font-size:13px;line-height:1.4;color:#6b7280}
#olivia-app .ol-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:var(--ol-icon-control-size);height:var(--ol-icon-control-size);border:1px solid #e3e7ef;border-radius:var(--ol-control-radius);color:#4b5563;background:#fff;cursor:pointer;padding:0;flex:none}
#olivia-app .ol-icon-btn:focus-visible,#olivia-app .ol-refurl:focus-visible,#olivia-app .ol-refnotes:focus-visible,#olivia-app .ol-file-pill:focus-visible,#olivia-app .ol-file-pill:focus-within,#olivia-app .ol-file-clear:focus-visible,#olivia-app .ol-ref-clear:focus-visible,#olivia-app .ol-ref-done:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-shortcuts{display:grid;gap:7px;margin-top:8px}
#olivia-app .ol-shortcut{display:grid;grid-template-columns:112px minmax(0,1fr);align-items:center;gap:12px;min-height:38px;border:1px solid #eef1f5;border-radius:8px;background:#fbfcff;padding:8px 10px;color:#374151;font-size:13px}
#olivia-app .ol-shortcut kbd{display:inline-flex;align-items:center;justify-content:center;min-width:74px;justify-self:start;border:1px solid #dfe4ec;border-bottom-color:#c8d0dc;border-radius:7px;background:#fff;color:#111827;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;font-weight:850;padding:4px 8px;box-shadow:0 1px 0 #c8d0dc}
#olivia-app .ol-help-note{margin:12px 0 0;color:#7b8494;font-size:12px}
#olivia-app .ol-ref-label{display:block;margin:12px 0 6px;font-size:12px;font-weight:850;color:#4b5563}
#olivia-app .ol-ref-detail{margin:8px 0 0;color:#7b8494;font-size:12px;font-weight:750}
#olivia-app .ol-web-research{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:12px;padding:10px 11px;border:1px solid #e3e7ef;border-radius:var(--ol-control-radius);background:#fbfcff;cursor:pointer}
#olivia-app .ol-web-research-copy{display:flex;align-items:flex-start;gap:9px;min-width:0;color:#374151}
#olivia-app .ol-web-research-copy>i{margin-top:1px;color:#2563eb;font-size:17px;flex:none}
#olivia-app .ol-web-research-copy strong,#olivia-app .ol-web-research-copy small{display:block}
#olivia-app .ol-web-research-copy strong{font-size:12px;line-height:1.35}
#olivia-app .ol-web-research-copy small{margin-top:2px;color:#7b8494;font-size:11px;line-height:1.35;font-weight:650}
#olivia-app .ol-web-research input{position:absolute;opacity:0;pointer-events:none}
#olivia-app .ol-switch{position:relative;width:34px;height:20px;border-radius:10px;background:#cbd2dc;flex:none;transition:background .15s ease}
#olivia-app .ol-switch:after{content:"";position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.2);transition:transform .15s ease}
#olivia-app .ol-web-research input:checked+.ol-switch{background:#2563eb}
#olivia-app .ol-web-research input:checked+.ol-switch:after{transform:translateX(14px)}
#olivia-app .ol-web-research input:focus-visible+.ol-switch{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-file-row{display:flex;align-items:center;gap:8px;min-width:0}
#olivia-app .ol-ref-label-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}
#olivia-app .ol-capture-state{display:flex;align-items:center;gap:7px;margin-top:7px;color:#5f6368;font-size:11px;line-height:1.35}
#olivia-app .ol-capture-state i{color:#1a73e8;font-size:14px;flex:none}
#olivia-app .ol-ref-label-row .ol-ref-label{margin:0}
#olivia-app .ol-file-count{font-size:11px;color:#80868b;font-variant-numeric:tabular-nums}
#olivia-app .ol-file-row.is-dragover .ol-file-pill{background:#eef4ff;color:#174ea6;border-color:#8ab4f8}
#olivia-app .ol-ref-previews{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:8px}
#olivia-app .ol-ref-previews[hidden]{display:none}
#olivia-app .ol-ref-preview{position:relative;display:grid;grid-template-columns:42px minmax(0,1fr) var(--ol-icon-control-size);align-items:center;gap:7px;min-height:48px;padding:4px;background:#f8f9fa;border-radius:7px;overflow:hidden}
#olivia-app .ol-ref-preview img{width:42px;height:40px;object-fit:cover;border-radius:5px;background:#e8eaed}
#olivia-app .ol-ref-preview-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#3c4043}
#olivia-app .ol-ref-preview-remove{display:grid;place-items:center;width:var(--ol-icon-control-size);height:var(--ol-icon-control-size);border:0;background:transparent;color:#5f6368;cursor:pointer;border-radius:var(--ol-control-radius);padding:0}
#olivia-app .ol-ref-preview-remove:hover{background:#e8eaed;color:#202124}
#olivia-app .ol-ref-preview-remove:focus-visible{outline:2px solid #93b4ff;outline-offset:1px}
#olivia-app .ol-file-clear{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:var(--ol-control-height);border:1px solid #e3e7ef;border-radius:var(--ol-control-radius);background:#fff;color:#6b7280;font:inherit;font-size:12px;font-weight:800;padding:0 12px;cursor:pointer;white-space:nowrap}
#olivia-app .ol-file-clear[hidden]{display:none!important}
#olivia-app .ol-ref-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px}
#olivia-app .ol-ref-clear{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);border:1px solid #e3e7ef;border-radius:var(--ol-control-radius);background:#fff;color:#6b7280;font:inherit;font-size:12px;font-weight:800;padding:0 12px;cursor:pointer}
#olivia-app .ol-ref-clear:disabled{cursor:default;opacity:.45}
#olivia-app .ol-ref-done{display:inline-flex;align-items:center;justify-content:center;min-height:var(--ol-control-height);border:1px solid #111827;border-radius:var(--ol-control-radius);background:#111827;color:#fff;font:inherit;font-size:12px;font-weight:850;padding:0 14px;cursor:pointer}
#olivia-app .ol-bar{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:8px;padding-top:10px;border-top:1px solid #f0f2f6}
#olivia-app .ol-modes{position:absolute;left:0;right:0;bottom:calc(100% + 12px);display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}
#olivia-app .ol-main-panel.has-chat .ol-modes{position:static;margin-bottom:10px}
#olivia-app .ol-main-panel.has-chat .ol-bar{align-items:stretch;flex-direction:column}
#olivia-app .ol-main-panel.has-chat .ol-actions{align-self:flex-end}
#olivia-app .ol-mode{position:relative;cursor:pointer;font-size:12px;font-weight:750;color:#697386;padding:8px 9px;border:1px solid #eceff4;border-radius:var(--ol-control-radius);background:#f7f8fb;min-height:62px}
#olivia-app .ol-mode:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-mode input{position:absolute;opacity:0;width:0;height:0}
#olivia-app .ol-mode:has(input:checked),#olivia-app .ol-mode.is-active{background:#fff;color:#6d28d9;border-color:#d8ccff;box-shadow:0 1px 2px rgba(15,23,42,.05)}
#olivia-app .ol-mode-title{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:850;line-height:1.2;color:#111827}
#olivia-app .ol-mode-title i{font-size:14px;color:#6d7280}
#olivia-app .ol-mode:has(input:checked) .ol-mode-title,#olivia-app .ol-mode.is-active .ol-mode-title{color:#6d28d9}
#olivia-app .ol-mode:has(input:checked) .ol-mode-title i,#olivia-app .ol-mode.is-active .ol-mode-title i{color:#6d28d9}
#olivia-app .ol-mode-desc{display:block;margin-top:3px;font-size:10.5px;font-weight:600;line-height:1.25;color:#737b8a}
#olivia-app .ol-actions{display:flex;align-items:center;gap:8px}
#olivia-app .ol-ref-open{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);border:1px solid #dadce0;cursor:pointer;font:inherit;font-weight:750;font-size:13px;color:#3c4043;background:#fff;border-radius:var(--ol-control-radius);padding:0 14px;white-space:nowrap}
#olivia-app .ol-ref-open:focus-visible,#olivia-app .ol-send:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-ref-open.has-reference{border-color:#cfe0ff;background:#eef4ff;color:#2458c9}
#olivia-app .ol-send{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);border:1px solid #111827;cursor:pointer;font:inherit;font-weight:800;font-size:14px;color:#fff;padding:0 18px;border-radius:var(--ol-control-radius);background:#111827;min-width:120px}
#olivia-app .ol-send.is-empty{border-color:#d9dee7;background:#f5f7fb;color:#7b8494}
#olivia-app .ol-example-label{margin:18px 0 10px;font-size:11px;font-weight:850;letter-spacing:.12em;text-transform:uppercase;color:#717887}
#olivia-app .ol-chips{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0;width:min(920px,100%)}
#olivia-app .ol-chip{display:flex;min-height:74px;cursor:pointer;text-align:left;font:inherit;color:#111827;background:#fff;border:1px solid #edf1f6;border-radius:var(--ol-control-radius);padding:10px 11px;align-items:flex-start;gap:9px;justify-content:flex-start;box-shadow:0 8px 20px rgba(15,23,42,.03)}
#olivia-app .ol-chip:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-chip.is-active{border-color:#bfd3ff;background:#eef4ff;color:#1d4ed8}
#olivia-app .ol-chip.is-active .ol-chip-icon{background:#dbe8ff;color:#1d4ed8}
#olivia-app .ol-chip-icon{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:8px;background:#f2f6ff;color:#2f6fed;font-weight:800;font-size:15px;flex:none}
#olivia-app .ol-chip-copy{display:block;min-width:0}
#olivia-app .ol-chip-title{display:block;font-size:13px;font-weight:800;line-height:1.2;white-space:nowrap}
#olivia-app .ol-chip-meta{display:block;margin-top:4px;font-size:11px;font-weight:700;line-height:1.25;color:#7b8494}
#olivia-app .ol-adv{margin-top:12px;background:#fff;border:1px solid #eceff4;border-radius:8px;padding:0 14px;box-shadow:none}
#olivia-app .ol-adv summary{cursor:pointer;list-style:none;padding:16px 2px;font-weight:800;font-size:14px;color:#202124}
#olivia-app .ol-adv summary:focus-visible,#olivia-app .ol-select:focus-visible,#olivia-app .ol-color:focus-visible,#olivia-app .ol-swatch:focus-visible,#olivia-app .ol-json:focus-visible,#olivia-app .ol-btn:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-adv-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;min-width:0}
#olivia-app .ol-adv-title{white-space:nowrap}
#olivia-app .ol-adv-status{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#8a94a6;font-size:11px;font-weight:800}
#olivia-app .ol-adv summary::-webkit-details-marker{display:none}
#olivia-app .ol-adv summary::before{content:'⌄ ';color:#1a73e8}
#olivia-app .ol-adv[open] summary::before{content:'⌃ '}
#olivia-app .ol-adv[open]{padding-bottom:18px}
#olivia-app .ol-advrow{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin:6px 0 14px}
#olivia-app .ol-advrow-end{justify-content:flex-end}
#olivia-app .ol-select{font:inherit;font-size:14px;padding:9px 12px;border:1px solid #dadce0;border-radius:8px;background:#fff;min-width:220px;color:#202124}
#olivia-app .ol-refurl{font:inherit;font-size:13px;padding:10px 12px;border:1px solid #e3e7ef;border-radius:8px;background:#fbfcff;color:#202124;min-width:0;width:100%}
#olivia-app .ol-file-pill{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);white-space:nowrap;cursor:pointer;font-size:13px;font-weight:750;color:#3f4654;border:1px solid #e3e7ef;border-radius:var(--ol-control-radius);background:#fff;padding:0 14px}
#olivia-app .ol-file-pill input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}
#olivia-app .ol-file{font:inherit;font-size:13px;color:#5f6368;max-width:100%}
#olivia-app .ol-refnotes{width:100%;font:inherit;font-size:13px;line-height:1.45;color:#202124;border:1px solid #e3e7ef;border-radius:8px;padding:9px 12px;background:#fbfcff;resize:vertical;margin:8px 0 0;min-height:42px}
#olivia-app .ol-lbl{display:block;font-size:12px;font-weight:700;color:#6f7278;margin:9px 0 7px}
#olivia-app .ol-json-head{display:flex;align-items:flex-start;flex-wrap:wrap;gap:8px 10px;margin:9px 0 7px;min-width:0}
#olivia-app .ol-json-head .ol-lbl{margin:0;min-width:0;flex:1 0 100%}
#olivia-app .ol-json-badges,#olivia-app .ol-json-actions{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap}
#olivia-app .ol-json-badges{flex:1 1 300px}
#olivia-app .ol-json-actions{flex:0 0 auto;flex-wrap:nowrap;margin-left:auto}
#olivia-app .ol-json-head>.ol-copy-status{flex:1 0 100%}
#olivia-app .ol-json-jump{cursor:pointer}
#olivia-app .ol-json-jump:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-json-status{display:inline-flex;align-items:center;min-height:26px;border:1px solid #e4e8f0;border-radius:8px;background:#f8fafc;color:#8a94a6;font-size:11px;font-weight:850;padding:4px 8px;white-space:nowrap}
#olivia-app .ol-json-status.is-ok{border-color:#d8eadf;background:#f1f8f3;color:#188038}
#olivia-app .ol-json-status.is-bad{border-color:#f3c6c0;background:#fff4f2;color:#b42318}
#olivia-app .ol-json-site{display:inline-flex;align-items:center;max-width:260px;min-height:26px;border:1px solid #e7dcff;border-radius:8px;background:#f8f5ff;color:#6d28d9;font-size:11px;font-weight:850;padding:4px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-json-site[hidden]{display:none!important}
#olivia-app .ol-json-size{display:inline-flex;align-items:center;min-height:26px;border:1px solid #e4e8f0;border-radius:8px;background:#fff;color:#8a94a6;font-size:11px;font-weight:850;padding:4px 8px;white-space:nowrap}
#olivia-app .ol-json-shape{display:inline-flex;align-items:center;min-height:26px;border:1px solid #dbe6ff;border-radius:8px;background:#f4f7ff;color:#2458c9;font-size:11px;font-weight:850;padding:4px 8px;white-space:nowrap}
#olivia-app .ol-json-shape[hidden]{display:none!important}
#olivia-app .ol-json-hint{display:inline-flex;align-items:center;min-height:26px;border:1px solid #fde2b7;border-radius:8px;background:#fff8ed;color:#a15c00;font-size:11px;font-weight:850;padding:4px 8px;white-space:nowrap}
#olivia-app .ol-json-hint[hidden]{display:none!important}
#olivia-app .ol-copy-status{min-width:44px;color:#8a94a6;font-size:11px;font-weight:800}
#olivia-app .ol-json{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.55;color:#263238;border:1px solid #dadce0;border-radius:8px;padding:13px;background:#fbfdff;resize:vertical}
#olivia-app .ol-json:focus{border-color:#8ab4f8;box-shadow:none}
#olivia-app .ol-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);cursor:pointer;font:inherit;font-weight:750;font-size:13px;line-height:1.2;color:#3c4043;background:#fff;border:1px solid #dadce0;border-radius:var(--ol-control-radius);padding:0 14px}
#olivia-app .ol-btn.ol-compact{min-height:var(--ol-control-height-compact);padding:0 10px;font-size:12px}
#olivia-app .ol-btn:disabled{cursor:default;opacity:.48}
#olivia-app [data-thinking-submit="true"]{cursor:wait;opacity:.6;pointer-events:none}
#olivia-app .ol-btn.ol-ghost{background:#fff}
#olivia-app .ol-btn.ol-ghost.has-json-warning{border-color:#e7b15b;background:#fff8ed;color:#a15c00}
#olivia-app .ol-btn.ol-primary{color:#fff;border-color:#188038;background:#188038}
#olivia-app .ol-btn.ol-primary.has-json-warning{border-color:#c16b00;background:#c16b00}
#olivia-app .ol-chattrail{flex:1;min-height:0;width:min(760px,100%);margin:18px auto 0;background:transparent;border:0;border-radius:0;padding:8px 0 255px;overflow:auto}
#olivia-app .ol-main-panel.has-chat .ol-chattrail{flex:none;padding-bottom:8px;overflow:visible}
#olivia-app .ol-chattrail-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
#olivia-app .ol-chattrail h2{font-size:16px;margin:0;color:#111827}
#olivia-app .ol-chattrail-head span{display:block;margin-top:3px;font-size:12px;color:#8a94a6}
#olivia-app .ol-chattrail a{font-size:12px;font-weight:750;text-decoration:none;color:#6d28d9}
#olivia-app .ol-msg{display:grid;grid-template-columns:32px minmax(0,1fr);gap:10px;border:1px solid #edf1f6;border-radius:10px;padding:12px;margin:8px 0;background:#fff}
#olivia-app .ol-msg-user{background:#f8fafc}
#olivia-app .ol-msg-icon{display:grid;place-items:center;width:32px;height:32px;border-radius:8px;background:#eef4ff;color:#2f6fed;font-size:16px}
#olivia-app .ol-msg-user .ol-msg-icon{background:#111827;color:#fff}
#olivia-app .ol-msg-type-build-request .ol-msg-icon{background:#f3f4f6;color:#4b5563}
#olivia-app .ol-msg-type-build .ol-msg-icon{background:#ecfdf3;color:#188038}
#olivia-app .ol-msg-type-audit .ol-msg-icon{background:#f5f3ff;color:#6d28d9}
#olivia-app .ol-msg-type-questions .ol-msg-icon{background:#fff7ed;color:#c2410c}
#olivia-app .ol-msg-type-preview .ol-msg-icon{background:#eff6ff;color:#2563eb}
#olivia-app .ol-msg-type-error .ol-msg-icon{background:#fff1f2;color:#be123c}
#olivia-app .ol-msg-body{min-width:0}
#olivia-app .ol-msg-meta{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#7b8494;margin-bottom:5px}
#olivia-app .ol-msg-meta strong{color:#111827}
#olivia-app .ol-msg-meta time{font-weight:700;letter-spacing:0;text-transform:none;color:#9aa3af}
#olivia-app .ol-msg-text{font-size:13px;line-height:1.5;color:#1f2937;overflow-wrap:anywhere}
#olivia-app .ol-msg-user .ol-msg-text{font-weight:650}
#olivia-app .ol-msg-pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}
#olivia-app .ol-msg-pills span{display:inline-flex;align-items:center;min-height:24px;border:1px solid #e4e8f0;border-radius:8px;background:#fff;color:#4b5563;font-size:11px;font-weight:800;padding:3px 7px}
#olivia-app .ol-msg-sources{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}
#olivia-app .ol-msg-sources a{display:inline-flex;align-items:center;gap:4px;max-width:260px;min-height:24px;border:1px solid #dbe7fb;border-radius:8px;background:#f7faff;color:#2458c9;font-size:11px;font-weight:800;padding:3px 7px;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#olivia-app .ol-msg-sources a:hover{background:#eef4ff;color:#174ea6}
#olivia-app .ol-msg-sources a:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-msg-actions{display:flex;justify-content:flex-end;margin-top:10px}
#olivia-app .ol-msg-use,#olivia-app .ol-fix{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:var(--ol-control-height-compact);border:1px solid #dfe5ee;border-radius:var(--ol-control-radius);background:#fff;color:#374151;font:inherit;font-size:12px;font-weight:800;padding:0 10px;cursor:pointer}
#olivia-app .ol-fix{margin-top:.6em;border-color:#c7d2fe;background:#eef2ff;color:#4338ca}
#olivia-app .ol-msg-use:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-fix:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
/* utility pages and shared data tables */
#olivia-app .ol-utilnav{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:18px;width:100%;max-width:1520px;margin:0 auto 20px}
#olivia-app .ol-utilbrand{display:flex;align-items:center;justify-content:center;gap:9px;font-weight:850;color:#111827}
#olivia-app .ol-utilbrand-mark{display:grid;place-items:center;width:30px;height:30px;border-radius:8px;background:#eef4ff;color:#2f6fed;font-size:15px}
#olivia-app .ol-utilnav-links{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
#olivia-app .ol-utilnav>a{justify-self:start}
#olivia-app .ol-utilnav a{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:var(--ol-control-height);color:#4b5563;background:#fff;border:1px solid #eceff4;border-radius:var(--ol-control-radius);padding:0 14px;font-size:13px;font-weight:750;text-decoration:none}
#olivia-app .ol-utilnav a:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-utilnav .is-active{color:#2f6fed;background:#f7faff;border-color:#cfdcff}
#olivia-app .ol-page{width:100%;max-width:1520px;margin:0 auto;background:#fff;border:1px solid #e5e9f0;border-radius:8px;padding:28px 30px 30px;box-shadow:none}
#olivia-app .ol-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}
#olivia-app .ol-page-heading{display:flex;align-items:flex-start;gap:14px;min-width:0}
#olivia-app .ol-page-icon{display:grid;place-items:center;flex:0 0 auto;width:42px;height:42px;border-radius:8px;background:#eef4ff;color:#2f6fed;font-size:20px}
#olivia-app .ol-page-kicker{margin:0 0 3px;color:#7b8494;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.06em}
#olivia-app .ol-page-title{margin:0;font-size:26px;line-height:1.15;font-weight:850;color:#111827}
#olivia-app .ol-page-heading .detail{max-width:820px;margin-top:5px}
#olivia-app .ol-stat-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));margin:0 0 22px;border:1px solid #e5e9f0;border-radius:8px;background:#f8fafc;overflow:hidden}
#olivia-app .ol-stat-strip>div{display:flex;min-height:72px;flex-direction:column;justify-content:center;gap:5px;padding:12px 16px;border-right:1px solid #e5e9f0}
#olivia-app .ol-stat-strip>div:last-child{border-right:0}
#olivia-app .ol-stat-strip span{color:#7b8494;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
#olivia-app .ol-stat-strip strong{color:#111827;font-size:17px;font-weight:850}
#olivia-app .ol-stat-strip strong.is-ok{color:#15803d}
#olivia-app .ol-stat-strip strong.is-warning{color:#b45309}
#olivia-app .ol-empty{border:1px dashed #d9dee8;border-radius:8px;padding:22px;background:#fbfcff;color:#687080;font-size:14px}
#olivia-app .ol-table-wrap{width:100%;overflow-x:auto;border:1px solid #e7eaf0;border-radius:8px;background:#fff}
#olivia-app table.ol-data-table{width:100%;border-collapse:collapse;font-size:13px;line-height:1.45;background:#fff}
#olivia-app table.ol-build-table{min-width:1080px}
#olivia-app table.ol-skills-table{min-width:640px}
#olivia-app table.ol-data-table th{background:#f7f8fb;color:#5f6673;font-weight:850;text-align:left}
#olivia-app table.ol-data-table td,#olivia-app table.ol-data-table th{padding:13px 14px;border-bottom:1px solid #eef1f5;vertical-align:middle}
#olivia-app table.ol-data-table tbody tr:hover{background:#fbfcff}
#olivia-app table.ol-data-table tbody tr:last-child td{border-bottom:0}
#olivia-app table.ol-data-table code{font-size:12px;white-space:normal;overflow-wrap:anywhere}
#olivia-app table.ol-build-table code{white-space:nowrap;overflow-wrap:normal}
#olivia-app .ol-td-num{text-align:right;font-variant-numeric:tabular-nums}
#olivia-app .ol-td-actions{text-align:right;white-space:nowrap}
#olivia-app .ol-date-cell{white-space:nowrap;color:#4b5563}
#olivia-app .ol-status{display:inline-flex;align-items:center;justify-content:center;min-height:24px;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:850}
#olivia-app .ol-status-ok{background:#ecfdf3;color:#15803d}
#olivia-app .ol-status-warning{background:#fff7ed;color:#b45309}
#olivia-app .ol-module-name{display:inline-flex;align-items:center;gap:9px;color:#111827}
#olivia-app .ol-module-name i{display:grid;place-items:center;width:28px;height:28px;border-radius:7px;background:#f1f5f9;color:#5f6b7a;font-size:14px}
#olivia-app .ol-source-badge{display:inline-flex;align-items:center;min-height:26px;border-radius:7px;background:#f1f5f9;color:#475569;padding:3px 8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;font-weight:750}
#olivia-app .ol-debug-layout{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(340px,.8fr);align-items:start;gap:22px}
#olivia-app .ol-debug-layout h2{margin:0 0 12px;font-size:16px}
#olivia-app .ol-debug-bundle{position:sticky;top:18px;border-left:1px solid #e5e9f0;padding-left:22px}
#olivia-app .ol-debug-bundle-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
#olivia-app .ol-debug-bundle-head h2{margin:0}
#olivia-app textarea.ol-debug-json[readonly]{width:100%;min-height:540px;margin-top:10px;border:1px solid #1e293b;border-radius:8px;background:#0f172a;color:#e5e7eb;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;line-height:1.55;padding:14px;resize:vertical}
/* shared panel styling for the appended sections */
#olivia-app h2{font-size:18px;font-weight:800;color:#202124;margin:34px 0 8px}
#olivia-app .detail,#olivia-app p.detail{color:#6f7278;font-size:13px;margin:0 0 12px}
#olivia-app table.AdminDataTable{display:block;width:100%;max-width:100%;min-width:0;border:1px solid #dadce0;border-radius:8px;overflow-x:auto;font-size:13px;background:#fff;box-shadow:none}
#olivia-app table.AdminDataTable th{background:#f4f7fb;color:#5f6368;font-weight:800}
#olivia-app table.AdminDataTable td,#olivia-app table.AdminDataTable th{border-color:#eef1f5}
#olivia-app #olivia-generating{border:0;background:transparent}
#olivia-app .ol-activity-slot:empty{display:none}
#olivia-app .ol-activity-slot{flex:none;width:min(760px,100%);margin:14px auto 0}
#olivia-app textarea[readonly]{border:1px solid #dadce0;border-radius:8px;background:#fbfdff}
#olivia-app .InputfieldForm{background:#fff;border:1px solid #dadce0;border-radius:8px;padding:16px;box-shadow:none}
#olivia-app .InputfieldSubmit button,#olivia-app .InputfieldSubmit input[type=submit]{min-height:var(--ol-control-height);border-radius:var(--ol-control-radius);padding:0 14px;font-size:13px;line-height:1.2}
@media (min-width:701px) and (max-width:1100px){
	#olivia-app{padding-left:12px;padding-right:12px}
	#olivia-app .ol-app-shell,#olivia-app .ol-app-shell.is-sidebar-collapsed{grid-template-columns:72px minmax(0,1fr)}
	#olivia-app .ol-sidebar,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-sidebar{padding:20px 10px}
	#olivia-app .ol-side-brand,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-brand{justify-content:center;margin:0 0 28px;gap:0}
	#olivia-app .ol-side-brand strong,#olivia-app .ol-side-logo,#olivia-app .ol-side-actions span,#olivia-app .ol-chat-search input,#olivia-app .ol-chatlist{display:none}
	#olivia-app .ol-sidebar-toggle,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-sidebar-toggle{margin-left:0}
	#olivia-app .ol-side-actions{margin-bottom:0}
	#olivia-app .ol-side-actions a,#olivia-app .ol-side-actions button,#olivia-app .ol-side-actions label{justify-content:center;padding:0;width:var(--ol-control-height);margin:0 auto}
	#olivia-app .ol-main-panel{padding:22px 24px 28px}
	#olivia-app .ol-welcome h1{font-size:42px}
	#olivia-app .ol-welcome p{max-width:520px}
	#olivia-app .ol-chips{grid-template-columns:repeat(3,minmax(0,1fr))}
	#olivia-app .ol-chip{min-height:68px}
	#olivia-app .ol-debug-layout{grid-template-columns:minmax(0,1fr)}
	#olivia-app .ol-debug-bundle{position:static;border-top:1px solid #e5e9f0;border-left:0;padding-top:22px;padding-left:0}
}
@media (max-width:700px){
	#olivia-app{padding:18px 10px 36px}
	#olivia-app .ol-app-shell{display:block;min-height:auto;border-radius:12px}
	#olivia-app .ol-app-shell.is-sidebar-collapsed{display:block}
	#olivia-app .ol-sidebar{border-right:0;border-bottom:1px solid #edf1f6;min-height:0}
	#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-sidebar{padding:12px 14px}
	#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-brand{justify-content:flex-start;margin:0;gap:10px}
	#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-brand strong{display:inline}
	#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-logo{display:inline-flex}
	#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-sidebar-toggle{margin-left:auto}
	#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-side-actions,#olivia-app .ol-app-shell.is-sidebar-collapsed .ol-chatlist{display:none}
	#olivia-app .ol-main-panel{min-height:720px;padding:18px 14px 24px}
	#olivia-app .ol-panel-top{align-items:flex-start;gap:10px}
	#olivia-app .ol-welcome{min-height:0;padding:28px 0 24px}
	#olivia-app .ol-chattrail{padding-bottom:24px}
	#olivia-app .ol-welcome h1{font-size:34px}
	#olivia-app .ol-welcome p{font-size:13px}
	#olivia-app .ol-composer{position:relative;left:auto;right:auto;bottom:auto;transform:none;width:100%;max-width:none;margin:18px auto 0}
	#olivia-app .ol-hero{margin-top:12px;padding:0 6px}
	#olivia-app .ol-greet{font-size:29px}
	#olivia-app .ol-sub{font-size:15px}
	#olivia-app .ol-hero{display:block}
	#olivia-app .ol-status{display:none}
	#olivia-app .ol-workspace{display:block;margin-top:16px}
	#olivia-app .ol-chatlist{margin:0 0 12px}
	#olivia-app .ol-prompt{min-height:82px}
	#olivia-app .ol-prompt-foot{flex-wrap:wrap}
	#olivia-app .ol-prompt-foot [data-ref-summary]{order:3;max-width:100%;flex:1 1 100%}
	#olivia-app .ol-refnotes{min-height:38px}
	#olivia-app .ol-bar{align-items:stretch;flex-direction:column}
	#olivia-app .ol-modes{position:static;grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:10px}
	#olivia-app .ol-actions,#olivia-app .ol-send,#olivia-app .ol-ref-open{width:100%}
	#olivia-app .ol-actions{align-items:stretch;flex-direction:column}
	#olivia-app .ol-ref-open,#olivia-app .ol-send{justify-content:center}
	#olivia-app .ol-mode{text-align:left;min-height:0}
	#olivia-app .ol-send{justify-content:center}
	#olivia-app .ol-chips{grid-template-columns:1fr}
	#olivia-app .ol-chip{min-height:0;width:100%}
	#olivia-app .ol-chip-meta{font-size:11.5px}
	#olivia-app .ol-select{min-width:100%;width:100%}
	#olivia-app .ol-json-badges{flex-basis:100%;width:100%}
	#olivia-app .ol-json-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));width:100%;margin-left:0}
	#olivia-app .ol-json-actions .ol-btn{min-width:0;padding-left:8px;padding-right:8px}
	#olivia-app .ol-shortcut{grid-template-columns:minmax(0,1fr);align-items:start;gap:7px}
	#olivia-app .ol-shortcut kbd{max-width:100%;white-space:normal}
	#olivia-app .ol-advrow .ol-btn{flex:1}
	#olivia-app .ol-utilnav{display:flex;align-items:stretch;flex-direction:column}
	#olivia-app .ol-utilbrand{order:-1;justify-content:flex-start}
	#olivia-app .ol-utilnav-links{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}
	#olivia-app .ol-utilnav a{justify-content:center}
	#olivia-app .ol-page-head{align-items:stretch;flex-direction:column}
	#olivia-app .ol-page{padding:20px}
	#olivia-app .ol-stat-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
	#olivia-app .ol-stat-strip>div{border-bottom:1px solid #e5e9f0}
	#olivia-app .ol-debug-layout{grid-template-columns:minmax(0,1fr)}
	#olivia-app .ol-debug-bundle{position:static;border-top:1px solid #e5e9f0;border-left:0;padding-top:22px;padding-left:0}
}
@media (min-width:960px){
	#content{width:calc(100% + 80px);margin-left:-40px;margin-right:-40px}
}
/* "Olivia is thinking" placeholder — shown instantly on submit and while a job runs */
#olivia-app .ol-thinking{display:flex;align-items:flex-start;gap:14px;margin:0;padding:16px 18px;border:1px solid #d2e3fc;border-radius:8px;background:#f8fbff;overflow:hidden}
#olivia-app .ol-thinking-spark{flex:none;display:inline-grid;place-items:center;width:34px;height:34px;border-radius:9px;background:#1a73e8;color:#fff;font-size:18px;animation:ol-pulse 1.4s ease-in-out infinite}
#olivia-app .ol-thinking-content{flex:1;min-width:0}
#olivia-app .ol-thinking-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
#olivia-app .ol-thinking-main{font-weight:800;font-size:15px;color:#202124}
#olivia-app .ol-thinking-sub{margin-top:2px;font-size:13px;color:#5f6368;min-height:1.2em;transition:opacity .25s}
#olivia-app .ol-thinking-elapsed{flex:none;min-width:42px;color:#5f6368;font:750 11px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;text-align:right;font-variant-numeric:tabular-nums}
#olivia-app .ol-thinking-track{height:3px;margin-top:11px;border-radius:3px;background:#dce9fc;overflow:hidden}
#olivia-app .ol-thinking-track span{display:block;width:34%;height:100%;border-radius:inherit;background:#1a73e8;animation:ol-progress 1.6s ease-in-out infinite}
#olivia-app .ol-thinking-note{margin-top:8px;color:#7a8494;font-size:11px;line-height:1.4}
@keyframes ol-pulse{0%,100%{transform:scale(1);opacity:.85}50%{transform:scale(1.14);opacity:1}}
@keyframes ol-progress{0%{transform:translateX(-110%)}100%{transform:translateX(310%)}}
#olivia-app .ol-thinking-overlay{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(247,249,252,.82)}
#olivia-app .ol-thinking-overlay .ol-thinking{min-width:340px;background:#fff}
@media (max-width:700px){
	#olivia-app .ol-activity-slot{margin-top:10px}
	#olivia-app .ol-thinking{padding:14px}
	#olivia-app .ol-thinking-note{font-size:10px}
}
@media (prefers-reduced-motion:reduce){
	#olivia-app .ol-thinking-spark,#olivia-app .ol-thinking-track span{animation:none}
	#olivia-app .ol-thinking-track span{width:100%;opacity:.7}
}
/* theme picker */
#olivia-app .ol-swatches{display:inline-flex;gap:6px;flex-wrap:wrap;align-items:center}
#olivia-app .ol-swatch{width:24px;height:24px;border-radius:var(--ol-control-radius);border:1px solid rgba(0,0,0,.12);cursor:pointer;padding:0}
#olivia-app .ol-swatch.is-active{box-shadow:0 0 0 2px #fff,0 0 0 4px #93b4ff}
#olivia-app .ol-color{width:46px;height:40px;padding:3px;border:1px solid #dadce0;border-radius:8px;background:#fff;cursor:pointer}
#olivia-app .ol-design-system{margin:4px 0 14px;padding:10px 0 13px;border-bottom:1px solid #eef0f2}
#olivia-app .ol-design-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
#olivia-app .ol-design-head .ol-lbl{display:inline-flex;align-items:center;gap:7px}
#olivia-app .ol-design-tokens{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
#olivia-app .ol-design-token{display:inline-flex;align-items:center;gap:6px;min-height:28px;padding:4px 7px;background:#f8f9fa;border-radius:7px;color:#3c4043;font-size:11px}
#olivia-app .ol-design-token code{font-size:11px;color:#202124}
#olivia-app .ol-design-key{color:#80868b;text-transform:capitalize}
#olivia-app .ol-design-color{width:14px;height:14px;border-radius:4px;border:1px solid rgba(32,33,36,.14);flex:none}
/* "Olivia will install these modules" opt-in shown above Build */
#olivia-app .ol-modinstall{display:flex;align-items:center;gap:9px;margin:12px 0 6px;font-size:14px;font-weight:700;color:#202124;cursor:pointer}
#olivia-app .ol-modinstall input{width:16px;height:16px;accent-color:#1a73e8}
#olivia-app .ol-modinstall input:focus-visible{outline:2px solid #93b4ff;outline-offset:2px}
#olivia-app .ol-modlist{margin:0 0 6px 26px;padding:0;list-style:disc;color:#3c4043;font-size:13px}
#olivia-app .ol-modlist li{margin:2px 0}
</style>
HTML;
	}

}
