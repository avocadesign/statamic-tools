# Changelog

Avoca Tools uses semantic versioning. While the version starts with 0, a release that breaks something sites rely on, or needs them to do something when they update, moves the middle number (0.1 to 0.2). Anything else moves the last number (0.1.0 to 0.1.1).

## v0.1.32 (10 October 2026)

- **Only decisions are labelled,** in the prototype and on the site: the Comment label goes, and Decision sits over the card's top right corner, amber while it's to make and green with a tick once done.
- **Remove decision reads Make a comment,** in the team's ⋯ menu.
- **Show comment pins sits at the foot of the Comments panel,** pinned there over a list longer than the panel. On the site it shares the foot with who's commenting.
- **To do on update:** nothing.

## v0.1.31 (10 October 2026)

- **The recipe says which background colours to use in the prototype:** white, light and medium greys, and darkish greys down to about `gray-700`, but nothing darker, the Dark scheme included, since the interface around the frames is near-black and a dark band there makes the page seem to end. The page options guidance moves under the same new prototype section.
- **Decisions lose their yellow borders,** in the prototype and on the site: the label is amber text in the same grey outline as Comment, and the card no longer has a yellow edge. The amber number still marks a decision to make.
- **A comment's time sits below its author's name,** in the prototype and on the site, replies included.
- **To do on update:** nothing.

## v0.1.30 (10 October 2026)

- **A comment is labelled only Comment or Decision,** in the prototype and on the site. The colour says where it stands: plain while open, amber for a decision to make, green with a tick once done. Who decides, when the team gives it with `avoca:feedback --who`, shows as a line inside the open comment rather than in the label, and the date no longer wraps beside a long label.
- **Comments have an open and close chevron** at the right of their header: down on a closed comment, up on an open one.
- **Add comment stays on one line** in the Comments panel.
- **Fixed: the site's Comments tab opens again.** Since v0.1.26 a click on the tab, or a `?review` or `?feedback=` link, failed with "open is not a function": the loader's new setting for a reviewers list took the name of the function that opens the panel. Pins and the count, which start another way, still worked.
- **To do on update:** nothing.

## v0.1.29 (10 October 2026)

- **The page options bar starts folded,** to one line of what's showing, on every visit, like page notes and journeys. Show options opens it.
- **Frames are just the page.** The browser bar and its address are gone from every frame; the page's address is beside its title above. Display: Desktop shows the page edge to edge at full size in all the room it has, with no frame or label, so the page lays out at that width and follows the window as it's resized. Both keeps the desktop as a 1440 by 900 laptop scaled to fit beside the phone.
- **Sites can add other devices,** such as a tablet, with `DEVICES` in `data.js`: none by default. Each becomes a choice in Display options, shown on its own, and comments made on it are kept for it.
- **Menus close when you click into a page,** as they do for a click anywhere else: Display options, the version menu and a comment's ⋯ menu.
- **The Display options button says what's showing:** Display: Both, Display: Desktop or Display: Mobile.
- **Comments are in git.** They are kept in `content/feedback`, one file per comment, rather than `storage/app/feedback`, which git ignores. Comments made locally go up with a push; on a server whose content is edited there, its git script commits them with the rest of the content, so they come down with a pull rather than only through `avoca:feedback --from`. The digest's record of when it last sent stays in storage, so it never makes a commit. Comments saved in storage by an earlier version move across the first time they're read.
- **To do on update:** nothing, unless a site's repository is public: comments, with reviewers' names, now go into its history.

## v0.1.28 (10 October 2026)

- **The page options bar is just the options,** each name above its choices, and Close or Show options to fold it: no line asking reviewers to try each, and no note beside an option's name, which only invited "to decide". Decisions are made in the comments.
- **The recipe says how to write page options:** a word or two for each name and choice, no question in the label, two or three real alternatives, and out of the next version once decided.
- **To do on update:** a site whose `data.js` gives an option a `note` can drop it; it no longer shows.

## v0.1.27 (10 October 2026)

- **Journeys are numbered,** with who takes each one under its name and its device as a small icon on the right, rather than the words Mobile or Desktop. The journey being followed has its number filled in.
- **To do on update:** nothing.

## v0.1.26 (10 October 2026)

- **Page options in the prototype.** A page can be shown more than one way for reviewers to compare, from `OPTIONS` in `data.js`: a bar above the frames shows the choices on pages that have them, and folds to one line of what's showing. A choice redraws the page in both frames, or in the one it names, or sets the timeline's layout with `mode: true`. `pages.js` reads it with the new `option('id')` helper, the frame carries `data-option-<id>` for CSS, and a comment records which choices were showing. It replaces the timeline switch in page notes (`tryit`), which only one site's timeline used.
- **The Comments tab shows on every page when the site lists its reviewers,** since only their emails can sign in: no `?review` link needed. Without a list it still waits for `?review`.
- **Journeys** list Arrives, Wants to and Success each above its text, and say "an enquiry" rather than "a enquiry".
- **To do on update:** a site whose `data.js` sets `tryit` on a page moves that switch to `OPTIONS`.

## v0.1.25 (10 October 2026)

- **Feedback on the site works as the prototype's comments do.** The tab reads Comments and carries the number open on the site. Its panel shows this page's comments, with those done folded away, or all the feedback page by page, filtered to open, to decide or done. Pins are graphite while open, amber for a decision to make and green once done; they can be dragged when they cover something, switched off with Show comment pins, and still show for a comment being pointed at. The team raises and records decisions from the panel, and Add comment writes the comment in the panel with the + on the page. Cards no longer say where a comment is in words (the pin shows it; `avoca:feedback` still does).
- **Fewer, smaller actions,** on the site and in the prototype. An open comment has a slim reply box, whose Reply button shows once something is typed, and one button for the next step: Mark done, Record decision (the team) or Reopen, which reopens a decision and a resolved comment alike. The team's Make it a decision and Make a comment sit in a ⋯ menu. Show on page is gone: opening a comment brings its pin into sight, scrolling only when it's out of view, and one on another page takes you there.
- **Pins show as reviewers browse.** For a browser that has reviewed before, the loader starts the widget once the page is idle, so pins and the count show without a click. Visitors who haven't reviewed still get only the loader.
- **To do on update:** nothing.

## v0.1.24 (10 October 2026)

- **The team hears about new comments.** One email every 10 minutes, a digest of what reviewers said since the last on the site and in the prototype, each with a link that opens the comment, through sign-in when needed (the prototype now takes `?comment=<id>`). It goes to reviewers marked `team: true` and `FEEDBACK_NOTIFY`'s addresses, through the site's mail, and leaves out the team's own comments. `avoca:feedback:notify` sends it, with `--dry-run` to see who would be told what; Laravel's scheduler runs it while feedback is on. If an email fails, the next run sends everything since. `avoca:site:check` warns when feedback is on and nobody would hear.
- **Pins can be moved.** Drag a comment's pin in either frame when it covers something: it pins to the element it's dropped on, for everyone, and a click still opens the comment. Anyone signed in can, as anyone can resolve, through a new `comments/{id}/anchor` route.
- **With pins switched off,** pointing at a comment in the panel still shows its pin, and the open comment's.
- **The prototype's pins switch reads Show comment pins,** in the Comments tab and in Display options.
- **The side panel closes with a cross** rather than a panel icon.
- **The add-on's config is merged as it registers,** rather than as it boots, so the scheduler sees it.
- **To do on update, on a site being reviewed:** make sure the server runs Laravel's scheduler (`php artisan schedule:run` every minute in cron), and mark the team in `reviewers.yaml` with `team: true` or set `FEEDBACK_NOTIFY`.

## v0.1.23 (10 October 2026)

- **The prototype's guide says it isn't designed at all:** the design comes after the prototype is approved, rather than "it isn't the final look".
- **To do on update:** nothing.

## v0.1.22 (10 October 2026)

The prototype's decisions come from its comments, and its interface is reworked around them.

- **Comments in the prototype.** With feedback on, the Comments button, with the number still open, opens a side panel. Add comment asks for a spot on either frame; the numbered pin sits on the element clicked, in both frames and at the same size whatever the zoom: graphite while open, amber for a decision to make, green once done. Done covers a decision made and a comment resolved, which a client can't tell apart, so the panel says Done and Mark as done for both. It lists this page's comments or all the feedback, filtered to open, to decide or done, with a switch for the pins. Prototype comments keep their version, page, route and frame, and never mix with the site's own comments or counts.
- **Decisions are comments the team raises.** A control panel login, a reviewer marked `team: true` in `reviewers.yaml`, or `avoca:feedback` can raise a comment as a decision, record what was decided, reopen it or take it off; reviewers can't. Decisions carry from one version to the next. `avoca:feedback` gains `--decisions`, `--raise`, `--who`, `--decide`, `--outcome`, `--drop-decision` and `--write-decisions`, which copies them into `resources/site/decisions.md` at sign-off.
- **Decisions written into `data.js` are gone,** with the Decisions tab and the numbered markers. `pin()` and `toConfirm()` draw nothing, so pages that call them still work.
- **Notes and Comments buttons.** The side panel stays out of sight until one opens it, on its Notes or Comments tab. Notes open with every section showing and no longer repeat the page's address; a page without notes has no Notes button.
- **The content model is the team's** until it's shared: `PROTOTYPE_CONTENT_MODEL=everyone` shows it to reviewers, `off` hides it from everyone.
- **Layout.** Always dark, with no light and dark switch. The desktop frame is a 1440 by 900 laptop, and the phone has the same browser frame; a frame's size shows on pointing at its name, its zoom always. Both, Desktop or Mobile, Scroll together and Comment pins sit in a Display options menu. The version is a link under the site's name, which comes from `APP_NAME` unless `data.js` names it. The signed-in name is shortened to the name and Sign out.
- **To do on update:** nothing. Optionally, take `WHO`, `DECISIONS`, `DEC`, `SITEMAP_DECISIONS`, `BEFORE_DESIGN` and the `decisions` in `NOTES` out of a site's `data.js`, and set `name: ''` to use `APP_NAME`; mark the team in `reviewers.yaml` with `team: true`.

## v0.1.21 (10 October 2026)

The discovery prototype moves into the add-on, so every site gets its improvements.

- **The add-on serves `/prototype`:** the route, the switch, the sign-in and the interface. On for local and staging, off everywhere else, unless `PROTOTYPE_ENABLED` says otherwise; a 404 when off or when the site has no `prototype` folder. Signing in is the same as for feedback, in the same cookie: the password when `PROTOTYPE_PASSWORD` is set, an email on the reviewers list when the site keeps one, or nothing with neither.
- **The site keeps only what the prototype says,** one folder per version: `data.js`, `pages.js` and `version.json` with `format: 2`. The page helpers, such as `pageHeader()`, `img()` and `pin()`, come from the add-on as function declarations a site's `pages.js` can redefine.
- **The frames use the site's real CSS.** The add-on inlines `resources/css/site.css` and its imports, and Tailwind's browser build compiles it in each frame at the version the site builds with, so the pages lay out exactly as the site does. A wireframe layer on top greys the brand colours and adds the placeholders, markers and patterns.
- **A new look,** in the feedback widget's palette: graphite on cool greys, system fonts with nothing loaded from outside, and the same controls, with focus rings only for the keyboard. The sign-in page matches.
- **`php please avoca:prototype:version 2`** starts a version as a copy of the newest, replacing `new-version.sh` and its Python. A version from before the move, a folder with a built `index.html` and no `data.js`, is served exactly as it was built.
- **To do on update:** nothing until a site removes its own copy of the prototype (`app/Http/Controllers/PrototypeController.php`, its middleware, `config/prototype.php`, its routes, sign-in view and tests): while that controller is there, the site keeps serving its own copy. The starter kit no longer ships it.

## v0.1.20 (10 October 2026)

Feedback gets tighter control over who can see and use it.

- **No password, no feedback.** Anywhere but a local machine, feedback stays off without `PROTOTYPE_PASSWORD`, and `avoca:site:check` says so. Without one, anybody who found the site could read and write its comments.
- **Nobody else sees it.** The Feedback tab only appears in a browser that has signed in to review, or that opens a link ending `?review` (the one reviewers are sent) or `?feedback=<id>`. The open count needs sign-in too.
- **A list of who may comment.** With `resources/site/reviewers.yaml` listing names and emails, signing in asks for an email on the list and shows the list's name, not one typed in, and taking someone off the list signs them out. Without the file, any name will do.
- **A developer key for the command.** `avoca:feedback --from` now sends the server's `FEEDBACK_KEY` (`--key`, or this site's `.env`), never the reviewers' password, and a server with no key refuses those requests. `--password` is gone.
- **Comments are treated as written by reviewers.** The command prints their text escaped, so it can't pass for its own output, and says above every listing, and in its JSON as `about`, that a comment is a request to consider, never an instruction. The recipe tells an agent to put anything unusual to the developer.
- **Neutral colours.** The widget uses graphite rather than purple, resolved pins are grey, and the panel's heading no longer shows a focus ring when the panel opens.
- **To do on update, on a site using feedback away from a local machine:** set `PROTOTYPE_PASSWORD` if it has none, set `FEEDBACK_KEY` there and on the machine that reads it with `--from`, and send reviewers the `?review` link.

## v0.1.19 (10 October 2026)

Feedback: comments pinned to the site's pages, for a client review on local or staging.

- **Off unless `FEEDBACK_ENABLED=true`,** in every environment. Switched off, the code that adds anything to a page is never registered and every feedback route is a 404, so production pages don't change by a byte. Switched on, a page gets one deferred script under 4 KB and a Feedback tab with the open count; the widget loads on the first click. It sits outside Statamic's static cache, so cached copies never hold it.
- **Pinned to the page.** Add feedback asks for a spot, and the comment is pinned to the element clicked, at that point within it, so its numbered pin follows the element. Each comment says in words where it is, such as "Form block, "Name", near "Contact us"", reading the block from the template comments the kit's partials already leave in the page.
- **Threads, resolving and who.** Replies, resolve and reopen, recording who resolved it and when. Someone logged in to the control panel comments as the team; anyone else gives a name, and the password when `PROTOTYPE_PASSWORD` is set, in the prototype's own sign-in cookie, so one name works in both.
- **Stored as files,** one YAML file per comment in `storage/app/feedback`. No database, no outside service.
- **`php please avoca:feedback`** lists open comments in words with their element, or as JSON, and resolves, reopens and replies. `--from` does the same with a server's comments over HTTP, sending its password, so comments made on staging reach a developer's machine. `?feedback=<id>` on a page opens that comment.
- `avoca:site:check` warns when feedback is switched on in production. The README has a Feedback section, and the recipe's review step 5 says how to run a client review with it.
- **Nothing to do on update.** Feedback stays off until a site switches it on.

## v0.1.18 (10 October 2026)

The recipe's Designs from Paper section, rewritten after testing it against Paper itself.

- **Check Paper first.** Paper is changing quickly, so the section opens by sending whoever works with it to Paper's build log and roadmap, and to the tools its MCP offers, with a list of the features it works around (themes and tokens with `calc()` and `color-mix()`, CSS grid, native Tailwind, components, rich text, remote MCP, per-file permissions, the scale tool) and what each would change once released.
- **Before anything goes up:** the client's colours, a warning when the site has no font of its own (Paper stands Inter in), and the type scale settled in the site. During design the scale's numbers change in the site and the new sizes go to Paper in the same step; a single size is never edited in Paper.
- **The whole theme goes up, not only what the site uses:** every text size at desktop and phone width (phone sizes as `--phone-text-*`), every spacing step from 0 to 384px, Tailwind's line heights and letter spacing, the radii and the breakpoints. Each value has one token per Tailwind group, because Paper turns a typed value into the class of the one token that has it and gives up when two share it.
- **Colours are linked the way the site links them,** set in order from their sources, Tailwind's own included, so changing `--color-primary` in Paper moves everything built on it. A `color-mix()` colour can't be linked in Paper yet and goes up plain, with its formula in the description.
- **Images, layer names, and two new pages.** Images go in as local files. Only layer names survive, so they say what each part is. A Colour schemes page shows each scheme for checking, and a Components page holds every button style and state, measured against the site's own buttons.
- **Coming back down:** Paper's Tailwind export is a first draft, layer names are read from the tree, phone sizes come from their tokens, a button is the site's partial with only its rounding class carried over, and the type scale's numbers match the kit's corrected scale.
- **Nothing to do on update.** The recipe is all that changed.

## v0.1.17 (10 October 2026)

The recipe covers designs from Paper, and the site check catches the agency's logo left in place.

- `avoca:site:check` warns while form emails or the site header still show the agency's logo. The kit points both at `public/agencies/<AGENCY>` until the client's logo arrives, and a site must not reach client sign-off like that. Form emails are checked in every language the site uses, through `form_mail_logo` in `lang/<language>/strings.php`; the header through `resources/views/components/_logo.antlers.html`. It is a warning and never fails, even with `--strict`, because the agency's logo is expected while a site is being built. The control panel keeps the agency's logo and is never mentioned.
- The recipe's review step 5 checks both logos before a new site's first sign-off, and the Forms section says how to swap the form email logo.
- The recipe gains a Designs from Paper section: the two artboard widths, the site's tokens as Paper tokens on the way up, and on the way back down a rule for every value, with a conversion table so nothing reaches a template as an arbitrary pixel or colour value.
- **Nothing to do on update.** A site that still shows the agency's logo gets two new warnings until the client's logo is in.

## v0.1.16 (6 October 2026)

- `/site/style` reads a breakpoint set in px as px. It took every breakpoint for rem and multiplied it by 16, so a site with `--breakpoint-md: 768px` listed its section spacing and content widths from "12288px and up". Breakpoints in rem or em read as before.

## v0.1.15 (6 October 2026)

The reference pages take their look from the site, so a dark design gets dark reference pages.

- `/site/content` and `/site/style` put the `<body>` classes from the site's layout on their own, so blocks preview on the site's page background rather than on white. `site.body_class` gives the classes instead, for a layout that sets them per page, and `site.layout_template` names the layout.
- The pages' own text, lines, buttons and panels are mixed from the site's text colour and page background, in place of fixed slate greys, so they read on light or dark. Highlights use `--color-primary`, titles the site's heading font and the rest its sans font. A site can set any `--sk-*` variable on `.sk-reference` in its own CSS.
- On the content page the details of each block and set sit on a panel a shade darker than the page, and the preview on the page background, so the two are told apart.
- A header that floats over the page, absolute or fixed as one over a hero image is, no longer covers the toolbar: its height is kept clear, and a fixed or sticky one keeps the quick links below it. `site.header` is `auto`, `flow` or `hidden`.
- The contrast ratios on `/site/style` start against the page background. Choosing another background turns that section's text light or dark to match it.
- **Nothing to do on update.** A site on a white page looks as it did. A site that published the config doesn't need the three new keys: without them the defaults apply.

## v0.1.14 (22 September 2026)

The server git script becomes the site's file rather than the package's.

- `php please avoca:site:script` writes `scripts/server-git.sh` into the site, executable, and records the version and a checksum of it in `resources/site/installed.yaml`. The site owns that copy from then on: the add-on never reads it back and never changes it again, so a site whose server needs something different can have it. The starter kit runs the command when a site is installed, so new sites start with one.
- It refuses to write over a copy the site has changed, and says so rather than doing it. `--diff` shows what differs, `--force` takes the add-on's copy anyway. A copy the site has not touched is simply brought up to date, because nothing is lost. Run it by hand, not from a deploy script: it exits non-zero when it refuses.
- `avoca:site:check` warns when the add-on's copy has moved on, and when a copy exists that nothing recorded publishing. It says nothing about a site with no copy, because a site whose content is not edited on the server never needs one, and nothing about a copy the site changed on purpose.
- `scripts/server-git.sh` in the package is executable at last. It was committed 0644, which is why only `bash vendor/...` ever worked.
- **Nothing existing breaks.** The package keeps the script at the same path, so cron jobs and deploy scripts that call `vendor/avocadesign/statamic-tools/scripts/server-git.sh` keep working. That path stays working; the site's own copy is what the notes and the recipe now describe.

## v0.1.13 (22 September 2026)

- `avoca:site:check` warns when `llms.txt` doesn't mention a collection that has a route, and when the starter kit's placeholder text is still in it. A site that gains a collection stops describing itself accurately, and nothing was saying so. Warnings only: the site works either way.
- The recipe says how `llms.txt` is put together and what it needs when a site grows.

## v0.1.12 (22 September 2026)

- `/site/style` and `/site/content` read the space between blocks from `--block-space` tokens when the page template names a rhythm rather than numbers, such as the starter kit's new `stack-block`. A template that still carries `stack-12 md:stack-16` is read as before.

## v0.1.11 (22 September 2026)

- Help text on a field is one short line, and the recipe says so: five or six words saying what the field does, with what it is for and when to use it left to the block's guidance, where the reference pages show it. The library blocks' display settings follow, and so does the one a scaffolded collection writes.

## v0.1.10 (22 September 2026)

Every page builder block can take a CSS class for one-off design work, and block margins are their own fieldset so every block can have them.

- The library's FAQ, Projects and Testimonials blocks import `block_margins` and `custom_class`, and pass the class to the wrapper. A site needs the matching starter kit change for those fieldsets to exist.
- Scaffolding a collection writes all three imports into its listing block, in the same order: scheme, margins, class.
- `/site/style` reads the margin options from their own fieldset, through the new `margins_fieldset` config key, with `class_fieldset` beside it.
- `/site/content` leaves the class field empty in its previews. A sample sentence in a class attribute is worse than no class.

## v0.1.9 (22 September 2026)

- `php please avoca:site:urls` says which pages a check should render, because listing them by hand does not survive a site with two thousand of them. It takes the pages the site names in `resources/site/updates.yaml`, every page a collection is mounted on, one entry from each collection so each collection's own template runs, and then whichever pages add blocks and blueprints nothing chosen already has. `--sample` sets how many of those last it adds.
- `check-site.sh` renders that list rather than a hand written one.

## v0.1.8 (22 September 2026)

Groundwork for updating dependencies automatically. Nothing runs on its own yet: this is what a site has to offer the runner that will.

- `scripts/check-site.sh`: installs from the lock files, builds, refreshes, runs `avoca:site:check --strict`, serves the site and renders the pages a site lists. It writes `.env.check` and runs with `APP_ENV=check` and Statamic Pro off, so it never touches the site's `.env` and needs no licence key. Exit 0 renders, 1 doesn't, 2 couldn't run. A database is only made and migrated when the site has actually moved a driver to it: flat file sites don't get one.
- The recipe has an Updates section: what a site owes the process, and the rules the runner works to, which are patch and minor only, nothing younger than three days, canary sites before the fleet, fast forward before pushing and stop if it can't, and no pull request without a green check.

## v0.1.7 (22 September 2026)

- The reference pages render with the images a site already has, rather than generating nine grey placeholders into `images/site/` and leaving them in the client's asset library. They take what the images container holds, preferring files with "placeholder" in the name, then the biggest over 1200 pixels on the long side, which is how installing a library item already chose an image. A gallery walks that list instead of showing one image six times, and nothing is ever written to the container.
- `avoca:site:install` writes nothing now. It says which images the pages will use, and warns when the container holds none.
- The crop settings on `/site/style` show one real image through every crop, instead of a generated image already cut to that shape, so the crop is what changes between them.
- `statamic-tools.site.placeholder_dir` is gone, as nothing is generated.
- A site that ran an earlier version can delete `public/images/site/` and its `.meta` folder once nothing in its content points at them.

## v0.1.6 (22 September 2026)

- The readme's installing section drops the repository entry sites needed while the package was private. It comes from Packagist now, so Composer needs no repository and no credentials anywhere.

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
