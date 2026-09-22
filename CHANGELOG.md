# Changelog

Avoca Tools uses semantic versioning. While the version starts with 0, a release that breaks something sites rely on, or needs them to do something when they update, moves the middle number (0.1 to 0.2). Anything else moves the last number (0.1.0 to 0.1.1).

## v0.1.5 (22 September 2026)

The first release of the package in public, under a new name. A site updating to it changes the package it requires; nothing about how the addon works changes with it.

- Renamed to `avocadesign/statamic-tools`, with the namespace `Avocadesign\StatamicTools`, the config file and key `statamic-tools`, and views under `statamic-tools::`. The `avoca:` commands keep their names. Packagist can't rename a package once published, hence doing it now.
- Published source, not open source. `LICENSE` allows running it on a site Avoca Design Ltd hosts, or elsewhere by written agreement, and a site keeps the version it has if it leaves.
- The published history starts at one commit. The development history is archived privately, because early versions of the recipe described how Avoca hosts sites.
- Avoca's own hosting notes have left the package. They described how Avoca hosts rather than how to build a site, and the package installed them onto every client server. `server-git.sh` stays, because it runs on the server, and has moved to `scripts/`: deploy scripts and cron jobs that call it need their path changed.
- The recipe's hosting section now holds only what someone building a site needs: ask how the site is hosted, build to the static rules where you can, pull before working on a site whose content is edited on the server, and leave `[BOT]` to the server.
- A licence. The addon is published so Avoca's sites can install it, and is not open source. `composer.json` declares `proprietary`.
- `tests/` and `phpunit.xml` no longer ship in the Composer package, through `.gitattributes`.
- The README says how to install it, that issues and pull requests aren't monitored, and credits Agentic for two ideas.
- The recipe says one form per site on Statamic's free edition, what to do instead, and that enquiries go to the Site Details email.

## v0.1.4 (17 September 2026)

The build recipe covers structured data and contact details found in content. The addon's code is unchanged.

- Structured data: a site's knowledge graph lives in the SEO global's custom JSON-ld field, filled from Site Details, and a page that offers a service adds only a Service node in its own SEO tab. The recipe says why the starter kit publishes Peak's SEO snippet and what to do when Peak SEO updates, and that Antlers in those fields reads values and modifiers but doesn't run tags.
- Contact details found in a site's content go in the Contact Details set rather than being typed in. A detail Site Details doesn't hold yet, such as a postal address, is added as a field and an option on the set first, in five steps.

## v0.1.3 (16 September 2026)

- `/site/style` lists a `--prose-*` token as a prose colour only when its value is a colour, so spacing tokens such as `--prose-space` stay out of the colour list.

## v0.1.2 (16 September 2026)

- Sites take every release up to and including 1.x: the starter kit asks for `<2.0` rather than `^0.1`, so a new release line inside that range reaches them like any other release, while 2.0 stays a deliberate move.
- The prose sample on `/site/style` puts two paragraphs next to each other, and ends with text straight inside the stacked article, so both paragraph rhythms show.

## v0.1.1 (16 September 2026)

New blocks, sets and collections are checked against the library before they are built.

- `avoca:check-name {handle}` says whether a handle is free for a new block, set or collection: not used by a library item the site hasn't installed, nor by anything the site already has. Similar names are listed as warnings.
- `avoca:make:collection` stops when a library item the site hasn't installed uses the collection's handle, such as `testimonials`. `--ignore-library` makes it anyway.
- `avoca:site:check` warns when a library item the site hasn't installed uses a handle the site already has.

## v0.1.0 (15 September 2026)

The first tagged release, commit 5efa22b. New sites made from the Avoca starter kit require `^0.1`.

- `/site/style` and `/site/content`, the reference pages built from the site's CSS tokens, templates, fieldsets and guidance files.
- `avoca:site:install`, `avoca:site:catalogue` and `avoca:site:check` with `--strict` and `--stubs`.
- `avoca:make:collection`, for a collection with a page builder or custom blueprint, a listing block and a record an AI agent finishes it from.
- The editor role kept holding the permissions for every collection, taxonomy, navigation, global set and asset container, with `avoca:site:permissions` and opt-outs in `resources/site/editor-access.yaml`.
- The library, with `avoca:library` and `avoca:library:install`, and the FAQ, Projects and Testimonials presets.
- The build recipe in `recipe/`, and hosting research and drafts in `hosting/`, which are untested.
