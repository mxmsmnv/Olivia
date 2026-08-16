# Olivia — Agent Handoff & Shared Memory

> Shared source of truth for contributors and development agents working on the
> Olivia module. Read this first. Keep it updated when you change behavior.

## What Olivia is

Olivia is an **AI Solution Architect for ProcessWire**: it turns a human prompt into a
working website (fields, templates, pages, content, frontend) — reviewably and reversibly.

**Current focus = build a site from scratch.** Generation engine is **Squad** (prompt → plan).
The **Context** module is for *editing existing* sites and is deferred until that phase
(on a blank site it has nothing to read).

The maintainer has additional private product-vision notes. Treat those as
vision, not implementation truth. Do not add more speculative documents here;
build and verify the supported product.

## Core loop

```
prompt (or "Load sample")
  → OliviaPlanner: Squad → plan JSON         (Direct mode)
  → OliviaInterviewer: questions → answers → augmented prompt → plan   (Interview mode)
  → OliviaValidator: normalize + check (warnings vs hard errors)
  → preview (dry run)  → Build
  → OliviaBuilder: create fields/templates/pages + content
       + OliviaViewGenerator: Tailwind view per created template + home landing
  → OliviaStore: save rollback manifest (assets/Olivia/builds/*.json)
  → Undo: OliviaBuilder::rollback(manifest)
```

Two **generation modes**, switchable in module config (`generationMode`): `direct`, `interview`.
Invariants (both): always show a plan preview before Build; every Build writes a rollback manifest.

## Files (site/modules/Olivia/ in release packages)

- `Olivia.module.php` — primary configurable product module and ProcessWire Directory identity.
  Owns runtime settings, requires Squad, and auto-installs the admin companion through
  `installs => ['ProcessOlivia']`. Its install hook migrates settings from pre-split development builds.
  It also declares the optional provider-driven MCP contract. The initial
  `lqrs_olivia_status` tool is read-only and secret-free; do not expose prompts,
  content, credentials, job payloads, planning, Build, installation, or Undo
  without a separately reviewed scoped and idempotent tool.
- `ProcessOlivia.module.php` — Process admin companion for **Setup → Olivia**. Handles
  submit routing, plan/job coordination and validation wiring. It receives the saved
  product settings from `Olivia`; do not add configurable fields or a second config store here.
  Admin rendering is composed from the traits below. `set_time_limit()` on POST uses
  `ProcessOlivia::WEB_POST_SOFT_LIMIT` (180s), also exposed in support/debug JSON.
  Its `getModuleInfo()` declares only the admin page, permission and dependency on `Olivia`;
  PHP 8.1+, ProcessWire 3.0.200+ and Squad 1.4.0+ requirements belong to
  `Olivia::getModuleInfo()`. Do not add separate info files.
- `src/bootstrap.php` — registers the unchanged `ProcessWire\Olivia*` class names
  across the domain folders below. Both the web module and CLI bootstrap must use it.
- `src/Admin/` — composer markup, browser interactions, admin views, support/debug,
  Build History and Module Skills tables. These are traits used by `ProcessOlivia`.
- `bin/` — production CLI entrypoints and their shared ProcessWire bootstrap. These are
  release/runtime files, not tests; the launcher passes `OLIVIA_SITE_ROOT` so symlinked
  development installs resolve the real ProcessWire root safely.
- `src/Planning/OliviaPlanner.php` — prompt → plan JSON via `Squad::ask`. `SYSTEM_PROMPT` defines the
  plan schema. maxTokens 12000, timeout 240. The enclosing plan worker still has a 330-second hard
  deadline, so a stalled provider cannot run indefinitely. Has `samplePlan()` (works with no API key).
  Optional per-request Web research uses Squad 1.9.0+'s provider-independent `webSearch` API,
  caps results at five, normalizes safe HTTP(S) citations, and stores those source links with
  the plan chat event. A failed searched call gets one bounded retry without search so this
  optional feature cannot take down normal planning.
- `src/Planning/OliviaInterviewer.php` — prompt → clarifying questions; `augmentPrompt()` builds the
  follow-up planning prompt from answers. maxTokens 4000, timeout 120.
- `src/Planning/OliviaValidator.php` — normalizes + validates a plan. `ok=false` (hard errors) blocks Build.
  Auto-renames reserved field names (see gotchas). Returns `['ok','errors','warnings','plan']`.
- `src/Build/OliviaBuilder.php` — executes a plan. Additive + idempotent. `build()`, `rollback()`,
  `preview()` (includes counts plus a nested page/template tree). Records manifest:
  fields/templates/pages(ids)/files/updated_files + reused + errors (+home_alt/home_title).
- `src/Build/OliviaViewGenerator.php` — writes self-contained Tailwind views for Olivia-created
  templates; `generateHome()` gives the root a landing page via the home template's `altFilename`.
  It does not overwrite user/template files, except for safe upgrades to Olivia-owned generated
  `olivia_home.php` and Olivia-owned generated template views; those previous contents are recorded
  in the rollback manifest.
- `src/Build/OliviaImageGenerator.php` — fills image fields via xAI (Grok Imagine). Reuses the
  `grokApiKey` from the installed **GrokImagine** module; forces the cheap model
  `grok-imagine-image`; single-image fields get one image, gallery-intent fields can get up
  to 3, and all paid image calls are capped at 10/build. Image timeout is 90s;
  optional art-director prompt calls use maxTokens 1500, timeout 45. Off by default —
  enabled via Olivia config `generateImages`, passed to `build($plan,$prompt,$views,$images)`.
- `src/Runtime/OliviaStore.php` — persists/loads/lists/deletes build manifests as JSON.
- `src/Runtime/OliviaChats.php` — persists chat threads as JSON under `assets/Olivia/chats/`.
  Threads keep prompt/mode/current plan plus compact user/assistant events so any chat can be
  reopened with `?chat=<id>` and continued after reloads/background jobs. Only real
  `prompt`/`audit_request` user events may seed an empty resumable prompt and chat title;
  workflow events such as `build_request` and interview answers stay timeline-only.
- `src/Planning/OliviaSiteTypes.php` — the four minimum product contracts:
  `landing_page`, `business_website`, `catalog`, and `online_store`. It owns
  aliases, planner guidance, labels, and required runtime modules. Online stores
  require Mercato; do not downgrade that contract to a decorative product grid.
- `src/Planning/OliviaBlueprints.php` — curated deterministic plans for the four
  minimum site types plus restaurant, photographer and agency. They are instant,
  free, and use the normal validate → preview → Build path.
- `src/Integration/OliviaModules.php` — module discovery & trust: recommend(classNames) -> installed/available/updated/trust + dir link, from the PW directory export-json (cached 24h; pagination URLs must re-add apikey=pw223). Installation runs only after an explicit user action.
- The optional "install recommended modules before Build" checkbox is an explicit opt-in and defaults OFF; Preview submissions preserve both checked and unchecked states. Plan modules remain recommendations when it is unchecked.
- `online_store` is the exception to recommendation-only semantics for capability:
  Mercato remains approval-gated, but Build is blocked until it is already installed
  or included in the explicitly approved install list. The worker verifies installation
  again before mutating the site, so a failed download/install cannot fall through to a
  non-transactional fake store. Mercato owns products, collections, cart, checkout,
  payments, customers/orders, discounts, fulfilment and inventory; Olivia builds the
  complementary brand/content layer and must not duplicate that schema.
- Approved module installation validates official-directory ZIPs before extraction (25 MB archive,
  5000 entries, 100 MB expanded limits; no absolute/traversal paths) and never overwrites an existing
  non-installable module directory.
- `src/Integration/OliviaContribute.php` — author invitation + copy-paste AGENTS.md template (shown on the
  module page); `shareableBlueprint()` strips page content + field values, keeps structure (for sharing).
- `src/Reference/OliviaReferenceAnalyzer.php` — reference-site intake. Fetches a URL best-effort
  (timeout 12s, connect timeout 5s, 3 redirects, capped pages/text/html bytes, detects
  captcha/Cloudflare/bot protection), saves optional screenshot uploads under
  `/site/assets/Olivia/references/`, and augments the planning prompt with a non-copying reference brief.
  The planner does NOT browse the web itself. Up to four uploaded screenshots are passed to
  `OliviaVisualAnalyzer`, which uses Squad vision to extract a bounded, non-copying design profile.
- `src/Reference/OliviaVisualAnalyzer.php` — multimodal reference analysis. Routes uploaded screenshots
  through Squad's `vision()` API, normalizes colors/type/radius/container tokens plus layout,
  component, and mobile observations, and degrades to the normal text prompt on any vision failure.
- `src/Runtime/OliviaTelemetry.php` — opt-in (OFF by default; Olivia config `telemetry`), content-free usage
  signals to the LOCAL log 'olivia-telemetry' (planShape counts/types, 'build'/'build_undone' for
  Undo-rate, 👍/👎 feedback). NEVER records field values/content/URLs/secrets. No central endpoint yet.
  Strategy: docs/OLIVIA_SKILL_COLLECTION_STRATEGY.md.
- `src/Integration/OliviaSkills.php` — Olivia's skills library at `skills/modules/<Class>.md`, recorded from each installed module's AGENTS.md/API.md/EXAMPLES.md/README. collectInstalled()/record()/all()/read(). Lets Olivia learn the ecosystem (e.g. SEO via the user's Ichiban module once installed). NOTE: SEO itself is the user's Ichiban module — don't build sitemap/meta beyond it.
- `src/Build/OliviaComponents.php` + `components/` — validated catalog of 119 website
  patterns across 13 categories. The 97 `rendered` entries form the planner's
  active forceable palette; 22 `reference` variants resolve to shared Tailwind
  archetype snippets for future renderer work and reference analysis. Keep
  unsupported variants out of default `vocabulary()`; expose them only through
  compact `referenceVocabulary()` structural guidance. Run `validate()` after
  catalog edits; smoke enforces ids, uniqueness, metadata and reference files.
- Generated views (view version 34, home version 17) include: bundled production CSS, SEO `<meta description>` + Open Graph (og:image = generated hero), safer JSON-LD encoding, safe public-link scheme filtering, and self-contained native-PW contact/enquiry forms (logs to 'olivia-contact' + best-effort WireMail). A page with `product-grid` provides bounded server-side text search and category filtering; `product-detail` pages get a product-specific enquiry form and render multi-image galleries through the normal image-field path. A `customer-account` page supplies CSRF-protected registration, one-hour email verification, login/logout, verification resend for pending accounts, and Mercato-backed order history without exposing order pages. Generated views capture raw page/home/collection text with `getUnformatted()` before applying their own escaping; this is required because a fresh `children()`/`parents()` PageArray may contain formatted Page instances even if an earlier PageArray was switched to `of(false)`. Ichiban's already-escaped SEO markup is rendered separately. Forms include ProcessWire CSRF validation, a honeypot, a 30-second session throttle, bounded inputs, and DMARC-friendly mail delivery. If Ichiban is installed, the `seo` field renders in `<head>` only and is skipped in body field loops. Bump the "Olivia view version: N" marker in viewSource when changing it so upgradeOliviaView() rewrites existing sites; ALWAYS lint the GENERATED /site/templates/<tpl>.php (view code lives in a nowdoc, so php -l on OliviaViewGenerator won't catch errors in it). Rebuild `assets/olivia.css` with `scripts/build-css.sh` whenever generated view classes change.
- `src/Runtime/OliviaJobs.php` + `bin/olivia-worker.php` — async background generation. Slow model
  calls (DeepSeek/MiniMax reasoning ~25-90s) exceed MAMP's mod_fastcgi ~30s request timeout,
  so "Generate plan" / interview questions / answers→plan run in a **detached CLI worker**:
  module `spawn()` writes a job + launches `bin/olivia-worker.php` detached; the page returns
  instantly with a "Generating…" poller; `executeJob()` (URL setup/olivia/job/?id=) reports
  status; on done the poller reloads with ?olivia_planjob / ?olivia_qjob and the result loads.
  This bypasses the FastCGI timeout entirely — no server config change needed.
  **Build also runs in this worker** (job type 'build'): submit_build validates synchronously
  (fast, shows reserved-rename warnings), then spawns a build worker that creates objects +
  images and saves the manifest; poller picks it up via ?olivia_buildjob. So big image builds
  never hit the 30s timeout either. The build worker setCurrentUser(superuser) for unrestricted
  object/file creation in CLI. Jobs move `pending` → `running` → `done|error` and record
  `created/started/finished` timestamps. Worker jobs record their PID; the poll watchdog fails
  stalled jobs and tries to stop the worker instead of auto-retrying model calls (no duplicate
  spend/work from a hung provider). CLI workers also set a best-effort `pcntl_alarm()` deadline
  where available. Verified end-to-end in Chrome (SereniTea House, 2 Grok images).
- `bin/olivia-watchdog.php` is the detached hard-deadline fallback when the CLI PHP lacks
  `pcntl_alarm`. It re-reads the job after its budget, exits for terminal jobs, and otherwise
  uses the PID identity guard before stopping/failing the worker. It does not depend on browser polling.
- Job JSON is capped at 4 MB on both read and write; oversized/corrupted records are rejected
  before `file_get_contents()` so polling and support/debug cannot exhaust PHP memory on them.
- Slow model operations and Build use an atomic file-locked single-flight claim per
  job type and chat (or plan fingerprint without a chat). Repeated POSTs, double clicks,
  reloads, and parallel tabs follow the existing pending/running/terminal job until its
  result is picked up and deleted; they never launch a duplicate worker or append a
  duplicate chat request.
- While the browser follows a background job, its activity card is rendered between
  the chat timeline and composer with the current stage, elapsed time, indeterminate
  progress and a background-continuation note. Composer submit actions stay disabled
  until the poller reaches a terminal state, preventing misleading duplicate actions.
- Chat JSON is capped at 4 MB and rollback manifests at 16 MB on list/load/save. The larger
  manifest budget accommodates previous generated view bodies required by Undo.
- Reopening a saved chat automatically discovers its latest plan/change/questions/audit/build
  job. Active jobs restore the in-composer poller; terminal jobs flow through the normal
  pickup path and persist their result into the chat before the job record is deleted.
  Leaving the page must never strand a completed model response in the job store.
- Olivia-owned view upgrades back up at most 512 KB per file and 4 MB total per Build. An
  oversized file is left untouched and recorded as a Build error, preserving a saveable Undo manifest.
- The build worker persists through `OliviaBuilder::saveManifestOrRollback()`. If the manifest
  save fails, it immediately rolls back using the in-memory manifest and fails the job visibly;
  it must never leave a completed but untracked Build without History/Undo.

## Plan JSON schema

```json
{
  "site": {"title": "...", "type": "...", "tagline": "..."},
  "fields": [{"name":"snake_case","type":"text|textarea|url|email|integer|float|checkbox|datetime|image|file|page","label":"..."}],
  "templates": [{"name":"lowercase-or-snake","label":"...","fields":["field_name"]}],
  "pages": [{"title":"...","template":"section|<custom>","parent":"/","content":{"field":"value"},"children":[...]}],
  "modules": ["RecommendationsOnly — NOT installed"]
}
```
- `title` is built-in; never declare it as a field, but may be listed in a template's fields.
- Use template name `section` for simple section/landing pages (Olivia provides headline, summary, body, hero_image; builder also remaps `basic-page`→`section`).

## How to run / test (no UI needed)

Bootstrap pattern (PHP CLI):
```php
<?php namespace ProcessWire;
require('/absolute/path/to/processwire/index.php');
$users = wire('users'); $users->setCurrentUser($users->get('admin')); // act as superuser
$o = wire('modules')->getModule('ProcessOlivia', ['noPermissionCheck'=>true]); $o->init(); // registers src/ autoload
$b = wire(new OliviaBuilder()); $pl = wire(new OliviaPlanner()); $v = wire(new OliviaValidator());
$plan = $pl->samplePlan();                 // or $pl->plan($prompt) for a live AI call
$r = $v->validate($plan); $plan = $r['plan'];
$m = $b->build($plan, 'test'); // ... $b->rollback($m) to undo
```
Run with the site's supported PHP CLI, for example: `php /tmp/script.php`
- Process modules: in CLI you MUST `setCurrentUser(admin)` + `getModule(...,['noPermissionCheck'=>true])`.
- DB checks: `/Applications/MAMP/Library/bin/mysql80/bin/mysql -h127.0.0.1 -P3306 -uolivia -p<pass> olivia`
- After delete, the in-process `$pages->get()` cache may still return a page — verify deletions via DB, not the same-process API.
- Reusable smoke test (no paid AI/image calls):
  `OLIVIA_SITE_ROOT=/absolute/path/to/processwire PHP_BIN=/path/to/php scripts/release-check.sh`
  It now lints generated template PHP files from the sample build before rollback,
  so nowdoc view-source syntax errors are caught, and verifies malformed UTF-8
  text survives chat/job/manifest persistence.
- Full pre-publish gate: `scripts/release-check.sh`.
  It verifies release metadata/package contents, rebuilds CSS into a temporary file and compares it,
  lints every PHP file, then runs the full ProcessWire smoke test. No paid model/image calls.
- Portable/CI gate: `scripts/static-check.sh`.
  It validates metadata, reproducible CSS, PHP syntax and the staged exported package
  without requiring a ProcessWire site. GitHub Actions also lints on PHP 8.1 and 8.4.
- Reproducible release package: `scripts/build-release.sh`.
  It requires a clean committed tree, runs the full gate, then writes a versioned ZIP
  with one top-level `Olivia/` directory and a SHA-256 checksum under ignored `dist/`.
- Destructive package lifecycle smoke: `scripts/lifecycle-smoke.php`. Run it only
  against a disposable cloned site containing `.olivia-release-smoke`; it refuses
  symlinked module directories and exercises package load plus two uninstall/reinstall cycles.

## Environment

Use `OLIVIA_SITE_ROOT` and `PHP_BIN` to select a disposable ProcessWire test
installation and its supported PHP CLI. The maintainer's local paths, database
credentials, admin account, provider routing and API keys are not part of the
repository contract. Never print or commit them. Live provider calls cost the
user's account, so prefer the no-cost release and smoke checks.

## Gotchas (learned the hard way)

- Field names: `sanitizer->fieldName` (no hyphens). Template names: `sanitizer->name` (hyphens ok).
- **Reserved field names** (e.g. `description`) fail at save. Validator auto-renames them
  (`description`→`description_field`, collapses `foo__bar`→`foo_bar`) via a renameMap propagated
  into template field lists AND page content keys, so data is preserved. Check with `$fields->isNative($name)`.
- `$files->unlink($file, true)` only allows /site/assets — pass the templates path to delete view files.
- **Home dedupe**: models keep emitting a "Home" page → builder maps `home`/title "Home" at root to
  the existing root home (sections land at top level).
- **Home landing** uses `home` template's `altFilename` = `olivia_home` (+ olivia_home.php); profile
  home.php is never overwritten; rollback restores altFilename + home title.
- **Reasoning models** (DeepSeek V4 Pro) spend tokens "thinking" — too-low maxTokens → empty output.
  That's why planner=12000, interviewer=4000.
- Squad is `singular`: after `saveModuleConfigData`, the already-loaded instance has stale props;
  read config in a fresh request to verify.
- **Config property shadowing (critical):** do NOT declare a module config field as a typed/initialized
  PHP property (e.g. `protected $generateImages = 0;`). That shadows PW's WireData config store, so
  `$this->generateImages` always returns the declared default, never the saved config. Leave it
  undeclared and read `$this->generateImages` (with a fallback like `?: 'direct'`). This silently
  broke the whole image feature once.
- `WireArray::filter()` takes a SELECTOR STRING, not a PHP closure — closures throw "Object of class
  Closure could not be converted to string". Use selectors, e.g. `children("template!=admin, id!=$a|$b")`.
- Home landing: must set the home template `noAppendTemplateFile`+`noPrependTemplateFile` (else the
  profile `_main.php` is appended and leaks "Default content"); and exclude system pages from the
  section list (`template!=admin, id!=trashPageID|http404PageID`). Both recorded for rollback.
- Detached worker spawn (`ProcessOlivia.module.php` spawn()): do NOT use `nohup` under FastCGI — it errors
  "can't detach from console". Instead close inherited fds (`for fd in $(seq 3 255); do exec
  $fd<&- $fd>&-`) so the child releases the FastCGI socket (otherwise mod_fastcgi holds the HTTP
  response open until the worker exits), then `/bin/sh -c '…' </dev/null >/dev/null 2>&1 &`.
  phpBinary() must be a CLI php (web PHP_BINARY is php-cgi) — picks highest /Applications/MAMP/bin/php/php*/bin/php.
- TEST ARTIFACT (not a bug): the Chrome MCP synthetic click submits a multi-submit form WITHOUT the
  submitter button, so `submit_generate` etc. don't arrive. Real browser clicks include the submitter.
  When driving via MCP, submit with `form.requestSubmit(button)` in JS.

## Status

The admin UI ships Remix Icon 4.6.0 locally under
`assets/vendor/remixicon/` (Apache-2.0 license included); never restore the old
jsDelivr runtime dependency. The release gate verifies all three vendor files.
The full release gate also builds an `Olivia-main/` GitHub-style source ZIP and
passes it through ProcessWire's own `ProcessModuleInstall`; it must resolve to a
flat `/site/modules/Olivia/` installation containing both Olivia module classes.
Production classes are grouped under `src/Admin`, `src/Planning`, `src/Build`,
`src/Reference`, `src/Runtime`, and `src/Integration`. Their namespace and class
names remain unchanged for ProcessWire and third-party callers.

CURRENT RELEASE: **1.0.0**. This is the first public release. The supported release path is Create (Direct or Interview)
→ preview → Build → history/Undo. Change and Operate are present but experimental until
they have broader compatibility coverage across existing ProcessWire sites.
Improve passes the user's composer prompt to `OliviaOperator::audit()` as its
second, optional audit-focus argument while retaining the original options-array
first argument and the grounded, read-only JSON contract in its system prompt;
do not silently replace a requested focus with the generic audit prompt.
Audit findings with destructive change prompts (`delete`, `remove`, `drop`, or
`uninstall`) remain visible as advice but lose the one-click Change action;
Change mode is additive and must not advertise unsupported destructive work.

DONE (v1, verified live with DeepSeek/MiniMax): Direct mode, Interview mode, validation
(incl. reserved-word rename), Tailwind views, page content, home landing, build history + Undo.
All additive + reversible; profile files untouched.

Visual reference analysis is live-verified through Squad/OpenRouter with
`google/gemini-2.5-flash`: up to four uploaded screenshots become a bounded
design profile and extended site theme, while provider failures fall back to
the original text/reference brief without blocking planning.
The Advanced panel renders a live Design system inspector from the editable
`plan.site.theme`, including color swatches, font, radius, container, and a
copy-JSON action for support/debug handoff.
Theme controls are explicit, independent overrides: Preview/Build leave
`plan.site.theme` untouched until the user changes a font or colour control,
then merge that one token with the plan theme or the site's last applied theme.
This is especially important in Change mode, where an unrelated content change
must retain the existing site's design.
On an empty desktop chat, opening `Blueprints & advanced` moves the composer into
normal document flow, compacts the welcome block, and disables its bottom fade;
the expanded JSON panel must never grow upward over the welcome content.
On resumed chats, generation modes stay inside the composer flow instead of
being positioned above it, so they cannot cover the last timeline message.
Synchronous workflow results (Preview, Interview, Audit, Share and Feedback) render
inside `.ol-result-slot` in the main Olivia workspace. Never append `$extra` after
the closing `.ol-app-shell`; background-job markup may move itself from this slot
to the dedicated activity slot.
The Build plan toolbar always gives its label a full row; diagnostics and JSON
actions wrap beneath it, with a three-column action row on mobile.
Admin command buttons use shared control tokens: 38px standard height, 32px compact
height, 32px square icon controls, and an 8px control radius. Keep one-off inline
button sizing out of admin renderers so composer, dialogs and utility pages agree.
The composer has three responsive layouts: full sidebar above 1100px, a 72px icon
rail with a three-column example grid from 701-1100px, and the stacked mobile
workspace at 700px and below. Keep the intermediate layout when adding cards;
five desktop columns must never be squeezed into the narrow main panel.
Smoke syntax-checks the fully rendered composer JavaScript with `node --check`;
keep shortcut labels using named keys such as `Backslash` so PHP string
interpolation cannot create invalid JavaScript escapes.
Reference intake previews local files with revocable blob URLs and accepts picker,
drop, or clipboard images. Client-side selection is capped at four supported
image MIME types; server-side byte/MIME/image validation remains authoritative.
Saved reference uploads have a seven-day TTL. `cleanupStaleReferences()` deletes
at most 100 strictly named Olivia image files per request and never follows
symlinks or touches foreign files in the references directory.
Vision jobs return `{plan, visual}` while plan pickup remains compatible with
legacy unwrapped plan results. Chat metadata stores only bounded status fields
(success, model, image count, reason/message), never image bytes, prompts, or keys.
The expected `no_images` result for requests without uploaded or captured images
is silent in notices and chat metadata pills; real vision failures remain visible.
- `src/Reference/OliviaScreenshotCapture.php` — optional ScreenshotOne POST integration for
  URL-only visual references. Off by default; fixed HTTPS endpoint, SSRF URL
  revalidation, 45s timeout, 8 MB streaming cap, PNG validation, and graceful fallback.
  `settings()`, `safeUrl()`, and `requestScreenshot()` are protected seams for
  deterministic smoke doubles; production still uses the fixed ScreenshotOne endpoint.
Support/debug exposes a secret-free `olivia.reference_capture` readiness object;
never add the configured screenshot access key or request body to diagnostics/logs.
Reference images are capped at 12,000 px per side and 32 MP in addition to the
8 MB byte cap. Keep Olivia and Squad dimension limits aligned.
The Reference dialog also exposes an OFF-by-default Web research toggle for
Direct, Interview and Change planning. Interview carries the choice through the
question form and searches only for the final plan, avoiding duplicate paid
searches. Search content is untrusted reference material and never an instruction.
The Reference dialog's capture status is a preflight hint only; the persisted
chat vision status remains authoritative after the worker finishes.
Visual metadata `source` is restricted to `none|uploaded|screenshotone`; never
persist the captured URL as provenance.

## TODO / next

- Images: DONE — OliviaImageGenerator + GrokImagine, cheap `grok-imagine-image` only, verified
  live (1280x720, renders on page; one retry handles transient xAI failures). xAI key lives in
  GrokImagine config; enable per-build via Olivia config `generateImages` (off by default, cap 10/build).
- Possible: richer per-template views; install recommended modules (with approval).
- DONE: Direct + Interview modes (Chrome-verified), validation+reserved-rename, home landing, images.
- DONE: New Olivia-created `section` templates include `hero_image`, so image-enabled builds have visual section/card material without relying on the model to define an image field.
- DONE: Planner now asks for `site.tagline`; generated `olivia_home.php` embeds it as the home hero subtitle fallback without adding fields to the profile home template.
- DONE: Rollback manifests can restore Olivia-owned `updated_files`, used for safe upgrades of old generated home views.
- DONE: Plan Preview now shows a nested page tree with template names, not just flat counts.
- DONE: Build history table shows explicit Fields/Templates/Pages counts plus Views, Images and Issues. Build completion notices and chat metadata also report Olivia-owned views written or upgraded, so an idempotent object build never looks like a no-op when it updates generated templates.
- DONE: Generated template views are versioned (`Olivia view version: 2`) and render mobile-wrapping nav, `headline` as hero copy, email links, checkbox values, and labelled short text fields. Old Olivia-owned views upgrade safely via `updated_files`.
- DONE: Builder no longer silently falls back to `/` for an unknown explicit parent; it records `parent not found (...)` and skips that page.
- DONE: Validator blocks top-level pages whose explicit `parent` neither exists nor appears as a page created by the same plan, so bad parent refs surface before Build.
- DONE: Background jobs now record `running`, `started`, and `finished` state for clearer async lifecycle diagnostics.
- DONE: `OliviaStore::all()` strips full `updated_files` bodies for lightweight build history; `load()` still returns full manifests for Undo.
- DONE: Added `scripts/smoke.php` for repeatable CLI validation of sample build/rollback and bad-parent validation.
- DONE: Plan Preview shows image generation status/cost note based on Olivia config before Build.
- DONE: Admin home is a focused composer; Build history and Module skills live on separate `?view=history` / `?view=skills` utility pages with plain Olivia tables and Remix icons.
- DONE: Chat threads persist across reloads; the main screen lists recent chats and any thread can be reopened with `?chat=<id>` to continue from its saved prompt/plan/history.
- DONE: Reference URL/screenshot/notes live in a compact Reference modal on the composer; mode cards include short descriptions for Direct, Interview, Change site, and Improve.
- DONE: Support/debug bundle lives at `?view=debug`; it shows a copyable JSON with Olivia/Squad versions, provider/model routing, roles, feature flags, active chat/build/job ids and masked configs (never API keys).
- DONE: Support info top table shows `Generated at` and `Debug schema`, so a screenshot of the page carries timestamp/schema context even without copying JSON.
- DONE: Support info top table shows runtime context (`Runtime`) with PHP SAPI and ProcessWire debug state.
- DONE: Support/debug resolves the ProcessWire version from core module metadata
  (with a config fallback), so web requests no longer expose an empty version.
- DONE: Support/debug bundle includes compact plan status/summary fields (`plan_status`, `plan_summary`) so support can immediately tell whether the current plan is missing, invalid JSON, schema-incomplete, or ready.
- DONE: Support info top table shows compact feature flags (`Feature flags`) for image generation, content filling, and telemetry.
- DONE: Support info top table shows compact build/runtime caps (`Build caps`) for images per build, content fills per build, and reference pages.
- DONE: Support info top table shows compact model-call timeouts (`Model budgets`) for planner, interview, image, and reference fetch calls.
- DONE: Support info top table shows worker/web watchdog limits (`Job deadlines`) for plan/build jobs and the web POST soft limit.
- DONE: Support/debug environment and top table show worker control capabilities
  (`worker_stop_available` requires `posix_kill` plus `/bin/ps` identity verification, `worker_alarm_available` via
  `pcntl_alarm`) so support can tell whether stuck-worker cleanup can run.
- DONE: Support/debug environment and top table show worker launch paths
  (`worker_php_binary`, `worker_script`) so support can verify the detached CLI
  worker is launched with the expected PHP binary and script.
- DONE: Support/debug environment and top table show worker launch checks
  (`worker_php_binary_executable`, `worker_script_exists`) so support can spot
  broken detached worker setup before blaming a model call.
- DONE: Support/debug environment and top table show `worker_launch_ready`, a
  direct boolean AND of executable PHP binary + present worker script.
- DONE: Recent-job compact support/debug entries include `deadline_used_percent`, derived from elapsed/deadline seconds, so stuck-job triage does not require manual division.
- DONE: Raw `recent_jobs` support/debug entries also include
  `deadline_used_percent`, so every job row can be triaged without recomputing
  elapsed/deadline manually.
- DONE: Raw `recent_jobs` support/debug entries include `overrun_seconds`, so
  every job row shows direct seconds past deadline without recomputing it.
- DONE: Raw `recent_jobs` support/debug entries include an `over_deadline`
  boolean for machine filtering/alerts without comparing timing fields.
- DONE: Recent worker `recent_job_health_primary_issue_key` is selected from the
  actual counted `status:type` issue keys (with over-deadline winning ties), so
  support/debug no longer emits synthetic combinations that never occurred.
- DONE: Recent worker primary issue status/type/key now derive from the same
  real counted issue key, so support/debug triage labels stay internally
  consistent in mixed error + over-deadline cases.
- DONE: Worker watchdog stop attempts now return success only when a signal was
  actually sent (or the process disappeared after SIGTERM); missing/invalid PIDs
  stay false so timeout handling does not overstate cleanup success.
- DONE: Watchdog timeout logs include `elapsed`, `deadline`, and
  `worker_stop=ok|failed`, so stalled job cleanup is visible in the local
  Olivia log without digging into process state.
- DONE: Watchdog timeout job errors include the job type/id plus
  elapsed/deadline seconds, so UI/support can identify and size the stalled
  operation from the failed job record alone.
- DONE: CLI worker `pcntl_alarm()` timeout errors include the job type and id,
  so copied support/debug errors identify the stalled operation directly.
- DONE: CLI worker fatal shutdown errors also include the job type and id, so
  OOM/fatal failures identify the stalled operation directly.
- DONE: Support info top table shows compact module installation status (`Module status`) for Squad, GrokImagine, Context, and Ichiban.
- DONE: Support/debug table values wrap long code-like summaries (`overflow-wrap:anywhere`) so copied ids/models/status strings do not break the layout on narrow screens.
- DONE: Support/debug table references the visible support description with `aria-describedby="ol-debug-desc"`.
- DONE: Support info top table shows the active composer mode (`Active mode`) before chat/plan diagnostics.
- DONE: Support info page surfaces compact plan/job diagnostics in the top table (`Plan status`, `Plan summary`, `Job health`, `Job action`, `Job target`, `Job PID`, `Job error`, `Job counts`, `Job issue mix`, `Job deadline use` with job id) before the raw copyable JSON.
- DONE: Support info top table shows chat diagnostics (`Chat state`) so a support bundle makes it clear whether the requested chat id was found and how many messages it has.
- DONE: Support info top table also shows build diagnostics (`Builds`, `Latest build`) with the latest manifest id, age, and error count when present.
- DONE: Main admin UI uses an app-shell layout inspired by modern chat tools: persistent left sidebar for chats/tools, centered welcome state, quick prompt chips, and bottom composer.
- DONE: Chat sidebar has client-side search, Today/Yesterday/Earlier grouping, and missing `?chat=<id>` links are cleaned up into a fresh chat state.
- DONE: Chat rows have a compact actions menu for renaming or deleting saved chat threads from the sidebar.
- DONE: Active chat history now renders inside the main app panel as a feed; the welcome state is shown only for empty/new chats.
- DONE: Active-chat history and the composer share normal layout flow, so an expanded Advanced panel cannot cover timeline actions; the empty/new welcome screen keeps its anchored composer.
- DONE: Main app shell has keyboard shortcuts and an in-app Shortcuts help modal: `?`,
  Cmd/Ctrl+K prompt focus, Cmd/Ctrl+Enter generate, Cmd/Ctrl+Shift+N new chat,
  Cmd/Ctrl+Shift+F chat search, Cmd/Ctrl+Shift+R reference modal, Esc close panels.
- DONE: Sidebar collapse state is stored separately for desktop and mobile; a new
  mobile viewport starts compact and the collapse button actually hides navigation
  and chat rows instead of leaving a full-height sidebar above the composer.
- DONE: Shortcuts openers and close control use native buttons, keeping pointer,
  touch, and keyboard activation consistent across desktop and mobile layouts.
- DONE: Mobile composer and Advanced settings stay in normal document flow, so
  an expanded composer cannot cover the visible Shortcuts/Support controls or
  intercept their pointer events on narrow screens.
- DONE: The Shortcuts dialog caps its height to the viewport and scrolls
  internally, keeping its heading and close button reachable on mobile.
- DONE: Composer mode controls show Remix icons, a live active-mode badge, and can be
  switched with Cmd/Ctrl+1..4 for Direct, Interview, Change site, and Improve.
- DONE: Active chat history renders as a compact timeline with type icons, timestamps,
  metadata chips for questions/plans/builds/audits, and "Use again" actions that restore
  earlier user prompts into the composer.
- DONE: Composer textarea auto-resizes and keeps a browser-local draft per active chat/new
  chat, with a small restored/saved status indicator so accidental reloads do not lose work.
- DONE: Reference composer state now reflects URL/notes/screenshot context: the composer
  button switches to "Reference added" and the screenshot picker shows the selected filename.
- DONE: Composer has a compact clear-prompt action (and Cmd/Ctrl+Backspace/Delete) that
  clears the textarea and removes the saved draft for the current chat/new chat.
- DONE: Welcome prompt chips have an active/pressed state while the composer text matches
  that example; manual edits or clearing the prompt remove the active state.
- DONE: The main chat sidebar can collapse into an icon rail with a Remix sidebar button;
  the preference is saved locally and mobile always falls back to the readable expanded layout.
- DONE: Composer copy now follows the selected mode: prompt title, placeholder, and submit
  button label update for Direct, Interview, Change site, and Improve.
- DONE: Sidebar collapse is also available from the keyboard with Cmd/Ctrl+Backslash and
  is listed in the Shortcuts modal.
- DONE: Chat search now updates the sidebar count while filtering and Escape clears an
  active search back to the full chat list.
- DONE: Composer shows lightweight prompt readiness and character count below the textarea,
  synced with drafts, quick chips, manual edits, and clear prompt.
- DONE: Composer submit button gets a soft empty-prompt visual state while staying clickable
  for nonstandard/testing flows.
- DONE: Composer footer shows a compact Reference summary when URL, screenshot, or notes
  are attached, and hides it when no reference context is present.
- DONE: Reference modal includes a compact "Clear reference" action that resets URL, notes,
  screenshot file input, button state, filename label, and footer summary.
- DONE: Welcome prompt chips now render as a compact responsive grid with visible short
  descriptions, instead of hiding the chip metadata.
- DONE: Composer footer layout is responsive: readiness, reference summary, and character
  count share space safely, with reference summary wrapping on mobile.
- DONE: Reference modal has an explicit Done action that closes the modal after adding
  URL, screenshot, or notes, alongside the secondary Clear reference action.
- DONE: Welcome quick chips now show an "Example loaded" draft status and save the selected
  example immediately, instead of waiting for the debounce timer.
- DONE: Chat history "Use again" restores a prompt with immediate draft save and a
  "Prompt restored" status, matching quick-chip feedback behavior.
- DONE: Opening the Reference modal via button or Cmd/Ctrl+Shift+R now focuses the first
  useful field (empty URL first, otherwise notes) for faster entry.
- DONE: Composer Reference summary is now a clickable button that reopens the Reference
  modal with the same autofocus behavior.
- DONE: Composer draft/status messages auto-clear after a short delay, guarded so older
  timers do not erase a newer status.
- DONE: Clear-prompt button now hides in the empty-prompt state without shifting the
  composer header layout.
- DONE: Composer status/readiness is more accessible: draft status uses polite aria-live,
  and the prompt textarea is described by readiness and character count text.
- DONE: Composer readiness and character count statuses declare and preserve
  `aria-controls="ol-main-prompt"`, matching their textarea description role.
- DONE: Composer prompt textarea is also described by the draft status live
  region, which declares/preserves `aria-controls="ol-main-prompt"`.
- DONE: Cmd/Ctrl+Enter respects the Reference modal: when Reference is open it closes the
  modal as Done instead of submitting the main Generate action.
- DONE: Reference modal restores focus to the opening trigger (Reference button or footer
  summary) when closed with Done, Esc, or Cmd/Ctrl+Enter.
- DONE: Reference modal ARIA state stays in sync: opener controls use `aria-controls` and
  `aria-expanded`, while the modal updates `aria-hidden` on every open/close path.
- DONE: Composer mode cards expose radio semantics with synced `aria-checked` and `is-active`
  state, including a CSS fallback alongside `:has(input:checked)`.
- DONE: Composer mode cards are wrapped in a `radiogroup`, are focusable, and support
  ArrowLeft/ArrowRight/ArrowUp/ArrowDown navigation across Direct/Interview/Change/Improve.
- DONE: Keyboard focus is visibly indicated for composer mode cards and the clickable
  Reference summary via restrained `:focus-visible` rings.
- DONE: Focused composer mode cards can be selected with Enter or Space, in addition to
  arrow-key navigation and Cmd/Ctrl+1..4 shortcuts.
- DONE: Shortcuts help now documents mode-card arrow/Enter/Space controls and keeps its
  modal `aria-hidden` / opener `aria-expanded` state in sync when opened or closed.
- DONE: Reference and Shortcuts dialogs declare `aria-modal` and close consistently from
  Done/Esc, the close icon, or backdrop click while restoring focus to the opener.
- DONE: Chat search empty state now names the active query and includes a Clear search
  action that restores the full saved-chat list and focus.
- DONE: Composer readiness treats URL/notes/screenshot Reference context as valid input:
  empty prompt + attached reference no longer looks empty, and the Generate button reflects it.
- DONE: Reference URL input normalizes bare domains like `modenza.org` to `https://modenza.org`
  on blur, modal close, and submit so the URL field does not block common shorthand input.
- DONE: Reference modal and composer footer show the concrete reference host when a URL is
  attached (e.g. `modenza.org, screenshot, notes`) instead of the generic `URL` label.
- DONE: Reference modal labels are explicitly wired to URL, screenshot, and notes inputs
  with stable ids, improving native click/focus behavior and accessibility.
- DONE: Reference screenshot can be removed independently with a compact Remove action,
  without clearing the reference URL or notes.
- DONE: Reference `Clear reference` action is disabled until URL, screenshot, or notes
  are present, so empty reference state has less misleading affordance.
- DONE: Composer submit button keeps its visible label, tooltip, and `aria-label` synced
  with the selected mode (`Generate`, `Ask questions`, `Plan change`, `Improve`).
- DONE: Reference opener and footer summary expose state-aware tooltips/`aria-label`s that
  say whether the user is adding or editing reference context and name the attached parts.
- DONE: Welcome quick example chips expose explicit `aria-label`/tooltip copy and a
  restrained `:focus-visible` ring for keyboard navigation.
- DONE: Sidebar navigation and top tools expose explicit titles/`aria-label`s, so collapsed
  icon-only controls remain understandable via hover and assistive tech.
- DONE: Saved chat rows and their action menus name the specific chat in labels/tooltips
  and expose restrained focus rings for keyboard navigation.
- DONE: Saved chat rename/delete controls inside the action menu also name the target chat
  and expose focus rings for the title input and menu buttons.
- DONE: Saved chat action menus behave like a single popover group: opening one closes the
  others, Esc closes open menus, and summary `aria-expanded` stays synced.
- DONE: Saved chat action menus also close on outside click, while clicks inside the menu
  keep the active rename/delete controls usable.
- DONE: Chat search exposes a hidden live status for filtered result counts and a restrained
  focus ring on the search control.
- DONE: Chat search result status uses `role=status` / `aria-live=polite`, and the Chats
  counter exposes a descriptive label for saved-chat counts.
- DONE: Chat count labels and search result announcements use singular/plural text
  correctly (`1 chat`, `2 chats`) in both initial markup and live updates.
- DONE: Composer textarea exposes a mode-aware `aria-label` that stays synced with the
  Direct/Interview/Change/Improve prompt title.
- DONE: Composer mode badge exposes a polite status, title, and `aria-label` like
  `Current mode: Direct`, synced whenever the mode changes.
- DONE: Composer mode badge declares and preserves `aria-controls="ol-main-prompt"`
  because the selected mode changes prompt title/placeholder/submit context.
- DONE: Composer clear-prompt action is disabled in the empty state and exposes
  state-aware tooltip/`aria-label` text when a draft can be cleared.
- DONE: Composer submit action exposes state-aware tooltip/`aria-label` text: empty prompt
  with no reference asks for prompt/reference context, while ready states name the mode action.
- DONE: Composer mode cards expose self-contained tooltip/`aria-label` text with mode name,
  short purpose, and keyboard shortcut instead of shortcut-only titles.
- DONE: Composer Reference opener has a self-contained initial tooltip/`aria-label` and keeps
  both synced when reference context is added or removed.
- DONE: Composer Reference opener and footer summary preserve `aria-controls`
  links to the reference modal while JS syncs state-aware labels/tooltips.
- DONE: Composer Reference opener and footer summary declare and preserve
  `aria-describedby` for the reference description/status while syncing state.
- DONE: Reference modal open-state sync preserves opener/footer `aria-controls`
  links while updating `aria-expanded`.
- DONE: Reference modal detail line (`No reference added` / `Using ...`) is a polite live
  status so URL, screenshot, and notes changes are announced.
- DONE: Reference modal detail status declares and preserves `aria-controls`
  for URL, screenshot, and notes fields that make up the reference summary.
- DONE: Reference screenshot attach/remove controls expose state-aware labels/tooltips that
  include the selected filename when present.
- DONE: Reference screenshot attach/remove controls declare `aria-controls` for the
  screenshot input and reference detail status they update, preserved during JS sync.
- DONE: Reference screenshot attach/remove controls reference the modal context
  and/or live detail status with `aria-describedby`, preserved during JS sync.
- DONE: Reference URL and notes fields expose self-contained labels/tooltips, clarifying
  that those inputs are reference context for Olivia.
- DONE: Reference URL and notes fields reference the modal description and live
  reference detail status with `aria-describedby`.
- DONE: Reference URL and notes fields declare and preserve
  `aria-controls="ol-ref-detail"` because typing updates the live summary.
- DONE: Reference Clear/Done actions expose self-contained labels/tooltips, and Clear
  switches between `No reference to clear` and `Clear all reference context`.
- DONE: Reference Clear action JS sync preserves `aria-controls` for URL, screenshot,
  notes, and live detail status while updating disabled/label state.
- DONE: Reference Clear action references the live detail status with
  `aria-describedby`, preserved during disabled/label sync.
- DONE: Reference modal controls share restrained focus rings across close, URL, notes,
  screenshot attach/remove, Clear, and Done actions.
- DONE: Shortcuts dialog is labelled by its visible title/description and its close control
  exposes a self-contained label/tooltip.
- DONE: Both top and sidebar Shortcuts openers share synchronized `aria-expanded` state
  via a non-styling `data-help-open` marker.
- DONE: Shortcuts opener JS sync preserves `aria-controls="ol-help-modal"`
  while updating shared expanded state.
- DONE: Shortcuts openers declare and preserve `aria-describedby` for the
  shortcuts dialog description/note while syncing open state.
- DONE: Support info links describe that they open copyable debug parameters, in both the
  top toolbar and sidebar.
- DONE: Top toolbar controls, sidebar navigation, and the sidebar collapse button share a
  restrained keyboard focus ring.
- DONE: Composer bottom actions (Reference opener and submit button) share the same
  restrained keyboard focus ring as the rest of the shell.
- DONE: Blueprints & advanced controls (summary, selects, color picker, JSON textarea,
  and action buttons) share the same restrained keyboard focus ring.
- DONE: Blueprints & advanced summary now shows a live compact status for selected
  font, brand colour, blueprint, and whether editable JSON is present.
- DONE: Theme colour swatches expose accessible labels, synced `aria-pressed` state,
  active selection styling, and the shared keyboard focus ring.
- DONE: Advanced theme/blueprint selects, colour input, JSON editor, and utility/build
  buttons expose self-contained tooltips and `aria-label`s that describe their action.
- DONE: Keyboard shortcuts include Cmd/Ctrl+Shift+A to toggle the Blueprints & advanced
  panel and focus its summary so the open/closed state is clear.
- DONE: Blueprints & advanced remembers the user's open/closed preference in the browser,
  keeps plan-loaded states open, and syncs summary `aria-expanded` plus shortcut tooltip.
- DONE: Build plan JSON has a compact Copy JSON action with disabled empty-state,
  clipboard fallback, polite copied status, and mobile-safe header wrapping.
- DONE: Keyboard shortcuts include Cmd/Ctrl+Shift+C to copy the editable build plan JSON
  through the same clipboard fallback/status path as the Copy JSON button.
- DONE: Build plan JSON editor shows a live non-blocking `No JSON` / `Valid JSON` /
  `Invalid JSON` status while server-side validation remains authoritative.
- DONE: Invalid build-plan JSON status keeps the visible badge compact while exposing the
  parser error through tooltip and `aria-label` for faster manual fixes.
- DONE: Build plan JSON editor has a Format action that is enabled only for valid JSON and
  pretty-prints via `JSON.parse`/`JSON.stringify(..., null, 2)` without changing validation.
- DONE: Keyboard shortcuts include Cmd/Ctrl+Shift+P to run the same valid-JSON Format
  action without conflicting with Cmd/Ctrl+Shift+F chat search.
- DONE: Copy JSON and Format JSON button tooltips/`aria-label`s now mention their matching
  Cmd/Ctrl+Shift+C/P shortcuts while keeping visible button text compact.
- DONE: Format JSON preserves the editor's approximate cursor and scroll position after
  pretty-printing, so large plan edits do not jump back to the top.
- DONE: Build plan JSON editor has a one-step Undo edit action that captures manual
  `beforeinput` edits and Format changes, then refreshes JSON status and toolbar state.
- DONE: Keyboard shortcuts include Cmd/Ctrl+Shift+Z for the JSON Undo edit action, keeping
  ordinary Cmd/Ctrl+Z available for the browser/editor's native undo.
- DONE: Build plan JSON header shows a live character-count badge (compact `k chars`
  formatting for large plans) with an exact-count tooltip for quick debugging.
- DONE: Valid build-plan JSON shows a compact live shape badge (`F/T/P/M`) with recursive
  page counts and a tooltip naming fields, templates, pages, and modules.
- DONE: Valid build-plan JSON also shows a compact site badge from `site.title`/`site.type`
  with truncation in the header and the full value in tooltip/`aria-label`.
- DONE: Valid build-plan JSON shows a non-blocking schema hint when expected top-level
  keys (`site`, `fields`, `templates`, `pages`) are missing; server validation remains final.
- DONE: JSON editor toolbar state is centralized through `syncJsonTools()` so input,
  Format, Undo, and initial load refresh status, size, shape, hints, and buttons together.
- DONE: Build remains server-validated and clickable, but gets a warning state/tooltip
  when editable JSON is non-empty and syntactically invalid.
- DONE: Preview gets the same non-blocking invalid-JSON warning treatment as Build, with
  shared action-button sync while both still submit to server validation.
- DONE: Preview/Build warning state also covers valid JSON with active schema hints
  (missing top-level plan keys), still without blocking server validation.
- DONE: Preview/Build schema-warning tooltips/`aria-label`s include the concrete missing
  top-level keys, reusing the JSON schema hint state.
- DONE: JSON header badges are focusable jump controls; click or Enter/Space focuses the
  editable JSON textarea, with matching focus rings and action-aware `aria-label`s.
- DONE: JSON header badge sync preserves `aria-controls="ol-plan-json"` while
  updating validity, site, size, shape, and schema hint labels.
- DONE: JSON header badge sync preserves `aria-describedby="ol-json-diagnostics-help"`
  so each focusable diagnostic badge keeps the shared diagnostic context.
- DONE: Focusing the editable JSON textarea opens/persists Blueprints & advanced, so badge
  jumps and direct focus do not leave the advanced panel closed after reload.
- DONE: Closing Blueprints & advanced moves focus back to its summary when focus was inside
  the panel, avoiding focus remaining in hidden JSON controls.
- DONE: Blueprints & advanced summary keeps a state-aware `aria-label`/tooltip naming the
  current expand/collapse action and Cmd/Ctrl+Shift+A shortcut.
- DONE: Blueprints & advanced summary JS sync preserves `aria-controls` and
  `aria-describedby` links to the advanced panel and live compact status.
- DONE: Sidebar collapse button keeps its tooltip synchronized with collapsed/expanded
  state and the Cmd/Ctrl+Backslash shortcut, matching its `aria-label`.
- DONE: Sidebar collapse button declares `aria-controls="ol-app-shell"` and the app shell
  has a stable id, making the controlled region explicit.
- DONE: Sidebar collapse button JS sync preserves `aria-controls="ol-app-shell"`
  while updating collapsed/expanded labels.
- DONE: JSON header jump badges declare `aria-controls="ol-plan-json"`, matching their
  click/keyboard behavior that focuses the editable JSON textarea.
- DONE: Blueprints & advanced summary declares `aria-controls="ol-advanced-panel"` and
  the advanced controls live in that stable panel region.
- DONE: Blueprints & advanced panel syncs `aria-hidden` on its controlled region with the
  `<details>` open state, including localStorage-restored states.
- DONE: Blueprints & advanced panel is labelled by the visible summary title via
  `aria-labelledby="ol-advanced-title"`, so the controlled region has a clear name.
- DONE: Blueprints & advanced controlled panel declares `role="region"` so that label is
  exposed as a named region instead of just a generic div.
- DONE: Blueprints & advanced summary describes itself with the live compact status via
  `aria-describedby="ol-advanced-status"`, exposing font/colour/blueprint/JSON context.
- DONE: Blueprints & advanced compact status declares `role=status` / `aria-live=polite`,
  matching its live updates as font, colour, blueprint, or JSON state changes.
- DONE: Blueprints & advanced compact status declares and preserves `aria-controls`
  for the font, colour, blueprint, and JSON controls it summarizes.
- DONE: Theme font, brand colour, and blueprint controls declare/preserve
  `aria-controls="ol-advanced-status"` because changes update the compact status.
- DONE: Brand colour swatches are wrapped in a labelled `role="group"` so the preset
  colour buttons are announced as one related control set.
- DONE: Theme font, brand colour, and preset swatches describe themselves with the visible
  theme helper text, keeping the override context available to assistive tech.
- DONE: JSON Undo, Format, and Copy actions declare `aria-controls="ol-plan-json"`,
  matching the editable build-plan textarea they operate on.
- DONE: JSON Undo, Format, and Copy actions are wrapped in a labelled `role="group"` so
  they are announced as related build-plan JSON tools.
- DONE: JSON status/site/size/shape/hint badges are wrapped in a labelled `role="group"`
  so they are announced as related build-plan JSON diagnostics.
- DONE: JSON action group is described by the shared live action status
  (`ol-json-action-status`) that reports Copied, Formatted, and Undo applied.
- DONE: JSON Copy action preserves its `aria-controls` / `aria-describedby`
  links to the editor and live action status while showing copied-state feedback.
- DONE: JSON Copy disabled/ready sync also preserves `aria-controls` /
  `aria-describedby` links to the editor and live action status.
- DONE: JSON Format and Undo sync functions preserve their `aria-controls` /
  `aria-describedby` links to the editor and live action status.
- DONE: JSON action buttons share `preserveJsonActionReferences()` so Copy,
  Format, and Undo keep consistent editor/live-status ARIA links.
- DONE: Preview and Build actions declare `aria-controls="ol-plan-json"`, matching the
  reviewed editable plan JSON they validate and submit.
- DONE: Preview and Build warning-state sync preserves `aria-controls` /
  `aria-describedby` links to the editor, plan-action help, and JSON diagnostics.
- DONE: Preview and Build are wrapped in a labelled action group, keeping the final plan
  review/build controls announced as related actions.
- DONE: Theme font, brand colour picker, and colour preset swatches are wrapped in a
  labelled group so theme override controls are announced as related controls.
- DONE: Blueprint select plus Load blueprint, Refresh skills, and Share actions are wrapped
  in a labelled group so those advanced utilities are announced as related controls.
- DONE: Blueprint utility group and its controls describe themselves with hidden helper text
  explaining blueprint loading, skills refresh, and shareable blueprint creation.
- DONE: Preview/Build action group and buttons describe themselves with hidden helper text
  explaining dry preview versus creating the reviewed JSON plan in ProcessWire.
- DONE: Editable build-plan JSON textarea is described by the JSON diagnostics group and
  shared JSON action status so current validity/size/action feedback follows focus.
- DONE: JSON action group has persistent hidden helper text explaining Undo, Format, and
  Copy, while still announcing live action feedback through `ol-json-action-status`.
- DONE: JSON action status declares and preserves `aria-controls="ol-plan-json"`
  plus `aria-describedby="ol-json-action-help"` during Copy/Format/Undo feedback.
- DONE: JSON diagnostics group has persistent hidden helper text explaining validity, site,
  size, shape, and schema-hint badges for the editable build plan.
- DONE: Editable build-plan JSON textarea directly references the diagnostics helper, JSON
  action helper, and live action status via `aria-describedby` for more reliable reading.
- DONE: Load blueprint and Share actions declare `aria-controls="ol-plan-json"` because
  they load into or read from the editable build-plan JSON.
- DONE: Theme colour swatch buttons declare `aria-controls="ol-theme-primary"`, matching
  the brand colour picker they update.
- DONE: Theme colour swatch sync preserves each swatch button's `aria-controls`
  and `aria-describedby` links while updating active/pressed state.
- DONE: Brand colour presets group declares `aria-controls="ol-theme-primary"`, matching
  the brand colour picker controlled by the individual swatch buttons.
- DONE: Brand colour presets group and swatch buttons describe themselves with hidden
  helper text explaining that preset buttons update the brand colour override picker.
- DONE: Theme override group declares `aria-controls="ol-theme-font ol-theme-primary"`,
  with a stable id on the font select and existing brand colour picker id.
- DONE: Theme override group describes itself with the visible theme helper text, matching
  the individual font, colour, and swatch controls.
- DONE: Blueprint utility group declares `aria-controls="ol-blueprint-select ol-plan-json"`,
  with a stable id on the blueprint select and existing editable plan JSON id.
- DONE: Preview/Build action group declares `aria-controls="ol-plan-json"`, matching the
  editable plan JSON controlled by the individual Preview and Build buttons.
- DONE: JSON diagnostics group declares `aria-controls="ol-plan-json"`, matching the
  editable plan JSON controlled by its individual diagnostic jump badges.
- DONE: JSON action group declares `aria-controls="ol-plan-json"`, matching the editable
  plan JSON controlled by its Undo, Format, and Copy buttons.
- DONE: Editable build-plan JSON textarea is labelled by the visible build-plan label via
  `aria-labelledby="ol-plan-json-label"` instead of a shorter duplicate `aria-label`.
- DONE: JSON validity badge and shared JSON action status use `aria-atomic="true"` so
  short live updates are announced as complete messages.
- DONE: Blueprints & advanced compact status uses `aria-atomic="true"` so font/colour/
  blueprint/JSON summary changes are announced as one complete status.
- DONE: Blueprints & advanced summary is labelled by its visible title via
  `aria-labelledby="ol-advanced-title"`, matching the controlled panel region.
- DONE: Blueprints & advanced controlled panel is described by the compact status via
  `aria-describedby="ol-advanced-status"`, matching the summary context.
- DONE: Composer draft status, mode badge, and Reference detail use `aria-atomic="true"`
  so their short live updates are announced as complete messages.
- DONE: Composer prompt readiness is a polite atomic live status, while character count
  remains a non-live description to avoid announcing every keystroke.
- DONE: Composer prompt textarea is labelled by the visible mode-aware prompt title via
  `aria-labelledby="ol-prompt-title"` instead of a duplicate `aria-label`.
- DONE: Composer prompt textarea has stable id `ol-main-prompt`; Clear prompt and Generate
  actions declare `aria-controls="ol-main-prompt"` because they operate on that prompt.
- DONE: Composer Clear prompt and Generate JS sync preserves `aria-controls="ol-main-prompt"`
  while updating empty/ready and mode-specific labels.
- DONE: Composer prompt-target controls share `preservePromptTarget()` so Clear,
  Generate, and mode cards keep consistent prompt ARIA links.
- DONE: Composer generation mode radiogroup declares `aria-controls="ol-main-prompt"`
  because mode changes update prompt title, placeholder, and submit action context.
- DONE: Each composer generation mode radio card declares `aria-controls="ol-main-prompt"`,
  matching the prompt context updated when that mode is selected.
- DONE: Composer mode-card JS sync preserves `aria-controls="ol-main-prompt"`
  while updating active and `aria-checked` state.
- DONE: Reference dialog is labelled by its visible `Reference` title via
  `aria-labelledby="ol-ref-title"` instead of a duplicate dialog `aria-label`.
- DONE: Reference dialog is described by its visible subtitle via
  `aria-describedby="ol-ref-desc"`, preserving the URL/screenshot/notes context.
- DONE: Reference dialog description also includes the live Reference detail status via
  `aria-describedby="ol-ref-desc ol-ref-detail"`, exposing current reference context.
- DONE: Reference dialog close control uses self-contained `Close reference dialog`
  label/title instead of a generic `Close`.
- DONE: Shortcuts dialog description includes the visible Ctrl fallback note via
  `aria-describedby="ol-help-desc ol-help-note"`.
- DONE: Sidebar collapse button initial tooltip is self-contained (`Collapse sidebar -
  Command/Ctrl + \`) before JavaScript syncs collapsed/expanded state.
- DONE: Sidebar New chat action keeps tooltip and `aria-label` synced with the
  Cmd/Ctrl+Shift+N shortcut.
- DONE: Sidebar chat search keeps tooltip and input `aria-label` synced with the
  Cmd/Ctrl+Shift+F shortcut.
- DONE: Sidebar chat search input and Clear search action declare `aria-controls`
  for the stable saved-chat list region they filter or restore.
- DONE: Sidebar Clear search action declares and preserves `aria-describedby`
  for the chat search live status it updates.
- DONE: Top and sidebar Shortcuts openers keep tooltip and `aria-label` synced with
  the `?` shortcut.
- DONE: Sidebar Build history and Module skills links use action-oriented
  tooltip/`aria-label` text for collapsed icon-only navigation.
- DONE: Top and sidebar Support info links use concise action-oriented
  tooltip/`aria-label` text: `Open support debug bundle`.
- DONE: Chat search empty-state Clear search button uses self-contained
  `title`/`aria-label` text: `Clear chat search`.
- DONE: Saved chat rename input plus Rename/Delete menu actions keep matching
  `title` and `aria-label` text naming the target chat.
- DONE: Chat history `Use again` actions expose matching `title`/`aria-label`
  text: `Use this prompt again`.
- DONE: Improve/audit suggestion `Apply in Change mode` buttons expose matching
  `title`/`aria-label` text: `Apply this suggestion in Change mode`.
- DONE: Standalone utility-page nav links expose action-oriented `title`/`aria-label`
  text for composer, history, skills, and support debug navigation.
- DONE: Support info Copy debug bundle action exposes matching `title`/`aria-label`
  text: `Copy support debug bundle`.
- DONE: Support info Copy debug bundle action declares `aria-controls` for the
  copyable debug JSON textarea and preserves it during copied-state feedback.
- DONE: Support info Copy debug bundle action declares `aria-describedby` for
  its copied-state live status and preserves it during copied-state feedback.
- DONE: Standalone Module skills `Refresh skills` actions expose matching
  `title`/`aria-label` text: `Refresh Olivia module skills library`.
- DONE: Standalone Module skills `Refresh skills` form/button reference
  `ol-skills-desc` with `aria-describedby`, matching the visible page context.
- DONE: Build history Undo actions expose matching `title`/`aria-label` text
  naming the target build id before the destructive confirmation.
- DONE: Module recommendation Install and Teach Olivia actions expose matching
  `title`/`aria-label` text naming the target module.
- DONE: Module recommendation Install and Teach Olivia form/buttons reference
  the recommendation list and intro text via `aria-controls`/`aria-describedby`.
- DONE: Share blueprint / module author template textareas and telemetry feedback
  buttons expose self-contained `title`/`aria-label` text.
- DONE: Telemetry feedback Yes/No buttons reference the visible feedback question
  with `aria-describedby`, matching the feedback form context.
- DONE: Background worker stalled-state `check the result` link exposes matching
  `title`/`aria-label` text: `Check Olivia worker result`.
- DONE: Module recommendation external directory links use `rel="noopener noreferrer"`
  and expose matching `title`/`aria-label` text naming the target module.
- DONE: Support info debug JSON textarea exposes matching `title`/`aria-label`
  text: `Copyable Olivia support debug JSON`.
- DONE: Composer Reference opener and Generate submit initial markup keeps
  shortcut-aware `title` and `aria-label` text in sync before JavaScript state sync.
- DONE: Composer mode card initial markup keeps shortcut-aware `title` and
  `aria-label` text in sync for Direct, Interview, Change site, and Improve.
- DONE: Reference URL and notes inputs expose context-specific matching
  `title`/`aria-label` text naming Olivia as the consumer of that context.
- DONE: Advanced theme font, brand colour, and blueprint selectors expose
  Olivia-specific matching `title`/`aria-label` text.
- DONE: Composer advanced `Refresh skills` action uses the same matching
  `title`/`aria-label` text as standalone skills pages.
- DONE: Reference modal Clear and Done actions declare `aria-controls` for the
  reference fields/status and modal they update or close.
- DONE: Reference modal Done action declares `aria-describedby` for the
  reference description/status so closing keeps the current context available.
- DONE: Build plan JSON Undo, Format, and Copy buttons declare `aria-describedby`
  for the shared JSON action help and copy/status live region.
- DONE: Preview and Build actions describe both the plan-action help and JSON
  diagnostics help because they operate on the editable build plan JSON.
- DONE: Module recommendation status labels (`installed`, `install manually`,
  `learned`) expose matching `title`/`aria-label` text naming the module.
- DONE: Sidebar chat date group labels expose heading semantics with
  `role="heading"` and `aria-level="3"`.
- DONE: Sidebar empty chat and no-results states expose `role="status"` with
  polite live-region behavior.
- DONE: Sidebar empty chat state has a stable id and lets its visible
  `No saved chats yet` text provide the status announcement.
- DONE: Sidebar no-results chat search state has a stable id, lets its visible
  dynamic query text provide the status announcement, and references the search
  live status with `aria-describedby`.
- DONE: Active saved-chat links expose `aria-current="page"` in the sidebar.
- DONE: Saved chat action popovers are connected to their summary controls with
  `aria-controls` and expose a named `role="group"` for the target chat.
- DONE: Saved chat action popover JS sync preserves each summary's `aria-controls`
  link to the concrete popover while updating `aria-expanded`.
- DONE: Saved chat Rename/Delete submit actions declare `aria-controls` for the
  stable saved-chat list region they update.
- DONE: Saved chat Rename/Delete forms also declare `aria-controls` for the
  saved-chat list, matching their submit actions.
- DONE: Saved chat Rename title input declares `aria-controls="ol-saved-chats"`
  because submitting it updates the saved-chat list region.
- DONE: Chat history feed items render as labelled `<article>` elements and mark
  decorative message icons `aria-hidden`.
- DONE: Chat history timestamps render with machine-readable `datetime` plus a
  tooltip containing the original saved timestamp.
- DONE: Chat history metadata chips render as a labelled ARIA list with each
  count/job chip marked as a list item.
- DONE: Chat history message action containers expose a labelled `role="group"`
  around actions such as `Use again`.
- DONE: Utility tables define explicit header scopes: support info uses row
  headers, while Module skills and Build history use column headers.
- DONE: Module skills and Build history table rows use their primary identifier
  cells as `scope="row"` headers.
- DONE: Standalone Module skills and Build history empty states expose
  `role="status"` with polite live-region behavior.
- DONE: Main composer panel exposes an accessible name via
  `aria-label="Olivia composer workspace"`.
- DONE: Sidebar landmark exposes an accessible name via
  `aria-label="Olivia chats and tools"`.
- DONE: Sidebar utility navigation exposes an accessible name via
  `aria-label="Olivia tools"`.
- DONE: Saved chats sidebar list renders as a named navigation landmark with
  `aria-label="Saved chats"`.
- DONE: Sidebar brand/tools/search Remix icons are marked `aria-hidden` where
  adjacent text or aria-labels already provide the accessible name.
- DONE: Saved chat action menu Remix icons are marked `aria-hidden` where
  summary/button labels already provide the accessible name.
- DONE: Chat history section is labelled by its visible heading, and the
  `Use again` action icon is marked decorative with `aria-hidden`.
- DONE: Composer header logo/help/debug Remix icons are marked `aria-hidden`
  where visible text or aria-labels already provide names.
- DONE: Composer prompt, reference modal, mode card, and submit/control Remix
  icons are marked `aria-hidden` where labels or visible text provide names.
- DONE: Advanced JSON toolbar and Build action Remix icons are marked
  `aria-hidden` where button labels already provide accessible names.
- DONE: Standalone utility navigation exposes `aria-label="Olivia utility pages"`,
  and utility nav/debug/skills/history action icons are marked decorative.
- DONE: Dynamic Copy JSON reset, async thinking loader, and learned-module
  status Remix icons are marked decorative with `aria-hidden`.
- DONE: Standalone utility navigation marks the active History, Skills, or
  Support info link with `aria-current="page"` alongside the visual state.
- DONE: Standalone Support info, Module skills, and Build history page sections
  are labelled by their visible H1 headings via `aria-labelledby`.
- DONE: Utility data tables expose explicit `aria-label` names for support
  parameters, module skills, and build history.
- DONE: Sidebar search/empty states and standalone utility empty states use
  `aria-atomic="true"` on polite status regions.
- DONE: Saved chat rename/delete forms expose chat-specific `aria-label`
  names inside each action popover.
- DONE: Composer, module install/teach, feedback, skills refresh, and build
  undo forms expose explicit `aria-label` names.
- DONE: Chat history `Continue here` link exposes matching `title` and
  `aria-label` text.
- DONE: Composer footer Reference summary button has initial `title` and
  `aria-label` fallback before JavaScript sync updates attached context.
- DONE: Blueprints & advanced summary starts with server-rendered
  `aria-expanded`, `title`, and `aria-label` matching its open/closed state.
- DONE: Composer mode cards now server-render `aria-checked` from the selected
  radio state before JavaScript sync runs.
- DONE: Hidden Help and Reference checkbox toggles are removed from tab order
  and the accessibility tree with `tabindex="-1"` and `aria-hidden="true"`.
- DONE: Help and Reference modal backdrop labels are marked `aria-hidden` so
  only the actual dialogs and close controls are exposed to assistive tech.
- DONE: Shortcuts dialog rows expose list semantics with a labelled
  `role="list"` container and `role="listitem"` shortcut rows.
- DONE: Welcome prompt examples expose list semantics, and their Remix icons
  are marked decorative with `aria-hidden`.
- DONE: Module recommendation rows expose list semantics, and trust badges
  include self-contained `title`/`aria-label` text.
- DONE: Build-time module install opt-in checkbox is described by the generated
  module list via `aria-describedby`, and that list is explicitly labelled.
- DONE: Build-time module install opt-in checkbox declares `aria-controls` for
  the generated module list it toggles for the build.
- DONE: Plan preview summary table exposes `aria-label` and row-header
  `scope="row"` attributes.
- DONE: Plan preview page tree root list exposes `aria-label="Planned page tree"`,
  and template chips include self-contained `title`/`aria-label` text.
- DONE: Share-safe blueprint and module-author contribution fragments render as
  labelled sections via `aria-labelledby`.
- DONE: Share-safe blueprint and module-author contribution sections reference
  their visible helper text with `aria-describedby`.
- DONE: Share-safe blueprint and module-author template textareas have stable ids
  and reference their visible helper text with `aria-describedby`.
- DONE: Telemetry feedback form is described by its visible question via
  `aria-describedby`.
- DONE: Thinking/worker status cards expose `role="status"`, `aria-live`,
  `aria-atomic`, and decorative loader icons across server and JS paths.
- DONE: Composer mode radio inputs are removed from tab order/accessibility
  tree while the labelled mode cards expose radio semantics and submit values.
- DONE: Sidebar collapse control now exposes synced `aria-expanded` in addition
  to `aria-pressed`, including the initial server-rendered expanded state.
- DONE: Improve-mode `Apply in Change mode` now dispatches `input`/`change`
  events after filling the composer so drafts, status text, and mode UI sync.
- DONE: Chat history `Use again` and mode shortcut/card selection now dispatch
  native-style `input`/`change` events so all composer subscribers stay synced.
- DONE: Welcome quick examples and Clear prompt now use the same `input` event
  pipeline as manual typing, with explicit draft persistence/clearing.
- DONE: Browser-local prompt draft restore now also dispatches `input` through
  the composer pipeline while cancelling the automatic save timer afterward.
- DONE: Reference URL normalization, screenshot removal, and Clear reference now
  dispatch `input`/`change` events instead of relying only on direct state sync.
- DONE: Advanced color swatches plus JSON Format/Undo now dispatch `change` or
  `input` events so advanced status and JSON diagnostics use one sync path.
- DONE: Help/Reference label-based modal controls now expose button semantics,
  keyboard focus, and Enter/Space activation for open and close actions.
- DONE: Reference screenshot attach pill now owns the keyboard focus/button
  semantics while the hidden file input is removed from tab order.
- DONE: Help and Reference modal close controls now expose `aria-controls`
  pointing at the dialog they close.
- DONE: Help and Reference modal close controls now expose `aria-describedby`
  links to the dialog description/status text, so icon-only closes have context.
- DONE: Reference screenshot attach pill shows the shared focus ring on direct
  `:focus-visible` now that the pill itself owns keyboard focus.
- DONE: Build-time recommended-module install checkbox now has a self-contained
  label/title and the shared visible keyboard focus ring.
- DONE: Advanced Theme descriptor now renders as descriptive text instead of a
  standalone `<label>` without a directly labelled form control.
- DONE: Chat history `Use again` buttons now expose the shared visible keyboard
  focus ring.
- DONE: Sidebar `Clear search` and standalone utility navigation links now use
  the shared visible keyboard focus ring.
- DONE: Improve audit `Apply in Change mode` buttons now expose the shared
  visible keyboard focus ring.
- DONE: Support info copy action now exposes a polite copied status and resets
  its button label/title/aria-label after clipboard feedback.
- DONE: Build plan Copy JSON action now keeps title/aria-label synced during
  copied feedback and resets them with the original icon label.
- DONE: Build plan JSON diagnostic jump badges now server-render fallback
  `title`/`aria-label` values before JavaScript diagnostics sync runs.
- DONE: Composer prompt title, placeholder, mode badge, and submit label now
  server-render from the selected mode before JavaScript mode sync runs.
- DONE: Composer mode labels/copy now use the PHP mode map as the single source
  for both server-rendered HTML and JavaScript mode sync.
- DONE: Composer prompt count/readiness, Clear prompt state, and submit empty
  state now server-render from the current prompt/reference before JS sync runs.
- DONE: Reference URL/notes state now server-renders the composer Reference
  button, footer summary, modal detail, and Clear reference disabled state.
- DONE: Advanced colour swatches now server-render the active swatch class and
  `aria-pressed` state from the current primary colour before JS sync runs.
- DONE: Welcome quick example chips now server-render active styling and
  `aria-pressed` when the current prompt exactly matches an example.
- DONE: Welcome quick example chips declare and preserve `aria-controls="ol-main-prompt"`
  because selecting a chip fills the composer prompt textarea.
- DONE: Saved chat row metadata now uses correct singular/plural text for
  `1 message` vs `N messages` in the server-rendered sidebar.
- DONE: Chat search live filtering now keeps the visible chat counter's
  `title`/`aria-label` synced and uses singular/plural match text correctly.
- DONE: Chat search live filtering preserves the visible counter's
  `aria-controls="ol-saved-chats"` link to the filtered chat list.
- DONE: Chat search live filtering preserves the visible counter's
  `aria-describedby="ol-chat-search-status"` link to the live search status.
- DONE: Chat history range text now uses correct singular/plural message labels
  for visible and total counts.
- DONE: Chat history metadata chips now use explicit singular/plural labels for
  interview question counts and audit finding counts.
- DONE: Chat history build metadata chips now use explicit singular/plural
  labels for field/template/page/image counts.
- DONE: Chat history build metadata omits the `0 images` chip while preserving
  zero counts for structural build objects.
- DONE: Chat history build count labels no longer shadow message-type labels
  across later timeline entries.
- DONE: Chat history metadata lists expose type-specific labels like `Build
  metadata` or `Audit metadata` instead of a generic `Message metadata`.
- DONE: Chat history message action groups expose type-specific labels like
  `Prompt actions` instead of a generic `Message actions`.
- DONE: Chat history build count loop no longer shadows the message author
  label within build timeline entries.
- DONE: Chat history message `role`/`type` values are normalized before being
  used as CSS class tokens, while visible labels keep their readable text.
- DONE: Chat history build entries have a distinct success-colored Remix icon
  treatment so completed builds scan separately from generic assistant entries.
- DONE: Chat history audit entries have a distinct Improve-colored Remix icon
  treatment so audit results scan separately from generic assistant entries.
- DONE: Chat history interview-question entries have a distinct warm Remix icon
  treatment so pending clarification steps scan separately from generic entries.
- DONE: Chat history build-request entries have a distinct neutral pending icon
  treatment so requested builds scan separately from completed builds.
- DONE: Chat history preview entries have a distinct review-step icon
  treatment so dry-run previews scan separately from generic assistant entries.
- DONE: Chat history normalized class tokens now replace underscores with
  hyphens, so types like `build_request` match `.ol-msg-type-build-request`.
- DONE: Chat history `Use again` only appears on real prompt-like user
  entries (`prompt` and `audit_request`), not synthetic build/answer events.
- DONE: Chat history `Use again` restores the matching composer mode: Direct
  for normal prompts and Improve for saved audit requests.
- DONE: New chat prompt events persist their original composer mode in metadata,
  so `Use again` can restore Direct, Interview, Change, or Improve accurately.
- DONE: Chat history `Use again` feedback names the restored mode, e.g.
  `Prompt restored to Improve`, matching the actual composer mode switch.
- DONE: Chat history `Use again` buttons include the restore target in
  `title`/`aria-label`, e.g. `Use this prompt again in Change site mode`.
- DONE: Chat history `Use again` buttons declare and preserve
  `aria-controls="ol-main-prompt"` because they restore the composer prompt.
- DONE: Composer mode labels now come from `composerModeLabels()` so the main
  composer and chat-history restore actions share one label source.
- DONE: Composer mode title/placeholder/submit copy now comes from
  `composerModeCopy()` so server-rendered copy and JavaScript sync share one source.
- DONE: Composer mode ordering/valid values now come from `composerModeValues()`,
  shared by keyboard shortcuts, arrow navigation, and chat-history mode restore.
- DONE: Shortcuts help renders its `Cmd/Ctrl+1-4` mode list from the shared
  composer mode values/labels instead of hardcoded mode names.
- DONE: Composer mode-card visible descriptions plus `title`/`aria-label`
  strings now render from shared mode label/description helpers.
- DONE: Improve audit `Apply in Change mode` now switches to Change before
  filling the composer prompt, so prompt status/copy sync against the target mode.
- DONE: Improve audit `Apply in Change mode` now gives the composer draft-status
  live feedback (`Suggestion loaded to Change`) after filling the prompt.
- DONE: Improve audit suggestion feedback now clears any older feedback timer
  before scheduling a new one, so rapid clicks do not erase newer status early.
- DONE: Improve audit `Apply in Change mode` buttons include the finding title
  in `title`/`aria-label`, so repeated suggestion buttons are distinguishable.
- DONE: Improve audit `Apply in Change mode` buttons declare and preserve
  `aria-controls="ol-main-prompt"` because they fill the composer prompt.
- DONE: Improve audit finding severity is normalized to `high`/`medium`/`low`
  before rendering label text and severity color.
- DONE: Improve audit area chips truncate long model-provided area names in the
  visible chip while preserving the full area in `title`/`aria-label`.
- DONE: Improve audit finding titles truncate long model-provided titles in the
  visible card while preserving the full title in `title`/`aria-label`.
- DONE: Improve audit `why` and `suggestion` text now truncate long model output
  in the visible card while preserving full text in `title`/`aria-label`.
- DONE: Text truncation now uses shared `clipText()` with UTF-8 handling and a
  non-mbstring fallback for audit cards and build-history prompt snippets.
- DONE: Debug bundle masking now uses `clipText(..., '...')` for long string
  config values, avoiding byte-level truncation of UTF-8 text.
- DONE: Composer prompt character counts and `clipText()` share `textLength()`
  for UTF-8-aware length checks with a non-mbstring fallback.
- DONE: `clipText()` now accounts for suffix length so clipped output stays
  within the requested visible limit, with UTF-8 and fallback paths aligned.
- DONE: `clipText()` / `textLength()` now use a PCRE Unicode character fallback
  when `mbstring` is unavailable, with byte truncation only as a last resort.
- DONE: `scripts/smoke.php` now asserts UTF-8 `textLength()` / `clipText()`
  behavior and suffix-length clipping before build/rollback checks.
- DONE: Build history prompt snippets keep the full prompt in `title` and
  `aria-label` while showing a clipped table value.
- DONE: Build history prompt cells show `No prompt recorded` when the manifest
  prompt is empty, instead of rendering an empty prompt cell.
- DONE: Build history prompt cells use the same fallback value for visible text,
  `title`, and `aria-label` when the manifest prompt is empty.
- DONE: Build history Undo actions declare `aria-controls` for the stable
  history content region, covering both empty-state and populated table views.
- DONE: Build history Undo forms also declare `aria-controls` for the stable
  history content region, matching the Undo submit actions.
- DONE: Build history Undo form/button reference `ol-history-desc` with
  `aria-describedby`, matching the visible rollback warning context.
- DONE: Module skills table source/summary cells use clipped visible text while
  preserving full values in `title`/`aria-label`.
- DONE: Module skills table source/summary cells show `Not recorded` when a
  value is empty, while preserving real full values when present.
- DONE: Module skills empty source/summary cells use the same `Not recorded`
  fallback in visible text, `title`, and `aria-label`.
- DONE: Module skills Refresh actions declare `aria-controls` for the stable
  skills content region, covering both empty-state and populated table views.
- DONE: Module skills Refresh forms also declare `aria-controls` for the stable
  skills content region, matching the Refresh submit actions.
- DONE: Composer advanced `Refresh skills` action declares
  `aria-controls="ol-advanced-status"`, matching the compact status context
  used by the other advanced controls.
- DONE: Plan Preview output renders as stable region `ol-plan-preview`, and
  the Preview action declares/preserves `aria-controls` for both the editable
  JSON and the preview result.
- DONE: Background job placeholder `olivia-generating` is exposed as a labelled
  status region around the existing polite thinking card.
- DONE: Background job placeholder marks itself `aria-busy="true"` while the
  worker is running and clears busy state when the long-running fallback asks
  the user to check the result.
- DONE: Background thinking cards label their live status with stable
  `ol-thinking-main` / `ol-thinking-sub` text in both normal and fallback states.
- DONE: Instant submit thinking overlay also labels its live status with separate
  `ol-thinking-overlay-main` / `ol-thinking-overlay-sub` ids to avoid conflicts
  with server-rendered background job cards.
- DONE: Instant submit overlay marks the Olivia app shell `aria-busy="true"`
  while the submitted action is handing off to the server/worker.
- DONE: Instant submit overlay is guarded as a singleton, preventing duplicate
  overlay ids during rapid repeated submits before the page navigates.
- DONE: Rapid repeated submits are blocked while the instant thinking overlay is
  active, preventing duplicate server requests/jobs before navigation completes.
- DONE: The submitted action gets `aria-disabled="true"` while the instant
  thinking overlay is active, without using real `disabled` so submitter
  `name/value` still reaches the server.
- DONE: Chat rename/delete submits are excluded from instant thinking overlay,
  avoiding stale overlay state when the delete confirmation is cancelled.
- DONE: Submitted actions marked with `data-thinking-submit` get scoped visual
  disabled styling (`cursor: wait`, lower opacity, no pointer events) without
  removing their submitter value from the request.
- DONE: Instant overlay submit handling now determines the Olivia action kind
  before normalizing reference URL or saving the composer draft, so excluded
  quick forms do not mutate composer state.
- DONE: Instant thinking overlay wrapper is exposed as labelled region
  `Olivia submit status` while the inner card remains the polite live status.
- DONE: Long-running background job fallback link references the job status
  region and fallback text with `aria-controls` / `aria-describedby`.
- DONE: Background job status region also references the current thinking
  sub-message via `aria-describedby="ol-thinking-sub"` in normal and fallback
  states.
- DONE: Thinking-card sub-message rotation respects `prefers-reduced-motion:
  reduce`, matching the existing CSS spinner motion preference.
- DONE: Thinking-card rotation reuses one `MediaQueryList` and checks it on
  every tick, so changing reduced-motion while the page is open stops further
  text rotation.
- DONE: Thinking-card delayed text update also checks reduced-motion before
  applying the fade-in/text swap, covering preference changes between tick and
  timeout.
- DONE: Thinking-card rotation clears its interval when the rotated text node is
  no longer in the document, avoiding orphaned timers after overlay cleanup.
- DONE: Thinking-card delayed text swap also exits if the rotated text node was
  removed before the timeout fires.
- DONE: Confirm-backed submit actions (`submit_install_module`, `submit_undo`)
  are excluded from instant thinking overlay, matching chat delete and avoiding
  stale overlay state when the browser confirmation is cancelled.
- DONE: Fast feedback submits (`submit_feedback_up`, `submit_feedback_down`)
  are excluded from instant thinking overlay to avoid unnecessary full-screen
  flashes for telemetry-only actions.
- DONE: Fast local utility submits (`submit_sample`, `submit_share`) are also
  excluded from instant thinking overlay because they synchronously update local
  JSON/blueprint output rather than waiting on a worker/model.
- DONE: Instant overlay button-kind map no longer includes excluded
  confirm-backed actions (`submit_install_module`, `submit_undo`), keeping the
  client-side classification consistent with the skip list.
- DONE: Thinking label payload no longer includes unused labels for excluded
  instant-overlay actions (`sample`, `share`, `feedback`, `undo`); server-side
  `install` remains because background install jobs still use it.
- DONE: Instant overlay JSON payload filters out `install`; PHP still keeps the
  server-side install label for confirmed background install polling.
- DONE: Instant overlay skip logic uses explicit `NO_OVERLAY_BTN` and
  `KIND_BY_BTN` maps instead of a long inline condition, keeping future submit
  classification easier to audit.
- DONE: Instant overlay submit maps now include inline guidance explaining
  overlay-backed, skipped, and future fallback submit actions.
- DONE: Instant thinking overlay wrapper also marks itself `aria-busy="true"`,
  matching the app-shell busy state during server/worker handoff.
- DONE: Background job status region references the visible thinking headline
  with `aria-labelledby="ol-thinking-main"` while keeping the sub-message as
  its description.
- DONE: Background job status region no longer carries a redundant generic
  `aria-label` once the visible thinking headline provides its accessible name.
- DONE: Instant thinking overlay wrapper is named by its visible headline and
  described by its sub-message, matching the background job region pattern.
- DONE: Instant thinking overlay reuses local id constants for its headline and
  sub-message ARIA references, reducing future typo risk.
- DONE: Server-rendered thinking card also reuses local headline/sub-message id
  variables for `aria-labelledby` / `aria-describedby` and matching element ids.
- DONE: Long-running job fallback JS reuses local thinking headline/sub-message
  ids and no longer adds a redundant generic region `aria-label`.
- DONE: Advanced summary no longer mixes `aria-labelledby` with dynamic
  `aria-label`, so the Expand/Collapse label updated by JS is the accessible
  name while the panel remains labelled by `ol-advanced-title`.
- DONE: Composer prompt textarea no longer gets a redundant dynamic
  `aria-label`; its accessible name follows the updated visible prompt title
  through `aria-labelledby="ol-prompt-title"`.
- DONE: Composer mode cards now use a roving tabindex radio pattern: only the
  active mode is in the Tab order, while arrows/click/shortcuts still switch
  Direct, Interview, Change site, and Improve.
- DONE: Composer mode-card keyboard navigation also supports Home/End to jump
  to the first/last mode, and the Shortcuts modal documents those keys.
- DONE: Composer mode radiogroup declares `aria-orientation="horizontal"`,
  matching its left/right arrow behavior and keeping screen-reader context
  explicit.
- DONE: Reference URL and Notes fields no longer duplicate their visible
  labels with `aria-label`; their accessible names now come from the wired
  `<label for=...>` text.
- DONE: Composer mode cards declare `aria-keyshortcuts` for Cmd/Ctrl+1..4, so
  the same shortcuts shown in tooltips/help are exposed to assistive tech.
- DONE: Composer primary actions now expose `aria-keyshortcuts` too: prompt
  clear (Cmd/Ctrl+Backspace/Delete), Reference (Cmd/Ctrl+Shift+R), and
  Generate (Cmd/Ctrl+Enter).
- DONE: Shortcuts openers in the top bar and sidebar expose
  `aria-keyshortcuts="?"`, matching the visible help entry and keyboard
  handler.
- DONE: Sidebar collapse/expand control exposes `aria-keyshortcuts` for
  Cmd/Ctrl+Backslash, and JS preserves it while toggling collapsed state.
- DONE: Sidebar New chat and chat search controls expose `aria-keyshortcuts`
  for Cmd/Ctrl+Shift+N and Cmd/Ctrl+Shift+F, matching their visible tooltips
  and keyboard handlers.
- DONE: Advanced panel and JSON actions expose `aria-keyshortcuts` for
  Cmd/Ctrl+Shift+A/C/P/Z, and JS preserves those attributes while labels and
  disabled states change.
- DONE: Main prompt textarea exposes `aria-keyshortcuts` for Cmd/Ctrl+K,
  matching the global focus-prompt shortcut.
- DONE: Help and Reference close controls expose `aria-keyshortcuts` for Esc;
  Reference close/Done also expose Cmd/Ctrl+Enter because that shortcut closes
  the Reference dialog when it is open.
- DONE: Chat search input includes Escape in `aria-keyshortcuts`, matching its
  focused clear-search behavior.
- DONE: Chat search "Clear search" no-results button also supports Escape,
  returns focus to the search input, and exposes `aria-keyshortcuts="Escape"`.
- DONE: Saved-chat action menu summaries expose `aria-haspopup="true"` while
  JS continues to sync `aria-expanded` and `aria-controls` for the popover.
- DONE: Reference and Shortcuts openers expose `aria-haspopup="dialog"`, and
  JS preserves it while syncing their open/closed ARIA state.
- DONE: Saved-chat action menu buttons now reference the row title/meta with
  `aria-describedby`, so the actions popover trigger keeps its chat context.
- DONE: Saved-chat links reference their visible meta row with
  `aria-describedby`, so message count and updated time are available as link
  description.
- DONE: Saved-chat links now use the visible chat title as their accessible
  name via `aria-labelledby`, with the meta row remaining as description.
- DONE: Saved-chat rename/delete forms, title input, and action buttons now
  reference the row meta text with `aria-describedby`, preserving chat context
  inside the actions popover.
- DONE: Saved-chat DOM ids for title/meta/action popovers now fall back to a
  per-row `chat-N` id when a thread id sanitizes to an empty string, preventing
  empty/duplicate ARIA references.
- DONE: Saved-chat DOM ids now include the row index even when the sanitized
  thread id is present, preventing collisions when unusual ids normalize to the
  same DOM-safe string.
- DONE: Saved-chat action popover containers reference the row meta text with
  `aria-describedby`, so the grouped action region keeps message/time context.
- DONE: Chat search no-results status no longer has a static `aria-label`;
  screen readers can announce the visible dynamic text that includes the active
  query.
- DONE: Empty saved-chat status no longer has a redundant static `aria-label`;
  screen readers can announce the visible `No saved chats yet.` text directly.
- DONE: Saved-chat list navigation is labelled by the visible `Chats` heading
  via `aria-labelledby`, instead of a separate static `aria-label`.
- DONE: Sidebar chat search wrapper exposes `role="search"` with an explicit
  `aria-label="Saved chat search"`, while the nested input keeps its
  action-specific shortcut label.
- DONE: Sidebar landmark keeps its descriptive `aria-label="Olivia chats and
  tools"` and now references the visible `Chats` heading with
  `aria-describedby`.
- DONE: Saved-chat counter now references both the visible `Chats` heading and
  the search live status with `aria-describedby`, and filtering preserves both
  references while updating counts.
- DONE: Chat history's `Continue here` link now uses its visible text as the
  accessible name and references the history heading/range as description, so
  the action keeps context outside the visual layout.
- DONE: Chat history message meta/text rows now get stable ids, and `Use again`
  buttons reference the concrete message they will restore with
  `aria-describedby`.
- DONE: Chat history section now references the visible message-range text with
  `aria-describedby`, so the section label includes how much history is shown.
- DONE: Chat history message articles now use their visible meta row as
  `aria-labelledby` and their visible text as `aria-describedby`, avoiding a
  separate static `aria-label`.
- DONE: Chat history action groups now also reference the owning message
  meta/text with `aria-describedby`, matching the `Use again` button context.
- DONE: Chat history metadata chip lists now also reference the owning message
  meta/text with `aria-describedby`, keeping counts/jobs tied to their source.
- DONE: Chat history empty technical messages now render a neutral display
  fallback like `Plan recorded without message text.` so ARIA descriptions do
  not point at an empty text block; `Use again` still only appears for real
  prompt text.
- DONE: Chat history timestamps keep their compact visible relative time, while
  `title`/`aria-label` now include `Message saved <timestamp>` for standalone
  context.
- DONE: Chat history `Continue here` links now build their `?chat=` href with
  `rawurlencode($chatId)`, keeping unusual future chat ids safe in query
  parameters.
- DONE: Saved-chat sidebar links and active rename form actions now also build
  their `?chat=` hrefs from raw ids with `rawurlencode()`, while hidden form
  values remain HTML-escaped.
- DONE: Main composer form/debug links and async poller chat query prefixes now
  encode raw chat ids before HTML escaping output, avoiding mixed entity +
  URL-encoding order.
- DONE: Async poller script now emits job id, job parameter, and chat query
  prefix via `json_encode(... JSON_HEX_*)`, so JavaScript URLs keep `&`
  correctly instead of receiving HTML entities like `&amp;`.
- DONE: Main composer inline script now emits active chat id and mode metadata
  via shared `json_encode(... JSON_HEX_*)` flags, avoiding HTML-escaped values
  inside JavaScript strings.
- DONE: Composer `THINKING` overlay labels now use the same shared
  `json_encode(... JSON_HEX_*)` flags as other inline-script data.
- DONE: Server-rendered thinking-card `data-subs` JSON now uses explicit
  `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` for HTML
  attribute output.
- DONE: Inline-script and thinking-card JSON output now includes
  `JSON_INVALID_UTF8_SUBSTITUTE`, preventing malformed saved text from breaking
  emitted UI JSON.
- DONE: Copyable share-blueprint and support-debug JSON textareas also include
  `JSON_INVALID_UTF8_SUBSTITUTE`, preserving output when stored text contains
  malformed UTF-8.
- DONE: Plan JSON emitted from finished plan jobs, sample/blueprint loads,
  preview validation, and build chat state also includes
  `JSON_INVALID_UTF8_SUBSTITUTE`.
- DONE: File-backed chat threads, async jobs, and build rollback manifests now
  write JSON with `JSON_INVALID_UTF8_SUBSTITUTE` so malformed text cannot make
  persistence silently fail.
- DONE: Module directory cache, theme state, and telemetry log JSON writes also
  use `JSON_INVALID_UTF8_SUBSTITUTE` for the same malformed-text resilience.
- DONE: Generated template views are now version 20 and encode Organization and
  Breadcrumb JSON-LD with `JSON_UNESCAPED_SLASHES`,
  `JSON_INVALID_UTF8_SUBSTITUTE`, and `JSON_HEX_*` flags.
- DONE: `scripts/smoke.php` now lints generated template PHP from the sample
  build before rollback, catching generated-view nowdoc syntax errors.
- DONE: Job-status JSON responses and Grok image request payload JSON now use
  `JSON_INVALID_UTF8_SUBSTITUTE`, avoiding failed encoding from malformed
  prompt/status text.
- DONE: `scripts/smoke.php` now writes, reloads, and deletes temporary
  chat/job/manifest records containing malformed UTF-8 text, proving JSON
  persistence remains valid.
- DONE: `scripts/smoke.php` also verifies malformed UTF-8 in async job results,
  not just job payloads, because model output is stored in `result`.
- DONE: Job-status JSON endpoint now sends
  `Content-Type: application/json; charset=utf-8`, matching Olivia's UTF-8 JSON
  response handling.
- DONE: Job-status JSON endpoint now sends no-cache headers so polling never
  reuses stale worker status responses.
- DONE: Job-status JSON endpoint now sends `X-Content-Type-Options: nosniff`
  alongside its JSON content type.
- DONE: Async poller `fetch("./job/?id=...")` now uses `cache: "no-store"` in
  addition to the server no-cache headers.
- DONE: Async poller also sends `Accept: application/json` when requesting job
  status, matching the endpoint's JSON-only response.
- DONE: Async poller now checks `Response.ok` before parsing JSON, sending
  non-2xx job-status responses through the existing retry path.
- DONE: Async poller now verifies the parsed job-status payload is an object
  before reading `status`, sending malformed JSON shapes through retry.
- DONE: Async poller now stops immediately on `status: "missing"` and shows a
  clear "background job was not found" state instead of waiting for the
  11-minute backstop.
- DONE: Async poller terminal states now share one `showTerminal()` renderer,
  keeping stalled/missing ARIA markup consistent.
- DONE: Async poller now names its normal and retry delays as
  `POLL_INTERVAL` and `RETRY_INTERVAL`, avoiding duplicated timeout literals.
- DONE: Async poller backstop is now named `MAX_POLL_TIME`, clarifying the
  11-minute client-side timeout.
- DONE: `scripts/smoke.php` success output now names the checked malformed
  UTF-8 persistence targets explicitly: chat/job/manifest.
- DONE: `scripts/smoke.php` now registers cleanup callbacks for temporary
  malformed UTF-8 chat/job/manifest records, so early failures do not leave
  test artifacts behind.
- DONE: `scripts/smoke.php` also registers a rollback cleanup after the sample
  build manifest is created, protecting against fatal errors before explicit
  rollback runs.
- DONE: `scripts/smoke.php` handles CLI `SIGINT`/`SIGTERM` when pcntl is
  available, running the same cleanup stack before exiting interrupted tests.
- DONE: Async job polling wraps status `fetch()` in an `AbortController`
  timeout (10s when available), so a hung status request falls back to the
  retry loop instead of waiting only for the 11-minute page backstop.
- DONE: `OliviaJobs::get()`/`delete()` now require Olivia's generated job-id
  shape (`YYYYMMDD-HHMMSS-xxxxxxxx`) instead of relying only on filename
  sanitization; smoke verifies invalid job ids are rejected.
- DONE: `scripts/smoke.php` success output explicitly reports the invalid
  job-id rejection check, keeping the OK summary aligned with covered cases.
- DONE: `OliviaJobs::write()` now enforces the same generated job-id shape as
  `get()`/`delete()` and throws on invalid ids; smoke verifies invalid writes
  are blocked.
- DONE: `OliviaJobs::write()` writes job JSON through a same-directory temp
  file and atomic `rename()`, so pollers do not read partially written job
  status files.
- DONE: `OliviaJobs::get()` now rejects job JSON whose internal `id` does not
  match the requested/generated job id, and smoke verifies mismatched job files
  are ignored.
- DONE: `OliviaJobs::get()`/`write()` now accept only known job statuses
  (`pending`, `running`, `done`, `error`), and smoke verifies invalid status
  reads/writes are rejected.
- DONE: `OliviaJobs::get()`/`write()` now accept only worker-supported job
  types (`plan`, `questions`, `change`, `audit`, `install`, `build`), and
  smoke verifies invalid type reads/writes are rejected.
- DONE: `OliviaJobs` keeps valid job types/statuses in class constants and
  its job-shape docblock now lists all supported worker job types.
- DONE: `OliviaJobs` validates `id`/`type`/`status` through a scalar-field
  helper before string conversion, preventing PHP warnings from corrupted job
  JSON with array/object fields; smoke covers non-scalar read/write cases.
- DONE: `OliviaJobs::create()` now rejects unsupported job types before
  generating or writing a job id, and smoke verifies invalid public creates
  are blocked.
- DONE: Smoke now verifies `OliviaJobs::create()` persists the expected initial
  job shape: matching embedded id, `pending`, payload, null
  result/pid/start/finish, empty error, zero attempts, and a string `created`
  timestamp.
- DONE: Smoke now verifies the initial `created` timestamp from
  `OliviaJobs::create()` is parseable and recent, keeping watchdog elapsed
  timing grounded.
- DONE: Smoke now verifies `OliviaJobs::create()` accepts every supported job
  type (`plan`, `questions`, `change`, `audit`, `install`, `build`) and
  persists the matching type/payload.
- DONE: Worker and watchdog job deadlines now come from
  `OliviaJobs::deadlineSeconds()`, keeping timeout budgets centralized and
  smoke-covered instead of duplicated in PHP entry points.
- DONE: Smoke now verifies the full `OliviaJobs::deadlineSeconds()` budget map
  for plan/change/questions/audit/install/build plus the unknown-type fallback.
- DONE: `OliviaJobs` now uses named status constants for pending/running/done/error
  assignments and validation, so written statuses and accepted statuses share
  one in-class contract.
- DONE: Worker shutdown and module watchdog now use `OliviaJobs::isTerminal()`
  for done/error detection, with smoke coverage for terminal vs running status.
- DONE: Smoke now verifies `OliviaJobs::isTerminal()` is strict: missing,
  non-scalar, or differently cased statuses are not treated as terminal.
- DONE: `OliviaJobs::create()` now generates the random job-id suffix with
  `random_bytes(4)` plus a legacy fallback, and smoke verifies the generated
  id still matches Olivia's expected shape.
- DONE: `OliviaJobs::create()` now allocates job ids through a short retry loop
  that checks for an existing job file before writing, avoiding accidental
  replacement on an extremely rare timestamp+suffix collision.
- DONE: `OliviaJobs` now removes stale same-directory atomic-write `*.tmp`
  files older than one hour during store initialization, with smoke coverage
  that stale temps are removed while fresh temps are preserved.
- DONE: Job temp cleanup is guarded to run once per PHP process on store
  initialization, with an explicit `cleanupStaleTemps()` method for smoke tests
  and maintenance paths.
- DONE: `OliviaJobs` now routes job-dir setup through `ensureDir()`, used by
  both `wired()` and explicit stale-temp cleanup, so maintenance cleanup calls
  are not sensitive to initialization order.
- DONE: `OliviaJobs` now also calls `ensureDir()` from `newId()`, `get()`,
  `delete()`, and `write()`, so all direct job-store operations share the same
  lifecycle-safe directory setup.
- DONE: `scripts/smoke.php` success output now explicitly reports job
  deadline, terminal-status, and temp-cleanup checks.
- DONE: `OliviaJobs::get()` suppresses transient `file_get_contents()` warnings
  and returns `null` if a job file disappears or becomes unreadable between
  `is_file()` and read, keeping poll responses quiet during races.
- DONE: `OliviaJobs::delete()` now removes matching atomic-write temp files
  for the deleted job id immediately, and smoke verifies delete-time temp
  cleanup separately from stale-temp cleanup.
- DONE: Smoke now verifies `OliviaJobs::delete()` is idempotent for missing
  valid ids and invalid ids and does not remove unrelated job temp files.
- DONE: Smoke now verifies `OliviaJobs::delete()` preserves malformed same-id
  temp files that do not match Olivia's atomic-write temp filename contract.
- DONE: `OliviaJobs` now uses one `isTempName()` helper for stale-temp cleanup
  and delete-time temp cleanup, keeping the atomic-write temp filename contract
  in one place.
- DONE: `scripts/smoke.php` now verifies stale temp cleanup preserves
  similarly named non-Olivia temp files while removing only matching Olivia
  job temp files.
- DONE: Smoke now verifies stale-temp cleanup also preserves stale malformed
  Olivia-like temp filenames that do not match the atomic-write temp contract.
- DONE: `scripts/smoke.php` now verifies `OliviaJobs::get()` ignores malformed
  job JSON files instead of treating corrupted status files as valid jobs.
- DONE: `OliviaJobs::elapsedSeconds()` now reads `started`/`created` through
  the scalar-field helper, avoiding warnings from corrupted job arrays; smoke
  verifies non-scalar timestamps return 0.
- DONE: `OliviaJobs::elapsedSeconds()` suppresses invalid-date parse warnings
  and returns 0 for unparseable timestamp strings; smoke covers that path.
- DONE: Smoke now verifies `OliviaJobs::elapsedSeconds()` returns 0, not a
  negative value, when job timestamps are in the future due to clock skew.
- DONE: Smoke now verifies `OliviaJobs::elapsedSeconds()` falls back to
  `created` when `started` is empty and prefers `started` when both timestamps
  are present, keeping watchdog timing deterministic.
- DONE: `OliviaJobs::stopWorker()` now parses `pid` only as a strict positive
  integer/int-string, so malformed scalar values like `123abc` cannot be cast
  into a process id; smoke verifies non-scalar and malformed pids are rejected.
- DONE: `scripts/smoke.php` success output explicitly includes the job
  strict stop-pid parsing check alongside deadlines, terminal states, and temp
  cleanup.
- DONE: `OliviaJobs::get()`/`write()` reject non-scalar `attempts` fields at
  the job-store boundary; `bumpAttempt()` still normalizes scalar corrupted
  values such as negative or huge counters.
- DONE: `OliviaJobs::bumpAttempt()` now clamps negative attempt counters to
  zero before incrementing, and smoke verifies corrupted negative attempts
  become 1.
- DONE: `OliviaJobs::bumpAttempt()` caps corrupted high attempt counters at
  `MAX_ATTEMPTS` before incrementing, and smoke verifies huge attempts are
  bounded.
- DONE: `OliviaJobs::get()`/`write()` now require `payload` to be an array,
  preventing corrupted job files with scalar payloads from reaching worker
  code; smoke verifies invalid payload reads/writes are rejected.
- DONE: `OliviaJobs::write()` now also rejects non-JSON-safe values nested in
  `payload` (objects/resources), so corrupted job inputs fail before atomic
  file writes; smoke verifies non-JSON payload content is rejected.
- DONE: `OliviaJobs::get()`/`write()` now require `result` to be `null` or a
  JSON-safe array, so corrupted worker results cannot reach admin render paths;
  smoke verifies invalid result reads/writes are rejected.
- DONE: Job JSON-shape validation now rejects non-finite floats (`INF`/`NaN`)
  inside `payload` or `result` before `json_encode()` can fail; smoke covers
  both cases.
- DONE: `OliviaJobs::get()`/`write()` now require `error` fields to be strings
  or `null` when present, preventing corrupted job error payloads from reaching
  admin notices/poller responses; smoke covers numeric error rejection on
  read/write.
- DONE: `OliviaJobs::get()`/`write()` now require `created`, `started`, and
  `finished` timestamp fields to be strings or `null` when present, preventing
  corrupted job clocks from being silently treated as valid; smoke covers
  numeric timestamp rejection on read/write.
- DONE: `OliviaJobs::get()`/`write()` now require `pid` fields to be positive
  integer-like or `null` when present, keeping worker stop/watchdog paths from
  seeing corrupted/non-positive pid shapes; smoke covers read/write rejection.
- DONE: Smoke now verifies non-integer `attempts` reads/writes are rejected,
  while integer-like negative/huge attempt counters remain clamped by
  `bumpAttempt()`.
- DONE: `OliviaJobs::get()`/`write()` keep `attempts` as signed integer-like
  or `null`, so negative string counters can still be normalized by
  `bumpAttempt()` while boolean attempts are rejected.
- DONE: Removed the unused broad `hasScalarOrNullFields()` helper after job
  shape validation moved to explicit string/null, positive-integer/null, and
  signed-integer/null helpers.
- DONE: `OliviaJobs` now centralizes validated job string fields and
  integer-like field groups in constants, keeping `get()`/`write()` shape
  validation synchronized.
- DONE: `OliviaJobs` now splits positive integer-like `pid` validation from
  signed integer-like `attempts` validation; smoke verifies pid `0` is rejected.
- DONE: Smoke now also verifies a positive integer-like persisted `pid` is
  accepted by `write()` and survives `get()`, so pid validation is not
  over-restrictive.
- DONE: `OliviaJobs::start()` now ignores non-positive pid values instead of
  writing invalid pid data; smoke verifies null, zero, and negative pid starts
  still mark the job running and leave `pid` null.
- DONE: Smoke now also verifies `OliviaJobs::start($id, 123)` persists a
  positive pid plus parseable recent `started` timestamp while marking the job
  running, and a repeated start on the same running job can update that pid
  while a repeated non-positive pid start preserves the existing valid pid.
- DONE: `OliviaJobs::start()` and watchdog `bumpAttempt()` now canonicalize
  running jobs by clearing stale `result`, `error`, and `finished` fields while
  preserving a valid running `pid`; smoke covers both transitions.
- DONE: `OliviaJobs::start()` now leaves terminal jobs unchanged, so a late or
  duplicate worker start cannot move `done`/`error` jobs back to `running`;
  smoke verifies finished and failed jobs keep their terminal status and `pid`
  null.
- DONE: Smoke now verifies public `OliviaJobs::start()`/`finish()`/`fail()`
  quietly ignore missing valid ids and invalid ids, keeping stale worker/poller
  paths non-throwing.
- DONE: Smoke now verifies public `OliviaJobs::finish()` rejects non-JSON-safe
  result content and leaves the original pending job readable.
- DONE: Smoke now verifies successful public `OliviaJobs::finish()` stores
  `status=done`, an empty `error`, a parseable recent `finished` timestamp,
  and malformed UTF-8 result text safely.
- DONE: Smoke now verifies successful public `OliviaJobs::fail()` stores
  `status=error`, a null `result`, a parseable recent `finished` timestamp,
  and the error message safely.
- DONE: `OliviaJobs::finish()`/`fail()` now leave terminal jobs unchanged, so a
  late worker result cannot overwrite a watchdog error and a late failure cannot
  overwrite a completed job; smoke covers both overwrite directions and keeps a
  failed job's `result` null plus `finished` timestamp after a late `finish()`,
  while preserving a completed job's `result` and `finished` timestamp after a
  late `fail()`.
- DONE: `OliviaJobs::finish()`/`fail()` now canonicalize terminal fields:
  successful `finish()` clears stale `error`, successful `fail()` clears stale
  `result`, and smoke covers both transitions.
- DONE: `OliviaJobs::finish()`/`fail()` now clear stale worker `pid` values
  when a running job reaches `done` or `error`, so terminal jobs no longer look
  attached to an active worker in debug/poller output; smoke covers both paths.
- DONE: `OliviaJobs::fail()` now byte-limits persisted error messages to 4000
  bytes with an ASCII ellipsis marker, preventing huge or multibyte provider
  exceptions from bloating job JSON or poller/debug output; truncation keeps a
  valid UTF-8 prefix when possible, and smoke covers this.
- DONE: `OliviaJobs::fail()` now trims failure messages and stores
  `Unknown job error` when a worker/provider reports an empty or whitespace-only
  error, so poller/debug output always has actionable text; smoke covers this.
- DONE: `OliviaJobs::get()`/`write()` also normalize string `error` fields at
  the job-store boundary, so old/manual job JSON and private writes cannot
  return or persist huge error strings; `get()` lazily repairs normalized
  valid job files on disk through a best-effort repair helper, logs repair
  write failures for valid job ids to the local `olivia` log with compact
  details, and smoke covers read/write/repair plus repair-write failure paths.
- DONE: Smoke now directly covers `OliviaJobs::limitString()` helper behavior
  for unchanged short strings, tiny-limit passthrough, and exact capped output.
- DONE: `OliviaChats::load()`/`all()` and `OliviaStore::load()`/`all()` now
  suppress transient read warnings and ignore malformed JSON files; smoke
  covers malformed chat/build JSON load and list paths.
- DONE: `OliviaChats::save()` and `OliviaStore::save()` now write JSON through
  same-directory temp files and atomic `rename()`, matching job-store safety;
  smoke verifies normal chat/manifest saves do not leave temp files behind.
- DONE: `OliviaChats` and `OliviaStore` now clean stale atomic-write temp files
  older than one hour on initialization, while preserving fresh/malformed temp
  names; smoke covers explicit cleanup for chat and manifest stores.
- DONE: `OliviaChats::delete()` and `OliviaStore::delete()` now also remove
  matching atomic-write temp files for that id while preserving malformed temp
  names; smoke covers both delete paths.
- DONE: Chat/build manifest delete-time temp cleanup now runs only for valid
  Olivia ids, so invalid delete requests cannot remove unrelated temp files;
  smoke covers both stores.
- DONE: `OliviaChats::load()` and `OliviaStore::load()` now reject non-Olivia
  id shapes before reading JSON files, so arbitrary sanitized filenames cannot
  be loaded; smoke covers both stores.
- DONE: `OliviaChats::all()` and `OliviaStore::all()` now also skip JSON files
  whose filename is not a valid Olivia id, so sidebar/history lists cannot
  surface arbitrary local JSON files; smoke covers both stores.
- DONE: `OliviaChats::delete()` and `OliviaStore::delete()` now reject invalid
  id shapes before unlinking files, so arbitrary local JSON files cannot be
  deleted through sanitized ids; smoke covers both stores.
- DONE: `OliviaChats::load()`/`all()` and `OliviaStore::load()`/`all()` now
  canonicalize the returned `id` from the JSON filename, not the JSON body, so
  manually edited/mismatched files cannot surface a forged id; smoke covers
  both stores.
- DONE: `OliviaChats::save()` now rejects explicit invalid id shapes before
  writing JSON files, and empty-id normalization now uses the same valid id
  format as `create()`; smoke covers the invalid-save path.
- DONE: `OliviaStore::save()` now uses a bounded millisecond id generator
  (`000..999`) instead of `round()`, so manifest ids can never become invalid
  `-1000` edge ids at the end of a second; smoke covers the edge case.
- DONE: Smoke now also covers the same bounded millisecond edge for
  `OliviaChats::newId()`, guarding chat ids against future `round()` regressions.
- DONE: Reference screenshot uploads now allocate a non-overwriting target
  filename (`-2`, `-3`, ...) when the same screenshot hash lands in the same
  second; smoke covers the collision helper without needing a real upload.
- DONE: `OliviaModules::index()` now suppresses transient cache read warnings
  and returns an empty index for malformed module-cache JSON; smoke covers the
  malformed cache path while restoring any existing cache file.
- DONE: `OliviaModules::refreshIndex()` now writes `modules-index.json`
  atomically via a same-directory temp file and `rename()`, so admin views never
  read partial module index JSON; smoke covers the write helper and no temp leak.
- DONE: `OliviaModules` and `OliviaTheme` now clean stale atomic-write temp
  files older than one hour while preserving fresh/malformed temp names; smoke
  covers both cleanup contracts.
- DONE: `OliviaTheme::current()` now suppresses transient cache read warnings
  and ignores malformed theme-cache JSON; `OliviaComponents` catalog/reference
  reads are warning-safe. Smoke covers the malformed theme-cache path.
- DONE: `OliviaTheme::save()` now writes `theme.json` atomically via a
  same-directory temp file and `rename()`, so reloads never see partial theme
  JSON; smoke verifies valid save/read and no leftover temp files.
- DONE: `OliviaSkills` local markdown reads are now warning-safe, richer-doc
  enrichment tolerates transient read failures, and `read()` rejects empty or
  invalid class names before resolving `.md`; smoke covers read/list behavior.
- DONE: `OliviaSkills::record()` and `recordRemote()` now write skill markdown
  atomically via same-directory temp files and `rename()`, so the skills
  library cannot expose partial `.md` files; smoke covers remote skill writes.
- DONE: `OliviaSkills::record()` / `read()` / `recordRemote()` now accept only
  PHP class-name shaped module ids (`[A-Za-z_][A-Za-z0-9_]*`), so remote skills
  cannot create markdown files with looser sanitized names; smoke covers this.
- DONE: `OliviaSkills` now cleans stale atomic-write `.md.*.tmp` files older
  than one hour while preserving fresh/malformed temp names; smoke covers the
  cleanup contract.
- DONE: Support/debug helpers now read `Squad/models.json` and recent job JSON
  warning-safely; malformed job JSON is skipped in the debug bundle and covered
  by smoke.
- DONE: `OliviaSeo` now reads existing `robots.txt` and rollback SEO files
  warning-safely, so disappearing/unreadable text files do not emit PHP
  warnings during build or rollback.
- DONE: Smoke now also verifies ordinary integer-like persisted `attempts`
  (`"0"`) are accepted by `write()` and survive `get()`.
- DONE: Smoke now verifies normal `OliviaJobs::bumpAttempt()` increments a
  fresh job to 1 and persists `status=running`, integer `attempts`, and a
  parseable recent `started` timestamp.
- DONE: Smoke now verifies `OliviaJobs::bumpAttempt()` preserves an existing
  running job `pid` while incrementing attempts.
- DONE: Smoke now verifies `OliviaJobs::bumpAttempt()` returns 0 for missing
  valid ids and invalid ids, keeping watchdog retry paths quiet on stale jobs.
- DONE: `OliviaJobs::bumpAttempt()` now refuses to restart terminal `done`/`error`
  jobs; smoke verifies a finished job stays `done` and returns 0 on bump.
- DONE: `OliviaJobs::get()` and `write()` now share `jobBodyShapeError()` for
  type/status/payload/result/string-field/integer-field validation, avoiding
  duplicate read/write shape checks.
- DONE: `OliviaJobs::get()`/`write()` now share `jobId()` for extracting and
  normalizing persisted job ids before filename/id validation or atomic writes.
- DONE: Smoke now verifies a valid persisted job id survives private `write()`
  and public `get()`, covering the positive path for centralized `jobId()`.
- DONE: Smoke now covers `OliviaJobs::get()` rejecting job files whose embedded
  persisted `id` is non-scalar, alongside mismatched filename/id rejection.
- DONE: Smoke now covers public `OliviaJobs::create()` rejecting non-JSON-safe
  payload content, not only private `write()` payload rejection.
- DONE: `OliviaJobs` inline job-shape docs now describe the actual persisted
  type contract for payload/result/error/timestamps/pid/attempts.
- DONE: `scripts/smoke.php` success output now names the stricter job shape
  categories (`non-string fields`, `non-integer fields`, `non-scalar ids`)
  instead of the older broad `non-scalar fields` wording.
- DONE: `OliviaViewGenerator` now writes generated template PHP and
  `olivia_home.php` atomically via same-directory temp files and `rename()`,
  preserving rollback manifest semantics; smoke verifies sample builds leave
  no new view temp files.
- DONE: `OliviaViewGenerator` now cleans stale atomic-write `*.php.*.tmp`
  files in `/site/templates` older than one hour while preserving fresh or
  malformed temp names; smoke covers the cleanup contract.
- DONE: `OliviaSeo` now writes root `sitemap.xml` and Olivia-owned `robots.txt`
  atomically, cleans stale `sitemap.xml.*.tmp` / `robots.txt.*.tmp` files older
  than one hour, and smoke verifies both cleanup and no temp leaks during sample
  build.
- DONE: `OliviaBuilder::rollback()` now restores `updated_files` atomically via
  same-directory temp files and `rename()`, so rolling back an upgraded
  Olivia-owned view cannot leave partial PHP; smoke verifies restored content
  and no rollback temp leak.
- DONE: `OliviaBuilder::rollback()` now restores `updated_files` only when the
  real parent directory is inside `/site/templates/`, so sibling paths with the
  same string prefix cannot be written during rollback; smoke covers this.
- DONE: `OliviaBuilder::rollback()` also applies the same real-directory guard
  before deleting generated `files`, so rollback cannot delete sibling paths
  that merely share the templates path prefix; smoke covers delete + restore.
- DONE: `OliviaImageGenerator` now removes generated cache image temp files in
  a `finally` block after attach attempts, validates base64 image data before
  writing, and cleans stale `cache/Olivia/img_*.jpg` temp files older than one
  hour while preserving fresh or malformed names; smoke covers the cleanup
  contract without paid image/API calls.
- DONE: Reference screenshot filename allocation exhaustion now returns a normal
  upload error instead of bubbling an exception through the request; smoke covers
  collision suffixes and the exhausted 100-suffix failure path.
- DONE: `OliviaImageGenerator::MAX_IMAGES` is back in sync with the visible
  UI/config contract at 10 paid image calls per build; smoke asserts the cap so
  it cannot drift silently.
- DONE: Olivia admin preview/config copy now reads paid-call caps from
  `OliviaImageGenerator::MAX_IMAGES` and `OliviaContentFiller::MAX_FILLS`
  instead of hardcoding duplicate numbers; smoke asserts both cap constants.
- DONE: Support/debug bundle now includes current build limits
  (`max_images_per_build`, `gallery_images_per_field`,
  `max_content_fills_per_build`) plus reference intake caps
  (`reference_max_pages`, `reference_link_cap`, `reference_url_char_cap`,
  `reference_text_char_cap`, `reference_notes_char_cap`, `reference_message_char_cap`,
  `reference_screenshot_byte_cap`) from the runtime constants, and smoke
  verifies the copied debug JSON exposes them.
- DONE: Reference HTML link discovery is capped at
  `OliviaReferenceAnalyzer::MAX_LINKS`, so large nav/link-heavy pages cannot
  create an oversized internal crawl queue; smoke covers the parser cap.
- DONE: Reference link normalization now treats protocol-relative URLs as
  absolute external URLs and drops fragment-only links, so external CDN/nav
  links do not become fake same-host crawl targets; smoke covers this.
- DONE: Reference absolute URL normalization now collapses `.` / `..` path
  segments and strips fragments while preserving query strings, reducing
  duplicate crawl targets from relative links; smoke covers this.
- DONE: Reference input URL normalization uses the same canonicalization as
  discovered links, so bare domains with path dot-segments/fragments become a
  stable fetch target before the crawler starts; smoke covers this.
- DONE: Reference URL canonicalization strips default `:80`/`:443` ports while
  preserving non-default ports, so equivalent fetch targets dedupe cleanly
  without breaking custom-port dev/reference sites; smoke covers this.
- DONE: Reference crawler now keeps discovered links only within the same
  origin (scheme + host + effective port), so same-host cross-port/scheme links
  do not leak into the crawl queue; smoke covers this.
- DONE: Reference relative/root links preserve the base URL's non-default port
  before same-origin filtering, so custom-port reference sites crawl their own
  internal paths instead of silently dropping them; smoke covers this.
- DONE: Reference parser honors a same-origin `<base href>` for discovered
  links and ignores external base URLs, so crawler follows real document link
  bases without escaping the reference site; smoke covers this.
- DONE: Reference URL resolution rejects non-http schemes (`data:`, `ftp:`,
  `sms:`, etc.) instead of treating them as same-origin relative paths; smoke
  covers this.
- DONE: Reference crawler skips common non-page asset links (`svg`, `css`, `js`,
  media, favicons, etc.) in addition to images/PDF/zip, so crawl slots stay
  focused on HTML-like pages; smoke covers this.
- DONE: Reference crawl URLs drop common tracking query parameters (`utm_*`,
  `fbclid`, `gclid`, etc.) while preserving useful query params, so marketing
  duplicates do not waste crawl slots; smoke covers this.
- DONE: Reference parser falls back to Open Graph/Twitter title and description
  meta tags when plain `<title>` / `description` are missing, improving briefs
  from modern JS/marketing sites; smoke covers this.
- DONE: Reference HTML parsing now hints UTF-8 to DOMDocument before extraction,
  preserving non-ASCII title/meta/body text in briefs; smoke covers this with
  Cyrillic text.
- DONE: Reference parser extracts page language from `<html lang>` or
  `og:locale` and includes it in the fetched brief, helping planner preserve
  locale/tone; smoke covers parser + brief output.
- DONE: Reference navigation extraction uses `aria-label` / `title` fallback
  for icon-only header/nav links, so briefs keep menu semantics even when link
  text is visually hidden; smoke covers this.
- DONE: Reference image cue extraction uses `alt`, then `aria-label`, then
  `title`, so hero/product/card image semantics survive when alt text is empty;
  smoke covers this.
- DONE: Reference parser extracts non-value form cues from labels,
  placeholders, aria labels, select labels, and buttons, then includes them in
  the brief so planner can infer required forms; smoke covers parser + brief.
- DONE: Reference form cues deliberately ignore input `value` attributes so
  prefilled/hidden user or token values do not enter prompts; smoke covers this
  privacy guard.
- DONE: Reference form cues skip hidden inputs entirely, including hidden field
  names, so CSRF/tracking/service fields do not enter prompts; smoke covers
  this privacy guard.
- DONE: Reference form cues also skip password/file/reset/submit/image/button
  input types, reducing private/login noise and technical controls in prompts;
  smoke covers this guard.
- DONE: Reference fetch records cURL's effective URL after redirects and parses
  relative links against that final URL while preserving its trailing slash, so
  redirected reference pages enqueue the correct internal paths; smoke covers
  this with a fake fetcher.
- DONE: Reference fetch rejects unsupported final/effective URLs after redirects
  instead of falling back silently when cURL reports a non-http target; smoke
  covers the URL guard helper.
- DONE: Reference URL normalization rejects `user:pass@host` credentials for
  both user-entered URLs and final/effective redirect URLs; smoke covers both
  paths so Olivia never silently strips hidden URL auth context.
- DONE: Reference URL normalization rejects obvious local/private literal hosts
  (`localhost`, loopback, RFC1918/link-local/reserved IPv4, and private/reserved
  IPv6 literals) to reduce SSRF risk from reference-site intake; smoke covers
  representative hosts.
- DONE: Reference URL normalization also rejects single-label non-IP hostnames
  like `intranet` or `printer`; reference examples must be public-looking
  domains/IPs, and smoke covers normalization plus user-facing reason.
- DONE: Reference host validation rejects DNS hostnames longer than 253 chars
  and labels longer than 63 chars before DNS lookup; smoke covers both limits
  plus the user-facing rejection reason.
- DONE: Reference host validation rejects invalid DNS label syntax (underscores
  and leading/trailing hyphens) before DNS lookup; smoke covers representative
  invalid hostnames.
- DONE: Reference host validation rejects obfuscated IPv4-like hostnames made
  only from digits/dots (e.g. octal/short/integer forms) before DNS/cURL, so
  they cannot bypass private-IP checks through resolver-specific parsing.
- DONE: Reference URL normalization preserves bracketed IPv6 authorities for
  allowed public IPv6 literals and still collapses default ports, so canonical
  reference URLs stay syntactically valid.
- DONE: Reference absolute-url smoke coverage includes relative links resolved
  from public IPv6 literal base URLs, guarding bracketed-authority handling in
  crawler link discovery.
- DONE: Reference fetch performs a best-effort DNS preflight before each request
  and blocks hostnames that resolve to private/reserved IPs before cURL is called;
  smoke covers blocked private DNS and allowed public DNS via override helpers.
- DONE: Reference fetch disables cURL auto-follow redirects and follows redirects
  manually so every hop goes through Olivia's URL normalization and DNS preflight;
  smoke covers safe redirects and private-DNS redirect blocking before fetch.
- DONE: Manual reference redirects track seen URLs and return `redirect_loop`
  immediately on loops instead of waiting for the max-redirect cap; smoke covers
  a two-hop loop.
- DONE: Reference URL normalization rejects explicit nonstandard ports and only
  allows default HTTP/HTTPS ports (80/443, collapsed in canonical URLs); smoke
  covers user-entered and final/effective redirect URLs.
- DONE: Reference fetch reports precise rejection reasons/messages for blocked
  user-entered reference URLs (`url_credentials`, `private_host`,
  `nonstandard_port`, etc.) instead of flattening every pre-fetch rejection to
  `invalid_url`; smoke covers the user-facing reasons.
- DONE: `normalizeAbsoluteUrl()` enforces `MAX_URL_CHARS` on every resolved
  canonical URL, so redirects/base href/relative links/final effective URLs
  cannot bypass the initial input URL cap; smoke covers an over-limit resolved URL.
- DONE: Reference fetch also restricts cURL allowed protocols and redirect
  protocols to HTTP/HTTPS with modern string cURL options (bitmask fallback);
  smoke covers the protocol option helper.
- DONE: Reference fetch now caps HTML while streaming cURL chunks instead of
  waiting until the full response is downloaded; smoke covers the chunk
  collector at the byte boundary.
- DONE: Reference fetchSite uses the same final/effective URL guard helper when
  choosing parse bases, including empty effective-URL fallback to the request
  URL; smoke covers this with a fake fetcher.
- DONE: Reference URLs are capped server-side at
  `OliviaReferenceAnalyzer::MAX_URL_CHARS`; over-limit URLs are rejected by
  normalization before fetch, and smoke covers both input capping and direct
  normalization rejection.
- DONE: Reference notes are capped server-side at
  `OliviaReferenceAnalyzer::MAX_NOTES_CHARS` before prompt augmentation, so a
  pasted reference essay cannot unexpectedly bloat the planner request; smoke
  covers the cap.
- DONE: Reference warning messages are capped at
  `OliviaReferenceAnalyzer::MAX_MESSAGE_CHARS`, so long cURL/provider details
  cannot bloat the UI warning or prompt context; smoke covers the cap.
- DONE: Generated view/home versions now live as
  `OliviaViewGenerator::VIEW_VERSION` / `HOME_VERSION`; upgrade checks,
  generated source markers, and support/debug JSON all read those constants.
  Smoke verifies the debug bundle and generated files expose the current markers.
- DONE: Support/debug job timeout values now read from
  `OliviaJobs::deadlineMap()` instead of duplicating the worker deadline table;
  smoke verifies both `deadlineSeconds()` and the debug bundle timeout map.
- DONE: Planner/interviewer model-call budgets now live as
  `OliviaPlanner::MAX_TOKENS` / `TIMEOUT` and
  `OliviaInterviewer::MAX_TOKENS` / `TIMEOUT`; support/debug JSON exposes those
  budgets and smoke verifies them.
- DONE: Image/art-director/reference-fetch timeout budgets now live as
  `OliviaImageGenerator::*` and `OliviaReferenceAnalyzer::*` constants;
  support/debug JSON exposes them and smoke verifies the copied diagnostics.
- DONE: The web POST soft timeout now lives as `ProcessOlivia::WEB_POST_SOFT_LIMIT`;
  runtime `set_time_limit()`, support/debug JSON, and smoke all read the same value.
- DONE: Support/debug JSON now includes `support.schema_version` from
  `ProcessOlivia::SUPPORT_DEBUG_SCHEMA_VERSION`; smoke verifies the copied diagnostics
  identify their schema contract.
- DONE: Support/debug schema version is now `38`, reflecting scalar-only debug
  string normalization and stricter secret/token-budget masking semantics.
- DONE: Support/debug schema version is now `39`, reflecting expanded token-budget
  allowlist keys such as `max_completion_tokens` and `max_prompt_tokens`.
- DONE: Support/debug schema version is now `56`; scalar debug strings are capped
  at 1000 characters with `...`, so huge provider/job/build strings cannot bloat
  the copyable support JSON. Smoke covers the scalar cap.
- DONE: Support/debug recent job entries now include `elapsed_seconds`,
  `deadline_seconds`, and `terminal`, using `OliviaJobs` timing/status helpers;
  smoke verifies those watchdog diagnostics.
- DONE: Support/debug recent jobs now load through `OliviaJobs::get()` instead of
  parsing raw job JSON, so malformed, mismatched, or invalid-shape job files are
  ignored consistently with worker/watchdog validation; smoke covers this.
- DONE: Support/debug state now includes `plan_json_valid`,
  `plan_json_error`, `plan_schema_ok`, `plan_schema_errors`, `plan_counts`, and
  `plan_top_level_keys`, so copied diagnostics show whether the current editable
  plan is present, parseable, why parsing failed, minimally shaped like an
  Olivia plan, which required schema keys are missing/wrong-typed, and roughly
  how large it is; smoke covers valid/invalid/wrong-shape JSON states.
- DONE: Plan diagnostics are centralized in `ProcessOlivia::planDebugState()` and
  `debugBundle()` maps that helper into support/debug state; smoke covers the
  helper directly plus the copied debug JSON.
- DONE: The support/debug `plan_top_level_keys` cap now lives as
  `ProcessOlivia::PLAN_DEBUG_TOP_LEVEL_KEY_LIMIT`; smoke verifies the helper respects it.
- DONE: `ProcessOlivia::planDebugState()` now reports `JSON root must be an object plan.`
  for valid JSON scalars or root arrays like `123`/`null`/`[]`, instead of the
  misleading parser message `No error`; smoke covers scalar and array JSON roots.
- DONE: Support/debug state now includes `chat_found`, distinguishing an empty or
  stale `chat_id` from a loaded saved chat; smoke covers empty/missing chat states.
- DONE: Support/debug state now includes `build_count`, matching
  `OliviaStore::all()`, so copied diagnostics show whether build history exists;
  smoke verifies the count.
- DONE: Support/debug state now includes `latest_build_counts` for the newest
  manifest (`fields`, `templates`, `pages`, `files`, `template_fields`,
  `images`, `updated_files`), derived from `OliviaStore::all()`; smoke verifies
  the summary.
- DONE: Support/debug state now includes `latest_build_has_errors`, derived from
  the newest manifest error count; smoke verifies the boolean error state.
- DONE: Latest-build support/debug summary is centralized in
  `ProcessOlivia::latestBuildDebugState()` and `debugBundle()` maps that helper into
  state; smoke covers empty/synthetic manifests plus the copied debug JSON.
- DONE: Latest-build support/debug summary now includes
  `latest_build_reused_counts` (`fields`, `templates`, `pages`), so idempotent
  rebuilds/change runs show what was reused; smoke verifies synthetic and store
  summaries.
- DONE: `ProcessOlivia::latestBuildDebugState()` counts `updated_files` from either the
  full manifest (`updated_files`) or lightweight history (`updated_files_count`),
  so it works with both `OliviaStore::load()` and `all()` shapes; smoke covers both.
- DONE: Latest-build support/debug counts clamp negative `images` and
  `updated_files_count` values from corrupted manifests to zero; smoke covers this.
- DONE: Latest-build support/debug state now includes `latest_build_ts` and
  `latest_build_created_at`, derived from the manifest timestamp; smoke verifies
  helper and copied debug JSON timestamp fields.
- DONE: Latest-build support/debug clamps negative manifest `ts` values to `0.0`
  with an empty `created_at`; smoke covers corrupted timestamp input.
- DONE: Latest-build support/debug state now includes
  `latest_build_age_seconds`, a non-negative integer age derived from manifest
  `ts`; smoke verifies helper and copied debug JSON age fields.
- DONE: Latest-build support/debug state now includes `latest_build_present`,
  avoiding inference from an empty id; smoke verifies helper and copied debug JSON
  presence fields.
- DONE: Latest-build support/debug rejects non-scalar manifest `id` values as an
  empty string instead of emitting PHP array-to-string warnings; smoke covers this.
- DONE: Latest-build support/debug state now includes
  `latest_build_errors_shape_valid`, so corrupted manifests with non-array
  `errors` are visible instead of being silently summarized as zero errors;
  smoke covers helper and copied debug JSON shape flags.
- DONE: Support/debug state now includes compact `recent_job_types`, derived from
  the already-loaded `recent_jobs` list without reading job files twice; smoke
  verifies it matches the recent job entries.
- DONE: Support/debug state now includes compact `recent_job_statuses`, derived
  from the same `recent_jobs` list, so running/error/done states are visible at a
  glance; smoke verifies it matches recent job entries.
- DONE: Compact recent-job support/debug summaries are centralized in
  `ProcessOlivia::recentJobSummary()`; `debugBundle()` maps `types`/`statuses` from
  that helper, and smoke verifies the helper plus copied debug JSON fields.
- DONE: `ProcessOlivia::recentJobSummary()` ignores malformed non-array job entries
  before compacting support/debug counts, types, statuses, and health; smoke covers this.
- DONE: Compact recent-job entries reject non-scalar `id`/`type` values as empty
  strings to avoid array-to-string warnings in copied support/debug JSON; smoke covers this.
- DONE: Recent-job support/debug string fields (`status`, `type`, `error`, compact
  ids/types) are normalized through a scalar-only helper; smoke covers malformed values.
- DONE: `ProcessOlivia::recentJobsDebug()` also uses the scalar-only helper for copied job
  strings (`id`, `type`, `status`, timestamps, `error`); smoke covers the helper contract.
- DONE: Support/debug module metadata and Squad routing strings reuse the same
  scalar-only helper; missing module metadata is covered by smoke.
- DONE: Support/debug secret masking is smoke-covered: API keys/tokens/passwords
  are masked, empty secrets stay empty, token budget fields (`maxTokens`,
  `max_tokens`, `max_output_tokens`, `max_completion_tokens`,
  `max_prompt_tokens`, `token_limit`, kebab/camel variants) are preserved,
  similar secret token fields remain masked, long strings are clipped.
- DONE: Support/debug token-budget masking allowlist lives in
  `ProcessOlivia::DEBUG_TOKEN_BUDGET_KEYS`, so future budget-key additions stay centralized.
- DONE: Support/debug state now includes `recent_job_status_counts`, a compact
  count of recent worker states (`running`, `done`, `error`) for faster diagnosis
  of stalled or failing jobs; smoke verifies helper and copied debug JSON counts.
- DONE: Support/debug state now includes
  `recent_job_running_max_elapsed_seconds`, the longest elapsed age among recent
  running worker jobs, making hung model/build calls obvious in copied diagnostics.
- DONE: Support/debug state now includes `recent_job_over_deadline_count`, a
  count of non-terminal recent jobs whose elapsed age already exceeds their
  worker deadline; smoke verifies helper and copied debug JSON fields.
- DONE: Support/debug state now includes `recent_jobs_over_deadline`, compact
  id/type/status/elapsed/deadline entries for the non-terminal recent jobs that
  exceeded their worker deadline, so support can identify the stuck job quickly.
- DONE: Support/debug state now includes `recent_job_active_count`, the count of
  recent non-terminal jobs, so support can distinguish active queue/running work
  from completed job history without scanning `recent_jobs`.
- DONE: Support/debug state now includes `recent_jobs_active`, compact
  id/type/status/elapsed/deadline entries for recent non-terminal jobs, so
  support can identify currently active workers without scanning full job data.
- DONE: Compact recent-job support/debug entries are centralized in
  `ProcessOlivia::recentJobCompactEntry()` and reused by both active and over-deadline
  job lists; smoke verifies the helper's field contract.
- DONE: Support/debug state now includes `recent_job_active_type_counts`, a
  compact map of active non-terminal job types to counts, so support can see
  whether active work is planning, build, questions, audit, etc.
- DONE: Support/debug state now includes
  `recent_job_over_deadline_type_counts`, a compact map of over-deadline
  non-terminal job types to counts, so stuck work is attributable by worker type.
- DONE: Support/debug state now includes `recent_job_max_overrun_seconds`, the
  largest elapsed-minus-deadline delta among recent non-terminal jobs that
  exceeded their worker deadline.
- DONE: Compact recent-job support/debug entries now include `overrun_seconds`
  alongside id/type/status/elapsed/deadline, so support does not have to
  recompute deadline deltas from copied diagnostics.
- DONE: Compact recent-job support/debug entries now include `over_deadline`,
  matching raw `recent_jobs` so UI/support can filter stuck workers directly.
- DONE: Support/debug state now includes
  `recent_job_active_max_elapsed_seconds`, the max elapsed age among recent
  non-terminal jobs, covering both pending and running worker states.
- DONE: Support/debug state now includes `recent_job_pending_count`, a direct
  count of recent pending jobs before worker start, in addition to
  `recent_job_status_counts`.
- DONE: Support/debug state now includes `recent_job_pending_type_counts`, a
  compact map of pending job types to counts, so queued work is attributable
  before worker start.
- DONE: Support/debug state now includes `recent_jobs_pending`, compact
  id/type/status/elapsed/deadline/overrun entries for recent pending jobs.
- DONE: Support/debug state now includes `recent_job_running_count`, a direct
  count of recent running jobs, in addition to `recent_job_status_counts`.
- DONE: Support/debug state now includes `recent_jobs_running`, compact
  id/type/status/elapsed/deadline/overrun entries for recent running jobs.
- DONE: Support/debug state now includes `recent_job_running_type_counts`, a
  compact map of running job types to counts, so active model/build calls are
  attributable by worker type.
- DONE: Support/debug state now includes `recent_job_terminal_count`, a direct
  count of recent terminal jobs, making active-vs-finished job totals explicit.
- DONE: Support/debug state now includes `recent_job_terminal_type_counts`, a
  compact map of terminal job types to counts, so finished/error job history is
  attributable by worker type.
- DONE: Support/debug state now includes `recent_jobs_terminal`, compact
  id/type/status/elapsed/deadline/overrun entries for recent terminal jobs.
- DONE: Support/debug state now includes `recent_job_error_count`, a direct
  count of recent jobs with status `error`, separate from terminal done/error
  totals.
- DONE: Support/debug state now includes `recent_job_error_type_counts`, a
  compact map of error job types to counts, so recent failures are attributable
  by worker type.
- DONE: Support/debug state now includes `recent_job_done_count`,
  `recent_job_done_type_counts`, and `recent_jobs_done`, so successful recent
  worker completions are visible next to error/terminal diagnostics.
- DONE: Support/debug state now includes `recent_job_terminal_status_counts`,
  a compact `done`/`error` status breakdown for recent terminal worker jobs.
- DONE: Support/debug state now includes `recent_job_latest_error`, a compact
  id/type/status/timing/error object for the newest recent failed worker job
  (or `null` when there are no recent errors).
- DONE: Support/debug state now includes `recent_job_latest_done`, a compact
  id/type/status/timing object for the newest recent successful worker job
  (or `null` when there are no recent successful jobs).
- DONE: Support/debug state now includes `recent_job_latest_active`, a compact
  id/type/status/timing object for the newest non-terminal recent worker job
  (`pending`/`running`, or `null` when there are no active jobs).
- DONE: Support/debug state now includes `recent_job_latest_over_deadline`, a
  compact id/type/status/timing object for the newest recent worker job that
  exceeded its deadline (or `null` when none are over deadline).
- DONE: Support/debug state now includes `recent_job_health_status`, one of
  `ok`, `active`, `error`, or `over_deadline`, with over-deadline taking
  priority for quick stuck-worker triage.
- DONE: Support/debug state now includes `recent_job_health_reason`, a compact
  human-readable reason derived from the same recent job counters as
  `recent_job_health_status`.
- DONE: Support/debug state now includes `recent_job_health_checked_at`, equal
  to `support.generated_at`, so copied diagnostics show when recent worker
  health was evaluated.
- DONE: Support/debug state now includes `recent_job_health_job`, the compact
  worker job object that explains `recent_job_health_status` (`over_deadline`,
  `error`, or `active`; `null` for `ok`).
- DONE: Support/debug state now includes `recent_job_health_job_id`,
  `recent_job_health_job_type`, and `recent_job_health_job_status`, scalar
  copies of the selected health job so support/UI can show the target directly.
- DONE: Support/debug state now includes scalar timing copies for the selected
  health job: elapsed, deadline, deadline-used percent, overrun, and
  over-deadline flag, so UI/support can badge it without parsing nested JSON.
- DONE: Support/debug state now includes `recent_job_health_job_error` and the
  top-table `Job error` row, so the selected error job's message is visible
  without opening nested JSON.
- DONE: Raw `recent_jobs` entries and the selected health-job scalars now
  include a sanitized worker `pid` (`0` when absent), and the support table
  shows `Job PID` for stuck-worker triage.
- DONE: Support/debug state now includes `recent_job_health_level`, a numeric
  severity for `recent_job_health_status`: `0=ok`, `1=active`, `2=error`,
  `3=over_deadline`.
- DONE: Support/debug state now includes `recent_job_health_action`, a compact
  operator-facing next step derived from recent worker health.
- DONE: Support/debug state now includes `recent_job_health_action_code`, a
  machine-readable action code: `none`, `wait`, `inspect_error`, or
  `stop_stuck_worker`.
- DONE: Support/debug state now includes `recent_job_health_summary`, a compact
  single-line human-readable combination of status, reason, and action code.
- DONE: Support/debug state now includes `recent_job_health_has_issue`, a
  boolean set for health levels `2` and `3` (`error`/`over_deadline`) but not
  for ordinary active jobs.
- DONE: Support/debug state now includes `recent_job_health_issue_count`, the
  number of recent issue jobs (`error_count + over_deadline_count`) for UI
  badges and alert thresholds.
- DONE: Support/debug state now includes `recent_job_health_issue_type_counts`,
  a merged per-worker-type issue map from recent errors and over-deadline jobs.
- DONE: Support/debug state now includes
  `recent_job_health_issue_status_counts`, a compact issue-class breakdown for
  `error` vs `over_deadline` jobs.
- DONE: Support/debug state now includes
  `recent_job_health_primary_issue_type`, derived from the selected real
  `status:type` issue key.
- DONE: Support/debug state now includes
  `recent_job_health_primary_issue_status`, derived from the selected real
  `status:type` issue key, with `over_deadline` winning ties over `error`.
- DONE: Support/debug state now includes
  `recent_job_health_primary_issue_key`, a compact real counted `status:type`
  issue key for UI grouping/alerts.
- DONE: Support/debug state now includes `recent_job_health_issue_key_counts`,
  exact `status:type` issue counts for recent worker errors and over-deadline
  jobs.
- DONE: Support/debug state now includes `recent_job_health_issue_summary`, a
  compact comma-separated `status:type=count` string derived from recent worker
  issue key counts.
- DONE: Support/debug schema version bumped to `55` after adding
  `worker_launch_ready` and the top-table `Worker ready` row.
- DONE: Support/debug schema version bumped to `54` after adding worker launch
  check diagnostics (`worker_php_binary_executable`, `worker_script_exists`).
- DONE: Support/debug schema version bumped to `53` after adding worker launch
  path diagnostics (`worker_php_binary`, `worker_script`).
- DONE: Support/debug schema version bumped to `52` after adding worker control
  capability diagnostics (`worker_stop_available`, `worker_alarm_available`).
- DONE: Support/debug schema version bumped to `51` after adding raw recent-job
  PID diagnostics plus `recent_job_health_job_pid` and `Job PID`.
- DONE: Support/debug schema version bumped to `50` after adding scalar
  `recent_job_health_job_error` and the top-table `Job error` row.
- DONE: Support/debug schema version bumped to `49` after adding scalar timing
  copies for the selected `recent_job_health_job`.
- DONE: Support/debug schema version bumped to `48` after adding scalar
  `recent_job_health_job_*` target fields and the top-table `Job target` row.
- DONE: Support/debug schema version bumped to `47` after adding
  `over_deadline` to compact recent-job entries.
- DONE: Support/debug schema version bumped to `46` after adding
  `over_deadline` to raw `recent_jobs` entries.
- DONE: Support/debug schema version bumped to `45` after adding
  `overrun_seconds` to raw `recent_jobs` entries.
- DONE: Support/debug schema version bumped to `44` after adding
  `deadline_used_percent` to raw `recent_jobs` entries.
- DONE: Support/debug schema version bumped to `43` after making recent worker
  primary issue status/type/key derive from the same real issue key.
- DONE: Support/debug schema version bumped to `42` after making
  `recent_job_health_primary_issue_key` use a real counted issue key.
- DONE: Support/debug schema version bumped to `41` after adding
  `deadline_used_percent` to compact recent worker job entries.
- DONE: Support/debug schema version bumped to `37` after adding recent worker
  issue summary.
- DONE: Support/debug schema version bumped to `36` after adding recent worker
  issue key counts.
- DONE: Support/debug schema version bumped to `35` after adding the recent
  worker primary issue key.
- DONE: Support/debug schema version bumped to `34` after adding the recent
  worker primary issue status.
- DONE: Support/debug schema version bumped to `33` after adding the recent
  worker primary issue type.
- DONE: Support/debug schema version bumped to `32` after adding the recent
  worker health issue status counts.
- DONE: Support/debug schema version bumped to `31` after adding the recent
  worker health issue type counts.
- DONE: Support/debug schema version bumped to `30` after adding the recent
  worker health issue count.
- DONE: Support/debug schema version bumped to `29` after adding the recent
  worker health issue flag.
- DONE: Support/debug schema version bumped to `28` after adding the recent
  worker health summary.
- DONE: Support/debug schema version bumped to `27` after adding the recent
  worker health action code.
- DONE: Support/debug schema version bumped to `26` after adding the recent
  worker health action.
- DONE: Support/debug schema version bumped to `25` after adding the recent
  worker health severity level.
- DONE: Support/debug schema version bumped to `24` after adding the recent
  worker health job pointer.
- DONE: Support/debug schema version bumped to `23` after adding the recent
  worker health checked timestamp.
- DONE: Support/debug schema version bumped to `22` after adding the recent
  worker health reason.
- DONE: Support/debug schema version bumped to `21` after adding the recent
  worker health status.
- DONE: Support/debug schema version bumped to `20` after adding the latest
  recent over-deadline worker object.
- DONE: Support/debug schema version bumped to `19` after adding the latest
  recent active worker object.
- DONE: Support/debug schema version bumped to `18` after adding the latest
  recent successful worker object.
- DONE: Support/debug schema version bumped to `17` after adding the latest
  recent worker error object.
- DONE: Support/debug schema version bumped to `16` after adding terminal job
  status counts.
- DONE: Support/debug schema version bumped to `15` after adding recent done
  job diagnostics.
- DONE: Support/debug schema version bumped to `14` after adding
  `recent_job_error_type_counts`.
- DONE: Support/debug schema version bumped to `13` after adding
  `recent_job_error_count`.
- DONE: Support/debug schema version bumped to `12` after adding
  `recent_jobs_terminal`.
- DONE: Support/debug schema version bumped to `11` after adding
  `recent_job_terminal_type_counts`.
- DONE: Support/debug schema version bumped to `10` after adding
  `recent_job_terminal_count`.
- DONE: Support/debug schema version bumped to `9` after adding
  `recent_job_running_type_counts`.
- DONE: Support/debug schema version bumped to `8` after adding
  `recent_jobs_running`.
- DONE: Support/debug schema version bumped to `7` after adding
  `recent_job_running_count`.
- DONE: Support/debug schema version bumped to `6` after adding
  `recent_jobs_pending`.
- DONE: Support/debug schema version bumped to `5` after adding
  `recent_job_pending_type_counts`.
- DONE: Support/debug schema version bumped to `4` after adding
  `recent_job_pending_count`.
- DONE: Support/debug schema version bumped to `3` after adding
  `recent_job_active_max_elapsed_seconds` on top of the v2 worker diagnostics.
- DONE: Support/debug schema version bumped to `2` after adding compact worker
  diagnostics for active, over-deadline, type-count, and overrun job fields.
- DONE: Reference opener click now uses the same explicit open path as keyboard
  shortcuts and footer summary, so focus restoration and ARIA state stay synced
  without relying on hidden checkbox label toggling.
- DONE: Reference screenshot removal now restores focus to the visible attach
  pill instead of the hidden file input, keeping keyboard flow readable.
- DONE: Shortcuts openers now use the same explicit open path as keyboard
  shortcuts, keeping focus restoration and ARIA state synced without relying on
  hidden checkbox label toggling.
- DONE: Reference Clear action now restores focus to the URL field after
  clearing, instead of leaving keyboard focus on the now-disabled Clear button.
- DONE: Composer mode-card clicks now use the same explicit `chooseMode()` path
  as keyboard selection, keeping focus on the visible card instead of the hidden
  radio input.
- DONE: Dynamic disabled controls now keep `aria-disabled` synced for prompt
  clear, Reference clear, and JSON copy/format/undo actions.
- DONE: Copy JSON's temporary "Copied" state now restores through the shared
  advanced-status sync, so the button label/disabled state remains truthful if
  the plan JSON changes while the timeout is pending.
- DONE: The Cmd/Ctrl+Backspace/Delete clear-prompt shortcut no longer hijacks
  editing inside Reference URL/notes, search, or other inputs; it only clears
  from non-input context or the main prompt itself.
- DONE: Cmd/Ctrl+1..4 mode shortcuts no longer hijack editing inside Reference,
  search, JSON, or other secondary inputs; they still work globally outside
  inputs, in the main prompt, and on the mode cards.
- DONE: Cmd/Ctrl+Shift+C/P/Z JSON shortcuts are scoped to non-input context, the
  main prompt, the plan JSON editor, and JSON controls, so they do not hijack
  Reference/search/other secondary field editing.
- DONE: Main navigation shortcuts (sidebar collapse, New chat, chat search,
  Reference, and Advanced) are scoped to non-input context or the main prompt,
  so they do not hijack Reference/JSON/search field editing.
- DONE: Cmd/Ctrl+Enter Generate is scoped to non-input context or the main
  prompt, while still closing the Reference dialog when that dialog is open, so
  JSON/Reference/search editing cannot accidentally submit generation.
- DONE: Shortcuts help note now states that global shortcuts pause while editing
  Reference, JSON, search, or other secondary fields.
- DONE: Shortcut context rules now use explicit helpers for main, mode-card,
  and composer/JSON contexts, keeping the global keydown handler readable.
- DONE: `scripts/smoke.php` now renders the main composer via reflection and
  checks the shortcut pause help text plus key shortcut guard helpers, so these
  UI safety rules are covered without browser login or paid calls.
- DONE: Initial composer markup now includes `aria-disabled` for server-rendered
  disabled controls (prompt clear, Reference clear, JSON copy/format/undo)
  before client-side JS sync runs; smoke covers the initial accessible state.
- DONE: Smoke now also covers the empty first-screen composer state, including
  disabled prompt clearing, readiness copy, empty submit styling, and
  mode-aware submit labels before client-side JS sync runs.
- DONE: Smoke now covers server-rendered Reference context on the composer
  (URL + notes): readiness copy, footer summary, opener state, live detail, and
  enabled Clear reference state before client-side JS sync runs.
- DONE: Smoke now covers server-rendered composer mode state for Interview,
  Change site, and Improve: prompt title, current-mode badge, checked radio,
  submit label, and submit ARIA before client-side JS sync runs.
- DONE: Smoke now covers the server-rendered sidebar control contract: collapse
  button, New chat, chat search live status, no-results clear search, and
  Shortcuts opener ARIA before client-side JS sync runs.
- DONE: Smoke now covers server-rendered Blueprints & advanced panel state:
  loaded plan opens the panel with matching ARIA, empty composer keeps it
  collapsed/hidden, and JSON diagnostics/actions plus Preview/Build are present.
- DONE: Smoke now covers server-rendered chat history: section/range labels,
  Continue link, prompt-like `Use again` restore mode/action context, empty
  question fallback text, and metadata chip list ARIA.
- DONE: Smoke now covers standalone Build history and Module skills pages in
  their initial server-rendered states, accepting either table content or the
  accessible empty status while checking stable headings/actions/containers.
- DONE: Smoke now covers rendered Plan Preview HTML: stable live region,
  summary table, planned page tree ARIA, template labels, image-cost status,
  and empty module recommendations.
- DONE: Smoke now covers Share-safe blueprint and Improve audit output:
  labelled export textarea plus audit finding/action markup that restores the
  suggestion into Change mode with accessible context.
- DONE: Smoke now covers telemetry feedback rendering by temporarily toggling
  Olivia config off/on, verifying hidden/visible feedback states, and restoring
  the original config before continuing.
- DONE: Smoke now renders Olivia's ProcessWire module config inputfields and
  verifies generation mode, paid image/content/telemetry toggles, and every AI
  role model field are present.
- DONE: Smoke now covers the watchdog path for stale pending jobs as well as
  stale running jobs, ensuring queued model work fails visibly instead of
  polling forever or auto-retrying.
- DONE: Smoke now fails if Olivia module config fields (`generationMode`,
  `generateImages`, `fillContent`, `telemetry`) are declared as PHP properties,
  preventing ProcessWire WireData config shadowing from regressing.
- DONE: `OliviaBuilder::build()` now wraps its complete mutating phase in a
  compensation boundary: an unexpected exception rolls back the live in-memory
  manifest before rethrowing a diagnostic error, preventing partial untracked builds.
- DONE: `OliviaBuilder::rollback()` isolates optional SEO and Atlas cleanup
  failures into report errors, so either integration cannot abort the core
  page/template/field/file rollback sequence.
- DONE: Additive Build semantics now apply to reused content and page metadata:
  `applyContent()` fills only empty fields, component metadata is set only on
  newly created pages, and an existing home banner is preserved.
- DONE: Deterministic plan content added to empty fields on the reused home page
  is recorded in manifest `content_values`; Undo restores each exact prior
  scalar value instead of leaving Change/Create residue behind.
- DONE: Undo uses compare-before-restore for `content_values`, AI `filled`
  values, and generated banners. If the owner changed Olivia's generated value
  after Build, rollback reports it as skipped and preserves the newer edit.
- DONE: Optional external integrations are capability-guarded: incompatible or
  throwing Atlas installs degrade to no-op/manifest diagnostics, while generated
  template module load/render calls are isolated so they cannot fatal the public page.
- DONE: Olivia indexes each page through Atlas 1.0's atomic `addChunked()` API;
  embedding failures preserve prior chunks and surface a bounded manifest issue.
- DONE: Optional Content Fill and Images integrations contain Squad/GrokImagine
  load, capability, and provider exceptions; failures become bounded manifest
  issues and cannot roll back an otherwise valid structural Build.
- DONE: Component catalog expanded to 119 validated patterns across navigation,
  hero, content, media, people, proof, commerce, conversion, data, property,
  careers, help and feedback categories. Shared archetype references avoid
  duplicate markup; only actually rendered components enter the default planner
  vocabulary, and smoke rejects broken catalog metadata or missing references.
- DONE: Generated view version 27 promotes 63 catalog variants into real output
  through deterministic aliases and four new child-collection engines: list,
  metrics, timeline, and accessible responsive table. Media-led hero/detail,
  cards, people, pricing, proof, feature, carousel, and logo variants reuse their
  closest stable renderer; the active forceable palette is now 85 components.
- DONE: The same view v27 renderer batch also adds page states
  (empty/success/error/loading/maintenance), child section tabs, editorial
  columns, pull quotes, masonry-style image grids, availability timelines,
  breadcrumbs and app detail layouts. The active forceable palette is now 97.
- DONE: Release metadata lives in `Olivia::getModuleInfo()` with explicit
  singular/autoload flags, repository URL and Squad 1.4.0+ dependency. Release checks now reject
  runtime/secret files, validate metadata and shell syntax, and a clean-tree builder
  produces a versioned install ZIP plus SHA-256 checksum.
- DONE: Release packaging honors `.gitattributes` export rules, so the install ZIP
  omits CI, internal agent memory, Tailwind build sources and release scripts. A
  portable static gate inspects the staged exported archive and CI lints PHP 8.1/8.4.
- DONE: A marker-guarded lifecycle smoke verifies a real exported module directory,
  ProcessWire module/page/permission cleanup, metadata reload, and two complete
  uninstall/reinstall cycles on an isolated database clone.
- DONE: The release module class/file/package root is `ProcessOlivia`, satisfying
  ProcessWire's core-type prefix convention while the admin title and product brand
  remain Olivia. Config consumers use the `ProcessOlivia` module key.
- DONE: Editable plan JSON Undo also records changes when a browser emits `input`
  without `beforeinput` (paste/autofill/automation), while preserving the normal
  per-edit `beforeinput` history path.
- DONE: Preview preserves an explicit opt-out from recommended module installation;
  its hidden presence marker distinguishes an unchecked box from a plan that did
  not render the setting yet, so Build cannot silently re-enable installation.
- DONE: Mobile Build history and Module skills tables keep readable minimum column
  widths and scroll inside their table wrappers instead of squeezing identifiers
  into one-character lines or widening the page.
- DONE: Build history, Module skills and Support info now share a 1520px
  ProcessWire-style workspace with symmetric gutters, consistent page headings,
  summary strips and responsive internal tables. Support parameters and the
  copyable debug bundle use a two-column desktop layout that stacks below 1100px;
  the main composer remains on its independent 1480px shell.
- DONE: Plan Preview labels ecosystem suggestions as `Recommended modules` and
  states that installation requires either an individual approval or an explicit
  pre-Build opt-in; the preview no longer implies every recommendation will install.

## Conventions for agents

- Keep Build **additive, reviewable, reversible**. Never modify /wire, /vendor, or third-party
  module source. Olivia may write under /site/templates, /site/assets, and create site objects.
- Don't print or commit the Squad API key.
- Update this file when you change behavior so the next agent (or model) stays in sync.
