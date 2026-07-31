<?php namespace ProcessWire;

trait OliviaAdminTables {

	protected function renderSkills(bool $standalone = false): string {
		$skills = $this->wire(new OliviaSkills())->all();
		$h = fn($s) => $this->wire->sanitizer->entities((string) $s);
		if(!$skills) {
			if(!$standalone) return '';
			return "<section class='ol-page' aria-labelledby='ol-skills-title'>"
				. "<div class='ol-page-head'><div class='ol-page-heading'><span class='ol-page-icon' aria-hidden='true'><i class='ri-graduation-cap-line'></i></span><div><p class='ol-page-kicker'>Knowledge library</p><h1 id='ol-skills-title' class='ol-page-title'>Module skills</h1>"
				. "<p id='ol-skills-desc' class='detail'>Recorded from installed module docs and used during generation.</p></div>"
				. "</div></div><form method='post' action='./?view=skills' aria-label='Refresh Olivia module skills library' aria-controls='ol-module-skills-content' aria-describedby='ol-skills-desc'><button type='submit' name='submit_skills' value='1' class='ol-btn ol-ghost' title='Refresh Olivia module skills library' aria-label='Refresh Olivia module skills library' aria-controls='ol-module-skills-content' aria-describedby='ol-skills-desc'><i class='ri-refresh-line' aria-hidden='true'></i> Refresh skills</button></form></div>"
				. "<div id='ol-module-skills-content' class='ol-empty' role='status' aria-live='polite' aria-atomic='true'>Olivia has not recorded module skills yet. Refresh skills after installing modules.</div>"
				. "</section>";
		}
		$sourceCount = count(array_unique(array_filter(array_map(
			static fn(array $info): string => trim((string)($info['source'] ?? '')),
			$skills
		))));
		$out  = $standalone ? "<section class='ol-page' aria-labelledby='ol-skills-title'><div class='ol-page-head'><div class='ol-page-heading'><span class='ol-page-icon' aria-hidden='true'><i class='ri-graduation-cap-line'></i></span><div><p class='ol-page-kicker'>Knowledge library</p><h1 id='ol-skills-title' class='ol-page-title'>Module skills</h1>" : '';
		$out .= $standalone ? "<p id='ol-skills-desc' class='detail'>Recorded from each module's AGENTS.md/README and fed into generation.</p></div></div>"
			. "<form method='post' action='./?view=skills' aria-label='Refresh Olivia module skills library' aria-controls='ol-module-skills-content' aria-describedby='ol-skills-desc'><button type='submit' name='submit_skills' value='1' class='ol-btn ol-ghost' title='Refresh Olivia module skills library' aria-label='Refresh Olivia module skills library' aria-controls='ol-module-skills-content' aria-describedby='ol-skills-desc'><i class='ri-refresh-line' aria-hidden='true'></i> Refresh skills</button></form></div>" : "<h2>Module skills Olivia knows</h2>"
			. "<p class='detail'>Recorded from each module's AGENTS.md/README and fed into generation. Click \"Refresh module skills\" after installing a module.</p>";
		if($standalone) {
			$out .= "<div class='ol-stat-strip' aria-label='Module skills summary'>"
				. "<div><span>Modules learned</span><strong>" . count($skills) . "</strong></div>"
				. "<div><span>Documentation sources</span><strong>{$sourceCount}</strong></div>"
				. "<div><span>Generation context</span><strong>Active</strong></div>"
				. "</div>";
		}
		$out .= "<div id='ol-module-skills-content' class='ol-table-wrap'><table class='ol-data-table ol-skills-table' aria-label='Olivia module skills'><thead><tr><th scope='col'>Module</th><th scope='col'>Source</th><th scope='col'>What it does</th></tr></thead><tbody>";
		foreach($skills as $class => $info) {
			$sourceFull = trim((string)($info['source'] ?? ''));
			$summaryFull = trim((string)($info['summary'] ?? ''));
			$sourceDisplay = $sourceFull !== '' ? $sourceFull : 'Not recorded';
			$summaryDisplay = $summaryFull !== '' ? $summaryFull : 'Not recorded';
			$sourceDisplayE = $h($sourceDisplay);
			$summaryDisplayE = $h($summaryDisplay);
			$sourceShort = $h($this->clipText($sourceDisplay, 44));
			$summaryShort = $h($this->clipText($summaryDisplay, 140));
			$out .= "<tr><th scope='row'><span class='ol-module-name'><i class='ri-puzzle-2-line' aria-hidden='true'></i><strong>{$h($class)}</strong></span></th><td title='{$sourceDisplayE}' aria-label='Source: {$sourceDisplayE}'><span class='ol-source-badge'>{$sourceShort}</span></td><td title='{$summaryDisplayE}' aria-label='Summary: {$summaryDisplayE}'>{$summaryShort}</td></tr>";
		}
		$out .= "</tbody></table></div>";
		if($standalone) $out .= "</section>";
		return $out;
	}

	protected function renderBuilds(bool $standalone = false): string {
		$builds = $this->store()->all();
		$h = fn($s) => $this->wire->sanitizer->entities((string)$s);
		if(!$builds) {
			if(!$standalone) return '';
			return "<section class='ol-page' aria-labelledby='ol-history-title'>"
				. "<div class='ol-page-head'><div class='ol-page-heading'><span class='ol-page-icon' aria-hidden='true'><i class='ri-history-line'></i></span><div><p class='ol-page-kicker'>Reversible changes</p><h1 id='ol-history-title' class='ol-page-title'>Build history</h1>"
				. "<p id='ol-history-desc' class='detail'>Rollback manifests from Olivia builds appear here.</p></div></div></div>"
				. "<div id='ol-build-history-content' class='ol-empty' role='status' aria-live='polite' aria-atomic='true'>No builds yet. Generate a plan, preview it, then build when ready.</div>"
				. "</section>";
		}
		$totalPages = 0;
		$totalViews = 0;
		$totalImages = 0;
		$totalIssues = 0;
		foreach($builds as $build) {
			$totalPages += count($build['pages'] ?? []);
			$totalViews += count($build['files'] ?? []) + max(0, (int)($build['updated_files_count'] ?? 0));
			$totalImages += (int)($build['images'] ?? 0);
			$totalIssues += count($build['errors'] ?? []) + count($build['warnings'] ?? []);
		}
		$out  = $standalone ? "<section class='ol-page' aria-labelledby='ol-history-title'><div class='ol-page-head'><div class='ol-page-heading'><span class='ol-page-icon' aria-hidden='true'><i class='ri-history-line'></i></span><div><p class='ol-page-kicker'>Reversible changes</p><h1 id='ol-history-title' class='ol-page-title'>Build history</h1>"
			. "<p id='ol-history-desc' class='detail'>Rollback manifests from Olivia builds. Undo removes created objects and restores Olivia-owned views updated by that build.</p></div></div></div>"
			. "<div class='ol-stat-strip' aria-label='Build history summary'>"
			. "<div><span>Total builds</span><strong>" . count($builds) . "</strong></div>"
			. "<div><span>Pages created</span><strong>{$totalPages}</strong></div>"
			. "<div><span>Views written</span><strong>{$totalViews}</strong></div>"
			. "<div><span>Images generated</span><strong>{$totalImages}</strong></div>"
			. "<div><span>Issues recorded</span><strong class='" . ($totalIssues ? "is-warning" : "is-ok") . "'>{$totalIssues}</strong></div>"
			. "</div>" : "<h2>Build history</h2>";
		$out .= "<div id='ol-build-history-content' class='ol-table-wrap'><table class='ol-data-table ol-build-table' aria-label='Olivia build history'><thead><tr>"
			. "<th scope='col'>ID</th><th scope='col'>Prompt</th><th scope='col'>Created</th><th scope='col'>Fields</th><th scope='col'>Templates</th><th scope='col'>Pages</th><th scope='col'>Views</th><th scope='col'>Images</th><th scope='col'>Issues</th><th scope='col'>Actions</th>"
			. "</tr></thead><tbody>";
		foreach($builds as $b) {
			$idRaw = (string)($b['id'] ?? '');
			$id = $h($idRaw);
			$idShort = $h($this->clipText($idRaw, 22));
			$issues = count($b['errors'] ?? []) + count($b['warnings'] ?? []);
			$views = count($b['files'] ?? []) + max(0, (int)($b['updated_files_count'] ?? 0));
			$promptFull = trim((string)($b['prompt'] ?? ''));
			$promptDisplay = $promptFull !== '' ? $promptFull : 'No prompt recorded';
			$promptDisplayE = $h($promptDisplay);
			$promptShort = $h($this->clipText($promptDisplay, 60));
			$createdRaw = (string)($b['created_at'] ?? '');
			$createdDisplay = $createdRaw;
			try {
				if($createdRaw !== '') $createdDisplay = (new \DateTimeImmutable($createdRaw))->format('M j, Y · H:i');
			} catch(\Throwable $e) {
				$createdDisplay = $createdRaw;
			}
			$out .= "<tr>"
				. "<th scope='row'><code title='{$id}'>{$idShort}</code></th>"
				. "<td title='{$promptDisplayE}' aria-label='Prompt: {$promptDisplayE}'>{$promptShort}</td>"
				. "<td class='ol-date-cell' title='" . $h($createdRaw) . "'>" . $h($createdDisplay) . "</td>"
				. "<td class='ol-td-num'>" . count($b['fields'] ?? []) . "</td>"
				. "<td class='ol-td-num'>" . count($b['templates'] ?? []) . "</td>"
				. "<td class='ol-td-num'>" . count($b['pages'] ?? []) . "</td>"
				. "<td class='ol-td-num'>" . $views . "</td>"
				. "<td class='ol-td-num'>" . (int)($b['images'] ?? 0) . "</td>"
				. "<td class='ol-td-num'>" . ($issues ? "<span class='ol-status ol-status-warning'>{$issues}</span>" : "<span class='ol-status ol-status-ok'>Clear</span>") . "</td>"
				. "<td class='ol-td-actions'><form method='post' action='./?view=history' style='margin:0' aria-label='Undo build {$id}' aria-controls='ol-build-history-content' aria-describedby='ol-history-desc'>"
				. "<input type='hidden' name='olivia_undo_id' value='{$id}'>"
				. "<button type='submit' name='submit_undo' value='1' class='ol-btn ol-ghost' title='Undo build {$id}' aria-label='Undo build {$id}' aria-controls='ol-build-history-content' aria-describedby='ol-history-desc' "
				. "onclick=\"return confirm('Undo build {$id}? This removes created objects and restores Olivia views updated by this build.')\">"
				. "<i class='ri-arrow-go-back-line' aria-hidden='true'></i> Undo</button>"
				. "</form></td>"
				. "</tr>";
		}
		$out .= "</tbody></table></div>";
		if($standalone) $out .= "</section>";
		return $out;
	}

}
