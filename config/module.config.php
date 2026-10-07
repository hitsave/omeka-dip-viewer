<?php
return [
    'resource_page_block_layouts' => [
        'invokables' => [
            'mediaList' => 'OmekaDipViewer\Site\ResourcePageBlockLayout\MediaList',
        ],
    ],
    'asset_manager' => [
        'resolver' => [
            'map' => [
                'OmekaDipViewer/css/admin-item.css' => dirname(__DIR__) . '/asset/css/admin-item.css',
                'OmekaDipViewer/css/site-item.css' => dirname(__DIR__) . '/asset/css/site-item.css',
                'OmekaDipViewer/js/embla-carousel.umd.js' => dirname(__DIR__) . '/asset/js/embla-carousel.umd.js',
                'OmekaDipViewer/js/embla-carousel-wheel-gestures.umd.js' => dirname(__DIR__) . '/asset/js/embla-carousel-wheel-gestures.umd.js',
                'OmekaDipViewer/js/dip-embla.js' => dirname(__DIR__) . '/asset/js/dip-embla.js',
                'OmekaDipViewer/js/dip-gallery.js' => dirname(__DIR__) . '/asset/js/dip-gallery.js',
                'OmekaDipViewer/js/video.min.js' => dirname(__DIR__) . '/asset/js/video.min.js',
                'OmekaDipViewer/js/dip-videos.js' => dirname(__DIR__) . '/asset/js/dip-videos.js',
                'OmekaDipViewer/js/dip-layout.js' => dirname(__DIR__) . '/asset/js/dip-layout.js',
                'OmekaDipViewer/css/video-js.min.css' => dirname(__DIR__) . '/asset/css/video-js.min.css',
            ],
        ],
    ],
    'controllers' => [
        'invokables' => [
            'OmekaDipViewer\Controller\DipFile' => 'OmekaDipViewer\Controller\DipFileController',
            'OmekaDipViewer\Controller\DipBrowsePreview' => 'OmekaDipViewer\Controller\DipBrowsePreviewController',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'dipFileUrl' => 'OmekaDipViewer\Service\ViewHelper\DipFileUrlFactory',
            'dipBrowseThumbnail' => 'OmekaDipViewer\Service\ViewHelper\DipBrowseThumbnailFactory',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'template_map' => [
            'common/resource-page-block-layout/media-list' => dirname(__DIR__) . '/view/common/resource-page-block-layout/media-list.phtml',
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'omeka_dip_package' => 'OmekaDipViewer\Service\Media\Ingester\DipPackageFactory',
        ],
    ],
    'media_renderers' => [
        'factories' => [
            'omeka_dip_package' => 'OmekaDipViewer\Service\Media\Renderer\DipPackageFactory',
        ],
    ],
    'service_manager' => [
        'factories' => [
            'OmekaDipViewer\Service\DipConfig' => 'OmekaDipViewer\Service\DipConfigFactory',
            'OmekaDipViewer\Service\MetsParser' => 'OmekaDipViewer\Service\MetsParserFactory',
            'OmekaDipViewer\Service\DipIndexService' => 'OmekaDipViewer\Service\DipIndexServiceFactory',
            'OmekaDipViewer\Service\DipStreamCache' => 'OmekaDipViewer\Service\DipStreamCacheFactory',
            'OmekaDipViewer\Service\DipPreviewCache' => 'OmekaDipViewer\Service\DipPreviewCacheFactory',
            'OmekaDipViewer\Service\DipBrowsePreviewService' => 'OmekaDipViewer\Service\DipBrowsePreviewServiceFactory',
        ],
        'invokables' => [
            'OmekaDipViewer\Service\DipGalleryFilter' => 'OmekaDipViewer\Service\DipGalleryFilter',
            'OmekaDipViewer\Service\DipVideoFilter' => 'OmekaDipViewer\Service\DipVideoFilter',
            'OmekaDipViewer\Service\DipCollageBuilder' => 'OmekaDipViewer\Service\DipCollageBuilder',
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'ark' => [
                        'child_routes' => [
                            'dip-component' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:naan/:name/:media_id/:file_key',
                                    'constraints' => [
                                        'naan' => '\d{5}',
                                        'name' => '[0-9]+',
                                        'media_id' => '[0-9]+',
                                        'file_key' => '[A-Za-z0-9_]+(?:\.[a-z][a-z0-9_]*)?',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'OmekaDipViewer\Controller',
                                        'controller' => 'DipFile',
                                        'action' => 'streamFromArk',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'omeka-dip-file' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/omeka-dip/file/:media_id/:file_key',
                    'defaults' => [
                        'controller' => 'OmekaDipViewer\Controller\DipFile',
                        'action' => 'stream',
                    ],
                    'constraints' => [
                        'media_id' => '[0-9]+',
                        'file_key' => '[A-Za-z0-9._-]+',
                    ],
                ],
            ],
            'omeka-dip-browse-preview' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/omeka-dip/browse-preview/:media_id',
                    'defaults' => [
                        'controller' => 'OmekaDipViewer\Controller\DipBrowsePreview',
                        'action' => 'preview',
                    ],
                    'constraints' => [
                        'media_id' => '[0-9]+',
                    ],
                ],
            ],
        ],
    ],
];
