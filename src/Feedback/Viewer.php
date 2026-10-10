<?php

namespace Avocadesign\StatamicTools\Feedback;

use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who is commenting. Someone logged in to the control panel is the team and is known by their Statamic name. Anyone
 * else gives a name the first time, and the password too when PROTOTYPE_PASSWORD is set, and a cookie remembers them
 * for 30 days. It is the cookie the prototype's sign-in sets, in the same form, so a name given in either place works
 * in both, and changing the password signs everyone out of both.
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
        if (! is_array($cookie) || ! is_string($cookie['name'] ?? null) || trim($cookie['name']) === '') {
            return null;
        }

        $password = self::password();
        if ($password !== '' && ! hash_equals(self::key($password), (string) ($cookie['key'] ?? ''))) {
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

    public static function signIn(string $name): Cookie
    {
        return cookie(self::cookieName(), json_encode(['name' => trim($name), 'key' => self::key(self::password())]), self::REMEMBER);
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
