<?php namespace ProcessWire;

trait OliviaAdminViews {

	/** "Generating…" placeholder + JS poller that redirects when the job is done. */
	/**
	 * Per-job-kind UI copy: reload param, main line and rotating sub-messages.
	 * Shared by the server-rendered placeholder and the instant client overlay
	 * (emitted to JS in renderForm), so both look identical.
	 */
	protected function thinkingLabels(): array {
		return [
			'plan'      => ['olivia_planjob',  'Olivia is thinking…',          ['Designing the site structure…', 'Choosing templates and fields…', 'Writing realistic demo content…', 'Almost there…']],
			'questions' => ['olivia_qjob',     'Olivia is thinking…',          ['Working out what to ask…', 'Tailoring questions to your idea…']],
			'audit'     => ['olivia_auditjob', 'Olivia is reviewing your site…',['Reading your templates and pages…', 'Spotting quick wins…', 'Prioritizing improvements…']],
			'build'     => ['olivia_buildjob', 'Olivia is building…',          ['Creating fields and templates…', 'Generating pages…', 'Generating images…', 'Finishing up…']],
			'install'   => ['olivia_installjob','Olivia is installing…',        ['Downloading from the directory…', 'Unzipping and installing…', 'Learning the module’s skills…']],
			'preview'   => ['',                'Olivia is previewing…',        ['Doing a dry run — no changes yet…']],
			'skills'    => ['',                'Olivia is refreshing skills…', ['Reading installed modules…', 'Recording what Olivia learns…']],
			'learn'     => ['',                'Olivia is learning the module…',['Fetching its AGENTS.md…', 'Recording the skill…']],
			'working'   => ['',                'Olivia is working…',           ['One moment…']],
		];
	}

	/** The "Olivia is thinking" card markup for a kind (rotated client-side). */
	protected function thinking(string $kind): string {
		$set  = $this->thinkingLabels()[$kind] ?? $this->thinkingLabels()['plan'];
		$main = $this->wire->sanitizer->entities($set[1]);
		$subs = $set[2];
		$first = $this->wire->sanitizer->entities($subs[0] ?? '');
		$data = htmlspecialchars(json_encode(array_values($subs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$mainId = 'ol-thinking-main';
		$subId = 'ol-thinking-sub';
		return "<div class=\"ol-thinking\" role=\"status\" aria-live=\"polite\" aria-atomic=\"true\" aria-labelledby=\"{$mainId}\" aria-describedby=\"{$subId}\"><span class=\"ol-thinking-spark\"><i class=\"ri-loader-4-line\" aria-hidden=\"true\"></i></span>"
			. "<div class=\"ol-thinking-content\"><div class=\"ol-thinking-head\"><div id=\"{$mainId}\" class=\"ol-thinking-main\">{$main}</div><span class=\"ol-thinking-elapsed\" data-thinking-elapsed aria-label=\"Elapsed time\">0:00</span></div>"
			. "<div id=\"{$subId}\" class=\"ol-thinking-sub\" data-subs=\"{$data}\">{$first}</div>"
			. "<div class=\"ol-thinking-track\" aria-hidden=\"true\"><span></span></div>"
			. "<div class=\"ol-thinking-note\">Running in the background. You can leave this page; Olivia will continue.</div></div></div>";
	}

	protected function renderGenerating(string $jobId, string $kind, string $chatId = ''): string {
		$param = $this->thinkingLabels()[$kind][0] ?? 'olivia_planjob';
		$card  = $this->thinking($kind);
		$jsonFlags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$jobIdJs = json_encode($jobId, $jsonFlags);
		$paramJs = json_encode($param, $jsonFlags);
		$queryPrefix = $chatId !== '' ? 'chat=' . rawurlencode($chatId) . '&' : '';
		$queryPrefixJs = json_encode($queryPrefix, $jsonFlags);
		return <<<HTML
<div id="olivia-generating" role="region" aria-labelledby="ol-thinking-main" aria-describedby="ol-thinking-sub" aria-busy="true">{$card}</div>
<script>
(function(){
	var id = {$jobIdJs};
	var queryPrefix = {$queryPrefixJs};
	var jobParam = {$paramJs};
	var thinkingMainId = "ol-thinking-main";
	var thinkingSubId = "ol-thinking-sub";
	var t0 = Date.now();
	var generating = document.getElementById("olivia-generating");
	var activitySlot = document.querySelector("[data-activity-slot]");
	var composer = document.querySelector(".ol-composer");
	var operationButtons = composer ? Array.prototype.slice.call(composer.querySelectorAll('button[type="submit"]')) : [];
	var elapsedTimer = null;
	var MAX_POLL_TIME = 11 * 60 * 1000; // last-resort backstop if the status endpoint is unreachable;
	                                    // the server watchdog normally resolves (done/error) well before this.
	var POLL_INTERVAL = 2500;
	var RETRY_INTERVAL = 3500;
	var JOB_STATUS_TIMEOUT = 10000;
	if(generating && activitySlot) activitySlot.appendChild(generating);
	if(composer) {
		composer.classList.add("is-working");
		composer.setAttribute("aria-busy", "true");
	}
	operationButtons.forEach(function(button){
		button.__oliviaWasDisabled = button.disabled;
		button.disabled = true;
		button.setAttribute("aria-disabled", "true");
	});
	function unlockComposer(){
		if(composer) {
			composer.classList.remove("is-working");
			composer.setAttribute("aria-busy", "false");
		}
		operationButtons.forEach(function(button){
			button.disabled = !!button.__oliviaWasDisabled;
			button.setAttribute("aria-disabled", button.disabled ? "true" : "false");
		});
	}
	function elapsedText(milliseconds){
		var seconds = Math.max(0, Math.floor(milliseconds / 1000));
		var minutes = Math.floor(seconds / 60);
		return minutes + ":" + String(seconds % 60).padStart(2, "0");
	}
	function updateElapsed(){
		var elapsed = document.querySelector("#olivia-generating [data-thinking-elapsed]");
		if(elapsed) elapsed.textContent = elapsedText(Date.now() - t0);
	}
	updateElapsed();
	elapsedTimer = setInterval(updateElapsed, 1000);
	function go(){ window.location = "./?" + queryPrefix + jobParam + "=" + encodeURIComponent(id); }
	function showTerminal(icon, main, subHtml){
		var el = document.getElementById("olivia-generating");
		if(elapsedTimer) clearInterval(elapsedTimer);
		unlockComposer();
		if(el) {
			el.setAttribute("aria-busy", "false");
			el.innerHTML = '<div class="ol-thinking" role="status" aria-live="polite" aria-atomic="true" aria-labelledby="' + thinkingMainId + '" aria-describedby="' + thinkingSubId + '"><span class="ol-thinking-spark"><i class="' + icon + '" aria-hidden="true"></i></span>'
				+ '<div class="ol-thinking-content"><div id="' + thinkingMainId + '" class="ol-thinking-main">' + main + '</div>'
				+ '<div id="' + thinkingSubId + '" class="ol-thinking-sub">' + subHtml + '</div></div></div>';
		}
	}
	function giveUp(){
		showTerminal("ri-loader-4-line", "This is taking longer than expected.", 'The worker may have stalled — <a href="./?' + queryPrefix + jobParam + '=' + encodeURIComponent(id) + '" title="Check Olivia worker result" aria-label="Check Olivia worker result" aria-controls="olivia-generating" aria-describedby="' + thinkingSubId + '">check the result</a> or reload the page.');
	}
	function jobMissing(){
		showTerminal("ri-error-warning-line", "The background job was not found.", "Start the request again or reload Olivia.");
	}
	function fetchJobStatus(){
		var url = "./job/?id=" + encodeURIComponent(id);
		var options = {cache:"no-store", headers:{"Accept":"application/json","X-Requested-With":"XMLHttpRequest"}};
		if(typeof AbortController !== "function") return fetch(url, options);
		var controller = new AbortController();
		var timeout = setTimeout(function(){ controller.abort(); }, JOB_STATUS_TIMEOUT);
		options.signal = controller.signal;
		return fetch(url, options).then(function(response){
			clearTimeout(timeout);
			return response;
		}, function(error){
			clearTimeout(timeout);
			throw error;
		});
	}
	function poll(){
		if(Date.now() - t0 > MAX_POLL_TIME){ giveUp(); return; }
		fetchJobStatus()
			.then(function(r){ if(!r.ok) throw new Error("job status " + r.status); return r.json(); })
			.then(function(d){
				if(!d || typeof d !== "object") throw new Error("bad job status payload");
				if(d.status === "missing"){ jobMissing(); return; }
				if(d.status === "done" || d.status === "error"){ go(); }
				else { setTimeout(poll, POLL_INTERVAL); }
			})
			.catch(function(){ setTimeout(poll, RETRY_INTERVAL); });
	}
	setTimeout(poll, POLL_INTERVAL);
})();
</script>
HTML;
	}

	protected function renderChatList(string $activeId): string {
		$threads = array_slice($this->chats()->all(), 0, 12);
		$h = fn($s) => $this->wire->sanitizer->entities((string) $s);
		$count = count($threads);
		$countLabel = $count . ' saved ' . ($count === 1 ? 'chat' : 'chats');
		$debugHref = './?view=debug' . ($activeId !== '' ? '&chat=' . rawurlencode($activeId) : '');
		$out = "<aside class='ol-sidebar' aria-label='Olivia chats and tools' aria-describedby='ol-chatlist-title'>"
			. "<div class='ol-side-brand'><span class='ol-side-logo'><i class='ri-sparkling-2-line' aria-hidden='true'></i></span><strong>Olivia</strong><button type='button' class='ol-sidebar-toggle' aria-controls='ol-app-shell' aria-label='Collapse sidebar' aria-keyshortcuts='Meta+Backslash Control+Backslash' aria-pressed='false' aria-expanded='true' title='Collapse sidebar - Command/Ctrl + Backslash'><i class='ri-side-bar-line' aria-hidden='true'></i></button></div>"
			. "<nav class='ol-side-actions' aria-label='Olivia tools'>"
			. "<a class='ol-side-primary' href='./?new=1' title='New chat - Command/Ctrl + Shift + N' aria-label='New chat - Command/Ctrl + Shift + N' aria-keyshortcuts='Meta+Shift+N Control+Shift+N'><i class='ri-edit-box-line' aria-hidden='true'></i><span>New chat</span></a>"
			. "<label class='ol-chat-search' role='search' aria-label='Saved chat search' title='Search saved chats - Command/Ctrl + Shift + F'><i class='ri-search-line' aria-hidden='true'></i><input id='ol-chat-search-input' type='search' class='ol-chat-search-input' placeholder='Search in chats' aria-label='Search saved chats - Command/Ctrl + Shift + F' aria-keyshortcuts='Meta+Shift+F Control+Shift+F Escape' aria-describedby='ol-chat-search-status' aria-controls='ol-saved-chats'><span class='ol-sr-only' id='ol-chat-search-status' data-chat-search-status role='status' aria-live='polite' aria-atomic='true'>Showing {$countLabel}.</span></label>"
			. "<a href='./?view=history' title='Open build history page' aria-label='Open build history page'><i class='ri-history-line' aria-hidden='true'></i><span>Build history</span></a>"
			. "<a href='./?view=skills' title='Open module skills page' aria-label='Open module skills page'><i class='ri-graduation-cap-line' aria-hidden='true'></i><span>Module skills</span></a>"
			. "<button type='button' data-help-open title='Open keyboard shortcuts - ?' aria-label='Open keyboard shortcuts - ?' aria-keyshortcuts='?' aria-controls='ol-help-modal' aria-describedby='ol-help-desc ol-help-note' aria-expanded='false' aria-haspopup='dialog'><i class='ri-keyboard-line' aria-hidden='true'></i><span>Shortcuts</span></button>"
			. "<a href='{$h($debugHref)}' title='Open support debug bundle' aria-label='Open support debug bundle'><i class='ri-bug-line' aria-hidden='true'></i><span>Support info</span></a>"
			. "</nav>"
			. "<nav id='ol-saved-chats' class='ol-chatlist' aria-labelledby='ol-chatlist-title'><div class='ol-chatlist-head'><span id='ol-chatlist-title'>Chats</span><em data-chat-count='{$count}' title='{$countLabel}' aria-label='{$countLabel}' aria-controls='ol-saved-chats' aria-describedby='ol-chatlist-title ol-chat-search-status'>{$count}</em></div>";
		if(!$threads) {
			return $out . "<div id='ol-chat-empty-state' class='ol-chat-empty' role='status' aria-live='polite' aria-atomic='true'>No saved chats yet.</div></nav></aside>";
		}
		$lastGroup = '';
		foreach($threads as $idx => $t) {
			$rawId = (string)($t['id'] ?? '');
			$id = $h($rawId);
			$chatHref = './?chat=' . rawurlencode($rawId);
			$title = $h($t['title'] ?? 'New chat');
			$count = (int)($t['message_count'] ?? 0);
			$messageLabel = $count . ' ' . ($count === 1 ? 'message' : 'messages');
			$updatedRaw = (string)($t['updated'] ?? '');
			$updated = $h($this->relativeTime($updatedRaw));
			$group = $this->chatDateGroup($updatedRaw);
			if($group !== $lastGroup) {
				$out .= "<div class='ol-chat-group' role='heading' aria-level='3'>" . $h($group) . "</div>";
				$lastGroup = $group;
			}
			$active = $activeId !== '' && $activeId === $rawId ? ' is-active' : '';
			$current = $active !== '' ? " aria-current='page'" : '';
			$rowId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($t['id'] ?? ''));
			$rowId = 'chat-' . (int)$idx . ($rowId !== '' ? '-' . $rowId : '');
			$menuId = 'ol-chat-menu-' . $rowId;
			$rowTitleId = 'ol-chat-title-' . $rowId;
			$rowMetaId = 'ol-chat-meta-' . $rowId;
			$search = $h(strtolower((string)($t['title'] ?? '') . ' ' . ($t['prompt'] ?? '') . ' ' . ($t['id'] ?? '')));
			$out .= "<div class='ol-chatrow{$active}' data-search='{$search}'>"
				. "<a class='ol-chatitem' href='{$h($chatHref)}' title='Open chat: {$title}' aria-labelledby='{$rowTitleId}' aria-describedby='{$rowMetaId}'{$current}>"
				. "<span id='{$rowTitleId}' class='ol-chat-title'>{$title}</span>"
				. "<span id='{$rowMetaId}' class='ol-chat-meta'>{$messageLabel} · {$updated}</span>"
				. "</a>"
				. "<details class='ol-chat-menu'><summary title='Chat actions: {$title}' aria-label='Chat actions: {$title}' aria-describedby='{$rowTitleId} {$rowMetaId}' aria-haspopup='true' aria-expanded='false' aria-controls='{$menuId}'><i class='ri-more-line' aria-hidden='true'></i></summary>"
				. "<div id='{$menuId}' class='ol-chat-menu-pop' role='group' aria-label='Chat actions for {$title}' aria-describedby='{$rowMetaId}'>"
				. "<form method='post' action='" . ($active ? $h($chatHref) : './') . "' aria-label='Rename chat: {$title}' aria-describedby='{$rowMetaId}' aria-controls='ol-saved-chats'>"
				. "<input type='hidden' name='olivia_chat_manage_id' value='{$id}'>"
				. "<input type='text' name='olivia_chat_title' value='{$title}' class='ol-chat-title-input' aria-label='Rename chat: {$title}' title='Rename chat: {$title}' aria-describedby='{$rowMetaId}' aria-controls='ol-saved-chats'>"
				. "<button type='submit' name='submit_chat_rename' value='1' class='ol-menu-btn' aria-label='Rename chat: {$title}' title='Rename chat: {$title}' aria-describedby='{$rowMetaId}' aria-controls='ol-saved-chats'><i class='ri-edit-line' aria-hidden='true'></i> Rename</button>"
				. "</form>"
				. "<form method='post' action='./' aria-label='Delete chat: {$title}' aria-describedby='{$rowMetaId}' aria-controls='ol-saved-chats' onsubmit=\"return confirm('Delete this chat?')\">"
				. "<input type='hidden' name='olivia_chat_manage_id' value='{$id}'>"
				. "<button type='submit' name='submit_chat_delete' value='1' class='ol-menu-btn ol-danger' aria-label='Delete chat: {$title}' title='Delete chat: {$title}' aria-describedby='{$rowMetaId}' aria-controls='ol-saved-chats'><i class='ri-delete-bin-line' aria-hidden='true'></i> Delete</button>"
				. "</form>"
				. "</div></details>"
				. "</div>";
		}
		return $out . "<div id='ol-chat-noresults-state' class='ol-chat-empty ol-chat-noresults' role='status' aria-live='polite' aria-atomic='true' aria-describedby='ol-chat-search-status' hidden><span>No chats match <strong data-chat-query></strong>.</span><button type='button' class='ol-chat-clear-search' title='Clear chat search' aria-label='Clear chat search' aria-keyshortcuts='Escape' aria-controls='ol-chat-search-input ol-saved-chats' aria-describedby='ol-chat-search-status'>Clear search</button></div></nav></aside>";
	}

	protected function renderChatTrail(string $chatId): string {
		if($chatId === '') return '';
		$chat = $this->chats()->load($chatId);
		if(!$chat || empty($chat['messages'])) return '';
		$h = fn($s) => $this->wire->sanitizer->entities((string) $s);
		$ha = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$total = count($chat['messages']);
		$visible = array_slice($chat['messages'], -20);
		$visibleCount = count($visible);
		$totalLabel = $total . ' ' . ($total === 1 ? 'message' : 'messages');
		$visibleLabel = $visibleCount . ' ' . ($visibleCount === 1 ? 'message' : 'messages');
		$range = $visibleCount < $total ? 'Showing last ' . $visibleLabel . ' of ' . $totalLabel : $totalLabel;
		$chatHref = './?chat=' . rawurlencode($chatId);
		$out = "<section class='ol-chattrail' aria-labelledby='ol-chattrail-title' aria-describedby='ol-chattrail-range'><div class='ol-chattrail-head'>"
			. "<div><h2 id='ol-chattrail-title'>Chat history</h2><span id='ol-chattrail-range'>{$h($range)}</span></div>"
			. "<a href='{$h($chatHref)}' title='Continue this chat' aria-describedby='ol-chattrail-title ol-chattrail-range'>Continue here</a></div>";
		$icons = [
			'prompt' => 'ri-chat-new-line', 'audit_request' => 'ri-search-eye-line', 'answers' => 'ri-question-answer-line',
			'plan' => 'ri-file-list-3-line', 'questions' => 'ri-question-line', 'preview' => 'ri-eye-line',
			'build_request' => 'ri-flashlight-line', 'build' => 'ri-check-double-line', 'audit' => 'ri-survey-line',
			'error' => 'ri-error-warning-line',
		];
		$labels = [
			'prompt' => 'Prompt', 'audit_request' => 'Improve request', 'answers' => 'Answers',
			'plan' => 'Plan', 'questions' => 'Questions', 'preview' => 'Preview',
			'build_request' => 'Build request', 'build' => 'Build', 'audit' => 'Audit',
			'error' => 'Error',
		];
		foreach($visible as $msgIndex => $m) {
			$role = (string)($m['role'] ?? 'assistant');
			$type = (string)($m['type'] ?? 'message');
			$text = trim((string)($m['text'] ?? ''));
			if($text === '' && $type === 'questions') $text = 'Interview questions ready.';
			$label = $role === 'user' ? 'You' : 'Olivia';
			$typeLabel = $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
			$messageText = $text !== '' ? $text : $typeLabel . ' recorded without message text.';
			$icon = $icons[$type] ?? ($role === 'user' ? 'ri-user-line' : 'ri-sparkling-2-line');
			$created = (string)($m['created'] ?? '');
			$when = $created !== '' ? $this->relativeTime($created) : '';
			$timeHtml = $when !== '' ? "<time datetime='{$h($created)}' title='Message saved {$h($created)}' aria-label='Message saved {$h($created)}'>{$h($when)}</time>" : '';
			$meta = is_array($m['meta'] ?? null) ? $m['meta'] : [];
			$pills = '';
			$sourceLinks = '';
			if($type === 'build' && !empty($meta['result']['counts']) && is_array($meta['result']['counts'])) {
				$c = $meta['result']['counts'];
				$countLabels = ['fields' => 'field', 'templates' => 'template', 'pages' => 'page', 'views' => 'view', 'images' => 'image'];
				foreach($countLabels as $k => $countLabel) {
					if(isset($c[$k])) {
						$count = (int)$c[$k];
						if($k === 'images' && $count === 0) continue;
						$pills .= "<span role='listitem'>{$count} " . ($count === 1 ? $countLabel : $countLabel . 's') . "</span>";
					}
				}
			} elseif($type === 'questions' && !empty($meta['questions']) && is_array($meta['questions'])) {
				$count = count($meta['questions']);
				$pills .= "<span role='listitem'>{$count} " . ($count === 1 ? 'question' : 'questions') . "</span>";
			} elseif($type === 'audit' && !empty($meta['audit']['findings']) && is_array($meta['audit']['findings'])) {
				$count = count($meta['audit']['findings']);
				$pills .= "<span role='listitem'>{$count} " . ($count === 1 ? 'finding' : 'findings') . "</span>";
			} elseif($type === 'plan' && !empty($meta['job'])) {
				$pills .= "<span role='listitem'>Job " . $h($meta['job']) . "</span>";
			}
			$visual = is_array($meta['visual'] ?? null) ? $meta['visual'] : null;
			if($visual !== null && $this->visualStatusIsInformative($visual)) {
				if(!empty($visual['ok'])) {
					$imageCount = max(0, min(OliviaVisualAnalyzer::MAX_IMAGES, (int)($visual['images'] ?? 0)));
					$model = $this->clipText((string)($visual['model'] ?? 'visual model'), 40);
					$source = (string)($visual['source'] ?? 'none');
					$sourceLabel = $source === 'screenshotone' ? 'browser capture' : ($source === 'uploaded' ? 'uploaded' : 'references');
					$pills .= "<span role='listitem'>Vision: {$sourceLabel} · {$imageCount} image" . ($imageCount === 1 ? '' : 's') . " · " . $h($model) . "</span>";
				} else {
					$reason = $this->clipText((string)($visual['reason'] ?? 'unavailable'), 40);
					$pills .= "<span role='listitem'>Vision fallback · " . $h($reason) . "</span>";
				}
			}
			$web = is_array($meta['web'] ?? null) ? $meta['web'] : null;
			if($web !== null && !empty($web['enabled'])) {
				$rawSources = is_array($web['sources'] ?? null) ? array_slice($web['sources'], 0, OliviaPlanner::WEB_SEARCH_MAX_RESULTS) : [];
				$sources = [];
				foreach($rawSources as $source) {
					if(!is_array($source)) continue;
					$url = trim((string)($source['url'] ?? ''));
					$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
					if(!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) continue;
					$sources[] = $source;
				}
				if(!empty($web['ok'])) {
					$count = count($sources);
					$pills .= "<span role='listitem'>Web research · {$count} source" . ($count === 1 ? '' : 's') . "</span>";
					foreach($sources as $source) {
						$url = trim((string)($source['url'] ?? ''));
						$title = $this->clipText(trim((string)($source['title'] ?? 'Source')), 80);
						if($title === '') $title = (string)(parse_url($url, PHP_URL_HOST) ?: 'Source');
						$sourceLinks .= "<a href='" . $ha($url) . "' target='_blank' rel='noopener noreferrer' title='Open source: " . $ha($title) . "'><i class='ri-external-link-line' aria-hidden='true'></i> " . $h($title) . "</a>";
					}
				} elseif(!empty($web['fallback'])) {
					$pills .= "<span role='listitem'>Web fallback · plan completed without search</span>";
				}
			}
			$actions = '';
			if($role === 'user' && $text !== '' && in_array($type, ['prompt', 'audit_request'], true)) {
				$savedMode = (string)($meta['mode'] ?? '');
				$restoreMode = in_array($savedMode, $this->composerModeValues(), true) ? $savedMode : ($type === 'audit_request' ? 'operate' : 'direct');
				$restoreLabels = $this->composerModeLabels();
				$restoreLabel = $restoreLabels[$restoreMode] ?? ucfirst($restoreMode);
				$restoreAction = 'Use this prompt again in ' . $restoreLabel . ' mode';
				$actions = "<button type='button' class='ol-msg-use' data-fill=\"" . $ha($text) . "\" data-mode='{$h($restoreMode)}' title='{$h($restoreAction)}' aria-label='{$h($restoreAction)}' aria-controls='ol-main-prompt'><i class='ri-corner-down-left-line' aria-hidden='true'></i> Use again</button>";
			}
			$classRole = str_replace('_', '-', $this->wire->sanitizer->name($role)) ?: 'assistant';
			$classType = str_replace('_', '-', $this->wire->sanitizer->name($type)) ?: 'message';
			$msgId = 'ol-msg-' . (int)$msgIndex;
			$msgMetaId = $msgId . '-meta';
			$msgTextId = $msgId . '-text';
			if($actions !== '') {
				$actions = str_replace("aria-controls='ol-main-prompt'", "aria-controls='ol-main-prompt' aria-describedby='{$msgMetaId} {$msgTextId}'", $actions);
			}
			$out .= "<article class='ol-msg ol-msg-{$h($classRole)} ol-msg-type-{$h($classType)}' aria-labelledby='{$msgMetaId}' aria-describedby='{$msgTextId}'>"
				. "<div class='ol-msg-icon' aria-hidden='true'><i class='{$h($icon)}'></i></div>"
				. "<div class='ol-msg-body'>"
				. "<div id='{$msgMetaId}' class='ol-msg-meta'><strong>{$h($label)}</strong><span>{$h($typeLabel)}</span>{$timeHtml}</div>"
				. "<div id='{$msgTextId}' class='ol-msg-text'>" . nl2br($h($messageText)) . "</div>"
				. ($pills !== '' ? "<div class='ol-msg-pills' role='list' aria-label='{$h($typeLabel)} metadata' aria-describedby='{$msgMetaId} {$msgTextId}'>{$pills}</div>" : '')
				. ($sourceLinks !== '' ? "<div class='ol-msg-sources' aria-label='Web research sources' aria-describedby='{$msgMetaId} {$msgTextId}'>{$sourceLinks}</div>" : '')
				. ($actions !== '' ? "<div class='ol-msg-actions' role='group' aria-label='{$h($typeLabel)} actions' aria-describedby='{$msgMetaId} {$msgTextId}'>{$actions}</div>" : '')
				. "</div></article>";
		}
		return $out . "</section>";
	}

	protected function relativeTime(string $date): string {
		$t = $date !== '' ? strtotime($date) : 0;
		if(!$t) return 'unknown';
		$diff = max(0, time() - $t);
		if($diff < 60) return 'now';
		if($diff < 3600) return floor($diff / 60) . 'm ago';
		if($diff < 86400) return floor($diff / 3600) . 'h ago';
		return date('M j', $t);
	}

	protected function chatDateGroup(string $date): string {
		$t = $date !== '' ? strtotime($date) : 0;
		if(!$t) return 'Earlier';
		$today = strtotime('today');
		if($t >= $today) return 'Today';
		if($t >= $today - 86400) return 'Yesterday';
		return 'Earlier';
	}

	protected function renderInterview(array $questions, string $basePrompt, string $chatId = '', bool $webSearch = false): string {
		$m = $this->wire->modules;
		/** @var InputfieldForm $form */
		$form = $m->get('InputfieldForm');
		$form->action = $chatId !== '' ? './?chat=' . rawurlencode($chatId) : './';
		$form->method = 'post';
		$form->description = 'Olivia has a few questions. Answer what you can — blanks are fine.';

		$hp = $m->get('InputfieldHidden'); $hp->name = 'olivia_base_prompt'; $hp->value = $basePrompt; $form->add($hp);
		$hm = $m->get('InputfieldHidden'); $hm->name = 'olivia_mode'; $hm->value = 'interview'; $form->add($hm);
		$hid = $m->get('InputfieldHidden'); $hid->name = 'olivia_chat_id'; $hid->value = $chatId; $form->add($hid);
		$hc = $m->get('InputfieldHidden'); $hc->name = 'olivia_qcount'; $hc->value = count($questions); $form->add($hc);
		$hw = $m->get('InputfieldHidden'); $hw->name = 'olivia_web_search'; $hw->value = $webSearch ? '1' : '0'; $form->add($hw);

		foreach($questions as $i => $q) {
			$qtext = (string) ($q['q'] ?? '');
			$opts  = $q['options'] ?? [];

			$hq = $m->get('InputfieldHidden'); $hq->name = "olivia_qtext_$i"; $hq->value = $qtext; $form->add($hq);

			if(is_array($opts) && count($opts)) {
				$f = $m->get('InputfieldRadios');
				foreach($opts as $opt) $f->addOption((string)$opt, (string)$opt);
			} else {
				$f = $m->get('InputfieldText');
			}
			$f->name = "olivia_q_$i";
			$f->label = ($i + 1) . '. ' . $qtext;
			$form->add($f);
		}

		$s = $m->get('InputfieldSubmit');
		$s->name = 'submit_answers';
		$s->value = 'Build plan from answers';
		$s->icon = 'magic';
		$form->add($s);

		return '<h2>Interview</h2>' . $form->render();
	}

	/** Render the Operate-mode audit: a prioritized list of improvements. */
	protected function renderAudit(array $audit): string {
		$h = function($s) { return $this->wire->sanitizer->entities((string)$s); };
		$findings = $audit['findings'] ?? [];
		if(!is_array($findings) || !$findings) {
			return '<div style="margin:1em 0;padding:1em 1.25em;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#64748b">No findings — the site looks complete to Olivia.</div>';
		}
		$colors = ['high' => '#dc2626', 'medium' => '#d97706', 'low' => '#64748b'];
		$summary = trim((string)($audit['summary'] ?? ''));

		$rows = '';
		foreach($findings as $f) {
			if(!is_array($f)) continue;
			$sev   = strtolower((string)($f['severity'] ?? 'low'));
			if(!isset($colors[$sev])) $sev = 'low';
			$col   = $colors[$sev];
			$titleRaw = (string)($f['title'] ?? 'Improvement');
			$titleShort = $this->clipText($titleRaw, 80);
			$title = $h($titleShort);
			$titleFull = $h($titleRaw);
			$areaRaw = trim((string)($f['area'] ?? ''));
			$areaShort = $this->clipText($areaRaw, 32);
			$area = $h($areaShort);
			$areaFull = $h($areaRaw);
			$why   = trim((string)($f['why'] ?? ''));
			$sug   = trim((string)($f['suggestion'] ?? ''));
			$whyShort = $this->clipText($why, 220);
			$sugShort = $this->clipText($sug, 260);
			$cp    = trim((string)($f['change_prompt'] ?? ''));

			$btn = '';
			if($cp !== '') {
				$applyLabel = 'Apply suggestion in Change mode: ' . $titleRaw;
				$btn = "<button type='button' class='ol-fix' data-fill=\"" . $h($cp) . "\" title='" . $h($applyLabel) . "' aria-label='" . $h($applyLabel) . "' aria-controls='ol-main-prompt'>"
					. "→ Apply in Change mode</button>";
			}
			$whyHtml = $why !== '' ? "<p style='margin:.25em 0 0;color:#64748b;font-size:.92em' title=\"" . $h($why) . "\" aria-label=\"Reason: " . $h($why) . "\">" . $h($whyShort) . "</p>" : '';
			$sugHtml = $sug !== '' ? "<p style='margin:.45em 0 0;color:#334155' title=\"" . $h($sug) . "\" aria-label=\"Suggestion: " . $h($sug) . "\">" . $h($sugShort) . "</p>" : '';

			$rows .= "<div style=\"border:1px solid #e2e8f0;border-left:4px solid {$col};border-radius:8px;padding:.9em 1.1em;margin:.6em 0;background:#fff\">"
				. "<div style=\"display:flex;align-items:center;gap:.6em;flex-wrap:wrap\">"
				. "<span style=\"font-size:.72em;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:{$col}\">{$sev}</span>"
				. ($area !== '' ? "<span style=\"font-size:.72em;color:#94a3b8;background:#f1f5f9;border-radius:6px;padding:.15em .5em\" title=\"{$areaFull}\" aria-label=\"Area: {$areaFull}\">{$area}</span>" : '')
				. "<strong style=\"color:#0f172a\" title=\"{$titleFull}\" aria-label=\"Finding: {$titleFull}\">{$title}</strong>"
				. "</div>{$whyHtml}{$sugHtml}{$btn}</div>";
		}

		$summaryHtml = $summary !== '' ? "<p style='color:#475569;margin:.3em 0 1em'>" . $h($summary) . "</p>" : '';

		return "<div style=\"margin:1.25em 0\">"
			. "<h2 style=\"margin:0 0 .2em\">What to improve</h2>{$summaryHtml}{$rows}"
			. "</div>"
			. "<script>(function(){"
			. "var ta=document.querySelector('.ol-composer .ol-prompt');"
			. "var rc=document.querySelector('.ol-composer input[name=\"olivia_mode\"][value=\"change\"]');"
			. "var ds=document.querySelector('[data-draft-status]');"
			. "var timer=0;"
			. "document.querySelectorAll('.ol-fix').forEach(function(b){b.setAttribute('aria-controls','ol-main-prompt');b.addEventListener('click',function(){"
			. "if(rc){rc.checked=true;rc.dispatchEvent(new Event('change',{bubbles:true}));}if(ta){ta.value=b.getAttribute('data-fill');ta.dispatchEvent(new Event('input',{bubbles:true}));}"
			. "if(ds){if(timer)clearTimeout(timer);ds.textContent='Suggestion loaded to Change';timer=setTimeout(function(){if(ds.textContent==='Suggestion loaded to Change')ds.textContent='';},2400);}"
			. "window.scrollTo({top:0,behavior:'smooth'});if(ta){ta.focus();}"
			. "});});})();</script>";
	}

	protected function renderPreview(array $p): string {
		$h = function($s) { return $this->wire->sanitizer->entities((string)$s); };
		$list = function(array $a) use ($h) {
			return $a ? implode(', ', array_map($h, $a)) : '<em>none</em>';
		};
		$c = $p['counts'];
		$imageCap = OliviaImageGenerator::MAX_IMAGES;
		$imageStatus = $this->generateImages
			? "on — will fill empty image fields, capped at {$imageCap} paid xAI image calls"
			: 'off — no image generation costs';
		$out  = "<div id='ol-plan-preview' role='region' aria-live='polite' aria-labelledby='ol-plan-preview-title'>";
		$out .= "<h2 id='ol-plan-preview-title'>Plan preview</h2>";
		$out .= "<p><strong>Plan impact:</strong> {$c['fields_new']} new fields, {$c['templates_new']} new templates, {$c['pages_new']} planned pages.</p>";
		$out .= "<table class='AdminDataTable AdminDataList' aria-label='Olivia plan preview summary'><tbody>";
		$out .= "<tr><th scope='row'>New fields</th><td>" . $list($p['fields']['new']) . "</td></tr>";
		$out .= "<tr><th scope='row'>Reused fields</th><td>" . $list($p['fields']['reused']) . "</td></tr>";
		$out .= "<tr><th scope='row'>New templates</th><td>" . $list($p['templates']['new']) . "</td></tr>";
		$out .= "<tr><th scope='row'>Reused templates</th><td>" . $list($p['templates']['reused']) . "</td></tr>";
		$out .= "<tr><th scope='row'>Pages</th><td>" . $this->renderPageTree($p['pages']['tree'] ?? []) . "</td></tr>";
		$out .= "<tr><th scope='row'>Images</th><td>" . $h($imageStatus) . "</td></tr>";
		$out .= "<tr><th scope='row'>Recommended modules</th><td>" . $this->renderModuleRecs($p['modules'] ?? []) . "</td></tr>";
		$out .= "</tbody></table>";
		$out .= "</div>";
		return $out;
	}

	/** Trust-annotated recommendations; installation always requires explicit user approval. */
	protected function renderModuleRecs(array $modules): string {
		if(!$modules) return '<em>none needed — your site works as-is</em>';
		$h = fn($s) => $this->wire->sanitizer->entities((string) $s);
		$badge = [
			'olivia_ready' => '#7c3aed', 'installed' => '#16a34a', 'recommended' => '#2563eb',
			'stable_quiet' => '#64748b', 'caution' => '#d97706', 'legacy' => '#9ca3af', 'unknown' => '#dc2626',
		];
		$rows = [];
		$skills = $this->wire(new OliviaSkills());
		foreach($this->modules()->recommend($modules) as $r) {
			$color = $badge[$r['trust']] ?? '#64748b';
			$trustLabel = $h($r['trustLabel']);
			$tag = "<span style='display:inline-block;font-size:11px;color:#fff;background:$color;border-radius:8px;padding:1px 8px' title='Module trust: {$trustLabel}' aria-label='Module trust: {$trustLabel}'>{$trustLabel}</span>";
			$name = $r['available'] ? "<a href='" . $h($r['url']) . "' target='_blank' rel='noopener noreferrer' title='Open module directory page for " . $h($r['name']) . "' aria-label='Open module directory page for " . $h($r['name']) . "'>" . $h($r['name']) . "</a>" : $h($r['name']);
			$purpose = $r['purpose'] !== '' ? $h($r['purpose']) : 'Extra functionality';

			if(!empty($r['installed'])) {
				$action = "<span style='color:#16a34a;font-size:12px;white-space:nowrap' title='Module " . $h($r['name']) . " is installed' aria-label='Module " . $h($r['name']) . " is installed'>✓ installed</span>";
			} elseif(!empty($r['installable'])) {
				$action = "<form method='post' action='./' style='display:inline' aria-label='Install module " . $h($r['name']) . "' aria-controls='ol-module-recs-list' aria-describedby='ol-module-recs-desc'>"
					. "<input type='hidden' name='olivia_install_class' value='" . $h($r['name']) . "'>"
					. "<button type='submit' name='submit_install_module' value='1' class='ol-btn ol-ghost ol-compact' title='Install module " . $h($r['name']) . "' aria-label='Install module " . $h($r['name']) . "' aria-controls='ol-module-recs-list' aria-describedby='ol-module-recs-desc' "
					. "onclick=\"return confirm('Download and install " . $h($r['name']) . " from the ProcessWire directory?')\">Install</button></form>";
			} else {
				$action = "<span style='color:#94a3b8;font-size:12px;white-space:nowrap' title='Install module " . $h($r['name']) . " manually' aria-label='Install module " . $h($r['name']) . " manually'>install manually</span>";
			}

			// "Teach Olivia": learn the module from its repo's AGENTS.md (no install).
			// Hidden once installed or already learned.
			$learn = '';
			if(empty($r['installed'])) {
				if($skills->read($r['name']) !== null) {
					$learn = "<span style='color:#7c3aed;font-size:12px;white-space:nowrap' title='Olivia has learned module " . $h($r['name']) . "' aria-label='Olivia has learned module " . $h($r['name']) . "'><i class='ri-graduation-cap-line' aria-hidden='true'></i> learned</span>";
				} else {
					$learn = "<form method='post' action='./' style='display:inline' aria-label='Teach Olivia module " . $h($r['name']) . "' aria-controls='ol-module-recs-list' aria-describedby='ol-module-recs-desc'>"
						. "<input type='hidden' name='olivia_learn_class' value='" . $h($r['name']) . "'>"
						. "<button type='submit' name='submit_learn_module' value='1' class='ol-btn ol-ghost ol-compact' "
						. "title='Teach Olivia module " . $h($r['name']) . "' aria-label='Teach Olivia module " . $h($r['name']) . "' aria-controls='ol-module-recs-list' aria-describedby='ol-module-recs-desc'>Teach Olivia</button></form>";
				}
			}

			$rows[] = "<div role='listitem' style='display:flex;align-items:center;gap:10px;margin:6px 0'>"
				. "<div style='flex:1'><strong style='color:#202124'>{$purpose}</strong>"
				. "<div style='font-size:12px;color:#64748b;margin-top:1px'>via {$name} {$tag}</div></div>"
				. "<div style='display:flex;gap:6px;align-items:center'>{$learn}{$action}</div></div>";
		}
		$intro = "<p id='ol-module-recs-desc' class='detail' style='margin:0 0 6px'>To give your site these capabilities, Olivia recommends ready-made ProcessWire modules. Nothing is installed unless you approve an individual install here or explicitly opt in before Build.</p>";
		$devNote = "<p class='detail' style='margin:8px 0 0;color:#94a3b8'>Developer? Don’t like a pick — swap it by editing the <code>modules</code> list in the plan, or ask Olivia in Change mode to use a different module.</p>";
		$stale = $this->modules()->indexStale() ? "<div style='color:#94a3b8;font-size:11px;margin-top:4px'>directory index is stale — run <code>php bin/olivia-modules-refresh.php</code> to update trust data</div>" : '';
		return $intro . "<div id='ol-module-recs-list' role='list' aria-label='Recommended ProcessWire modules' aria-describedby='ol-module-recs-desc'>" . implode('', $rows) . "</div>" . $devNote . $stale;
	}

	protected function renderPageTree(array $items, int $depth = 0): string {
		if(!$items) return '<em>none</em>';
		$h = fn($s) => $this->wire->sanitizer->entities((string)$s);
		$listLabel = $depth === 0 ? " aria-label='Planned page tree'" : '';
		$out = "<ul{$listLabel} style='margin:.25em 0 .25em 1.25em'>";
		foreach($items as $item) {
			$title = $h($item['title'] ?? '');
			$template = $h($item['template'] ?? 'basic-page');
			$out .= "<li><strong>{$title}</strong> <span style='color:#64748b' title='Template: {$template}' aria-label='Template: {$template}'>({$template})</span>";
			if(!empty($item['children']) && is_array($item['children'])) $out .= $this->renderPageTree($item['children'], $depth + 1);
			$out .= "</li>";
		}
		$out .= "</ul>";
		return $out;
	}

	protected function renderShare(array $share): string {
		$json = json_encode($share, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		$h = $this->wire->sanitizer->entities($json);
		return "<section aria-labelledby='ol-share-title' aria-describedby='ol-share-desc'><h2 id='ol-share-title'>Share-safe blueprint</h2>"
			. "<p id='ol-share-desc' class='detail'>Structure only — page content and field values are stripped. Copy this to contribute a blueprint.</p>"
			. "<textarea id='ol-share-json' readonly rows='12' style='width:100%;font-family:monospace;font-size:12px' title='Share-safe blueprint JSON' aria-label='Share-safe blueprint JSON' aria-describedby='ol-share-desc' onclick='this.select()'>{$h}</textarea></section>";
	}

	protected function renderFeedback(): string {
		if(!$this->telemetry()->isEnabled()) return '';
		return "<form method='post' action='./' style='margin:1em 0' aria-label='Olivia build feedback' aria-describedby='ol-feedback-question'>"
			. "<span id='ol-feedback-question' style='margin-right:.75em;color:#555'>Was this build good?</span>"
			. "<button type='submit' name='submit_feedback_up' value='1' class='ol-btn ol-ghost ol-compact' title='Send positive Olivia build feedback' aria-label='Send positive Olivia build feedback' aria-describedby='ol-feedback-question'>👍 Yes</button> "
			. "<button type='submit' name='submit_feedback_down' value='1' class='ol-btn ol-ghost ol-compact' title='Send negative Olivia build feedback' aria-label='Send negative Olivia build feedback' aria-describedby='ol-feedback-question'>👎 No</button>"
			. "</form>";
	}

	protected function renderContribute(): string {
		$c = $this->contribute();
		$invite = $this->wire->sanitizer->entities($c->inviteText());
		$tpl = $this->wire->sanitizer->entities($c->agentsTemplate());
		return "<section aria-labelledby='ol-contribute-title' aria-describedby='ol-contribute-desc'><h2 id='ol-contribute-title'>For module authors — make Olivia use your module</h2>"
			. "<p>{$invite}</p>"
			. "<p id='ol-contribute-desc' class='detail'>Drop this <code>AGENTS.md</code> into your module folder, then click “Refresh module skills”.</p>"
			. "<textarea id='ol-contribute-template' readonly rows='16' style='width:100%;font-family:monospace;font-size:12px' title='Module author AGENTS.md template' aria-label='Module author AGENTS.md template' aria-describedby='ol-contribute-desc' onclick='this.select()'>{$tpl}</textarea></section>";
	}

	protected function renderUtilityNav(string $active): string {
		$historyClass = $active === 'history' ? " class='is-active'" : '';
		$skillsClass = $active === 'skills' ? " class='is-active'" : '';
		$debugClass = $active === 'debug' ? " class='is-active'" : '';
		$historyCurrent = $active === 'history' ? " aria-current='page'" : '';
		$skillsCurrent = $active === 'skills' ? " aria-current='page'" : '';
		$debugCurrent = $active === 'debug' ? " aria-current='page'" : '';
		return "<nav class='ol-utilnav' aria-label='Olivia utility pages'>"
			. "<a href='./' title='Return to Olivia composer' aria-label='Return to Olivia composer'><i class='ri-arrow-left-line' aria-hidden='true'></i> Olivia</a>"
			. "<div class='ol-utilbrand'><span class='ol-utilbrand-mark' aria-hidden='true'><i class='ri-sparkling-2-fill'></i></span><span>Olivia admin</span></div>"
			. "<div class='ol-utilnav-links'>"
			. "<a href='./?view=history'{$historyClass}{$historyCurrent} title='Open build history page' aria-label='Open build history page'><i class='ri-history-line' aria-hidden='true'></i> Build history</a>"
			. "<a href='./?view=skills'{$skillsClass}{$skillsCurrent} title='Open module skills page' aria-label='Open module skills page'><i class='ri-graduation-cap-line' aria-hidden='true'></i> Module skills</a>"
			. "<a href='./?view=debug'{$debugClass}{$debugCurrent} title='Open support debug bundle' aria-label='Open support debug bundle'><i class='ri-bug-line' aria-hidden='true'></i> Support info</a>"
			. "</div>"
			. "</nav>";
	}

}
