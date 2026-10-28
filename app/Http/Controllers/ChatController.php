<?php

namespace App\Http\Controllers;

use App\Events\NewChatMessage;
use App\Models\AuditEvent;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Services\Community\ProjectActionPolicy;
use App\Services\Discussion\Markup;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function lounge(): View
    {
        return view('chat.lounge', [
            'messages' => $this->recent(ChatMessage::loungeRoom()),
            'room' => ChatMessage::loungeRoom(),
            'roomLabel' => 'Community lounge',
            'project' => null,
            'denial' => auth()->check() ? null : 'Sign in to join the chat.',
        ]);
    }

    public function show(Project $project): View
    {
        Gate::authorize('view', $project);

        $room = ChatMessage::projectRoom($project);

        $this->markRead($project, $room, (int) (ChatMessage::where('room', $room)->max('id') ?? 0));

        return view('chat.room', [
            'project' => $project,
            'messages' => $this->recent($room),
            'room' => $room,
            'roomLabel' => $project->title.' chat',
            'denial' => ProjectActionPolicy::discussionDenial($project, auth()->user()),
        ]);
    }

    public function loungeHistory(Request $request): JsonResponse
    {
        return $this->historyPayload($request, ChatMessage::loungeRoom());
    }

    public function history(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->historyPayload($request, ChatMessage::projectRoom($project));
    }

    public function loungeSend(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validatedMessage($request);

        $message = $this->persist(ChatMessage::loungeRoom(), null, $request->user()->id, $data);

        return $this->sentResponse($request, $message, route('lounge'));
    }

    public function send(Request $request, Project $project): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $project);

        if ($denial = ProjectActionPolicy::discussionDenial($project, $request->user())) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $denial], 422);
            }

            return back()->withInput()->withErrors(['body' => $denial]);
        }

        $data = $this->validatedMessage($request);

        $message = $this->persist(ChatMessage::projectRoom($project), $project, $request->user()->id, $data);

        return $this->sentResponse($request, $message, route('projects.chat.show', $project));
    }

    public function destroy(Request $request, Project $project, ChatMessage $message): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($message->project_id === $project->id, 404);

        $user = $request->user();
        $isAuthor = $message->user_id === $user->id;
        abort_unless($isAuthor || $project->managedBy($user), 403);

        $message->delete();

        AuditEvent::record($isAuthor ? 'chat.removed' : 'chat.moderated', $user, $project, $message);

        return back()->with('status', 'Message removed.');
    }

    public function destroyLoungeMessage(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless($message->project_id === null, 404);
        abort_unless($message->user_id === $request->user()->id, 403, 'Only the author can remove a lounge message.');

        $message->delete();

        return back()->with('status', 'Message removed.');
    }

    public function read(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $data = $request->validate(['last_read_message_id' => ['required', 'integer', 'min:0']]);

        $this->markRead($project, ChatMessage::projectRoom($project), $data['last_read_message_id']);

        return response()->json(['ok' => true]);
    }

    public function loungeRead(Request $request): JsonResponse
    {
        $data = $request->validate(['last_read_message_id' => ['required', 'integer', 'min:0']]);

        DB::table('chat_reads')->upsert([
            'user_id' => $request->user()->id,
            'room' => ChatMessage::loungeRoom(),
            'last_read_message_id' => $data['last_read_message_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ], ['user_id', 'room'], ['last_read_message_id', 'updated_at']);

        return response()->json(['ok' => true]);
    }

    /**
     * Rooms for the chat launcher with unread counts: the lounge plus the
     * signed-in member's projects (managed, followed, or applied to).
     */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();

        $projectIds = Project::published()
            ->where(fn ($query) => $query
                ->where('owner_id', $user->id)
                ->orWhereHas('members', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
                ->orWhereHas('follows', fn ($query) => $query->where('user_id', $user->id))
                ->orWhereHas('offers', fn ($query) => $query->where('user_id', $user->id)))
            ->pluck('id');

        $rooms = collect([['room' => ChatMessage::loungeRoom(), 'label' => 'Community lounge', 'url' => route('lounge')]]);

        $projects = Project::whereIn('id', $projectIds)->orderBy('title')->take(12)->get();
        foreach ($projects as $project) {
            $rooms->push([
                'room' => ChatMessage::projectRoom($project),
                'label' => $project->title,
                'url' => route('projects.chat.show', $project),
            ]);
        }

        $reads = DB::table('chat_reads')
            ->where('user_id', $user->id)
            ->whereIn('room', $rooms->pluck('room'))
            ->pluck('last_read_message_id', 'room');

        $latest = ChatMessage::whereIn('room', $rooms->pluck('room'))
            ->selectRaw('room, MAX(id) as max_id')
            ->groupBy('room')
            ->pluck('max_id', 'room');

        $rooms = $rooms->map(fn (array $room): array => [
            ...$room,
            'unread' => ($latest[$room['room']] ?? 0) > ($reads[$room['room']] ?? 0),
            'latest_id' => $latest[$room['room']] ?? 0,
        ]);

        return response()->json(['rooms' => $rooms]);
    }

    /** @return Collection<int, ChatMessage> */
    private function recent(string $room)
    {
        return ChatMessage::with('author')
            ->where('room', $room)
            ->latest('id')
            ->take(50)
            ->get()
            ->reverse()
            ->values();
    }

    private function historyPayload(Request $request, string $room): JsonResponse
    {
        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = $data['limit'] ?? 50;

        $messages = ChatMessage::with('author')
            ->where('room', $room)
            ->when(isset($data['before_id']), fn ($query) => $query->where('id', '<', $data['before_id']))
            ->when(isset($data['after_id']), fn ($query) => $query->where('id', '>', $data['after_id']))
            ->orderBy('id', isset($data['before_id']) ? 'desc' : 'asc')
            ->take($limit)
            ->get();

        if (isset($data['before_id'])) {
            $messages = $messages->reverse()->values();
        }

        return response()->json([
            'messages' => $messages->map(fn (ChatMessage $message): array => $this->serialize($message)),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedMessage(Request $request): array
    {
        return $request->validate([
            'body' => ['required', 'string', 'max:2000', fn ($attr, $value, $fail): mixed => trim((string) $value) === '' ? $fail('Write something before sending.') : null],
            'client_key' => ['required', 'string', 'max:64'],
        ]);
    }

    private function persist(string $room, ?Project $project, int $userId, array $data): ChatMessage
    {
        // Retried sends (reconnect, double tap) resolve to the stored row.
        $existing = ChatMessage::where('client_key', $data['client_key'])->first();
        if ($existing !== null) {
            abort_unless($existing->room === $room && $existing->user_id === $userId, 422, 'That message key is already used elsewhere.');

            return $existing;
        }

        try {
            $message = ChatMessage::create([
                'room' => $room,
                'project_id' => $project?->id,
                'user_id' => $userId,
                'body' => trim($data['body']),
                'client_key' => $data['client_key'],
            ]);
        } catch (QueryException) {
            $message = ChatMessage::where('client_key', $data['client_key'])->firstOrFail();
            abort_unless($message->room === $room && $message->user_id === $userId, 422, 'That message key is already used elsewhere.');

            return $message;
        }

        $this->markRead($project, $room, $message->id, $userId);

        broadcast(new NewChatMessage($message))->toOthers();

        return $message;
    }

    private function sentResponse(Request $request, ChatMessage $message, string $fallback): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $this->serialize($message)], $message->wasRecentlyCreated ? 201 : 200);
        }

        return redirect($fallback.'#message-'.$message->id);
    }

    /** @return array<string, mixed> */
    private function serialize(ChatMessage $message): array
    {
        $message->loadMissing(['author', 'project']);

        return [
            'id' => $message->id,
            'room' => $message->room,
            'client_key' => $message->client_key,
            'body_html' => Markup::render($message->body),
            'author' => [
                'id' => $message->author->id,
                'name' => $message->author->name,
                'initials' => $message->author->initials(),
                'avatar_url' => $message->author->avatarUrl(),
                'profile_url' => $message->author->profileUrl(),
                'is_maintainer' => $message->project !== null && $message->project->managedBy($message->author),
            ],
            'created_at' => $message->created_at->toIso8601String(),
            'created_human' => $message->created_at->diffForHumans(),
        ];
    }

    private function markRead(?Project $project, string $room, int $messageId, ?int $userId = null): void
    {
        $userId ??= auth()->id();

        if ($userId === null || $messageId <= 0) {
            return;
        }

        DB::table('chat_reads')->upsert([
            'user_id' => $userId,
            'room' => $room,
            'last_read_message_id' => $messageId,
            'created_at' => now(),
            'updated_at' => now(),
        ], ['user_id', 'room'], ['last_read_message_id', 'updated_at']);
    }
}
