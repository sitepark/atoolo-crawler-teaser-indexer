<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Dto;

/** One structured entry ready for indexing. Extensions carry optional custom data (Abschnitt 7.2 im Proposal). */
readonly class IndexEntry
{
    /**
     * @param array<class-string, object> $extensions
     */
    public function __construct(
        public string $url,
        public string $title,
        public ?string $introText,
        public ?\DateTimeImmutable $datetime,
        private array $extensions = [],
    ) {}

    /** @return static */
    public function withExtension(object $extension): static
    {
        return new static(
            url: $this->url,
            title: $this->title,
            introText: $this->introText,
            datetime: $this->datetime,
            extensions: [...$this->extensions, $extension::class => $extension],
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function extension(string $type): ?object
    {
        /** @var T|null */
        return $this->extensions[$type] ?? null;
    }

    /** @return static */
    public function withTitle(string $title): static
    {
        return new static(
            url: $this->url,
            title: $title,
            introText: $this->introText,
            datetime: $this->datetime,
            extensions: $this->extensions,
        );
    }

    /** @return static */
    public function withIntroText(?string $introText): static
    {
        return new static(
            url: $this->url,
            title: $this->title,
            introText: $introText,
            datetime: $this->datetime,
            extensions: $this->extensions,
        );
    }

    /** @return static */
    public function withDatetime(?\DateTimeImmutable $datetime): static
    {
        return new static(
            url: $this->url,
            title: $this->title,
            introText: $this->introText,
            datetime: $datetime,
            extensions: $this->extensions,
        );
    }
}
