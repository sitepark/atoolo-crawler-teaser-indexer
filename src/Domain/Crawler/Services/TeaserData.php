<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

interface TeaserDataInterface
{
    public function getUrl(): string;

    public function getTitle(): string;

    public function getIntroText(): ?string;

    public function getDate(): ?\DateTimeInterface;
}

final class TeaserData implements TeaserDataInterface
{
    public function __construct(
        private readonly string $url,
        private readonly string $title,
        private readonly ?string $introText = null,
        private readonly ?\DateTimeInterface $date = null,
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIntroText(): ?string
    {
        return $this->introText;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }
}
