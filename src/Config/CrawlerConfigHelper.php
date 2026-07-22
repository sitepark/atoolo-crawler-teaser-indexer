<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Config;

use Atoolo\CrawlerIndexer\Pipeline\Parser\ScoreRuleConfig;
use Psr\Log\LoggerInterface;

final class CrawlerConfigHelper
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly array $params,
        private LoggerInterface $logger,
    ) {}

    private const MISSING = '__MISSING__';

    public function bool(string $key, bool $default = false): bool
    {
        $result = $default;
        $v = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $v) {
            $this->logger->debug(
                'Config missing boolean, using default',
                ['key' => $key, 'default' => $default],
            );
        } elseif (is_bool($v)) {
            $result = $v;
        } elseif (is_string($v)) {
            $vv = strtolower(trim($v));
            if ('true' === $vv || '1' === $vv) {
                $result = true;
            } elseif ('false' === $vv || '0' === $vv) {
                $result = false;
            } else {
                $this->logger->error(
                    'Config invalid boolean, using default',
                    ['key' => $key, 'value' => $v, 'default' => $default],
                );
            }
        } else {
            $this->logger->error(
                'Config invalid boolean, using default',
                ['key' => $key, 'value' => $v, 'default' => $default],
            );
        }

        return $result;
    }

    public function int(string $key, int $default = 0): int
    {
        $result = $default;
        $v = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $v) {
            $this->logger->debug(
                'Config missing integer, using default',
                ['key' => $key, 'default' => $default],
            );
        } elseif (is_int($v)) {
            $result = $v;
        } elseif (is_string($v) && ctype_digit($v)) {
            $result = (int) $v;
        } else {
            $this->logger->error(
                'Config invalid integer, using default',
                ['key' => $key, 'value' => $v, 'default' => $default],
            );
        }

        return $result;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $v) {
            $this->logger->debug('Config missing string, using default', ['key' => $key, 'default' => $default]);

            return $default;
        }

        if (is_string($v)) {
            return $v;
        }

        $this->logger->error('Config invalid string, using default', [
            'key' => $key,
            'value' => $v,
            'default' => $default,
        ]);

        return $default;
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        $v = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $v) {
            $this->logger->debug(
                'Config missing int list, using empty list',
                ['key' => $key],
            );

            return [];
        }

        if (!is_array($v)) {
            $this->logger->warning(
                'Config invalid int list, using empty list',
                ['key' => $key, 'value' => $v],
            );

            return [];
        }

        $out = [];

        foreach ($v as $item) {
            if (is_int($item)) {
                $out[] = $item;
                continue;
            }

            if (is_string($item) && ctype_digit($item)) {
                $out[] = (int) $item;
                continue;
            }

            if (is_numeric($item)) {
                $out[] = (int) $item;
                continue;
            }

            $this->logger->warning(
                'Config list item ignored (not int)',
                ['key' => $key, 'item' => $item],
            );
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $v = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $v) {
            $this->logger->debug('Config missing string list, using empty list', ['key' => $key]);

            return [];
        }

        if (!is_array($v)) {
            $this->logger->warning('Config invalid string list, using empty list', [
                'key' => $key,
                'value' => $v,
            ]);

            return [];
        }

        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && '' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<array{url: string, extraction_depth: int}> */
    public function startUrlsList(string $key): array
    {
        $rawStartUrlsList = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $rawStartUrlsList) {
            $this->logger->debug('Config missing string list, using empty list', ['key' => $key]);

            return [];
        }

        if (!is_array($rawStartUrlsList)) {
            $this->logger->warning(
                'Config invalid string list, using empty list',
                ['key' => $key, 'value' => $rawStartUrlsList],
            );

            return [];
        }

        $startUrlsList = [];
        foreach ($rawStartUrlsList as $startUrl) {
            if ('' === $startUrl) {
                continue;
            }
            if (is_array($startUrl) && isset($startUrl['sp_url']) && is_string($startUrl['sp_url'])) {
                $depth = $startUrl['sp_extraction_depth'] ?? 0;
                $startUrlsList[] = [
                    'url' => $startUrl['sp_url'],
                    'extraction_depth' => is_numeric($depth) ? (int) $depth : 1,
                ];
            }
        }

        return $startUrlsList;
    }

    /**
     * @return list<ScoreRuleConfig>
     */
    public function readScoreRules(string $key): array
    {
        $rules = $this->params[$key] ?? self::MISSING;

        if (self::MISSING === $rules) {
            $this->logger->debug('Config missing score rules, using empty list', ['key' => $key]);

            return [];
        }

        if (!is_array($rules)) {
            $this->logger->warning('Config invalid score rules, using empty list', [
                'key' => $key,
                'value' => $rules,
            ]);

            return [];
        }

        $out = [];
        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                $this->logger->warning('Config invalid score rule entry, skipping', [
                    'key' => $key,
                    'index' => $index,
                    'value' => $rule,
                ]);
                continue;
            }

            // sp_score handling
            $score = 0;
            if (isset($rule['sp_score'])) {
                if (is_numeric($rule['sp_score'])) {
                    $score = (int) $rule['sp_score'];
                } else {
                    $this->logger->warning('Config invalid sp_score, using default', [
                        'key' => $key,
                        'index' => $index,
                        'value' => $rule['sp_score'],
                    ]);
                }
            }

            // sp_match_any handling
            $matchAny = [];
            if (isset($rule['sp_match_any'])) {
                if (!is_array($rule['sp_match_any'])) {
                    $this->logger->warning('Config invalid sp_match_any, must be array', [
                        'key' => $key,
                        'index' => $index,
                        'value' => $rule['sp_match_any'],
                    ]);
                } else {
                    foreach ($rule['sp_match_any'] as $m) {
                        if (is_string($m) && '' !== $m) {
                            $matchAny[] = $m;
                        } elseif (!is_string($m) || '' === $m) {
                            $this->logger->warning('Config invalid sp_match_any entry, skipping', [
                                'key' => $key,
                                'index' => $index,
                                'value' => $m,
                            ]);
                        }
                    }
                }
            }

            // sp_condition handling
            $condition = null;
            if (isset($rule['sp_condition'])) {
                if (!is_array($rule['sp_condition'])) {
                    $this->logger->warning('Config invalid sp_condition, must be array', [
                        'key' => $key,
                        'index' => $index,
                        'value' => $rule['sp_condition'],
                    ]);
                } else {
                    $condition = $this->readCondition($rule['sp_condition'], $key, $index);
                }
            }

            $out[] = new ScoreRuleConfig(
                score: $score,
                matchAny: $matchAny,
                condition: $condition,
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $conditionData
     */
    private function readCondition(array $conditionData, string $parentKey, int $index): ?LengthConditionConfig
    {
        $bodyTextLengthLt = null;

        if (array_key_exists('sp_body_text_length', $conditionData)) {
            $v = $conditionData['sp_body_text_length'];
            if (is_numeric($v)) {
                $bodyTextLengthLt = (int) $v;
            } else {
                $this->logger->warning('Config invalid sp_body_text_length, skipping condition', [
                    'parentKey' => $parentKey,
                    'index' => $index,
                    'value' => $v,
                ]);
            }
        }

        if (null !== $bodyTextLengthLt) {
            return new LengthConditionConfig(bodyTextLengthLt: $bodyTextLengthLt);
        }

        return null;
    }
}
