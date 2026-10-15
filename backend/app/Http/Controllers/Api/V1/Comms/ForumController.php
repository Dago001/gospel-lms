<?php

namespace App\Http\Controllers\Api\V1\Comms;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\DiscussionPost;
use App\Models\DiscussionThread;
use App\Notifications\ForumReplyNotice;
use App\Services\AuditLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ForumController extends Controller
{
    public const EDIT_WINDOW_MINUTES = 30;

    public function __construct(private HtmlSanitizer $html, private AuditLogger $audit) {}

    private function staff(Course $c): bool
    {
        return Gate::allows('manage', $c);
    }

    private function postRow(DiscussionPost $p, Course $c): array
    {
        return ['id' => $p->id, 'body' => $p->body, 'author' => ['id' => $p->author->id, 'name' => $p->author->name, 'is_staff' => $p->author->hasPermission('courses.manage-own') || $p->author->hasPermission('courses.manage-all')],
            'created_at' => $p->created_at->toIso8601String(), 'edited' => (bool) $p->edited_at];
    }

    public function index(Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);
        $p = DiscussionThread::where('course_id', $course->id)->with('author:id,name')->withCount('posts')->orderByDesc('pinned')->orderByDesc('last_post_at')->paginate(20);

        return response()->json(['data' => $p->getCollection()->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'author' => $t->author->name, 'pinned' => $t->pinned, 'locked' => $t->locked, 'replies' => $t->posts_count, 'last_post_at' => $t->last_post_at->toIso8601String()])->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);
        $d = $request->validate(['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000']]);
        $t = new DiscussionThread(['title' => $d['title'], 'last_post_at' => now()]);
        $t->course_id = $course->id;
        $t->user_id = $request->user()->id;
        $t->body = $this->html->clean($d['body']);
        $t->last_post_at = now();
        $t->save();

        return response()->json(['data' => ['id' => $t->id]], 201);
    }

    public function show(Request $request, DiscussionThread $thread): JsonResponse
    {
        $course = Course::findOrFail($thread->course_id);
        Gate::authorize('learn', $course);
        $posts = $thread->posts()->with('author')->orderBy('created_at')->paginate(30);

        return response()->json(['data' => ['id' => $thread->id, 'title' => $thread->title, 'body' => $thread->body, 'author' => $thread->author->name, 'pinned' => $thread->pinned, 'locked' => $thread->locked,
            'created_at' => $thread->created_at->toIso8601String(), 'posts' => $posts->getCollection()->map(fn ($p) => $this->postRow($p, $course))->values()],
            'meta' => ['current_page' => $posts->currentPage(), 'last_page' => $posts->lastPage(), 'total' => $posts->total()]]);
    }

    public function reply(Request $request, DiscussionThread $thread): JsonResponse
    {
        $course = Course::findOrFail($thread->course_id);
        Gate::authorize('learn', $course);
        if ($thread->locked && ! $this->staff($course)) {
            throw new DomainException('This discussion is locked.', 'thread_locked', 403);
        }
        $d = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $post = DB::transaction(function () use ($thread, $d, $request) {
            $p = new DiscussionPost;
            $p->thread_id = $thread->id;
            $p->user_id = $request->user()->id;
            $p->body = $this->html->clean($d['body']);
            $p->save();
            $thread->forceFill(['last_post_at' => now()])->save();

            return $p;
        });
        if ($thread->user_id !== $request->user()->id) {
            $thread->author->notify(new ForumReplyNotice($thread->title, $request->user()->name, $course->id, $thread->id));
        }

        return response()->json(['data' => ['id' => $post->id]], 201);
    }

    /** Authors may edit within a short window; staff may moderate at any time. */
    public function editPost(Request $request, DiscussionPost $post): JsonResponse
    {
        $course = Course::findOrFail($post->thread->course_id);
        Gate::authorize('learn', $course);
        $own = $post->user_id === $request->user()->id;
        if (! $this->staff($course) && (! $own || $post->created_at->lt(now()->subMinutes(self::EDIT_WINDOW_MINUTES)) || $post->thread->locked)) {
            throw new DomainException('You can no longer edit this post.', 'edit_not_allowed', 403);
        }
        $d = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $post->forceFill(['body' => $this->html->clean($d['body']), 'edited_at' => now()])->save();

        return response()->json(['data' => ['id' => $post->id]]);
    }

    public function deletePost(Request $request, DiscussionPost $post): JsonResponse
    {
        $course = Course::findOrFail($post->thread->course_id);
        Gate::authorize('learn', $course);
        abort_unless($post->user_id === $request->user()->id || $this->staff($course), 403);
        if ($post->user_id !== $request->user()->id) {
            $this->audit->log('forum.post_removed', $post, ['author' => $post->user_id]);
        }
        $post->delete();

        return response()->json(['message' => 'Post removed.']);
    }

    public function moderate(Request $request, DiscussionThread $thread): JsonResponse
    {
        $course = Course::findOrFail($thread->course_id);
        abort_unless($this->staff($course), 403);
        $d = $request->validate(['pinned' => ['sometimes', 'boolean'], 'locked' => ['sometimes', 'boolean']]);
        $thread->update($d);
        $this->audit->log('forum.thread_moderated', $thread, new: $d);

        return response()->json(['data' => $thread->only(['pinned', 'locked'])]);
    }

    public function deleteThread(Request $request, DiscussionThread $thread): JsonResponse
    {
        $course = Course::findOrFail($thread->course_id);
        abort_unless($this->staff($course), 403);
        $this->audit->log('forum.thread_removed', $thread, ['author' => $thread->user_id]);
        $thread->delete();

        return response()->json(['message' => 'Thread removed.']);
    }
}
