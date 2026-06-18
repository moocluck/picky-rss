<?php

namespace App\Dto;

class RssFeedDto
{
    /**
     * @param RssItemDto[] $items
     */
    public function __construct(
        public string $title,
        public array $items
    ) {}
}
