<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

interface TeaserDataInterface
{
    public function getUrl(): string;
    public function getTitle(): string;
    public function getIntroText(): ?string;
    public function getDate(): \DateTimeInterface|null;
}

final readonly class TeaserData implements TeaserDataInterface
{
    public function __construct(
        private string $url,
        private string $title,
        private ?string $introText = null,
        private ?\DateTimeInterface $date = null,
    ) {
    }

    public function getUrl(): string        { return $this->url; }
    public function getTitle(): string      { return $this->title; }
    public function getIntroText(): ?string { return $this->introText; }
    public function getDate(): ?\DateTimeInterface { return $this->date; }
}
