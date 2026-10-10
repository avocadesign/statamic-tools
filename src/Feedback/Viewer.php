<?php

namespace Avocadesign\StatamicTools\Feedback;

use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who is commenting. Someone logged in to the control panel is the team and is known by their Statamic name. Anyone
 * else signs in: with the password when PROTOTYPE_PASSWORD is set, and with an email on resources/site/reviewers.yaml
 * when the site lists its reviewers, or else with any name. A cookie remembers them for 30 days. It is the cookie the
 * prototype's sign-in sets, in the same form, so a name given in either place works in both, and changing the password
 * signs everyone out of both. A listed reviewer is checked against the list on every request, so taking someone off it
 * signs them out.
 */
final class Viewer
{
    /** How long a browser stays signed in, in minutes: 30 days. */
    public const REMEMBER = 60 * 24 * 30;

    /** @return array{name: string, staff: bool}|null */
    public static function current(Request $request): ?array
    {
        if ($user = User::current()) {
            $name = trim((string) ($user->name() ?: $user->email()));

            return ['name' => $name, 'staff' => true];
        }

        $cookie = json_decode((string) $request->cookie(self::cookieName()), true);
        if (! is_array($cookie)) {
            return null;
        }

        $password = self::password();
        if ($password !== '' && ! hash_equals(self::key($password), (string) ($cookie['key'] ?? ''))) {
            return null;
        }

        if (Reviewers::listed()) {
            $person = is_string($cookie['email'] ?? null) ? Reviewers::find($cookie['email']) : null;

            return $person ? ['name' => $person['name'], 'staff' => $person['team']] : null;
        }

        if (! is_string($cookie['name'] ?? null) || trim($cookie['name']) === '') {
            return null;
        }

        return ['name' => trim($cookie['name']), 'staff' => false];
    }

    public static function needsPassword(): bool
    {
        return self::password() !== '';
    }

    public static function passwordMatches(string $given): bool
    {
        return self::needsPassword() && hash_equals(self::password(), $given);
    }

    public static function signIn(string $name, ?string $email = null): Cookie
    {
        $value = ['name' => trim($name), 'key' => self::key(self::password())];
        if ($email !== null) {
            $value['email'] = $email;
        }

        return cookie(self::cookieName(), json_encode($value), self::REMEMBER);
    }

    public static function signOut(): Cookie
    {
        return cookie()->forget(self::cookieName());
    }

    private static function cookieName(): string
    {
        return (string) config('statamic-tools.feedback.cookie', 'prototype');
    }

    private static function password(): string
    {
        return (string) config('statamic-tools.feedback.password');
    }

    /** Stands in for the password in the cookie, so a new password signs everyone out. */
    private static function key(string $password): string
    {
        return hash_hmac('sha256', $password, (string) config('app.key'));
    }
}
