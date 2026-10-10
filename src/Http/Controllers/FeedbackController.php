<?php

namespace Avocadesign\StatamicTools\Http\Controllers;

use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Feedback\Viewer;
use Avocadesign\StatamicTools\Site\Blocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the feedback widget and the avoca:feedback command talk to. The widget signs people in and reads and writes
 * comments as them. The command sends the password instead of a cookie, from any machine, so a developer's Claude can
 * read and resolve a staging site's comments. Everything here is a 404 unless FEEDBACK_ENABLED is true.
 */
class FeedbackController extends Controller
{
    public function __construct(private FeedbackStore $store)
    {
    }

    /** The two scripts, from the package: the small loader on every page, and the widget it loads on the first click. */
    public function script(string $file): Response
    {
        abort_unless(in_array($file, ['loader', 'widget'], true), 404);

        return response()->file(self::scriptPath($file), [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function scriptPath(string $file): string
    {
        return __DIR__."/../../../resources/feedback/{$file}.js";
    }

    /** Who is viewing, whether a name or a password is still needed, and the token for writing. Never cached. */
    public function session(Request $request): JsonResponse
    {
        return $this->json([
            'viewer' => Viewer::current($request),
            'needs_password' => Viewer::needsPassword(),
            'token' => csrf_token(),
        ]);
    }

    public function signIn(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'password' => [Viewer::needsPassword() ? 'required' : 'nullable', 'string'],
        ], [
            'name.required' => 'Enter your name.',
            'password.required' => 'Enter the password.',
        ]);

        if (Viewer::needsPassword() && ! Viewer::passwordMatches((string) $input['password'])) {
            return $this->json(['errors' => ['password' => ['That password isn’t right.']]], 422);
        }

        return $this->json(['viewer' => ['name' => trim($input['name']), 'staff' => false]])
            ->withCookie(Viewer::signIn($input['name']));
    }

    public function signOut(): JsonResponse
    {
        return $this->json(['viewer' => null])->withCookie(Viewer::signOut());
    }

    /** The open count for one page, for the tab's badge. */
    public function count(Request $request): JsonResponse
    {
        return $this->json(['open' => count($this->store->all($this->path($request->query('url')), FeedbackStore::OPEN))]);
    }

    /** Comments on one page, or every page, in one state or both, each with where it was made in words. */
    public function index(Request $request): JsonResponse
    {
        $this->authorise($request);

        $url = $request->query('scope') === 'all' ? null : $this->path($request->query('url'));
        $status = in_array($request->query('status'), [FeedbackStore::OPEN, FeedbackStore::RESOLVED], true) ? $request->query('status') : null;
        $blocks = Blocks::pageBuilder();

        $comments = array_map(function (array $comment) use ($blocks) {
            $handle = $comment['anchor']['block'] ?? null;
            $comment['anchor']['block_name'] = is_string($handle) && isset($blocks[$handle]) ? $blocks[$handle]['display'] : null;

            return $comment;
        }, $this->store->all($url, $status));

        return $this->json(['comments' => $comments]);
    }

    public function store(Request $request): JsonResponse
    {
        $author = $this->authorise($request);
        $input = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
            'entry' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:5000'],
            'anchor' => ['nullable', 'array'],
            'anchor.selector' => ['nullable', 'string', 'max:1000'],
            'anchor.x' => ['nullable', 'numeric', 'between:0,1'],
            'anchor.y' => ['nullable', 'numeric', 'between:0,1'],
            'anchor.page_x' => ['nullable', 'numeric', 'between:0,1'],
            'anchor.page_y' => ['nullable', 'numeric', 'min:0'],
            'anchor.block' => ['nullable', 'string', 'max:100'],
            'anchor.label' => ['nullable', 'string', 'max:300'],
            'anchor.text' => ['nullable', 'string', 'max:300'],
            'viewport' => ['nullable', 'array'],
            'viewport.width' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'viewport.height' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'viewport.breakpoint' => ['nullable', 'string', 'max:10'],
        ], ['body.required' => 'Write a comment first.']);

        $input['url'] = $this->path($input['url']);

        return $this->json(['comment' => $this->store->create($input, $author)], 201);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $author = $this->authorise($request);
        $input = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Write a reply first.']);

        return $this->found($this->store->reply($id, $input['body'], $author));
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        return $this->found($this->store->resolve($id, $this->authorise($request)));
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $this->authorise($request);

        return $this->found($this->store->reopen($id));
    }

    /**
     * The person a request acts for: the signed-in viewer, or, for the command, whoever it names when it sends the
     * right password. Anyone else is turned away.
     *
     * @return array{name: string, staff: bool}
     */
    private function authorise(Request $request): array
    {
        $given = $request->header('X-Feedback-Password');
        // The command's routes skip the session token, so they take the password and nothing else.
        abort_if($request->routeIs('*feedback.api.*') && (! is_string($given) || $given === ''), 403, 'Send the password.');
        if (is_string($given) && $given !== '') {
            abort_unless(Viewer::passwordMatches($given), 403, 'That password isn’t right.');
            $name = trim((string) $request->header('X-Feedback-Name', 'Developer'));

            return ['name' => $name !== '' ? mb_substr($name, 0, 80) : 'Developer', 'staff' => true];
        }

        $viewer = Viewer::current($request);
        abort_unless($viewer !== null, 401, 'Sign in to comment.');

        return $viewer;
    }

    /** A page's path without its host or query, so the same page is the same page on any domain. */
    private function path(mixed $url): string
    {
        $path = parse_url(is_string($url) ? $url : '/', PHP_URL_PATH) ?: '/';

        return '/'.trim($path, '/');
    }

    private function found(?array $comment): JsonResponse
    {
        abort_unless($comment !== null, 404);

        return $this->json(['comment' => $comment]);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, private']);
    }
}
