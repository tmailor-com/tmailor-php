<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;
use Tmailor\Internal\SchemaException;

/**
 * A message.
 *
 * From the message list `text`, `html` and `bodyAvailable` are null; from `Mailbox::message()` or
 * `waitForMessage()` they hold the body. `seen` is true once the message was read (reading one message marks it as
 * read).
 *
 * `otp`, `codes` and `links` are found by the API (Open API 1.3.0) and are only filled for a message read with its
 * body: `otp` is the one-time code or null when the API has no confident answer, `codes` every distinct code-like
 * value (subject first), `links` the http(s) links of the body. A list item, or a server without these fields,
 * gives null / [] / [].
 */
final class Message
{
    /**
     * @param list<string>         $codes
     * @param list<string>         $links
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly Sender $from,
        public readonly string $to,
        public readonly string $subject,
        public readonly int $receivedAt,
        public readonly int $expiresAt,
        public readonly bool $seen,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?bool $bodyAvailable = null,
        public readonly ?string $otp = null,
        public readonly array $codes = [],
        public readonly array $links = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * `withBody`: the response of `POST /v1/mailbox/message` (text, html and body_available are required there).
     *
     * @internal
     */
    public static function fromApi(mixed $data, string $path = '$', bool $withBody = false): self
    {
        $d = S::obj($data, $path);
        $f = S::reqObj($d, 'from', $path);
        $fp = $path . '.from';
        $text = $html = $otp = null;
        $bodyAvailable = null;
        $codes = $links = [];
        if ($withBody) {
            $text = S::reqStrOrNull($d, 'text', $path);
            $html = S::reqStrOrNull($d, 'html', $path);
            $bodyAvailable = S::reqBool($d, 'body_available', $path);
            // API 1.3.0 fields; optional so the SDK keeps working with an older server.
            if (S::has($d, 'otp')) {
                $otp = S::reqStrOrNull($d, 'otp', $path);
            }
            if (S::has($d, 'codes')) {
                $codes = S::reqStrList($d, 'codes', $path);
            }
            if (S::has($d, 'links')) {
                $links = S::reqStrList($d, 'links', $path);
            }
        }
        $seen = S::reqBool($d, 'seen', $path);
        if ($withBody && $seen !== true) {
            // POST /v1/mailbox/message marks the message as read, so a full message must come back with seen: true.
            throw new SchemaException($path . '.seen must be true for a message read with its body');
        }

        return new self(
            S::reqStr($d, 'id', $path),
            new Sender(S::reqStr($f, 'name', $fp), S::reqStr($f, 'address', $fp)),
            S::reqStr($d, 'to', $path),
            S::reqStr($d, 'subject', $path),
            S::reqInt($d, 'received_at', $path),
            S::reqInt($d, 'expires_at', $path),
            $seen,
            $text,
            $html,
            $bodyAvailable,
            $otp,
            $codes,
            $links,
            S::toArray($d),
        );
    }

    /** @internal */
    public static function fromApiWithBody(mixed $data): self
    {
        return self::fromApi($data, '$', true);
    }

    /** True when the message holds its text or HTML body. */
    public function hasBody(): bool
    {
        return $this->text !== null || $this->html !== null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
            'subject' => $this->subject,
            'receivedAt' => $this->receivedAt,
            'expiresAt' => $this->expiresAt,
            'seen' => $this->seen,
            'text' => $this->text === null ? null : '<' . strlen($this->text) . ' bytes>',
            'html' => $this->html === null ? null : '<' . strlen($this->html) . ' bytes>',
            'bodyAvailable' => $this->bodyAvailable,
            'otp' => $this->otp,
            'codes' => $this->codes,
            'links' => count($this->links) . ' link(s)',
        ];
    }
}
