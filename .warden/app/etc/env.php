<?php
return [
    'backend' => [
        'frontName' => 'admin'
    ],
    'cron_consumers_runner' => [
        'cron_run' => true,
        'max_messages' => 2,
        'single_thread' => true,
        'consumers_wait_for_messages' => 0,
        'consumers' => [
            'product_action_attribute.update',
            'product_action_attribute.website.update',
            'media.storage.catalog.image.resize',
            'exportProcessor',
            'inventory.source.items.cleanup',
            'inventory.mass.update',
            'inventory.reservations.update',
            'inventory.reservations.cleanup',
            'inventory.reservations.updateSalabilityStatus',
            'inventory.indexer.sourceItem',
            'inventory.indexer.stock',
            'media.content.synchronization',
            'media.gallery.renditions.update',
            'media.gallery.synchronization',
            'codegeneratorProcessor',
            'sales.rule.update.coupon.usage',
            'sales.rule.quote.trigger.recollect',
            'product_alert'
        ]
    ],
    'queue' => [
        'consumers_wait_for_messages' => 0
    ],
    'install' => [
        'date' => 'Fri, 20 Nov 2015 08:11:26 +0000'
    ],
    'crypt' => [
        'key' => '1a2f574b9fa432a37246d0d9ee363c1b'
    ],
    'session' => [
        'save' => 'redis',
        'redis' => [
            'host' => 'redis',
            'port' => '6379',
            'password' => '',
            'timeout' => '2.5',
            'persistent_identifier' => '',
            'database' => '2',
            'compression_threshold' => '2048',
            'compression_library' => 'gzip',
            'log_level' => '4',
            'max_concurrency' => '6',
            'break_after_frontend' => '5',
            'break_after_adminhtml' => '30',
            'first_lifetime' => '600',
            'bot_first_lifetime' => '60',
            'bot_lifetime' => '7200',
            'disable_locking' => '1',
            'min_lifetime' => '60',
            'max_lifetime' => '2592000'
        ]
    ],
    'db' => [
        'table_prefix' => '',
        'connection' => [
            'default' => [
                'host' => 'db',
                'dbname' => 'magento',
                'username' => 'magento',
                'password' => 'magento',
                'active' => '1'
            ]
        ]
    ],
    'resource' => [
        'default_setup' => [
            'connection' => 'default'
        ]
    ],
    'x-frame-options' => 'SAMEORIGIN',
    'MAGE_MODE' => 'developer',
    'cache_types' => [
        'config' => 1,
        'layout' => 1,
        'block_html' => 1,
        'collections' => 1,
        'reflection' => 1,
        'db_ddl' => 1,
        'eav' => 1,
        'config_integration' => 1,
        'config_integration_api' => 1,
        'full_page' => 0,
        'translate' => 1,
        'config_webservice' => 1,
        'compiled_config' => 1,
        'customer_notification' => 1,
        'amasty_shopby' => 1,
        'vertex' => 1,
        'wp_gtm_categories' => 1,
        'data_layer' => 1,
        'magewire' => 1,
        'hyva_checkout' => 1
    ],
    'cache' => [
        'frontend' => [
            'default' => [
                'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                'backend_options' => [
                    'server' => 'redis',
                    'database' => '0',
                    'port' => '6379'
                ]
            ],
            'page_cache' => [
                'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                'backend_options' => [
                    'server' => 'redis',
                    'port' => '6379',
                    'database' => '1',
                    'compress_data' => '0'
                ]
            ]
        ],
        'graphql' => [
            'id_salt' => 'nNEwOfl0pSyB0l96n7UAvI1fzwGWnEPs'
        ]
    ],
    'allow_parallel_generation' => true,
    'type' => [
        'default' => [
            'frontend' => 'default'
        ]
    ],
//    'http_cache_hosts' => [
//        [
//            'host' => 'varnish',
//            'port' => '6081'
//        ]
//    ],
];
