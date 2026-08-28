<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Enums;

/**
 * Intake channels from policy §3. Every one of them routes into this single
 * tracker — that single-tracker promise is the point of the enum, and DSR
 * 2021 Rule 12 requires the register to show how each complaint arrived.
 *
 * `Post`, `Phone` and `InPerson` are recorded by staff on the complainant's
 * behalf, so tickets on those channels start life with a staff actor.
 */
enum TicketChannel: string
{
    case Web = 'web';
    case ContactForm = 'contact_form';
    case Email = 'email';
    case Phone = 'phone';
    case Post = 'post';
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web form',
            self::ContactForm => 'Contact us form',
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Post => 'Post',
            self::InPerson => 'In person',
        };
    }

    /**
     * Channels a staff member records on the complainant's behalf.
     *
     * @return array<int, self>
     */
    public static function staffRecordable(): array
    {
        return [self::Phone, self::Post, self::InPerson, self::Email];
    }
}
