<?php

namespace Avocadesign\StatamicTools\Permissions;

/**
 * The permissions the editor role gets for each type of structure. Each set is every permission Statamic registers for
 * that type in vendor/statamic/cms/src/Auth/CorePermissions.php (registerCollections, registerTaxonomies,
 * registerNavigation, registerGlobals and registerAssets), in the order the control panel lists them and with
 * Statamic's own placeholders. A collection gets the nine permissions the kit gives pages, other authors included.
 *
 * Each type is named after the folder in content/ that holds a structure's settings file, which is also how the
 * opt-outs file lists them.
 */
final class PermissionSets
{
    /** @var array<string, array<int, string>> type => permissions */
    public const PERMISSIONS = [
        'collections' => [
            'view {collection} entries',
            'edit {collection} entries',
            'create {collection} entries',
            'delete {collection} entries',
            'publish {collection} entries',
            'reorder {collection} entries',
            'edit other authors {collection} entries',
            'publish other authors {collection} entries',
            'delete other authors {collection} entries',
        ],
        'taxonomies' => [
            'view {taxonomy} terms',
            'edit {taxonomy} terms',
            'create {taxonomy} terms',
            'delete {taxonomy} terms',
        ],
        'navigation' => [
            'view {nav} nav',
            'edit {nav} nav',
        ],
        'globals' => [
            'edit {global} globals',
        ],
        'assets' => [
            'view {container} assets',
            'upload {container} assets',
            'edit {container} folders',
            'edit {container} assets',
            'move {container} assets',
            'rename {container} assets',
            'delete {container} assets',
        ],
    ];

    /** What one structure of each type is called. */
    public const NAMES = [
        'collections' => 'collection',
        'taxonomies' => 'taxonomy',
        'navigation' => 'navigation',
        'globals' => 'global set',
        'assets' => 'asset container',
    ];

    /** @return array<int, string> the permissions for one structure: "collections", "news" => view news entries and the rest */
    public static function for(string $type, string $handle): array
    {
        if (! isset(self::PERMISSIONS[$type])) {
            throw new \InvalidArgumentException("There is no permission set for {$type}. The types are ".implode(', ', array_keys(self::PERMISSIONS)).'.');
        }

        return array_map(fn (string $permission) => (string) preg_replace_callback('/\{\w+\}/', fn () => $handle, $permission), self::PERMISSIONS[$type]);
    }

    /** "collections", "news" => collection news */
    public static function name(string $type, string $handle): string
    {
        return (self::NAMES[$type] ?? $type)." {$handle}";
    }
}
