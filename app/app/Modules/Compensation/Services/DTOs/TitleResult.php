<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

final readonly class TitleResult
{
    public function __construct(
        public ?string $title,
        public int $maxGsbSlab,       // 0–7; 0 means no GSB eligible
        public ?int $nextTitleBvPaise, // null at top title
    ) {}
}
