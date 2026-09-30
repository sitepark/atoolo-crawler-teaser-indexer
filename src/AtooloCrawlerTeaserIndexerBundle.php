<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer;

use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldExtractorInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\GlobFileLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @codeCoverageIgnore
 */
class AtooloCrawlerTeaserIndexerBundle extends Bundle
{
    public const FIELD_EXTRACTOR_TAG = 'atoolo.crawler.field_extractor';

    /**
     * @throws \Exception
     */
    public function build(ContainerBuilder $container): void
    {
        $locator = new FileLocator(__DIR__ . '/../config');
        $loader = new GlobFileLoader($locator);
        $loader->setResolver(
            new LoaderResolver(
                [
                    new YamlFileLoader($container, $locator),
                ],
            ),
        );
        $loader->load('services.yaml');

        // A project's field extractors are defined in the project's own
        // container, so the tag cannot come from this bundle's services.yaml.
        // Registering it for autoconfiguration means implementing the interface
        // is enough - no tag to remember.
        $container
            ->registerForAutoconfiguration(FieldExtractorInterface::class)
            ->addTag(self::FIELD_EXTRACTOR_TAG);
    }
}
