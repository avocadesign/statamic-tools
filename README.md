# Avoca Tools

The machinery Avoca Design sites share: reference pages for clients, plus the checks and generated files that keep each site's block knowledge in step with its fieldsets. The addon holds no site content. Each site keeps its own guidance files and AI block catalogue, committed in its own repo.

## What it provides

- `/site/content`: every page builder block and text editor set, with its guidance, required fields, display settings and a live preview.
- `/site/style`: colours, typography, spacing, buttons, colour schemes and image output, read from the site's CSS and templates.
- `php please avoca:site:install`: writes the placeholder images the reference pages use.
- `php please avoca:site:catalogue`: writes the site's AI block catalogue.
- `php please avoca:make:collection`: makes a collection with a page builder or custom blueprint, a listing block, editor access and a record an AI agent finishes it from.
- `php please avoca:site:permissions`: gives the editor role the permissions for every collection, taxonomy, navigation, global set and asset container that isn't opted out. `--dry-run` lists what it would add.
- `php please avoca:site:check`: checks the reference pages, guidance files and catalogue against the fieldsets. Add `--strict` in CI to fail on missing guidance or a stale catalogue, or `--stubs` to create guidance files for blocks and sets that have none. It also warns when a library item the site hasn't installed uses a handle the site already has.
- `php please avoca:check-name {handle}`: says whether a handle is free for a new block, set or collection, as [Names](#names) describes.

On production the reference pages are only visible to a logged-in Statamic user.

## Files in the site

| Path | Written by | Read by |
|---|---|---|
| `resources/site/blocks/<handle>.md` | people | the content page and the catalogue |
| `resources/site/sets/<handle>.md` | people | the content page and the catalogue |
| `resources/site/design.md` | people | the catalogue |
| `resources/site/catalogue.md` | `avoca:site:catalogue` | AI editors |
| `resources/site/collections/<handle>.md` | `avoca:make:collection`, then people and AI agents | AI agents finishing or changing a collection |
| `resources/site/editor-access.yaml` | people | `avoca:site:permissions`, the automatic editor role updates and `avoca:site:check` |

The paths are set in `config/statamic-tools.php`.

## Guidance files

One file per block or set. This example is for a cards block:

```markdown
---
title: Cards
description: A row of cards, each with a title, text and an optional button.
---
## When to use

Three to six items of equal weight, such as services or team members.

## When not to use

A single message that needs attention: use Call to action instead.

## Notes for AI

Keep card titles under six words, and use the same card type for every card in a block.
```

The content page shows the description and the When to use and When not to use sections. Notes for AI appear only in the catalogue. Any other section heading is shown on the page and included in the catalogue. Placeholder text left from a stub counts as not written, so it never reaches the catalogue.

Rules every block shares, such as when to use each colour scheme, live once in `resources/site/design.md` instead of in each block's file. Its written sections appear in the catalogue under Design, before the blocks, and a Notes for AI section works the same way as in block guidance. `--stubs` writes this file too.

## Keeping the catalogue current

1. Change a fieldset or a guidance file.
2. Run `php please avoca:site:catalogue` and commit the catalogue with your change.
3. CI runs `php please avoca:site:check --strict`, which fails when the committed catalogue does not match.

Point the site's AI brief at the catalogue so an AI editor reads it before adding content.

## Making a collection

    php please avoca:make:collection

It asks for a title and handle, whether each entry gets its own page and at what URL, whether entries are dated, whether editors put them in order by hand, whether an entry is built with the page builder or from its own fields, and whether a block lists the entries. Then it writes:

- the collection's settings, in `content/collections/<handle>.yaml`
- a blueprint: modelled on the pages `page` blueprint for the page builder, or for custom fields a minimal one with the title, plus the slug, date and SEO tab where they apply
- for custom fields with URLs, a show template stub at `resources/views/<handle>/show.antlers.html`
- unless `--no-block`, a listing block in the page builder's Dynamic group, which holds the blocks that show content from elsewhere, such as a form, collection entries or contact details. It comes with its fieldset, template and guidance stub, and shows all entries or chosen ones, in an order and number the editor sets.
- the editor role's permissions for the collection, unless `--no-editor-access`, which records the opt-out as `editor_access: false` in the record
- the record, `resources/site/collections/<handle>.md`: the decisions in its front matter, the blueprint brief, and Notes for AI with the conventions to follow and the checks the finished collection must pass

With the page builder and URLs, entries use the site's default template. The catalogue is regenerated when a block is added, unless `--no-catalogue`. Nothing is written if any of the files, or a page builder block with the same handle, already exists. It also stops when a library item the site hasn't installed uses the handle, as [Names](#names) describes. `--dry-run` shows every change, and `-v` adds each file's contents.

Every question has an option, so an AI agent can run it without prompts. `--json` reports the record path and each step:

    php please avoca:make:collection --title="People" --ordered --blueprint=custom \
        --description="Each person has a photo, name, role, a short bio, an email and a LinkedIn link." --json

The options are `--title`, `--handle`, `--route` or `--no-route`, `--dated`, `--ordered`, `--blueprint=page_builder|custom`, `--description`, `--no-block`, `--no-editor-access`, `--ignore-library`, `--no-catalogue`, `--dry-run`, `--json` and `--ai`.

An AI agent finishes a custom blueprint from the record: ask it to build the blueprint described in `resources/site/collections/people.md`. With `--ai`, once you confirm, the command runs Claude Code headless itself, if `claude` is on the PATH. It runs with `--restricted`, `--permission-mode dontAsk` and an allow list, so it can read the site, change only the files the record lists, and run `php please avoca:site:catalogue` and `php please avoca:site:check --strict`. It does not commit.

## Editor permissions

The editor role in `resources/users/roles.yaml` holds the permissions for every collection, taxonomy, navigation, global set and asset container, so the site is ready for editors as soon as it moves to Statamic Pro. Statamic Core ignores roles but still keeps the file, so this runs on Core too. Each type gets every permission Statamic has for it.

- A structure created in the control panel or through Statamic's PHP API gets its permissions as it is created, silently.
- `avoca:make:collection` and `avoca:library:install` add the permissions for what they create.
- For a structure added any other way, such as a settings file written by hand, `php please avoca:site:permissions --dry-run` lists what the role is missing and `php please avoca:site:permissions` adds it.
- `avoca:site:check` warns when the role is missing permissions, but never fails because of it, even with `--strict`.

To keep a structure away from editors, list its handle in `resources/site/editor-access.yaml`, under the folder in `content/` that holds its settings file:

    assets:
      - favicons
    globals:
      - seo

A collection made with `--no-editor-access` is opted out by `editor_access: false` in its record. Opting out stops permissions being added and never removes any, so a partial set, such as view only, stays as it is. The role's handle is `editor_role` in `config/statamic-tools.php`.

## Installing

Sites made from the Avoca starter kit get the addon while the kit installs. For any other site:

```bash
composer require "avocadesign/statamic-tools:<2.0"
```

It needs Statamic 6. It comes from Packagist, so Composer needs no repository entry and no
credentials, on a computer or on a server.

## Versions and releases

Releases are git tags on GitHub, such as `v0.1.0`, and `CHANGELOG.md` says what each one changed. Each site records the version it runs in its `composer.lock`, so sites can run different versions side by side.

- `composer show avocadesign/statamic-tools` shows the version a site runs.
- `composer update avocadesign/statamic-tools` moves a site to the newest release it allows. Sites made from the Avoca starter kit ask for `<2.0`, so that includes every release line up to and including 1.x, the launch line.
- To hold a site on one release line, pin it there: `composer require "avocadesign/statamic-tools:^1.0"`. Read the changelog entry of any release that changes how a site works.
- After any update, run `php please avoca:site:catalogue`, commit the catalogue if it changed, and run `php please avoca:site:check --strict`.

To make a release:

1. Add the release to `CHANGELOG.md` and commit it on `main`.
2. Tag that commit, such as `git tag -a v0.1.1 -m "Avoca Tools v0.1.1"`.
3. Push `main` and the tag: `git push origin main v0.1.1`.
4. A release that changes how sites work goes in the changelog with a note on what to do about it. Sites ask for `<2.0`, so only a 2.0 release needs the starter kit's constraint changed and sites moved on purpose.

The sandbox requires the addon as `*@dev` from its local folder, so it always runs the code being worked on.

## Library

Avoca's library of ready-made blocks, text editor sets and presets lives in `library/`. It holds the FAQ, Projects and Testimonials presets.

    php please avoca:library
    php please avoca:library:install {handle} --dry-run
    php please avoca:library:install {handle}

Installing copies the item's files into the site and adds its blocks or sets to their declared group. It places its
control panel screenshot, adds permissions, records the version in `resources/site/installed.yaml` and regenerates the
catalogue. It stops before writing anything if a file or fieldset entry already exists and differs, unless `--force`
is given. `library/README.md` describes the item format.

### Names

A library item reserves its handles: its own, those of the blocks and sets it adds, and those of the collections, taxonomies, global sets, navigations and fieldsets among its files. The recipe installs a library item rather than building the same thing again, and installing an item after new work took one of its handles would collide.

    php please avoca:check-name testimonials --display="Testimonials"

It exits with 1 when the handle is taken: a library item the site hasn't installed uses it, or the site already has a block, set, collection, taxonomy, global set, navigation or fieldset with it. A similar name, one that differs only in case, separators or a plural, or has the same name editors see, is listed but doesn't take the handle. `--json` reports as JSON.

- `avoca:make:collection` checks the collection's handle against the library the same way. A clash stops it, unless you pass `--ignore-library` because the clash is meant, and a similar name shows as a warning.
- `avoca:site:check` warns when a library item the site hasn't installed uses a handle the site already has, because installing that item later would collide. It never fails on this, even with `--strict`.
- Give a new library item handles that no block, set or fieldset in the starter kit uses.

## Licence and credits

Avoca Tools is published so that Avoca's sites can install it with Composer. It is not open source:
`LICENSE` grants the right to run it on a site Avoca builds or maintains, and nothing else.

Issues and pull requests are not monitored. The addon is written for Avoca's own starter kit and
isn't meant to be used elsewhere.

Two ideas here came from [Agentic](https://github.com/sanderjn/statamic-agentic) (MIT, © Sander
Janssen): a site reference an AI editor can read, and generating a block catalogue from the site's
own fieldsets. None of its code is used.
