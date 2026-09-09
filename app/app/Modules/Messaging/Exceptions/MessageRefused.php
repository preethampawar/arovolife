<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use RuntimeException;

/**
 * A send the messaging policy refused.
 *
 * One exception type rather than one per guard: the caller's job is the same
 * in every case — show the sender why, as a 422 on the compose form or in the
 * tree-card modal — and the reason is already a finished sentence addressed to
 * the sender. A hierarchy would only give controllers a way to treat some
 * refusals as less real than others.
 *
 * The messages avoid saying anything about the recipient's own choices. "They
 * are not accepting messages" is true for a block and for a closed channel
 * alike; telling a sender they were personally blocked hands them a reason to
 * find another way to reach that person.
 */
final class MessageRefused extends RuntimeException
{
    public static function outsideAudience(): self
    {
        return new self('You can only message people in your own Genos line — your sponsor, your upline, or someone in your team.');
    }

    public static function blocked(): self
    {
        return new self('This person is not accepting messages from you.');
    }

    public static function rateLimited(int $availableInSeconds): self
    {
        $minutes = (int) ceil($availableInSeconds / 60);
        $unit = $minutes === 1 ? 'a minute' : "{$minutes} minutes";

        return new self("You have sent a lot of messages just now. Please try again in about {$unit}.");
    }

    public static function tooLong(int $limit): self
    {
        return new self("Please keep your message under {$limit} characters.");
    }

    public static function empty(): self
    {
        return new self('Message body cannot be empty.');
    }

    public static function channelClosed(): self
    {
        return new self('Messaging is currently unavailable.');
    }

    /**
     * Raised with the sentence NoRawGovernmentId itself produced — it already
     * tells the sender which number to remove and what to quote instead, and
     * rewording it here would mean maintaining that guidance twice.
     */
    public static function governmentIdInBody(string $ruleMessage): self
    {
        return new self($ruleMessage);
    }
}
