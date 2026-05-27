<?php

namespace App\Domains\Chat\Events;

use App\Domains\Chat\Models\Message;
use App\Domains\Users\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public User $sender,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $conversationId = $this->message->conversation_id;

        $attachments = $this->message->getMedia('attachments')->map(function ($m) use ($conversationId) {
            $isImage = str_starts_with((string) $m->mime_type, 'image/');

            return [
                'id' => $m->id,
                'name' => $m->file_name,
                'size' => $m->size,
                'mime_type' => $m->mime_type,
                'is_image' => $isImage,
                'url' => $m->getUrl(),
                'thumb_url' => $isImage && $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : null,
                'download_url' => route('chat.conversations.attachments.download', [
                    'conversation' => $conversationId,
                    'media' => $m->id,
                ]),
            ];
        })->values()->all();

        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'user_id' => $this->message->user_id,
                'body' => $this->message->body,
                'type' => $this->message->type,
                'attachments' => $attachments,
                'created_at' => $this->message->created_at->toISOString(),
            ],
            'sender' => [
                'id' => $this->sender->id,
                'first_name' => $this->sender->first_name,
                'last_name' => $this->sender->last_name,
                'avatar_thumb_url' => $this->sender->avatarUrl('thumb'),
            ],
        ];
    }
}
