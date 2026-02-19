<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

use Symfony\Component\DomCrawler\Crawler;
use Atoolo\Crawler\Config\CrawlerConfig;

final class TeaserRelevanceEvaluator implements TeaserRelevanceEvaluatorInterface
{
    public function __construct(
        private readonly CrawlerConfig $config,
    ) {
    }

    /**
     * Evaluates whether a teaser is relevant based on its content (HTML, title, intro)
     * and the defined scoring configuration.
     *
     * @param array{
     * url: string,
     * title: string,
     * introText?: string,
     * html?: string,
     * datetime?: \DateTimeImmutable
     * } $relevanceData
     * @return bool
     */
    public function relevant(array $relevanceData): bool
    {
        $scoringCfg = $this->config->contentScoringConfig();
        $forcedArticleUrls = $this->config->forcedArticleUrls();

        if (in_array($relevanceData['url'], $forcedArticleUrls, true)) {
            return false;
        }

        $evaluation = $this->evaluate($relevanceData, $scoringCfg);

        if ($evaluation['score'] >= $scoringCfg->minScore) {
            return true;
        }

        return false;
    }

    /**
     * @param array{
     * url: string,
     * title: string,
     * introText?: string,
     * html?: string,
     * datetime?: \DateTimeImmutable
     * } $t
     * @return array{score:int,reasons:array<int,string>}
     */
    private function evaluate(array $t, ContentScoringConfig $cfg): array
    {
        $score = 0;
        $reasons = [];

        $title = (string) ($t['title']);
        $intro = (string) ($t['introText'] ?? '');
        $url   = (string) ($t['url']);
        $body  = $this->extractBodyTextFromHtml((string) ($t['html'] ?? ''));

        $haystack = $this->normalize($title . "\n" . $intro . "\n" . $body);

        foreach ($cfg->positive as $rule) {
            if ($this->ruleMatches($rule, $haystack, $intro, $body)) {
                $score += $rule->score;
                $reasons[] = '+' . $rule->score . ' "' . ($rule->matchAny[0] ?? 'rule') . '"';
            }
        }

        foreach ($cfg->negative as $rule) {
            if ($this->ruleMatches($rule, $haystack, $intro, $body)) {
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
        string $body
    ): bool {
        foreach ($rule->matchAny as $needle) {
            if ($this->contains($haystack, $needle)) {
                return true;
            }
        }

        if ($rule->condition?->bodyTextLengthLt !== null) {
            $len = mb_strlen(trim($intro . ' ' . $body));
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
        return $needle !== '' && str_contains($haystack, $this->normalize($needle));
    }

    private function extractBodyTextFromHtml(string $html): string
    {
        try {
            $crawler = new Crawler($html);
            foreach (['main', '#content', '#boxes', 'article', 'body'] as $sel) {
                $n = $crawler->filter($sel);
                if ($n->count() > 0) {
                    return trim($n->first()->text());
                }
            }
            return '';
        } catch (\Throwable) {
            return '';
        }
    }
}
