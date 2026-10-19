<?php

declare(strict_types=1);

namespace App\Modules\Shared\Mail;

use Illuminate\Mail\Events\MessageSending;

/**
 * Every arovolife mail is rendered from an HTML Blade view only, so it leaves
 * as a bare text/html part. Spam filters (Gmail, SpamAssassin MIME_HTML_ONLY)
 * score an HTML-only mail as bulk/phishing-like — and a password reset is the
 * mail most likely to be judged that way. This adds a text/plain alternative
 * derived from the HTML to any message that does not already carry one.
 */
final class AddPlainTextAlternative
{
    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $html = $message->getHtmlBody();

        if ($message->getTextBody() !== null || ! is_string($html) || trim($html) === '') {
            return;
        }

        $message->text(self::toText($html), 'utf-8');
    }

    public static function toText(string $html): string
    {
        // Outlook conditional blocks, the <head> and its <style>, and the
        // hidden inbox preheader are not part of the readable message.
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);
        $html = (string) preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<div\b[^>]*display:\s*none[^>]*>.*?</div>#is', '', $html);

        // A button or link keeps its label and gains its URL, so the reader of
        // the text part can still act on it.
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $target = str_starts_with($url, 'mailto:') ? substr($url, 7) : $url;

                return ($label === '' || $label === $target) ? $target : $label.': '.$target;
            },
            $html,
        );

        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|tr|table|h[1-6]|li)>#i', "\n\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $text)),
        );

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
