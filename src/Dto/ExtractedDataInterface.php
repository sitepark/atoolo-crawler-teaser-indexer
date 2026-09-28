<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Dto;

interface ExtractedDataInterface
{
    public function getUrl(): string;

    public function getTitle(): string;

    public function getIntroText(): ?string;

    public function getDate(): ?\DateTimeInterface;
}
