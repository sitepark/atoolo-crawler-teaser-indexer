<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator;

use Atoolo\CrawlerIndexer\Config\ContentScoringConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldSource;

/**
 * Keyword based relevance scoring: positive and negative rules are matched
 * against title, intro and the main content of the page; `sp_forced_article_urls`
 * are always relevant.
 *
 * Called by the Parser for every extracted document, on the block the Parser
 * has already parsed: the main content is read through the {@see FieldSource}
 * instead of parsing the HTML again. Which region counts as main content is a
 * scoring decision, so the selector priority list `sp_relevance_content_selector`
 * is applied here; without a configured or matching selector the whole block
 * is used. Only visible text is scored,
 * never markup or scripts.
 */
final class RelevanceEvaluator implements RelevanceEvaluatorInterface
{
    public function relevant(ExtractedDataInterface $entry, FieldSource $source, PipelineConfig $config): bool
    {
        if (in_array($entry->getUrl(), $config->forcedArticleUrls(), true)) {
            return true;
        }

        $scoringCfg = $config->contentScoringConfig();
        $evaluation = $this->evaluate($entry, $this->mainContent($source, $scoringCfg), $scoringCfg);

        return $evaluation['score'] >= $scoringCfg->minScore;
    }

    /**
     * Visible text of the first matching content selector, else of the block.
     */
    private function mainContent(FieldSource $source, ContentScoringConfig $cfg): string
    {
        foreach ($cfg->contentSelectors as $selector) {
            $text = $source->visibleText($selector);
            if (null !== $text) {
                return $text;
            }
        }

        return $source->visibleText() ?? '';
    }

    /**
     * @return array{score:int,reasons:array<int,string>}
     */
    private function evaluate(ExtractedDataInterface $entry, string $content, ContentScoringConfig $cfg): array
    {
        $score = 0;
        $reasons = [];

        $title = $entry->getTitle();
        $intro = $entry->getIntroText() ?? '';
        $url = $entry->getUrl();

        $haystack = $this->normalize($title . "\n" . $intro . "\n" . $content);

        foreach ($cfg->positive as $rule) {
            if ($this->ruleMatches($rule, $haystack, $intro, $content)) {
                $score += $rule->score;
                $reasons[] = '+' . $rule->score . ' "' . ($rule->matchAny[0] ?? 'rule') . '"';
            }
        }

        foreach ($cfg->negative as $rule) {
            if ($this->ruleMatches($rule, $haystack, $intro, $content)) {
                $score += $rule->score;
                $reasons[] = $rule->score . ' "' . ($rule->matchAny[0] ?? 'rule') . '"';
            }
        }

        if (str_contains($url, '#')) {
            $score -= 2;
            $reasons[] = '-2 "Fragment-URL"';
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    private function ruleMatches(
        ScoreRuleConfig $rule,
        string $haystack,
        string $intro,
        string $content,
    ): bool {
        foreach ($rule->matchAny as $needle) {
            if ($this->contains($haystack, $needle)) {
                return true;
            }
        }

        if (null !== $rule->condition?->bodyTextLengthLt) {
            $len = mb_strlen(trim($intro . ' ' . $content));

            return $len > 0 && $len < $rule->condition->bodyTextLengthLt;
        }

        return false;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    private function contains(string $haystack, string $needle): bool
    {
        return '' !== $needle && str_contains($haystack, $this->normalize($needle));
    }
}
