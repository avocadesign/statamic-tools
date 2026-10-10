<?php

namespace Avocadesign\StatamicTools\Feedback;

use Statamic\Facades\YAML;

/**
 * The people who may comment, when the site lists them in resources/site/reviewers.yaml. With a list, signing in asks
 * for an email on it, and the name shown is the list's, not one typed in. Without one, anyone with the password gives
 * any name. The email is not verified, so the password still decides who gets in; the list decides who they can be.
 *
 *     reviewers:
 *       - name: Jane Smith
 *         email: jane@example.com
 */
final class Reviewers
{
    public static function path(): string
    {
        $path = (string) config('statamic-tools.feedback.reviewers_path', 'resources/site/reviewers.yaml');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @return array<int, array{name: string, email: string}> */
    public static function all(): array
    {
        $path = self::path();
        if (! is_file($path)) {
            return [];
        }

        $data = YAML::parse((string) file_get_contents($path));
        $people = [];
        foreach ((array) ($data['reviewers'] ?? []) as $person) {
            $name = trim((string) ($person['name'] ?? ''));
            $email = self::normalise((string) ($person['email'] ?? ''));
            if ($name !== '' && $email !== '') {
                $people[] = ['name' => $name, 'email' => $email];
            }
        }

        return $people;
    }

    public static function listed(): bool
    {
        return self::all() !== [];
    }

    /** @return array{name: string, email: string}|null */
    public static function find(string $email): ?array
    {
        $email = self::normalise($email);
        foreach (self::all() as $person) {
            if (hash_equals($person['email'], $email)) {
                return $person;
            }
        }

        return null;
    }

    private static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
