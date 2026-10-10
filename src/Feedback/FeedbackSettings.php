<?php

namespace Avocadesign\StatamicTools\Feedback;

/**
 * Whether feedback is on. FEEDBACK_ENABLED switches it on, but anywhere other than a local machine it also needs
 * PROTOTYPE_PASSWORD: without one, anybody who found the site could read and write its comments, so it stays off.
 */
final class FeedbackSettings
{
    public static function requested(): bool
    {
        return (bool) config('statamic-tools.feedback.enabled');
    }

    public static function active(): bool
    {
        return self::requested() && (app()->isLocal() || self::hasPassword());
    }

    /** Switched on, but held off because the server has no password. */
    public static function heldForPassword(): bool
    {
        return self::requested() && ! self::active();
    }

    public static function hasPassword(): bool
    {
        return (string) config('statamic-tools.feedback.password') !== '';
    }

    /** The developer's key for the command's routes: separate from the password reviewers are given. */
    public static function key(): string
    {
        return (string) config('statamic-tools.feedback.key');
    }
}
