<?php

namespace Avocadesign\StatamicTools\Http\Controllers;

use Avocadesign\StatamicTools\Feedback\Reviewers;
use Avocadesign\StatamicTools\Feedback\Viewer;
use Avocadesign\StatamicTools\Prototype\Prototype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the prototype at /prototype, the default version, and /prototype/<version>. Signing in is the same as for
 * feedback, in the same cookie: the password when PROTOTYPE_PASSWORD is set, an email on the reviewers list when the
 * site keeps one, or nothing at all when it has neither.
 */
class PrototypeController extends Controller
{
    public function show(Request $request, ?string $version = null): Response
    {
        $current = $version === null ? Prototype::defaultVersion() : (Prototype::versions()[$version] ?? null);
        abort_unless($current, 404);

        $viewer = Viewer::current($request);
        if (self::needsSignIn() && ! $viewer) {
            return response()->view('statamic-tools::prototype.sign-in', [
                'version' => $version,
                'needsPassword' => Viewer::needsPassword(),
                'needsEmail' => Reviewers::listed(),
                'agency' => Prototype::agency(),
            ]);
        }

        return response(Prototype::page($current, $viewer), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    public function signIn(Request $request): RedirectResponse
    {
        $version = $request->input('version');
        $back = is_string($version) && isset(Prototype::versions()[$version]) ? "/prototype/{$version}" : '/prototype';

        if (! self::needsSignIn()) {
            return redirect($back);
        }

        $listed = Reviewers::listed();
        $input = $request->validate([
            'name' => [$listed ? 'nullable' : 'required', 'string', 'max:80'],
            'email' => [$listed ? 'required' : 'nullable', 'string', 'max:254'],
            'password' => [Viewer::needsPassword() ? 'required' : 'nullable', 'string'],
        ], [
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'password.required' => 'Enter the password.',
        ]);

        if (Viewer::needsPassword() && ! Viewer::passwordMatches((string) $input['password'])) {
            return redirect($back)->withErrors(['password' => 'That password isn’t right.'])->withInput($request->only('name', 'email'));
        }

        if ($listed) {
            $person = Reviewers::find((string) $input['email']);
            if (! $person) {
                return redirect($back)->withErrors(['email' => 'That email isn’t on the list of reviewers. Check it, or ask whoever sent you the link.'])->withInput($request->only('email'));
            }

            return redirect($back)->withCookie(Viewer::signIn($person['name'], $person['email']));
        }

        return redirect($back)->withCookie(Viewer::signIn((string) $input['name']));
    }

    public function signOut(): RedirectResponse
    {
        return redirect('/prototype')->withCookie(Viewer::signOut());
    }

    /** Signing in is needed with a password or a list of reviewers; with neither, the prototype is open. */
    private static function needsSignIn(): bool
    {
        return Viewer::needsPassword() || Reviewers::listed();
    }
}
