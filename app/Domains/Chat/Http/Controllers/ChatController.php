<?php

namespace App\Domains\Chat\Http\Controllers;

use App\Domains\Chat\Events\MessageSent;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Files\Support\UploadLimits;
use App\Domains\Notifications\Notifications\NewChatMessageNotification;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $models = Conversation::forUser($user->id)
            ->with(['users:id,first_name,last_name', 'latestMessage.user:id,first_name,last_name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        $unreadMap = $this->unreadCountsFor($user, $models);

        $conversations = [];

        foreach ($models as $c) {
            $conversations[] = $this->formatConversation($c, $unreadMap[$c->id] ?? 0);
        }

        return Inertia::render('Chat', [
            'conversations' => $conversations,
        ]);
    }

    /**
     * Lightweight JSON endpoint for the chat dropdown.
     */
    public function conversationsJson(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => 'sometimes|in:all,unread,groups',
            'q' => 'sometimes|string|max:100',
        ]);

        $user = $request->user();
        $filter = $request->input('filter', 'all');

        $query = Conversation::forUser($user->id)
            ->with(['users:id,first_name,last_name', 'latestMessage.user:id,first_name,last_name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');

        if ($filter === 'groups') {
            $query->where('is_group', true);
        }

        if ($filter === 'unread') {
            // Push the unread condition into SQL so the LIMIT returns 15
            // *unread* conversations, not 15 total of which some may be read.
            // Deleted-sender messages (user_id IS NULL) still count as unread.
            $query->whereHas('users', function ($sub) use ($user) {
                $sub->where('user_id', $user->id)
                    ->whereExists(function ($m) use ($user) {
                        $m->select(DB::raw(1))
                            ->from('messages')
                            ->whereColumn('messages.conversation_id', 'conversations.id')
                            ->where(function ($inner) use ($user) {
                                $inner->where('messages.user_id', '!=', $user->id)
                                    ->orWhereNull('messages.user_id');
                            })
                            ->where(function ($q) {
                                $q->whereNull('conversation_user.last_read_at')
                                    ->orWhereColumn('messages.created_at', '>', 'conversation_user.last_read_at');
                            });
                    });
            });
        }

        if ($q = $request->input('q')) {
            // Exclude the requester from the match set — otherwise searching for
            // your own name would match every conversation you're in.
            $matchingUserIds = User::search($q)
                ->keys()
                ->reject(fn ($id) => (int) $id === $user->id)
                ->values();
            $query->whereHas('users', fn ($sub) => $sub
                ->where('user_id', '!=', $user->id)
                ->whereIn('user_id', $matchingUserIds)
            );
        }

        $models = $query->limit(15)->get();

        $unreadMap = $this->unreadCountsFor($user, $models);

        $conversations = [];

        foreach ($models as $c) {
            $unreadCount = $unreadMap[$c->id] ?? 0;
            $conversations[] = $this->formatConversation($c, $unreadCount);
        }

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * Build a conversation_id => unread_count map in a single query.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, int>
     */
    private function unreadCountsFor(User $user, Collection $conversations): array
    {
        if ($conversations->isEmpty()) {
            return [];
        }

        $ids = $conversations->pluck('id')->all();

        $rows = Message::query()
            ->selectRaw('messages.conversation_id, COUNT(*) AS unread')
            ->join('conversation_user', 'conversation_user.conversation_id', '=', 'messages.conversation_id')
            ->where('conversation_user.user_id', $user->id)
            // Deleted-sender rows (user_id IS NULL) still count as "not mine".
            ->where(function ($inner) use ($user): void {
                $inner->where('messages.user_id', '!=', $user->id)
                    ->orWhereNull('messages.user_id');
            })
            ->whereIn('messages.conversation_id', $ids)
            ->where(function ($q): void {
                $q->whereNull('conversation_user.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_user.last_read_at');
            })
            ->groupBy('messages.conversation_id')
            ->pluck('unread', 'messages.conversation_id');

        return $rows->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatConversation(Conversation $c, int $unreadCount): array
    {
        $latest = $c->latestMessage;

        return [
            'id' => $c->id,
            'title' => $c->title,
            'is_group' => $c->is_group,
            'participants' => $c->users->map(fn (User $u) => [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'avatar_thumb_url' => $u->avatarUrl('thumb'),
            ])->values()->all(),
            'latest_message' => $latest ? [
                'id' => $latest->id,
                'body' => $latest->body,
                'user_id' => $latest->user_id,
                'user' => $latest->user ? [
                    'id' => $latest->user->id,
                    'first_name' => $latest->user->first_name,
                ] : [
                    'id' => null,
                    'first_name' => __('chat.unknown_user'),
                ],
                'created_at' => $latest->created_at->toISOString(),
            ] : null,
            'unread_count' => $unreadCount,
            'last_message_at' => $c->last_message_at?->toISOString(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|integer|exists:'.config('chat.connection', 'pgsql').'.users,id',
            'title' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $connection = config('chat.connection', 'pgsql');

        $participantIds = collect($validated['user_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $user->id)
            ->unique()
            ->values();

        if ($participantIds->isEmpty()) {
            return response()->json(['message' => 'At least one other participant is required.'], 422);
        }

        // Drop banned/invalid users *and* enforce the same tenancy scope the
        // search endpoint uses — a non-admin must only be able to start
        // conversations with users from their shared workspaces.
        $allowedQuery = User::on($connection)
            ->notBanned()
            ->whereIn('id', $participantIds);

        if (! $user->isSuperAdmin()) {
            $workspaceIds = $user->workspaces()->pluck('workspaces.id');
            $allowedQuery->whereHas('workspaces', function ($q) use ($workspaceIds) {
                $q->whereIn('workspaces.id', $workspaceIds);
            });
        }

        $allowedIds = $allowedQuery->pluck('id');
        $participantIds = $participantIds->intersect($allowedIds)->values();

        if ($participantIds->isEmpty()) {
            return response()->json(['message' => 'At least one other participant is required.'], 422);
        }

        $isGroup = $participantIds->count() > 1;
        $title = $validated['title'] ?? null;
        $attachIds = $participantIds->push($user->id)->unique()->all();
        $otherUserId = $isGroup ? null : $participantIds->first();

        // Dedupe + create runs inside a single transaction so two concurrent
        // requests for the same direct-chat pair can't both miss and create
        // duplicate conversations. The lockForUpdate on the dedupe read
        // serializes the two requests against the pivot table.
        [$conversation, $existed] = DB::connection($connection)->transaction(function () use ($title, $isGroup, $user, $attachIds, $otherUserId) {
            if ($otherUserId !== null) {
                $existing = Conversation::where('is_group', false)
                    ->whereHas('users', fn (Builder $q) => $q->where('user_id', $user->id))
                    ->whereHas('users', fn (Builder $q) => $q->where('user_id', $otherUserId))
                    ->withCount('users')
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (Conversation $c) => $c->users_count === 2);

                if ($existing) {
                    return [$existing, true];
                }
            }

            $created = Conversation::create([
                'title' => $title,
                'is_group' => $isGroup,
                'created_by' => $user->id,
            ]);
            $created->users()->attach($attachIds);

            return [$created, false];
        });

        return response()->json([
            'conversation' => ['id' => $conversation->id],
            'existing' => $existed,
        ], $existed ? 200 : 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $conversation->isParticipant($request->user())) {
            abort(403);
        }

        $messages = $conversation->messages()
            ->with(['user:id,first_name,last_name', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(50);

        $items = [];

        /** @var Message $m */
        foreach ($messages->items() as $m) {
            $items[] = $this->formatMessage($m);
        }

        $participants = [];

        /** @var User $u */
        foreach ($conversation->users as $u) {
            $participants[] = [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'avatar_thumb_url' => $u->avatarUrl('thumb'),
            ];
        }

        return response()->json([
            'messages' => $items,
            'next_cursor' => $messages->nextCursor()?->encode(),
            'has_more' => $messages->hasMorePages(),
            'participants' => $participants,
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $conversation->isParticipant($request->user())) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => 'nullable|string|max:5000',
            'attachments' => 'sometimes|array|max:10',
            'attachments.*' => [
                'file',
                // Per-file cap from /admin/settings (clamped to the PHP ceiling).
                'max:'.UploadLimits::chatMaxUploadKilobytes(),
                // Denylist server-interpreted / executable extensions. Files
                // still pass through the download route which forces
                // Content-Disposition: attachment, so even HTML/SVG/JS can't
                // execute in the browser — but active server extensions must
                // be blocked outright in case of a misconfigured web root.
                function (string $attribute, $value, \Closure $fail): void {
                    $blocked = [
                        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
                        'exe', 'bat', 'cmd', 'sh', 'ps1', 'msi', 'dll',
                        'jsp', 'asp', 'aspx', 'cgi',
                    ];
                    if (! $value instanceof UploadedFile) {
                        return;
                    }
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (in_array($ext, $blocked, true)) {
                        $fail(__('chat.attachment_blocked_type', ['ext' => $ext]));
                    }
                },
            ],
        ]);

        $hasFiles = $request->hasFile('attachments');
        $hasBody = isset($validated['body']) && trim((string) $validated['body']) !== '';

        if (! $hasBody && ! $hasFiles) {
            abort(422, 'Message must include text or at least one attachment.');
        }

        /** @var Message $message */
        $message = $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'] ?? '',
        ]);

        // Save attachments before touching conversation metadata so a failed
        // upload doesn't leave a stale last_message_at / last_read_at bump
        // visible to participants. If attachment storage throws, delete the
        // message row we just created and re-throw.
        if ($hasFiles) {
            try {
                /** @var UploadedFile $file */
                foreach ($request->file('attachments', []) as $file) {
                    $message->addMedia($file)->toMediaCollection('attachments');
                }
                $message->load('media');
            } catch (\Throwable $e) {
                $message->delete();

                throw $e;
            }
        }

        // Now that the message (and its attachments, if any) are durably
        // persisted, wrap the metadata bumps in a transaction on the chat
        // connection so they commit atomically.
        DB::connection($conversation->getConnectionName())->transaction(function () use ($conversation, $request) {
            $conversation->update(['last_message_at' => now()]);
            $conversation->users()->updateExistingPivot($request->user()->id, [
                'last_read_at' => now(),
            ]);
        });

        $message->load('user:id,first_name,last_name');

        broadcast(new MessageSent($message, $request->user()))->toOthers();

        // Notification delivery must not fail the already-sent message — the
        // client would retry and duplicate. Log and swallow instead.
        $sender = $request->user();
        $recipients = $conversation->users()
            ->where('user_id', '!=', $sender->id)
            ->get();

        if ($recipients->isNotEmpty()) {
            try {
                Notification::send($recipients, new NewChatMessageNotification($message, $sender));
            } catch (\Throwable $e) {
                Log::warning('Chat message notification delivery failed.', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'recipient_count' => $recipients->count(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $this->formatMessage($message),
        ], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $conversation->isParticipant($request->user())) {
            abort(403);
        }

        $conversation->users()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        // Reading the conversation also clears its chat notifications from the
        // bell, so the unread badge can't linger after the messages are read.
        $request->user()->unreadNotifications()
            ->where('type', NewChatMessageNotification::class)
            ->where('data->conversation_id', $conversation->id)
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Mark every conversation the user participates in as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();

        DB::connection(config('chat.connection', 'pgsql'))
            ->table('conversation_user')
            ->where('user_id', $user->id)
            ->update(['last_read_at' => $now, 'updated_at' => $now]);

        // Clear all chat notifications from the bell to match.
        $user->unreadNotifications()
            ->where('type', NewChatMessageNotification::class)
            ->update(['read_at' => $now]);

        return response()->json(['ok' => true]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $query = $request->input('q');
        $user = $request->user();
        // Escape LIKE/ILIKE wildcards so % and _ don't leak raw user input into the pattern.
        $escapedQuery = addcslashes($query, '%_\\');

        $connection = config('chat.connection', 'pgsql');
        $driver = DB::connection($connection)->getDriverName();
        $op = $driver === 'pgsql' ? 'ilike' : 'like';

        $usersQuery = User::on($connection)
            ->where('id', '!=', $user->id)
            ->notBanned()
            ->where(function ($q) use ($escapedQuery, $op) {
                $q->where('first_name', $op, "%{$escapedQuery}%")
                    ->orWhere('last_name', $op, "%{$escapedQuery}%");
            });

        // When tenancy is enabled and user is not admin, limit to users
        // who share at least one workspace (company) with the searcher.
        if (! $user->isSuperAdmin()) {
            $workspaceIds = $user->workspaces()->pluck('workspaces.id');
            $usersQuery->whereHas('workspaces', function ($q) use ($workspaceIds) {
                $q->whereIn('workspaces.id', $workspaceIds);
            });
        }

        $users = $usersQuery
            ->limit(10)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'full_name' => $u->fullName(),
                'avatar_thumb_url' => $u->avatarUrl('thumb'),
            ]);

        return response()->json(['users' => $users]);
    }

    /**
     * Stream a chat attachment to the requesting user, forcing
     * Content-Disposition: attachment so the browser always downloads the
     * original file (even HTML/SVG/JS) instead of rendering it inline.
     * Access is gated on conversation participation.
     */
    public function downloadAttachment(Request $request, Conversation $conversation, Media $media): BinaryFileResponse
    {
        if (! $conversation->isParticipant($request->user())) {
            abort(403);
        }

        // Guard against media rows attached to other models or other
        // conversations — a participant of conversation A must not be
        // able to download attachments from conversation B by guessing IDs.
        if (
            $media->collection_name !== 'attachments'
            || $media->model_type !== (new Message)->getMorphClass()
        ) {
            abort(404);
        }

        /** @var Message|null $message */
        $message = Message::query()->find($media->model_id);
        if (! $message || $message->conversation_id !== $conversation->id) {
            abort(404);
        }

        return response()->download(
            $media->getPath(),
            $media->file_name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessage(Message $message): array
    {
        $attachments = $message->getMedia('attachments')->map(function (Media $m) use ($message) {
            $isImage = str_starts_with((string) $m->mime_type, 'image/');

            return [
                'id' => $m->id,
                'name' => $m->file_name,
                'size' => $m->size,
                'mime_type' => $m->mime_type,
                'is_image' => $isImage,
                // `url` is for inline preview (image thumbs open-in-tab).
                // `download_url` always forces a save-as through the
                // authenticated download route, regardless of file type.
                'url' => $m->getUrl(),
                'thumb_url' => $isImage && $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : null,
                'download_url' => route('chat.conversations.attachments.download', [
                    'conversation' => $message->conversation_id,
                    'media' => $m->id,
                ]),
            ];
        })->values()->all();

        $sender = $message->user;

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'user_id' => $message->user_id,
            'user' => $sender ? [
                'id' => $sender->id,
                'first_name' => $sender->first_name,
                'last_name' => $sender->last_name,
                'avatar_thumb_url' => $sender->avatarUrl('thumb'),
            ] : [
                'id' => null,
                'first_name' => __('chat.unknown_user'),
                'last_name' => '',
                'avatar_thumb_url' => null,
            ],
            'body' => $message->body,
            'type' => $message->type,
            'attachments' => $attachments,
            'created_at' => $message->created_at->toISOString(),
        ];
    }
}
