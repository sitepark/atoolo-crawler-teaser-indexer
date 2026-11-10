<?php

/*
 * Example site configuration for the crawler.
 *
 * Location in a project: <config-dir>/indexer/atooloTeaserCrawler.php
 * Only sp_id, the start URLs and a title selector are required, all other
 * keys fall back to their defaults.
 */

return [
    'name' => 'Indexer für externe Teaser',
    'data' => [
        'sp_crawling_sites' => [
            [
                // --- Category ---
                'sp_categories' => [1527, 1528],
                'sp_categories_path' => [1531, 1527],

                // --- Core / Meta ---
                'sp_id' => 'cityname_my_websitename',
                'sp_respect_robots_txt' => true,
                'sp_robots_url' => 'https://example.com/robots.txt',
                'sp_max_teaser' => '1000',
                'sp_cleanup_threshold' => '50',
                'sp_max_retry' => '3',
                'sp_backoff_ms' => '500',
                'sp_delay_ms' => '500',
                'sp_parallel_requests' => '3',
                'sp_user_agent' => 'ExampleBot/1.0 (+contact@example.org)',

                // --- URLCollector (Discovery) ---
                'sp_start_urls' => [
                    [
                        'sp_url' => 'https://example.com/',
                        'sp_extraction_depth' => '2',
                    ],
                ],
                'sp_link_selector' => '#content a[href]',
                'sp_allow_prefixes' => [
                    'https://example.com/news/',
                    'https://example.com/services/',
                ],
                'sp_deny_prefixes' => [
                    'https://example.com/news/archive/',
                ],
                'sp_deny_endings' => [
                    '.ics',
                    '.vcf',
                ],
                'sp_forced_article_urls' => [
                    'https://example.com/services/important-service.php',
                ],
                'sp_strip_query_params_active' => true,
                'sp_strip_query_params' => [
                    'utm_source',
                    'utm_medium',
                ],
                'sp_strip_fragments' => [
                    'https://example.com/',
                ],

                // --- Parser: split one page into several teasers (XPath) ---
                'sp_split_html_document' => [],

                // --- Parser: Title (always required) ---
                'sp_title_prefix' => 'My Website - ',
                'sp_title_opengraph' => ['og:title'],
                'sp_title_css' => [
                    '#content .service-detail h2',
                    '#content h1',
                ],
                'sp_title_max_chars' => '140',

                // --- Parser: Intro text ---
                'sp_introText_present' => true,
                'sp_introText_required_field' => false,
                'sp_introText_opengraph' => ['og:description'],
                'sp_introText_css' => [
                    '#content .service-detail p',
                    '#content h1 + p',
                ],
                'sp_introText_max_chars' => '250',

                // --- Parser: DateTime ---
                'sp_datetime_present' => true,
                'sp_datetime_required_field' => false,
                'sp_datetime_only_date' => true,
                'sp_datetime_opengraph' => ['article:published_time'],
                'sp_datetime_css' => [
                    'time[datetime]',
                    '.published-date',
                ],

                // --- Content scoring ---
                'sp_content_scoring_active' => true,
                'sp_content_scoring_min_score' => '4',
                'sp_relevance_content_selector' => [
                    '#content',
                    'main',
                ],
                'sp_content_scoring_positive' => [
                    [
                        'sp_score' => '5',
                        'sp_match_any' => [
                            'online application',
                            'book appointment',
                        ],
                    ],
                    [
                        'sp_score' => '2',
                        'sp_match_any' => [
                            'opening hours',
                            'further information',
                        ],
                    ],
                ],
                'sp_content_scoring_negative' => [
                    [
                        'sp_score' => '-5',
                        'sp_match_any' => [
                            'newsletter',
                            'press office',
                        ],
                    ],
                    [
                        // matches pages with less than 150 characters of text
                        'sp_score' => '-3',
                        'sp_condition' => [
                            'sp_body_text_length' => '150',
                        ],
                    ],
                ],
            ],
            [
                'sp_id' => 'cityname_my_other_websitename',
                'sp_start_urls' => [
                    [
                        'sp_url' => 'https://other.example.com/',
                        'sp_extraction_depth' => '1',
                    ],
                ],
                'sp_title_css' => ['h1'],
            ],
        ],
    ],
];
