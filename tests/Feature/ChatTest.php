<?php

use App\Domains\Chat\Events\MessageSent;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Notifications\Notifications\NewChatMessageNotification;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config()->set('chat.enabled', true);
    config()->set('chat.connection', config('database.default'));

    $this->workspace = createWorkspace();

    $this->alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Smith']);
    joinWorkspace($this->alice, $this->workspace);

    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Jones']);
    joinWorkspace($this->bob, $this->workspace);

    $this->charlie = User::factory()->create(['first_name' => 'Charlie', 'last_name' => 'Brown']);
    joinWorkspace($this->charlie, $this->workspace);
});

function chatUrl(string $path = ''): string
{
    return '/chat'.$path;
}

// ── Feature flag ────────────────────────────────────────────────

it('returns 404 when chat is disabled', function () {
    config()->set('chat.enabled', false);

    $this->actingAs($this->alice)
        ->get(chatUrl())
        ->assertNotFound();
});

it('renders the chat page when enabled', function () {
    $this->actingAs($this->alice)
        ->get(chatUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chat'));
});

// ── Creating conversations ──────────────────────────────────────

it('creates a 1:1 conversation', function () {
    $response = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);

    $response->assertCreated()
        ->assertJsonPath('existing', false);

    $conversation = Conversation::find($response->json('conversation.id'));
    expect($conversation)->not->toBeNull()
        ->and($conversation->is_group)->toBeFalse()
        ->and($conversation->users)->toHaveCount(2);
});

it('returns existing conversation for duplicate 1:1', function () {
    // Create first
    $first = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);

    // Try again
    $second = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);

    $second->assertOk()
        ->assertJsonPath('existing', true)
        ->assertJsonPath('conversation.id', $first->json('conversation.id'));

    expect(Conversation::count())->toBe(1);
});

it('creates a group conversation with multiple users', function () {
    $response = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id, $this->charlie->id],
        ]);

    $response->assertCreated();

    $conversation = Conversation::find($response->json('conversation.id'));
    expect($conversation->is_group)->toBeTrue()
        ->and($conversation->users)->toHaveCount(3); // alice + bob + charlie
});

it('rejects creating a conversation with invalid user ids', function () {
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [99999],
        ])
        ->assertUnprocessable();
});

it('requires authentication to create a conversation', function () {
    $this->postJson(chatUrl('/conversations'), [
        'user_ids' => [$this->bob->id],
    ])->assertUnauthorized();
});

// ── Sending messages ────────────────────────────────────────────

it('sends a message in a conversation', function () {
    Event::fake([MessageSent::class]);

    // Create conversation first
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $response = $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hello Bob!',
        ]);

    $response->assertCreated()
        ->assertJsonPath('message.body', 'Hello Bob!')
        ->assertJsonPath('message.user_id', $this->alice->id);

    expect(Message::count())->toBe(1);

    Event::assertDispatched(MessageSent::class, function ($event) {
        return $event->message->body === 'Hello Bob!'
            && $event->sender->id === $this->alice->id;
    });
});

it('marks every conversation as read via /chat/read-all', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    // Alice creates two conversations and Bob sends messages to both.
    $convA = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');
    $convB = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->charlie->id]])
        ->json('conversation.id');

    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convA}/messages"), ['body' => 'hi from bob']);
    $this->actingAs($this->charlie)
        ->postJson(chatUrl("/conversations/{$convB}/messages"), ['body' => 'hi from charlie']);

    expect($this->alice->fresh()->unreadMessagesCount())->toBe(2);

    $this->actingAs($this->alice)
        ->postJson(chatUrl('/read-all'))
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($this->alice->fresh()->unreadMessagesCount())->toBe(0);
});

it('accepts file attachments on a message', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();
    Storage::fake('public');

    $convId = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');

    $image = UploadedFile::fake()->image('snapshot.png', 120, 80);
    $doc = UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf');

    $response = $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'See attached',
            'attachments' => [$image, $doc],
        ]);

    $response->assertCreated()
        ->assertJsonPath('message.body', 'See attached')
        ->assertJsonCount(2, 'message.attachments');

    $message = Message::first();
    expect($message->getMedia('attachments'))->toHaveCount(2);
});

it('counts messages from a deleted sender as unread', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    // Bob sends a message, then is deleted (simulating the ON DELETE SET NULL).
    $convId = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');

    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), ['body' => 'ghost']);

    // Zero out the sender column to mimic a deleted user.
    Message::query()->update(['user_id' => null]);

    // Alice hasn't read yet — unread count should still include the orphan row.
    expect($this->alice->fresh()->unreadMessagesCount())->toBe(1);
});

it('excludes the requester from conversation search matches', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    // Alice + Bob in a direct chat.
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]]);

    // Alice searches for her own first name — the search predicate must not
    // match her own user row, so no conversations should come back.
    $response = $this->actingAs($this->alice)
        ->get(chatUrl('/conversations-list?q='.$this->alice->first_name))
        ->assertOk();

    $ids = collect($response->json('conversations'))->pluck('id');
    expect($ids)->toBeEmpty();
});

it('returns the existing direct conversation on a duplicate create', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    $first = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->assertCreated()
        ->json('conversation.id');

    $second = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->assertOk()
        ->assertJsonPath('existing', true)
        ->json('conversation.id');

    expect($second)->toBe($first);
});

it('rejects attachments with blocked executable extensions', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();
    Storage::fake('public');

    $convId = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');

    $evil = UploadedFile::fake()->create('backdoor.php', 4, 'application/x-php');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'try this',
            'attachments' => [$evil],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('attachments.0');
});

it('lets a conversation participant download an attachment', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();
    Storage::fake('public');

    $convId = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');

    $doc = UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf');
    $response = $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'pdf here',
            'attachments' => [$doc],
        ])
        ->assertCreated();

    $mediaId = $response->json('message.attachments.0.id');
    expect($mediaId)->not->toBeNull();

    // Recipient can download.
    $this->actingAs($this->bob)
        ->get(chatUrl("/conversations/{$convId}/attachments/{$mediaId}"))
        ->assertOk()
        ->assertDownload('notes.pdf');

    // Non-participant is forbidden.
    $this->actingAs($this->charlie)
        ->get(chatUrl("/conversations/{$convId}/attachments/{$mediaId}"))
        ->assertForbidden();
});

it('requires either a body or attachments', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    $convId = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [])
        ->assertStatus(422);
});

it('notifies other participants when a message is sent', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi Bob!',
        ])
        ->assertCreated();

    Notification::assertSentTo($this->bob, NewChatMessageNotification::class);
    Notification::assertNotSentTo($this->alice, NewChatMessageNotification::class);
});

it('does not persist chat notifications to the database inbox', function () {
    Event::fake([MessageSent::class]);

    $convId = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]])
        ->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi Bob!',
        ])
        ->assertCreated();

    // Chat messages should only fan out to broadcast (and optionally mail),
    // never to the database notification inbox.
    expect($this->bob->fresh()->notifications()->count())->toBe(0);
    expect($this->bob->fresh()->unreadNotifications()->count())->toBe(0);
});

it('respects the notification_chat_messages preference for recipients', function () {
    Event::fake([MessageSent::class]);
    Notification::fake();

    $this->bob->settings()->merge(['notification_chat_messages' => false]);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi Bob!',
        ])
        ->assertCreated();

    // Laravel's fake still records the Notification::send call; verify via() honours the opt-out.
    $notification = new NewChatMessageNotification(Message::first(), $this->alice);
    expect($notification->via($this->bob))->toBe([]);
});

it('updates last_message_at when sending a message', function () {
    Event::fake([MessageSent::class]);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi!',
        ]);

    $conversation = Conversation::find($convId);
    expect($conversation->last_message_at)->not->toBeNull();
});

it('prevents non-participants from sending messages', function () {
    // Alice creates a conversation with Bob
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    // Charlie is NOT a participant — should get 403
    $this->actingAs($this->charlie)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Snooping!',
        ])
        ->assertForbidden();
});

it('rejects empty messages', function () {
    Event::fake([MessageSent::class]);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => '',
        ])
        ->assertUnprocessable();
});

// ── Reading messages ────────────────────────────────────────────

it('returns paginated messages for a conversation', function () {
    Event::fake([MessageSent::class]);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    // Send a few messages
    foreach (['Hello', 'How are you?', 'Fine thanks'] as $body) {
        $this->actingAs($this->alice)
            ->postJson(chatUrl("/conversations/{$convId}/messages"), [
                'body' => $body,
            ]);
    }

    $response = $this->actingAs($this->alice)
        ->getJson(chatUrl("/conversations/{$convId}"));

    $response->assertOk()
        ->assertJsonCount(3, 'messages')
        ->assertJsonPath('has_more', false);
});

it('prevents non-participants from reading messages', function () {
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->charlie)
        ->getJson(chatUrl("/conversations/{$convId}"))
        ->assertForbidden();
});

// ── Mark as read ────────────────────────────────────────────────

it('marks a conversation as read', function () {
    Event::fake([MessageSent::class]);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    // Bob sends a message
    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hey Alice!',
        ]);

    // Alice has 1 unread
    $conversation = Conversation::find($convId);
    expect($conversation->unreadCountFor($this->alice))->toBe(1);

    // Alice marks as read
    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/read"))
        ->assertOk();

    expect($conversation->unreadCountFor($this->alice))->toBe(0);
});

// ── User search ─────────────────────────────────────────────────

it('searches users by name', function () {
    $response = $this->actingAs($this->alice)
        ->getJson(chatUrl('/users/search?q=Bob'));

    $response->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.first_name', 'Bob');
});

it('excludes the current user from search results', function () {
    $response = $this->actingAs($this->alice)
        ->getJson(chatUrl('/users/search?q=Alice'));

    $response->assertOk()
        ->assertJsonCount(0, 'users');
});

it('requires a search query', function () {
    $this->actingAs($this->alice)
        ->getJson(chatUrl('/users/search'))
        ->assertUnprocessable();
});

// ── Conversation list ───────────────────────────────────────────

it('lists conversations for the authenticated user', function () {
    Event::fake([MessageSent::class]);

    // Alice creates 2 conversations
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->charlie->id],
        ]);

    $response = $this->actingAs($this->alice)
        ->get(chatUrl());

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Chat')
            ->has('conversations', 2)
        );
});

it('does not show conversations the user is not part of', function () {
    // Alice creates a conversation with Bob
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);

    // Charlie should see 0 conversations
    $this->actingAs($this->charlie)
        ->get(chatUrl())
        ->assertInertia(fn ($page) => $page
            ->component('Chat')
            ->has('conversations', 0)
        );
});

// ── Unread count in shared props ────────────────────────────────

it('shares unread_messages_count via Inertia', function () {
    Event::fake([MessageSent::class]);

    // Create conversation and send a message from Bob to Alice
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Unread message!',
        ]);

    // Alice sees 1 unread in shared props
    $this->actingAs($this->alice)
        ->get(workspaceUrl($this->workspace, '/dashboard'))  // dashboard is still workspace-scoped
        ->assertInertia(fn ($page) => $page->where('auth.user.unread_messages_count', 1));
});

// ── Multi-user messaging flow ───────────────────────────────────

it('allows two users to exchange messages', function () {
    Event::fake([MessageSent::class]);

    // Create conversation
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    // Alice sends
    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi Bob!',
        ])
        ->assertCreated();

    // Bob sends
    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi Alice!',
        ])
        ->assertCreated();

    // Alice sends again
    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'How are you?',
        ])
        ->assertCreated();

    // Both see all 3 messages
    $aliceMessages = $this->actingAs($this->alice)
        ->getJson(chatUrl("/conversations/{$convId}"));
    $aliceMessages->assertJsonCount(3, 'messages');

    $bobMessages = $this->actingAs($this->bob)
        ->getJson(chatUrl("/conversations/{$convId}"));
    $bobMessages->assertJsonCount(3, 'messages');
});

it('tracks unread counts per user correctly', function () {
    Event::fake([MessageSent::class]);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    // Alice sends 3 messages with time gaps
    foreach (['Msg 1', 'Msg 2', 'Msg 3'] as $i => $body) {
        if ($i > 0) {
            $this->travel(1)->seconds();
        }
        $this->actingAs($this->alice)
            ->postJson(chatUrl("/conversations/{$convId}/messages"), [
                'body' => $body,
            ]);
    }

    // Bob has 3 unread
    $conversation = Conversation::find($convId);
    expect($conversation->unreadCountFor($this->bob))->toBe(3);

    // Alice has 0 unread (her own messages)
    expect($conversation->unreadCountFor($this->alice))->toBe(0);

    // Bob marks as read
    $this->travel(1)->seconds();
    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/read"));

    $conversation = Conversation::find($convId);
    expect($conversation->unreadCountFor($this->bob))->toBe(0);

    // Alice sends one more after Bob marked as read
    $this->travel(1)->seconds();
    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Msg 4',
        ]);

    $conversation = Conversation::find($convId);
    expect($conversation->unreadCountFor($this->bob))->toBe(1);
});

it('handles group messaging correctly', function () {
    Event::fake([MessageSent::class]);

    // Create group with all three
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id, $this->charlie->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    // Each person sends a message with time gaps so timestamps differ
    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hello group!',
        ]);

    $this->travel(1)->seconds();

    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hey everyone!',
        ]);

    $this->travel(1)->seconds();

    $this->actingAs($this->charlie)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Hi all!',
        ]);

    // All three see 3 messages
    foreach ([$this->alice, $this->bob, $this->charlie] as $user) {
        $this->actingAs($user)
            ->getJson(chatUrl("/conversations/{$convId}"))
            ->assertJsonCount(3, 'messages');
    }

    // Alice has 2 unread (from Bob and Charlie, sent after her last_read_at)
    $conversation = Conversation::find($convId);
    expect($conversation->unreadCountFor($this->alice))->toBe(2);
    // Bob has 1 unread (only Charlie's, since Bob's last_read_at was set when he sent)
    expect($conversation->unreadCountFor($this->bob))->toBe(1);
});

// ── Encryption ──────────────────────────────────────────────────

it('encrypts messages at rest when enabled', function () {
    Event::fake([MessageSent::class]);
    config()->set('chat.encryption_enabled', true);

    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), [
            'user_ids' => [$this->bob->id],
        ]);
    $convId = $convResponse->json('conversation.id');

    $this->actingAs($this->alice)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), [
            'body' => 'Secret message',
        ]);

    $message = Message::first();

    // The raw DB value should NOT be the plaintext
    $rawBody = DB::table('messages')
        ->where('id', $message->id)
        ->value('body');

    expect($rawBody)->not->toBe('Secret message')
        ->and($message->is_encrypted)->toBeTrue()
        // But the model accessor decrypts it
        ->and($message->body)->toBe('Secret message');
});

// ── Conversations list JSON endpoint (dropdown) ─────────────────

it('returns conversations as JSON for the dropdown', function () {
    Event::fake([MessageSent::class]);

    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]]);

    $response = $this->actingAs($this->alice)
        ->getJson('/chat/conversations-list');

    $response->assertOk()
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('conversations.0.participants.0.first_name', fn ($v) => in_array($v, ['Alice', 'Bob']));
});

it('filters conversations by unread', function () {
    Event::fake([MessageSent::class]);

    // Create a conversation and send a message from Bob
    $convResponse = $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]]);
    $convId = $convResponse->json('conversation.id');

    $this->travel(1)->seconds();

    $this->actingAs($this->bob)
        ->postJson(chatUrl("/conversations/{$convId}/messages"), ['body' => 'Hey!']);

    // Create another conversation with no messages
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->charlie->id]]);

    // filter=all → 2 conversations
    $this->actingAs($this->alice)
        ->getJson('/chat/conversations-list?filter=all')
        ->assertJsonCount(2, 'conversations');

    // filter=unread → only the one with Bob's message
    $this->actingAs($this->alice)
        ->getJson('/chat/conversations-list?filter=unread')
        ->assertJsonCount(1, 'conversations');
});

it('filters conversations by groups', function () {
    Event::fake([MessageSent::class]);

    // 1:1 conversation
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]]);

    // Group conversation
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id, $this->charlie->id]]);

    // filter=groups → only the group
    $this->actingAs($this->alice)
        ->getJson('/chat/conversations-list?filter=groups')
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('conversations.0.is_group', true);
});

it('searches conversations by participant name', function () {
    Event::fake([MessageSent::class]);

    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->bob->id]]);
    $this->actingAs($this->alice)
        ->postJson(chatUrl('/conversations'), ['user_ids' => [$this->charlie->id]]);

    // Search for Bob → only the conversation with Bob
    $response = $this->actingAs($this->alice)
        ->getJson('/chat/conversations-list?q=Bob');

    $response->assertOk()
        ->assertJsonCount(1, 'conversations');
});

it('requires auth for conversations-list', function () {
    $this->getJson('/chat/conversations-list')->assertUnauthorized();
});
