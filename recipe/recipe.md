# Avoca build recipe

This is how Avoca Design builds and extends Statamic sites. Follow it whenever you build or extend an Avoca site: a page design, a page builder block, a text editor set, a collection, a global field, the page header, the header or the footer.

The recipe comes from the `avocadesign/statamic-tools` package and is the same for every site. Each site keeps its own knowledge in `resources/site/`: what it has, and the decisions made for it. This recipe never names blocks or sets, because every site has its own:

- For what a site already has, read `resources/site/catalogue.md` and open `/site/content`.
- For what is ready to install, run `php please avoca:library --json`.

Don't edit this file in `vendor/`. If a step is wrong, missing or contradicted by the site, stop, say what you found, and ask the developer.

## Before you start

1. Read `resources/site/catalogue.md`. It lists every block and text editor set the site has, with its fields, options, group and guidance. It is generated from the site's fieldsets, so trust it over any other list of blocks, including what you know from other Avoca sites.
2. Read `resources/site/design.md` for the rules every block shares, and the collection records in `resources/site/collections/`.
3. Open `/site/content` for a live preview of every block and set, and `/site/style` for colours, typography, spacing, buttons and colour schemes. On production both need a logged-in Statamic user.
4. Run `php please avoca:library --json` to see what Avoca's library can install. Each item has a `handle`, `name`, `category` (`blocks`, `sets` or `presets`), `version`, `status` (`not installed`, `installed` or `update available`), `description`, and the `blocks` and `sets` it adds. `resources/site/installed.yaml` records what this site has installed, and at which version.
5. Find out how the site is hosted and whether it will be generated as a static site. See [Static generation rules](#static-generation-rules) and [Hosting](#hosting).

## How an Avoca site is put together

### Layout, header and footer

- `resources/views/layout.antlers.html` wraps every page: the header partial, then the page, then the footer partial.
- The header is `resources/views/layout/_header.antlers.html`, with the main navigation in `resources/views/layout/navigation/`. The footer is `resources/views/layout/_footer.antlers.html`. Build header and footer designs in these partials, never as blocks or page content. Split a long one into further partials in `resources/views/layout/`.
- Don't type anything a client might change into them. Phone numbers, addresses and social links come from the Site Details global, copyright and privacy settings from the Configuration global, and menus from navigations in `content/navigation/`. A second menu, such as footer links, is a new navigation rendered in the partial. The editor role needs its permissions, as Editor permissions below describes.

### Site Details global

- Details used in more than one place, or that the client should be able to change, come from the Site Details global: phone numbers, email, address, social links, opening hours and key dates.
- When a site needs another detail, add a field to Site Details rather than typing the value into a template or a block. Put it in the section it belongs with, so a second phone number sits beside the first.
- Give a group of related fields its own tab, such as a Festival dates tab holding several date fields.
- The address is kept as separate parts, street, town, region, postcode and country, with the two map coordinates beside them, because search engines need clean strings rather than a block of rich text. The address shown on the site is built from those parts, so it and the site's structured data always say the same thing. The rich text address field under them is an override, for an address that has to read differently on the page. Structured data below has the rest.
- The business's contact details in a site's content, such as on a contact page or at the end of a policy, go in the Contact Details set rather than being typed into the text, so they follow Site Details. The set's guidance, `resources/site/sets/contact_details.md`, tells an editor how, including a detail that sits inside a sentence.
- When the content has a contact detail Site Details doesn't hold yet, such as a postal address, a second phone number or a fax, add it and show it with the set, rather than typing it into the page:
  1. A field in the right section of the blueprint: a text field for a number or an email, `common.text_basic` for an address. A postal address gets a field of its own and never goes in the street address parts, which search engines read as where the business is.
  2. An option on Display Contacts, keyed by the field's handle, in `resources/fieldsets/contact_details.yaml`. Add the same option in `resources/fieldsets/form.yaml` too, which keeps its own copy of the list for the Form block's contact details.
  3. A branch in `resources/views/components/_contact_details.antlers.html` that renders it like its neighbours: the inline button with `link_type="tel"` for a number or `link_type="email"` for an email, and the prose wrapper for an address. The Form block renders the same partial, so its template needs no change.
  4. The value in `content/globals/default/site_details.yaml`, and the set on the page with the new option ticked.
  5. The detail named in the set's description and guidance, then the catalogue regenerated and the strict check run.
- The blueprint is `resources/blueprints/globals/site_details.yaml`. Values live in `content/globals/default/site_details.yaml`. `content/globals/site_details.yaml` holds only the title.
- Pull a value into a template as `{{ site_details:<handle> }}`, inside `{{ if site_details:<handle> }}` so an empty field prints nothing.
- Label each field and write its instructions in the client's words.
- Check the editor role can edit the global: `edit site_details globals` in `resources/users/roles.yaml`.

### Structured data

- Every site carries one knowledge graph: the business, the website, and the page being looked at. It comes from the SEO global, Globals → SEO → JSON-ld, with Type set to Custom, and the kit ships the graph in that field. Leave Peak's own Organization output off. Two sources describing the same business is the defect this avoids: Google merges them by their `@id` and believes whichever it reads last.
- The graph is Antlers, so it fills itself in. Complete the Address section of Site Details, and the logo under Search engines, and the graph and the visible address on the site both follow, saying the same thing.
- The commented out lines in the field are the facts no field holds: the short name, the one line description, the price range, the area served. Take the comment markers off the ones that apply and write the values in. Leave the rest commented.
- Change `"@type": "Organization"` to the closest type under schema.org/LocalBusiness, such as `ProfessionalService`, for a business people can visit at the address. A business with no public address stays an `Organization`, and its address, geo and areaServed lines come out.
- Breadcrumbs stay with Peak, which builds them from the site structure. The kit ships `breadcrumbs: true`.
- Antlers in both of these fields, the global one and the page's, reads values and modifiers but does not run tags. `{{ permalink }}`, `{{ site_details:phone }}` and `{{ site_details:logo:url }}` all resolve; a `{{ glide:... }}` or a collection tag comes out empty and leaves a broken value behind. That is why the logo line uses the asset's own URL rather than a Glide size, and why anything needing a tag needs a developer and a template instead.
- A page that offers a service, or serves a place, adds that one node and nothing else, in the page's SEO tab under JSON-ld schema:

```json
{
  "@context": "https://schema.org",
  "@type": "Service",
  "@id": "{{ permalink }}#service",
  "name": "Web design, Nelson",
  "serviceType": "Website design",
  "provider": { "@id": "{{ config:app:url }}/#organization" },
  "areaServed": { "@type": "City", "name": "Nelson" },
  "description": "One line about the service."
}
```

- Both Antlers tags in it matter. `{{ permalink }}` means the node is right on whatever page it is pasted into, and `{{ config:app:url }}/#organization` is the same expression the global graph uses, so the two `@id`s match exactly and Google joins the service to the business. A typed out URL breaks that link silently on the first staging domain or trailing slash.
- Never paste an Organization, WebSite, WebPage or BreadcrumbList node into a page. Those are global, and a second copy contradicts the first.
- The kit publishes Peak's SEO snippet at `resources/views/vendor/statamic-peak-seo/snippets/_seo.antlers.html` for a single change: the page's JSON-ld field goes through the `antlers` modifier, which Peak leaves off, so the tags above resolve rather than printing as text. When Peak SEO is updated, copy its new snippet over that file and make the change again.
- Check the result in Google's Rich Results Test before the site goes live: one business, one website, no duplicate `@id`s.

### Editor permissions

- Editors use the editor role in `resources/users/roles.yaml`. It holds the permissions for every collection, taxonomy, navigation, global set and asset container that isn't opted out, so the site is ready for editors as soon as it moves to Statamic Pro.
- Roles only apply with Statamic Pro. Without Pro a site can have only one user, and Statamic removes the roles and groups fields, so that one account normally has full access. The roles file is still there, and the addon keeps it up to date on Core too.
- The addon updates the role for you, silently. A structure created in the control panel or through Statamic's PHP API gets its permissions as it is created, and `avoca:make:collection` and `avoca:library:install` add them for what they create. Each type gets every permission Statamic has for it, the same set the kit gives pages.
- A structure added any other way, such as a settings file you write in `content/`, gets nothing automatically. Run `php please avoca:site:permissions --dry-run` to see what the role is missing, then `php please avoca:site:permissions` to add it. `avoca:site:check` warns when the role is missing permissions for a structure that isn't opted out, but never fails because of it, even with `--strict`.
- To keep a structure away from editors, opt it out, and the automatic updates and `avoca:site:permissions` never add its permissions. For a new collection, pass `--no-editor-access` to `avoca:make:collection`, which records `editor_access: false` in the collection record. For anything else, list its handle in `resources/site/editor-access.yaml` under `collections`, `taxonomies`, `navigation`, `globals` or `assets`: the folder in `content/` that holds its settings file.
- Opting out never removes permissions the role already has, so a deliberate partial set, such as view only, stays as it is. Opt a structure out before you create it, or remove the permissions it was given when you opt it out.
- Commit `resources/users/roles.yaml`, and `resources/site/editor-access.yaml` when it changes, in the same pull request as the structure.

### Pages

- A page has a page header, then usually the page builder. `resources/views/default.antlers.html` renders the page header partial, `resources/views/layout/_page_header.antlers.html`, and then the page's content.
- Pages have two blueprints in `resources/blueprints/collections/pages/`:
  - `page` (Page Builder): the page header fields from the `block_hero` fieldset, then the page builder. Use it for pages designed from blocks.
  - `simple_page` (Simple Page): a single text editor field and no page builder. Use it for simple, continuous content such as a policy or terms.
- The page header comes from the template, not from a block. Adapt `resources/views/layout/_page_header.antlers.html` and its `block_hero` fieldset to the site's design.
- A page that offers a service, or serves a place, adds its own schema node in its SEO tab, as Structured data above describes. Everything else a search engine is told about the page is global, so there is nothing else to add per page.
- Key pages or the home page sometimes need a page header of their own, such as a slideshow or an animation. Build it as an option inside the page header partial and its fieldset, never as a block at the top of the page builder. Keeping what sits above the fold in the page header means the site's critical CSS can include it. Put the change in the plan, because the partial renders on every page.

### Forms

- **A site on Statamic's free edition may have one form.** Statamic enforces it: once a form exists, creating another in the control panel needs Pro (`FormsController@create` and `@store`), and Statamic's own pricing calls the free edition one content form. Writing a second form straight into `resources/forms/` walks around the check and renders fine, which is exactly why it happens by accident. Don't: it puts the site outside its licence, and the client can't manage that form in the control panel.
- **One form takes several kinds of enquiry.** Add a select field naming what the enquiry is about, list the options the site needs, and put that field in the notification's subject beside the sender's name the kit already puts there: `subject: '{{ trans:strings.form_subject_received }}: {{ enquiry_type }}, {{ name }}'`. Statamic parses the whole email config as Antlers with the submission's own fields, so any field on the form can go in the subject. Route it further with conditional fields where some enquiries need extra questions.
- **The same form can appear on several pages.** The Form block chooses the form and carries its own heading and text, so a service page and a contact page can each show the same form saying different things around it.
- **Submissions go to the Site Details email.** The kit's form sends to `{{ site_details:email ?? config:mail:from:address }}` and replies from it, so the client changes where enquiries land in one place, and a new site needs no edit to the form at all. Fill that field in before a site goes live: empty, it falls back to the site's own sending address.
- **A second form needs a decision, not a workaround.** It means Statamic Pro on that site, which costs money. Put it in the plan and ask the developer, before building anything that assumes it.

### Colours and design tokens

- Blocks take their colours, type and spacing from design tokens, so a changed token changes every block. That is why simple designs are quick.
- Start a design in the tokens, not in the blocks: colours and the colour schemes in `resources/css/colours.css`, fonts and the type scale in `resources/css/theme.css` (font files in `resources/css/fonts.css`), and heading sizes and text styles in `resources/css/typography.css`. `resources/css/site.css` imports them. CSS for a component goes in its own file in `resources/css/components/`, imported from `site.css` in the components layer like the files already there.
- Check a token change on `/site/style`, then on `/site/content`, before you change any block.
- If the site's blocks can reach a design through tokens and display settings, build it that way. A more complex design needs a new block: follow the decision order below.

## Deciding how to build it

**Check the library first, before building your own solution.** Take the first of these options that meets the brief:

1. **Use a block the site already has, as it is.** Look through the catalogue and the previews on `/site/content`, including every display setting. Content that a block's guidance lists under When not to use doesn't fit that block.
2. **Install a library item.** Preview the install with `php please avoca:library:install <handle> --dry-run`. Each step is marked `+` new, `=` already the same, `!` conflict or `✗` error. If any step is a conflict or an error, stop and put it in the plan. Never pass `--force` unless the developer has approved replacing those files. Install with `php please avoca:library:install <handle>`: it copies the item's files, adds its blocks or sets to their group with their screenshots, adds permissions, records the version in `resources/site/installed.yaml` and regenerates the catalogue. Do what the notes it prints ask, then read the guidance it installed and adjust it to this site. Installed files belong to the site from then on. An item marked `update available` is a change to plan with the developer, because the site may have edited its copy.
3. **Change an existing block.** Only when the block nearly fits and the change suits every page that uses it. Find those pages by searching `content/` for `type: <handle>`. Keep existing field handles and option keys, because saved content depends on them; if one has to change, move the saved content in the same pull request. Give a new option a default that keeps the current look, and put new layout options behind Display settings. Then update the block's guidance, retake its screenshot if its default look changed, and regenerate the catalogue. If `resources/site/installed.yaml` lists the block, say in the plan that the site's copy will differ from the library.
4. **Build a new block.** Only when none of the above meets the brief. Follow [Building a new block](#building-a-new-block).

Say which option you chose and why, in the plan and again in the pull request, including what ruled out each earlier option. For example: "Option 3, change an existing block. No block in the catalogue shows a date beside each item, the library has nothing for it, and a new date option that is off by default leaves every current page as it is."

The same order applies to text editor sets, with library items in the `sets` category, and to collections, with library items in the `presets` category.

## Building a new block

Start from a copy of the closest block the site already has, never from a blank file, and bring it into line with this section. Peak's commands (`php please peak:*`) don't follow these conventions: `peak:install:*` bypasses the library, the guidance files and the catalogue, and `peak:make:block` writes an empty fieldset and a placeholder template. Don't use them to add blocks, sets, presets or collections.

Check the handle before you create anything: `php please avoca:check-name <handle> --display="<Name>"`. It exits with 1 when the handle is taken, either by a library item the site hasn't installed, which option 2 installs instead, or by something the site already has. It lists similar names, such as a plural, without stopping: look at that library item before building your own, and never give different work a library item's handle.

A new block is six things, and all six go in one pull request: the fieldset, the template, its entry in the page builder, its guidance file, its control panel screenshot and the regenerated catalogue.

### Fieldset

`resources/fieldsets/<handle>.yaml`, titled `'Block: <Name>'`, with its fields in this order:

1. The heading fields. A block's heading is `heading` (display Heading) and its subheading is `sub_heading` (display Sub heading), both text fields, so the catalogue, the previews and editors recognise them. Don't prefix them with the block's name.
2. The rest of the content. Reuse what the site has before defining a field: `import: article` for the text editor with its sets, the fields in `resources/fieldsets/common.yaml` (such as `common.text_basic` and `common.text_plain`), `import: common_image` inside a group for an image with its caption, crop and link, and the same buttons import the existing blocks use. Text editor fields use `remove_empty_nodes: trim`. Give required content fields `validate` with `required`.
3. The Display settings revealer, exactly as the site's other blocks have it.
4. Layout options, each shown only when Display settings is on (`if: display_settings: 'equals true'`), each with a `default`, a `width` and `replicator_preview: false`. Explain a choice in `instructions`, with `instructions_position: below`.
5. Then the three shared imports, in this order and nothing after them: `import: colour_scheme`, `import: block_margins`, `import: custom_class`. Between them they add Colour Scheme, Block Margins and CSS class behind Display settings, so don't write your own scheme, margin or class fields. A block that needs a scheme for an inner panel gives that field a different handle, because the block wrapper applies `colour_scheme` to the whole section.
6. `import: custom_class` goes last in every block, because it is the field a site reaches for when a block needs one-off design work, and the class it adds is rendered after the block's own classes so it can win. Adding a display setting later means adding it above those three imports, never below.

Help text is one short line. Five or six words, no full stop, saying what the field does and nothing else: "Set the block's colour scheme", "A class the site's CSS defines". An editor reads it while deciding, not to learn the system, and a paragraph under every field makes a form look harder than it is. What a field is for, when to use it and what it does to the page belong in the block's guidance, which is where `/site/content` and the catalogue show them.

The CSS class field is an escape hatch, and escape hatches accumulate. Use it for a genuine one-off. Anything you would want twice is a display option on the block or a design token, and the class has to be defined in the site's own CSS, in `resources/css/components/`, and committed. A Tailwind utility typed into that field only exists after the site is rebuilt, so one typed on a live site does nothing at all.

```yaml
title: 'Block: <Name>'
fields:
  -
    handle: heading
    field:
      type: text
      display: Heading
  -
    handle: sub_heading
    field:
      type: text
      display: 'Sub heading'
  # the rest of the content
  -
    handle: display_settings
    field:
      mode: toggle
      input_label: 'Show settings'
      type: revealer
      display: 'Display settings'
      instructions: 'Change how this block looks: its layout, colour scheme and spacing.'
  # layout options, each with if: { display_settings: 'equals true' }
  -
    import: colour_scheme
```

Write every `display` and `instructions` in the client's words, in British English. They appear in the control panel and in the catalogue.

### Template

`resources/views/page_builder/_<handle>.antlers.html`:

```antlers
{{#
    @name <Name>
    @desc The <Name> page builder block.
    @set page.page_builder.<handle>
#}}

<!-- /page_builder/_<handle>.antlers.html -->
{{ partial:page_builder/block }}
    {{# heading #}}
    {{ if block:heading }}
        <header class="span-content flex flex-col gap-2">
            <h2>{{ block:heading | nl2br }}</h2>
            {{ if block:sub_heading }}
                <h3 class="subheading">{{ block:sub_heading | nl2br }}</h3>
            {{ /if }}
        </header>
    {{ /if }}

    {{# content wrapper #}}
    <div class="span-content">
    </div>
{{ /partial:page_builder/block }}
<!-- End: /page_builder/_<handle>.antlers.html -->
```

- Wrap everything in `partial:page_builder/block`, passing `:colour_scheme="block:colour_scheme"`, `:block_margins="block:block_margins"` and `:custom_class="block:custom_class"`. It renders the `<section>` on the fluid grid and turns those into classes, so don't handle the fields in the block. Pass the block's own layout classes with `class="..."`: the site's custom class is rendered after them, so it can override them.
- Place children on the grid with `span-content`, with `span-full` for edge to edge, or with `grid-cols-subgrid` to hand the grid down. Mark each element placed on the grid with an Antlers comment, such as `{{# content wrapper #}}`.
- Read the block's fields through the `block:` scope, such as `{{ block:heading }}`, so they never clash with page or global fields of the same name.
- The block heading is an `<h2>` and its subheading an `<h3 class="subheading">`. Size any other heading or heading-like text with `heading-size-1` to `heading-size-6`, never with `text-*` or `leading-*` utilities, which bring their own line height. `/site/style` lists the typography classes.
- Put text editor content in `<article class="prose max-w-none stack-8">`, looping the field and rendering each item with `{{ partial src="components/{type}" }}`. Hand-written prose goes in a wrapper inside the article: `<article class="prose"><div>...</div></article>`.
- Render images with the figure partial in `resources/views/components/utilities/` or Peak Tools' picture partial, with `sizes` set, never with a bare `<img>`. Render buttons with the same button partials the existing blocks use.
- Never write a colour value in a template: no hex, rgb or oklch values and no arbitrary colour classes. Don't put a text colour utility on ordinary text in a block. Text takes `--body-color` and `--headings-color`, which the colour scheme classes switch. A border, line or background that must follow the scheme reads a token, such as `border-(--your-token)`. If no token fits, declare one in the `@theme static` block of `resources/css/colours.css` and override it under each `.scheme-*` class that needs a different value.
- Space with `stack-*` and `gap-*`, not margins. The page template spaces the blocks and Block Margins adjusts that, so don't add outer margins or padding to the section to space it from its neighbours.
- Write mobile first. Use Alpine for interaction, with `x-data` scoped to the block, and put longer scripts in `resources/js/`.

### Page builder entry and group

Add the block to the `sets` of one group in `resources/fieldsets/page_builder.yaml`:

- A block with its own content fields goes in the **Content** group.
- A block that calls in content from elsewhere, such as a form, collection entries or contact details, goes in the **Dynamic** group. Every collection block goes there.

```yaml
<handle>:
  display: '<Name>'
  instructions: '<What the block is, in a short sentence.>'
  icon: <icon>
  image: <handle>.jpeg
  fields:
    -
      import: <handle>
```

### Guidance file

`resources/site/blocks/<handle>.md`. `php please avoca:site:check --stubs` writes a stub for every block or set that has none.

```markdown
---
title: <Name>
description: <One sentence saying what the block is.>
---
## When to use

## When not to use

## Notes for AI
```

- The description, When to use and When not to use appear on `/site/content`, which clients read. Write them in plain words, naming other blocks by their display names, never their handles. Under When not to use, name the block to use instead and say why.
- Notes for AI appear only in the catalogue: content lengths, how many items to use, which options to choose when, and mistakes to avoid.
- Replace every stub sentence. Stub text counts as not written and never reaches the catalogue, but the strict check still passes, so check it yourself.
- Rules every block shares, such as when to use each colour scheme, belong in `resources/site/design.md`, not in one block's guidance.
- If the new block takes over a job that another block's guidance sends people elsewhere for, update that guidance too.

### Control panel screenshot

Every block needs a screenshot. It is the block's preview in the control panel's block picker. Automation from the `/site/content` previews is planned. Until it exists:

1. Open the block's preview on `/site/content` at a desktop width, with its default settings.
2. Capture the block's preview only, without the page's sticky bars.
3. Save it as `public/page_builder/<handle>.jpeg`. That folder is the set preview images container (`set_preview_images` in `config/statamic/assets.php`). Match the width of the screenshots already there, at least 1400 pixels.
4. Add `image: <handle>.jpeg` to the block's entry in `resources/fieldsets/page_builder.yaml`.
5. Open Developer details for the block on `/site/content`. Preview image must name the file, not say missing or not added.

Retake the screenshot whenever the block's default look changes. Use a browser or screenshot tool you already have, and don't add a dependency to the site for it. If you can't take one, say so in the pull request and leave it for the developer. Don't commit a placeholder image. A library item brings its own screenshot and sets `image:` when it installs.

### Catalogue and check

```
php please avoca:site:catalogue
php please avoca:site:check --strict
```

Regenerate the catalogue after any change to a fieldset or a guidance file, and commit it with that change. Never edit `resources/site/catalogue.md` by hand.

## Text editor sets

A text editor set follows the same decision order, starts with the same `avoca:check-name` check, and has the same six parts, in different places:

- The fieldset, `resources/fieldsets/<handle>.yaml`, titled `'Set: <Name>'`.
- The template, `resources/views/components/_<handle>.antlers.html`, with `@set page.article.<handle>` in its docblock. A set renders inside prose: give anything that isn't running text `not-prose`, and size it with the `span-*` classes, as the site's existing sets do.
- Its entry in the `sets` of a group of the `article` field, in `resources/fieldsets/article.yaml`.
- Its guidance, `resources/site/sets/<handle>.md`, in the same format as a block's.
- A screenshot in `public/page_builder/`, with `image:` on the set's entry.
- The regenerated catalogue.

## Collections

Collections hold related items, such as people, projects and testimonials. Each collection type has a block in the Dynamic group that shows its entries.

1. **Check first.** Read the catalogue for a block that already shows these items, check `content/collections/` for a collection that already holds them, and run `php please avoca:library --json`: a preset holds a whole collection with its blueprint, its listing block and sample entries. Take the decision order above, and only run `avoca:make:collection` when nothing there meets the brief. The command checks the handle against the library itself and stops when a library item the site hasn't installed uses it: install that preset instead, or choose another handle. Pass `--ignore-library` only when the developer agrees the clash is meant.
2. **Decide, and put the answers in the plan:**
   - the title and the handle;
   - whether entries get their own URLs or only appear in blocks, and with URLs, the route. Give entries URLs when a visitor needs a page for a single item;
   - whether entries are dated, and whether they are ordered by hand;
   - whether the blueprint uses the page builder or is custom, and for a custom blueprint, what an entry holds, in plain words;
   - whether to create the listing block. Create it unless the plan says why not;
   - whether to keep editors away from the collection. They get access unless the plan says why not.
3. **Create it with `php please avoca:make:collection`.** Check `php please avoca:make:collection --help` for the current option names, and pass every answer as an option so the command runs without prompts. Run it with `--dry-run` first and read what it will write. `--json` gives its output as JSON. The command:
   - creates the collection from your answers. A page builder blueprint with URLs uses the kit's default template, so an entry page has the page header and then the page builder, like a page;
   - gives a collection with URLs an SEO tab that imports the Peak SEO fieldsets, as pages have. Keep that tab when you build a custom blueprint, and add it to any routed collection made another way;
   - writes the blueprint into the site. A blueprint the addon provides, from this command or a library preset, belongs to the site once it is copied in: change the site's copy, never the addon's template;
   - gives the editor role the collection's permissions, unless you pass `--no-editor-access`, which records the opt-out in the collection record;
   - creates the listing block in the Dynamic group when asked. The block shows all entries or chosen ones, with ordering and a limit;
   - writes the collection record, `resources/site/collections/<handle>.md`. Its front matter records the decisions. For a custom blueprint the record also holds a plain-language `## Blueprint brief` and `## Notes for AI`.
4. **Build from the record.** For a custom blueprint, build the blueprint, and a show template when entries have URLs, from the record, following the conventions in this recipe. The `--ai` option hands the brief to Claude Code running headless. Leave it off when you are the agent doing the build, and build from the record yourself.
5. **Keep the record.** It stays as the history of the collection's decisions. When a later change alters a decision, record it there in the same pull request.
6. **Finish the listing block like any new block:** its guidance written in full and naming the collection it shows, its control panel screenshot, the regenerated catalogue and a passing strict check.
7. **With URLs, check the entry pages.** An entry must render at its route, and the collection belongs in the sitemap collections of the SEO global unless the plan says otherwise.

`/site/content` gives an entries field no sample entries, so a collection block previews only the entries the site has. Add a few realistic entries before the visual review, or use the client's real ones, and say in the pull request which entries are samples.

### What a site tells language models

`llms.txt` comes from the Bots global, LLMs tab, and the starter kit ships a template that lists the site's pages
by itself and leaves the rest to you. Nothing updates it when a site grows, so:

- A collection with a route needs its own section, the way Pages has one. `avoca:site:check` warns for any routed
  collection the file never mentions, and for the placeholders the kit ships, so an unfinished file is visible
  rather than quietly wrong.
- Write it as you would for a person who has to summarise the site in a sentence: what the organisation is, who it
  serves, and where. The entries come from the collection tags; the framing around them is the part worth writing.
- The field takes Antlers, so nothing in it goes stale: the file is rendered per request.

## Static generation rules

A statically generated site is served as plain files made by Statamic's static site generator, `php please ssg:generate`, with no PHP behind them. Every page is rendered once, at build time, for an anonymous visitor. Build every feature so it still works that way, or stop and ask the developer. Keeping to these rules on every site lets one move to static hosting later without its features being rebuilt. The kit doesn't ship static generation yet, so ask the developer what a site needs before relying on it.

- Render the same page for everyone. Don't use `nocache`, `session`, `get:` or `post:` values, `old`, `get_errors`, `csrf_token`, `logged_in`, `user`, `can`, random sorting, or `now` for anything a visitor sees. On a static site each is frozen at build time or empty.
- Don't call the site's own server from the browser: no `fetch()` calls or form posts to Laravel routes or `/!/` endpoints. Alpine may only use data already in the page.
- On a static site, forms, search, logins and anything else that accepts input need the developer. Say so in the plan and write down what was asked for.
- A page is an entry in a collection with a route and a template. A custom route must be `Route::statamic()` with no `{parameters}`. The generator finds no other URL unless it is listed under `urls` in `config/statamic/ssg.php`.
- A taxonomy needs a `show` template, and its index page must be listed under `urls`.
- Paginate with `paginate` on the collection tag and Peak Tools' pagination partial, with one paginated list per page. Never build `?page=` links by hand, because a static site uses `/page/2` URLs.
- Show images with Peak Tools' picture partial or the `glide` tag, from a public asset container. Any other file a page needs lives in a `public/` folder listed under `copy` in `config/statamic/ssg.php`.
- Make links with `url`, `permalink` or `link`. Never write a host name, `localhost` or a `.test` domain.
- Use `environment` only for indexing and trackers, never to change what a page says.
- Add redirects to the Redirects global as plain paths with 301 or 302, never `#regex#` rules, so they can become the static host's redirect file.
- When the site has static generation set up, build it with `php please ssg:generate --no-interaction` and open the changed pages in `storage/app/static`. A static check in `avoca:site:check` is planned but doesn't exist yet, so check these rules yourself.

## Hosting

How a site is hosted decides what you can build, so ask the developer before you plan the work, and record the answer in the plan. What matters to you:

- Whether the live site has a control panel, and so whether content can change on the server as well as in the repository.
- Whether the site is generated as static files, which rules out anything needing PHP at the moment a visitor asks for a page. Follow [Static generation rules](#static-generation-rules) even where it isn't, so a site can move to static hosting later without its features being rebuilt.
- Whether forms, search and anything else that accepts input will work on the live site. Where they won't, say so in the plan and write down what was asked for.

On a site whose content is edited on the server:

- Pull before you start work and again before you push, because the server may have pushed content in between.
- Change code in the repository, never on the server.
- Commits ending in `[BOT]` come from the server pushing content it saved. Never put `[BOT]` in your own commit messages.
- The site commits that content with its own `scripts/server-git.sh`, which cron and the deploy script both call. It is a normal tracked file: read it to see what the server does, and treat a change to it as code, so it goes through a plan and a pull request like anything else.

### The server git script

The add-on ships the script every site starts from, and `php please avoca:site:script` writes a copy into the site at `scripts/server-git.sh`. A new site gets one during installation, so most of the time there is nothing to do.

The copy belongs to the site. The add-on never reads it back and never changes it again, so a site that needs something different can have it. That is the point of the copy: a server's git setup is the site's business, not the package's.

- `php please avoca:site:script` writes or updates the copy. It refuses to write over a copy the site has changed, so nobody loses work by running it.
- `--diff` says how the site's copy differs from the add-on's. `--force` takes the add-on's copy anyway, which is safe to undo because the site's copy is in git.
- `php please avoca:site:check` warns when the add-on's copy has moved on. It says nothing about a site with no copy, and nothing about a copy the site has changed on purpose.
- Run it by hand, never from a deploy script. It exits non-zero when it refuses, and a site that customised its copy would then fail every deploy.

When you change the script for one site, change it in that site's copy and say why in the commit. When the change suits every site, change the add-on's template instead and release it, so the next site starts from it.

## Updates

Dependencies are updated by a runner of Avoca's own, on its own machine, weekly. Nothing about it runs
inside a site. What a site owes the process is small, and it is all in the repository:

- **Lock files committed**, and a `composer.json` and `package.json` that say what the site can take.
- **`.nvmrc` and `engines.node`**, so the server and the runner build on the same Node. `.npmrc` sets
  `engine-strict=true`, which turns a silent build on the wrong major into a failed one.
- **`resources/site/updates.yaml`**, which says whether the site is enrolled, whether it is a canary or
  part of the fleet, the branch to work on, and the handful of pages that must always render.
- **The check itself**, which comes with this addon, so it improves in one place rather than in a
  hundred repositories:

```bash
bash vendor/avocadesign/statamic-tools/scripts/check-site.sh
```

It installs from the lock files, builds the assets, refreshes the Stache, runs `avoca:site:check
--strict`, serves the site and renders the pages `avoca:site:urls` names. A site with two thousand
pages is not rendered two thousand times: an update breaks a template or a block, not a page, so the
pages worth rendering are the ones that cover the most between them. That list is the ones the site
names in `updates.yaml`, every page a collection is mounted on, one entry from each collection so
each collection's own template runs, and then whichever pages add blocks and blueprints nothing
already chosen has. Run `php please avoca:site:urls` to see what a site would render. It writes `.env.check` and runs
with `APP_ENV=check` and Statamic Pro off, so it never touches the site's own `.env` and needs no
licence key. It exits 0 when the site renders, 1 when it doesn't, 2 when it couldn't run at all. Run it
by hand before pushing a dependency change; that is the same thing the runner will do.

The rules the runner works to, written down here because they are the point of the exercise:

- **Patch and minor only, unattended.** A major gets a pull request of its own that a person merges,
  because a major is a decision.
- **Nothing younger than three days.** A release that was published this morning has not been looked at
  by anybody yet, and that is when a compromised package is at its most dangerous.
- **Canary sites first, the fleet a few days later.** A bad release should be found on a site we are
  watching, not on a hundred we are not.
- **Fast forward before anything else, and stop if it can't.** On a site whose content is edited on the
  server, the branch moves without us: the server commits and pushes what the client saved. An update
  that cannot take those commits first has no business pushing its own.
- **A green check before a pull request exists.** An update nobody verified is worse than no update,
  because it looks like it was checked.

## Review steps

Every change goes through these five steps, in order.

### 1. A plan the developer approves

Before you change any file, write a short plan and wait for the developer to approve it. Include:

- what the brief asks for, in a sentence or two;
- the option you chose from the decision order, and why;
- the files you will add or change;
- for a block or set: its fields, content first and then display settings, its group, and any token you will add;
- for a collection: the answers you will give `avoca:make:collection`;
- anything that depends on how the site is hosted, or on static generation;
- anything you will leave out.

Don't build until the developer approves. If the work stops matching the plan, stop and say so.

### 2. Automated checks

Run these and quote their real output in the pull request:

```
php please avoca:site:catalogue
php please avoca:site:check --strict
npm run build
```

- The strict check must pass. It proves every block and set has a template and a guidance file, and that the committed catalogue is current.
- It doesn't prove the guidance is written or that the screenshot exists. Check both yourself, in the files and on `/site/content`.
- `npm run build` proves the CSS and JavaScript still build with any new classes.

If a check fails, fix the cause. Never weaken a check or work around it.

### 3. Visual review on /site/content and /site/style

- On `/site/content`, step through every display setting and every colour scheme of the block or set. Text, buttons and borders must stay readable on each scheme. Check a phone width and a desktop width, and the space between the block and its neighbours under each Block Margins option.
- On `/site/style`, check colours, typography, spacing, buttons and colour schemes after any token change.
- In the control panel, open the page builder and check the block sits in the right group with its screenshot, and that its fields read in a sensible order with the layout options behind Display settings.
- Keep screenshots of what you reviewed for the pull request.

### 4. One pull request

Make the change on its own branch, and open one pull request that holds all of it, so it is reviewed and reverted as one: the fieldset, the template, the guidance file, the screenshot and the catalogue change, with the page builder or text editor entry. Add, when they apply, token changes, the collection record and blueprint, `resources/site/installed.yaml` for a library install, and role permissions.

In the description, give the option you chose and why, the check output, the review screenshots, and anything left undone, such as a screenshot you could not take. Don't merge it yourself.

### 5. Client sign-off on the staging preview

A change is finished when the client has signed it off on the staging preview. Write a short note the developer can send: what changed and where to see it, using the names the client sees in the control panel, never handles. If the client asks for changes, go back to step 1.
