<?php

return [
    'site' => [
        // Reserved URL namespace: /site, /site/style, /site/content
        'prefix' => 'site',

        // On production the /site pages are only visible to a logged-in Statamic user.
        'protect_in_production' => true,

        // Entry point whose @import graph is scanned for @theme tokens.
        'css_entry' => 'resources/css/site.css',

        // Type-scale tokens listed by value only, not set as a specimen (they are display sizes).
        'type_scale_note_only' => ['text-8xl', 'text-9xl'],

        // Classes the typography section demonstrates (in addition to bare elements).
        'typography_classes' => ['lede', 'subheading', 'card-heading', 'caption', 'brand-text', 'heading-size-1', 'heading-size-2', 'heading-size-3', 'heading-size-4'],

        // Where blocks and sets are defined and rendered.
        'page_builder_fieldset' => 'page_builder',
        'blocks_view_path' => 'page_builder',
        'article_fieldset' => 'article',
        'article_field' => 'article',
        'sets_view_path' => 'components',
        'image_set' => 'image',
        // The page template whose outer element spaces the blocks on every page (its stack-* classes).
        'page_template' => 'default',

        // Fieldsets that hold the scheme and button options.
        'scheme_fieldset' => 'colour_scheme',
        'button_fieldset' => 'button',

        // Knowledge-base articles linked from the top of each tab on /site/content.
        'help' => [
            'page_builder' => ['label' => 'How the page builder works', 'url' => 'https://avoca.design/kb/the-page-builder-in-statamic'],
            'text_editor' => ['label' => 'How the text editor works', 'url' => 'https://avoca.design/kb/bard-editor-statamic'],
        ],

        // Guidance files: <docs_path>/blocks/<handle>.md and <docs_path>/sets/<handle>.md
        'docs_path' => 'resources/site',

        // The AI block catalogue the site commits: written by avoca:site:catalogue, checked by avoca:site:check.
        'catalogue_path' => 'resources/site/catalogue.md',

        // The role editors use. It is kept holding the permissions for every collection, taxonomy, navigation, global set
        // and asset container, so it works as soon as the site moves to Statamic Pro: see avoca:site:permissions.
        'editor_role' => 'editor',
        // Structures kept away from that role, as handles listed under collections, taxonomies, navigation, globals or assets.
        'editor_access_path' => 'resources/site/editor-access.yaml',

        // Option fields not to expand into per-block variants on /site/content.
        'variant_exclude' => [],
        // Repeating fields that sample a single item: a block's buttons read as one call to action.
        'sample_single' => ['buttons'],
        'margins_field' => 'block_margins', // its options render between two neighbouring blocks so the gap is visible

        // Folder inside the images container for generated placeholders.
        'placeholder_dir' => 'site',
    ],

    // Avoca's library of ready-made blocks, sets and presets. The addon's own library/ folder is always searched
    // first; add more folders here. Items are {category}/{handle}/item.yaml, described in library/README.md.
    'library' => [
        'paths' => [],
        // Where a site records the library items it installed, and their versions.
        'installed_path' => 'resources/site/installed.yaml',
        // The asset container sample images come from. A preset's sample entries mark each image 'avoca:sample-image',
        // and installing replaces the marker with an image this container already has, as library/README.md describes.
        'sample_images_container' => 'images',
    ],
];
