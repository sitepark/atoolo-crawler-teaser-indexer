<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Atoolo\Crawler\Domain\Crawler\Services\LengthConditionConfig;
use Atoolo\Crawler\Domain\Crawler\Services\ScoreRuleConfig;
use Psr\Log\LoggerInterface;

final class CrawlerConfigHelper
{
    public function __construct(private readonly CrawlerConfigContext $ctx, private LoggerInterface $logger)
    {
    }

    private const MISSING = '__MISSING__';

    public function int(string $key, int $default = 0): int
    {
        $result = $default;
        $v = $this->ctx->get($key, self::MISSING);

        if ($v === self::MISSING) {
            $this->logger->warning(
                'Config missing int, using default',
                ['key' => $key, 'default' => $default]
            );
        } elseif (is_int($v)) {
            $result = $v;
        } elseif (is_string($v) && ctype_digit($v)) {
            $result = (int) $v;
        } else {
            $this->logger->error(
                'Config invalid int, using default',
                ['key' => $key, 'value' => $v, 'default' => $default]
            );
        }
        return $result;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $result = $default;
        $v = $this->ctx->get($key, self::MISSING);

        if ($v === self::MISSING) {
            $this->logger->warning(
                'Config missing bool, using default',
                ['key' => $key, 'default' => $default]
            );
        } elseif (is_bool($v)) {
            $result = $v;
        } elseif (is_string($v)) {
            $vv = strtolower(trim($v));
            if ($vv === 'true' || $vv === '1') {
                $result = true;
            } elseif ($vv === 'false' || $vv === '0') {
                $result = false;
            } else {
                $this->logger->error(
                    'Config invalid bool, using default',
                    ['key' => $key, 'value' => $v, 'default' => $default]
                );
            }
        } else {
            $this->logger->error(
                'Config invalid bool, using default',
                ['key' => $key, 'value' => $v, 'default' => $default]
            );
        }

        return $result;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->ctx->get($key, self::MISSING);

        if ($v === self::MISSING) {
            $this->logger->warning('Config missing string, using default', ['key' => $key, 'default' => $default]);
            return $default;
        }

        if (is_string($v)) {
            return $v;
        }

        $this->logger->error('Config invalid string, using default', [
            'key' => $key,
            'value' => $v,
            'default' => $default
        ]);
        return $default;
    }

    public function nullableString(string $key): ?string
    {
        $v = $this->ctx->get($key, self::MISSING);

        if ($v === self::MISSING || $v === null) {
            return null;
        }

        if (is_string($v)) {
            $v = trim($v);
            return $v !== '' ? $v : null;
        }

        $this->logger->error('Config invalid nullable string, returning null', ['key' => $key, 'value' => $v]);
        return null;
    }

        /** @return list<int> */
    public function intList(string $key): array
    {
        $v = $this->ctx->get($key, self::MISSING);

        if ($v === self::MISSING) {
            $this->logger->warning(
                'Config missing int list, using empty list',
                ['key' => $key]
            );
            return [];
        }

        if (!is_array($v)) {
            $this->logger->error(
                'Config invalid int list, using empty list',
                ['key' => $key, 'value' => $v]
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
                ['key' => $key, 'item' => $item]
            );
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }


    /** @return list<string> */
    public function intStringList(string $key): array
    {
        $v = $this->ctx->get($key, self::MISSING);

        if ($v === self::MISSING) {
            $this->logger->warning('Config missing string list, using empty list', ['key' => $key]);
            return [];
        }

        if (!is_array($v)) {
            $this->logger->error('Config invalid string list, using empty list', ['key' => $key, 'value' => $v]);
            return [];
        }

        $out = [];
        foreach ($v as $item) {
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<ScoreRuleConfig>
     */
    public function readScoreRules(string $key): array
    {
        $raw = $this->ctx->get($key, []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $score = 0;
            if (isset($rule['score']) && is_numeric($rule['score'])) {
                $score = (int) $rule['score'];
            }

            $matchAny = [];
            if (isset($rule['match_any']) && is_array($rule['match_any'])) {
                foreach ($rule['match_any'] as $m) {
                    if (is_string($m) && $m !== '') {
                        $matchAny[] = $m;
                    }
                }
            }

            $condition = null;
            if (isset($rule['condition']) && is_array($rule['condition'])) {
                $bodyTextLengthLt = null;

                if (array_key_exists('body_text_length_lt', $rule['condition'])) {
                    $v = $rule['condition']['body_text_length_lt'];
                    if (is_numeric($v)) {
                        $bodyTextLengthLt = (int) $v;
                    }
                }

                if ($bodyTextLengthLt !== null) {
                    $condition = new LengthConditionConfig(bodyTextLengthLt: $bodyTextLengthLt);
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
}
