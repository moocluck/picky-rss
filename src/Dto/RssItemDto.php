<?php

namespace App\Dto;

class RssItemDto
{
    public function __construct(
        public string $guid,
        public string $title,
        public string $link,
        public ?string $description,
        public ?string $imageUrl,
        public ?\DateTimeImmutable $publishedAt
    ) {}
}
