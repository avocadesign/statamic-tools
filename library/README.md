# Avoca library

Ready-made blocks, text editor sets and presets that any Avoca site can install. Add an item
when a block, set or collection has proved itself on a site and is worth reusing.

    php please avoca:library
    php please avoca:library:install {handle} --dry-run
    php please avoca:library:install {handle}

## An item

    library/{category}/{handle}/
        item.yaml
        files/          mirrors the site root, and is copied into it
        screenshots/    {set handle}.jpeg, the control panel preview image for that block or set

The category folders are `blocks`, `sets` and `presets`. A preset holds more than one thing, such as a collection with
its blueprint, its listing block and sample entries.

~~~yaml
name: Team
version: 1.0.0
description: People from the People collection in a grid, choosing which people to show and in what order.
page_builder:
  - handle: team
    display: Team
    group: dynamic
    instructions: Show people from the People collection.
    icon: users
article_sets: []
permissions:
  editor:
    - view people entries
    - edit people entries
notes: |
  Anything the person installing it needs to do next.
~~~

- `page_builder` and `article_sets` each add a set that imports the fieldset named by `import`, or by `handle` when
  there is no `import`.
- `group` is required. A block with its own content fields goes in `content`. A block that calls in content from
  elsewhere, such as a form, collection entries or contact details, goes in `dynamic`. A group is matched by its key or its display name, and created when it doesn't exist.
- The block's guidance goes in `files/resources/site/blocks/{handle}.md`, in the site guidance format, so the catalogue
  and /site/content pick it up. A set's goes in `files/resources/site/sets/{handle}.md`.
- Sample entries don't bring their own photos, which the install would add to a live site. They mark each image
  instead, as Sample images describes.
- Raise `version` whenever the item changes, so sites can see an update is available.
- An item reserves its handles, as the Names section of the addon README describes: new work in a site can't take them without `--ignore-library`. Give an item handles that no block, set or fieldset in the starter kit uses.

## Sample images

Mark an image in a sample entry by giving its field the value `avoca:sample-image`, on its own line. A list item can be
a marker too:

~~~yaml
featured_image: 'avoca:sample-image'
images:
  - 'avoca:sample-image'
~~~

Installing a file under `content/` replaces every marker with one image the site already has in its images container,
the first of:

1. The largest image with `placeholder` in its file name, ignoring case, in any folder, such as the kit's
   `temp/placeholder-wepb-image.webp`.
2. The largest image at least 1200 pixels on its long side.
3. None: the field is left out of the entry.

The largest image is the one with the most pixels, and of two the same size, the first by path. An image's size comes
from Statamic's metadata, in the `.meta` folder beside it, or from the file when it has none. Only jpg, jpeg, png, gif,
webp and avif files count, hidden folders are skipped, and nothing is written to the container. The container is
`images`, unless `library.sample_images_container` in `config/statamic-tools.php` names another.

The dry run and the install output show, beside each file, the image its markers use, or the fields left out and why.
A file is compared as it would be written, with its image in place, so installing again reports it as the same, unless
the site's images have changed in between. A marker the installer can't replace, such as one inside `[ ]`, is an error,
and nothing is installed.

## What installing does

1. Copies `files/` into the site, with the sample images in `content/` in place.
2. Adds each block or set to its group in the page builder or article fieldset, with its screenshot as the preview image.
3. Copies the screenshots into the set preview images container.
4. Adds any permissions to roles that already exist.
5. Records the item and its version in `resources/site/installed.yaml`.
6. Refreshes Statamic's Stache in fresh processes, so new collections and entries show straight away.
7. Regenerates the catalogue.

It stops before writing anything when a step has an error, or when a file or fieldset entry already exists and differs.
`--force` replaces those. Installed files belong to the site from then on: updating the library changes no site, and
`avoca:library` shows when a newer version is available.
