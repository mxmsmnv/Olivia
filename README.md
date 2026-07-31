# Olivia

Olivia is an AI solution architect for ProcessWire. Describe the website you need and Olivia turns the request into a reviewable build plan, then creates the fields, templates, pages, content and frontend for you.

![Olivia](assets/Olivia.png)

It is made for starting real ProcessWire websites from an idea without giving up the CMS, the database structure or control of the generated files. Every build is previewed first, recorded in history and reversible with Undo.

**Author:** Maxim Semenov

**Website:** [smnv.org](https://smnv.org)

**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Olivia Does

- Converts a natural-language website request into a structured ProcessWire build plan.
- Treats Landing page, Business website, Catalog and Online store as first-class, validated site types.
- Creates fields, templates, pages, page references and realistic starter content.
- Generates responsive frontend views with production CSS, navigation, SEO metadata, Open Graph data and a working contact form.
- Supports Direct planning or an Interview flow that asks clarifying questions first.
- Accepts reference URLs, notes and up to four screenshots for visual direction, with optional ScreenshotOne capture for URL-only references.
- Can optionally ground a plan in current public web results through Squad, with normalized source links saved to the chat.
- Can generate page images and fill empty content fields when those features are enabled.
- Recommends compatible ProcessWire modules and learns their documented capabilities from local module documentation.
- Runs slow AI and image operations in detached background workers.
- Saves every completed build to History with a rollback manifest for Undo.

## Create, Change and Operate

### Create

Describe a new site in plain language. Olivia proposes its information architecture, content types, pages, visual theme and optional module recommendations. You review the plan before anything is created.

Create supports two planning styles:

- **Direct** produces a plan from the initial request.
- **Interview** asks focused questions before producing the plan.

Curated blueprints are also available for fast, deterministic starter sites without an AI call.

## Minimum Site Types

Olivia has explicit generation contracts and deterministic blueprints for:

- **Landing page**: one focused offer, a compact section journey, benefits, proof, repeated calls to action and a lead form.
- **Business website**: a multi-page company site with detailed services, trust content, optional news or work, and a tailored enquiry path.
- **Catalog**: products or services with an architecture for at least 50 listings, search, category filtering, detail pages, prices and availability, image galleries, and an enquiry form tied to each item.
- **Online store**: a real transactional storefront designed for at least 100 products and backed by [Mercato](https://github.com/mxmsmnv/Mercato), including products, collections, cart, checkout, payments, orders, discounts, fulfilment, inventory, verified customer accounts and order history.

Olivia does not present a visual product grid as a working store. An Online store Build fails closed unless Mercato is already installed or the administrator explicitly approves its installation before Build. Payment credentials, analytics IDs, social URLs, legal text and production launch settings are never invented; the owner must review and configure them.

### Change

Ask Olivia to extend an existing site with new fields, page types, sections or content. Change is additive and uses the same preview, Build and Undo workflow.

### Operate

Audit the current site and receive prioritized improvement suggestions. Findings can be turned into Change requests from the Olivia workspace.

Change and Operate are currently experimental while compatibility is expanded across more existing ProcessWire installations.

## Visual References

The Reference dialog accepts a website URL, design notes and screenshots. Olivia can inspect accessible page text and pass uploaded or captured screenshots through Squad vision to extract a bounded design profile: colors, typography, radius, container width, spacing density, layout patterns, components and mobile behavior. Optional Web research asks Squad to ground the final plan in up to five current public sources; it adds provider cost and latency, and the source links are retained with the plan message.

Reference analysis is directional. Olivia does not copy another site's branding or content, and inaccessible URLs gracefully fall back to the information you provide.

## Generated Website

A typical Build can include:

- a homepage and complete top-level site structure;
- custom ProcessWire fields and templates;
- nested detail pages with starter copy;
- responsive, versioned PHP template views;
- optional AI-generated images;
- SEO and Open Graph metadata;
- native ProcessWire contact and per-product catalogue enquiry forms;
- optional module-powered features;
- an Atlas index for retrieval-augmented content when Atlas is available.

Generated views are ordinary files under `/site/templates/`. ProcessWire pages, fields and templates remain editable through the normal admin interface.

## Safety and Undo

Olivia validates and previews every plan before Build. Builds are additive and record the ProcessWire objects, generated files and integration data they create or update.

Undo uses that manifest to restore the previous state. It also uses compare-before-restore behavior for generated content, so a newer manual edit is preserved and reported instead of being silently overwritten.

Undo is not a replacement for a normal database and files backup. Keep a current backup before using Olivia on an important production site.

## Integrations

Olivia uses [Squad](https://github.com/mxmsmnv/Squad) as its provider-independent AI gateway.

The package contains two ProcessWire modules: `Olivia` is the primary configurable product module, while `ProcessOlivia` is the automatically managed admin companion behind **Setup → Olivia**. Install and update `Olivia`; ProcessWire handles the companion through the standard module dependency lifecycle.

Optional integrations include:

- [Atlas](https://github.com/mxmsmnv/Atlas) for semantic indexing and grounded site context;
- [Ichiban](https://github.com/mxmsmnv/Ichiban) for extended SEO capabilities;
- [GrokImagine](https://github.com/mxmsmnv/GrokImagine) as an image-provider configuration source;
- [Mercato](https://github.com/mxmsmnv/Mercato) as the required commerce runtime for Online store Builds;
- documented ProcessWire modules whose public rendering APIs can be safely connected to generated templates.

AI, vision, embedding and image requests use the providers configured in Squad or the relevant optional module. These external calls may have usage costs.

## Admin Area

Olivia adds **Setup > Olivia**, a workspace where administrators can:

- start and continue planning chats;
- switch between Direct, Interview, Change and Improve modes;
- attach visual references;
- review and edit plan JSON;
- preview the planned site structure;
- start and monitor background Builds;
- browse Build history and run Undo;
- inspect learned module skills;
- copy a secret-free support and debug bundle.

## Requirements

- ProcessWire 3.0.200 or newer;
- PHP 8.1 or newer;
- [Squad](https://github.com/mxmsmnv/Squad) 1.9.0 or newer with at least one configured text-generation provider.

Embedding, vision and image features additionally require providers that support those capabilities.
Online store Builds additionally require Mercato. Olivia can install it only after the administrator explicitly enables the module-install option shown with the reviewed plan.

## Installation

1. Install and configure [Squad](https://github.com/mxmsmnv/Squad).
2. Extract the release ZIP so its `Olivia` folder is located at `/site/modules/Olivia/`.
3. In ProcessWire Admin, refresh modules.
4. Install `Olivia`. It automatically installs the `ProcessOlivia` admin companion and creates **Setup → Olivia**.
5. Open **Setup > Olivia**.
6. Review Olivia's configuration before enabling content filling, image generation, screenshot capture or telemetry.

Start with a prompt such as:

> Build a modern restaurant website with a menu, delivery information, an about page and contact details. Use a warm editorial style and realistic starter content.

Choose Direct or Interview, generate the plan, review the preview and select Build when it looks right.

## For Module Authors

Add an `AGENTS.md`, `API.md`, `EXAMPLES.md` or clear `README.md` to your module. Olivia can record that documentation in its local skills library, recommend the module for an appropriate website and use a documented public rendering method when the plan calls for it.

## Status

**1.0.0.** This is Olivia's first public release. Create with Direct or Interview, plan preview, background Build, History and Undo are the supported release path. Change and Improve are included as experimental workflows while compatibility coverage grows across existing ProcessWire sites. Start on a development or staging installation and keep normal backups.

## Support

Use the [GitHub issue tracker](https://github.com/mxmsmnv/Olivia/issues) for reproducible bugs and feature requests. Include the Olivia, ProcessWire and PHP versions, the affected mode, exact reproduction steps, and the secret-free support bundle from **Setup > Olivia > Support info**.

Do not post API keys, provider credentials, private URLs, customer content or unredacted logs. Report security vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Documentation

See [AGENTS.md in the source repository](https://github.com/mxmsmnv/Olivia/blob/main/AGENTS.md) for architecture, integration contracts, development commands and implementation notes.

Production PHP is grouped by responsibility under `src/Admin`, `src/Planning`,
`src/Build`, `src/Reference`, `src/Runtime` and `src/Integration`. Public
`ProcessWire\Olivia*` class names remain unchanged.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

GitHub Actions lints every committed PHP file with PHP 8.1 and 8.4.
Maintainer release tooling is intentionally kept local and is not included in
the public repository.

Report vulnerabilities privately using the instructions in [SECURITY.md](SECURITY.md).

## Author

Maxim Semenov

[smnv.org](https://smnv.org)

[maxim@smnv.org](mailto:maxim@smnv.org)

## License

[MIT](LICENSE)

The admin UI bundles Remix Icon 4.6.0 under the Apache License 2.0. Its license
is included at `assets/vendor/remixicon/LICENSE`; Olivia does not require an icon
CDN at runtime.
