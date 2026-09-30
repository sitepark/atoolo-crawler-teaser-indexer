<?php

return [
    'name' => 'Indexer für externe Teaser',
    'data' => [
        'crawling_sites' => [
            [
                'sp_categories' => [
                    1527,
                    1528,
                ],
                'sp_categories_path' => [
                    1531,
                    1527,
                ],
                'id' => 'cityname_my_websitename',
                'respect_robots_txt' => true,
                'robots_url' => 'https://example.com/robots.txt',
                'max_document' => '1000',
                'max_retry' => '3',
                'parallel_requests' => '3',
                'delay_ms' => '500',
                'sp_backoff_ms' => '500',
                'user_agent' => 'AtooloCrawlerBot/1.0 (+contact@example.org)',
                'start_urls' => [
                    [
                        'url' => 'https://example.com/',
                        'extraction_depth' => '2',
                    ],
                ],
                'link_selector' => "#content a[href]:not([href^='#']):not([href*='#'])",
                'allow_prefixes' => [
                    'https://example.com/news/',
                    'https://example.com/dienstleitung/',
                    'https://example.com/suche/-/vr-bis-detail/',
                ],
                'deny_prefixes' => ['https://example.com/news'],
                'deny_endings' => [
                    '.jpg',
                    '.jpeg',
                ],
                'forced_article_urls' => [
                    'https://example.com/',
                    'https://example.com/dienstleistung/',
                ],
                'strip_query_params_active' => true,
                'strip_query_params' => [
                    'utm_source',
                    'utm_medium',
                ],
                'title_prefix' => 'My Website - ',
                'title_opengraph' => ['og:title'],
                'title_css' => [
                    '#content .service-detail h2',
                    '.service-detail h2',
                    '#content .news h3',
                    '#content h3',
                ],
                'title_max_chars' => '140',
                'introText_present' => true,
                'required_field' => false,
                'introText_opengraph' => ['og:description'],
                'introText_css' => [
                    '#content .service-detail p',
                    '.service-detail p',
                    '#content h3 + p',
                ],
                'introText_max_chars' => null,
                'datetime_present' => true,
                'datetime_required_field' => false,
                'datetime_only_date' => true,
                'datetime_opengraph' => [
                    'article:published_time',
                    'datePublished',
                ],
                'datetime_css' => [
                    '.published-date',
                    '.article-date',
                ],
                'content_scoring_active' => true,
                'content_scoring_min_score' => '10',
                'content_scoring_positive' => [
                    [
                        'score' => '5',
                        'match_any' => [
                            '.news-article',
                            "[data-article='true']",
                        ],
                    ],
                    [
                        'score' => '3',
                        'match_any' => [
                            '.featured',
                            '.highlight',
                        ],
                    ],
                    [
                        'score' => '4',
                        'condition' => [
                            'body_text_length' => '300',
                        ],
                    ],
                ],
                'content_scoring_negative' => [
                    [
                        'score' => '-5',
                        'match_any' => [
                            '.archived',
                            "[data-archived='true']",
                        ],
                    ],
                    [
                        'score' => '-2',
                        'match_any' => [
                            '.outdated',
                            '.deprecated',
                        ],
                    ],
                    [
                        'score' => '-3',
                        'condition' => [
                            'body_text_length' => '50',
                        ],
                    ],
                ],
            ],
            [
                'sp_categories' => [
                    1525,
                    1527,
                    1528,
                ],
                'sp_categories_path' => [
                    1525,
                    1528,
                ],
                'id' => 'cityname_my_websitename',
                …,
            ],
        ],
    ],
];
