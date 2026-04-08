<?php return [
   "name" => "Indexer für externe Teaser",
   "data" => [
      "crawling_sites" => [[
         "id" => "cityname_my_websitename",
         "respect_robots_txt" => true,
         "robots_url" => "https://example.com/robots.txt",
         "max_teaser" => "1000",
         "max_retry" => "3",
         "parallel_requests" => "3",
         "delay_ms" => "500",
         "user_agent" => "AtooloCrawlerBot/1.0 (+contact@example.org)",
         "start_urls" => [[
            "url" => "https://example.com/",
            "extraction_depth" => "2"
         ]],
         "link_selector" => "#content a[href]:not([href^='#']):not([href*='#'])",
         "allow_prefixes" => [
            "https://example.com/news/",
            "https://example.com/dienstleitung/",
            "https://example.com/suche/-/vr-bis-detail/"
         ],
         "deny_prefixes" => ["https://example.com/news"],
         "deny_endings" => [
            ".jpg",
            ".jpeg"
         ],
         "forced_article_urls" => [
            "https://example.com/",
            "https://example.com/dienstleistung/"
         ],
         "strip_query_params_active" => true,
         "strip_query_params" => [
            "utm_source",
            "utm_medium"
         ],
         "title_prefix" => "My Website - ",
         "title_opengraph" => ["og:title"],
         "title_css" => [
            "#content .service-detail h2",
            ".service-detail h2",
            "#content .news h3",
            "#content h3"
         ],
         "title_max_chars" => "140",
         "introText_present" => true,
         "required_field" => false,
         "introText_opengraph" => ["og:description"],
         "introText_css" => [
            "#content .service-detail p",
            ".service-detail p",
            "#content h3 + p"
         ],
         "introText_max_chars" => null,
         "datetime_present" => false,
         "datetime_required_field" => true,
         "datetime_only_date" => true,
         "datetime_opengraph" => [
            "22.111111",
            "22.222222"
         ],
         "datetime_css" => [
            "23.111111",
            "23.222222"
         ],
         "content_scoring_active" => false,
         "content_scoring_min_score" => "24.1",
         "content_scoring_positive" => [
            [
               "score" => "25.11111",
               "match_any" => [
                  "26.161616",
                  "26.123"
               ]
            ],
            [
               "score" => "25.2",
               "match_any" => [
                  "26.2626",
                  "26.234"
               ]
            ],
            [
               "score" => "4",
               "condition" => [
                  "body_text_length" => "288888"
               ]
            ]
         ],
         "content_scoring_negative" => [
            [
               "score" => "29",
               "match_any" => [
                  "30.1",
                  "30.2"
               ]
            ],
            [
               "score" => "3",
               "match_any" => [
                  "30.2.1",
                  "30.2.2"
               ]
            ],
            [
               "score" => "8",
               "condition" => [
                  "body_text_length" => "322222"
               ]
            ]
         ]
      ]]
   ]
];
