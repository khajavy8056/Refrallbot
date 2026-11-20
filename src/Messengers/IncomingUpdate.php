<?php
declare(strict_types=1);

namespace RB\Messengers;

/**
 * مدل یکپارچه آپدیت ورودی از هر پیام‌رسان (تلگرام، بله، روبیکا، ایتا و پیام‌رسان‌های آینده)
 */
final class IncomingUpdate
{
    public function __construct(
        public readonly string $platform,
        public readonly string $updateKey,
        public readonly string $kind,             // message|callback|started|stopped|contact|ignored
        public readonly string $externalUserId,   // شناسه یکتای کاربر در آن پیام‌رسان (عدد یا GUID)
        public readonly string $chatId,           // شناسه چت خصوصی برای پاسخ
        public readonly string $chatType = 'private',
        public readonly ?string $messageId = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $username = null,
        public readonly string $languageCode = 'fa',
        public readonly bool $isBot = false,
        public readonly string $text = '',
        public readonly ?string $startPayload = null,
        public readonly ?string $callbackId = null,
        public readonly ?string $callbackData = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $contactUserId = null,
        public readonly array $raw = [],
    ) {
    }

    /** @return array<string,mixed> داده استاندارد کاربر برای UserService */
    public function userPayload(): array
    {
        return [
            'id' => $this->externalUserId,
            'chat_id' => $this->chatId,
            'is_bot' => $this->isBot,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
            'language_code' => $this->languageCode ?: 'fa',
        ];
    }

    /** Replace an unresolved chat-only identity after getChat confirms the user. */
    public function withExternalUserId(string $id): self
    {
        return new self($this->platform, $this->updateKey, $this->kind, $id, $this->chatId,
            $this->chatType, $this->messageId, $this->firstName, $this->lastName, $this->username,
            $this->languageCode, $this->isBot, $this->text, $this->startPayload, $this->callbackId,
            $this->callbackData, $this->contactPhone, $this->contactUserId, $this->raw);
    }

    public function isPrivateUserEvent(): bool
    {
        return !$this->isBot
            && $this->chatType === 'private'
            && $this->externalUserId !== ''
            && $this->chatId !== ''
            && $this->kind !== 'ignored';
    }
}
