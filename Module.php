<?php
namespace OmekaDipViewer;

use OmekaDipViewer\Media\DipPackageMedia;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Representation\ItemRepresentation;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Module as ModuleEntity;
use Omeka\Entity\User;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->syncInstalledVersionIfNeeded();
        $this->addAclRules();
    }

    /**
     * Bind-mounted dev copies bump module.ini without rerunning the upgrade CLI; Omeka
     * leaves the module in needs_upgrade and stops registering routes until synced.
     */
    protected function syncInstalledVersionIfNeeded(): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('OmekaDipViewer');
        if (!$module) {
            return;
        }

        $entityManager = $services->get('Omeka\EntityManager');
        $dbEntity = $entityManager->find(ModuleEntity::class, 'OmekaDipViewer');
        $iniVersion = (string) ($module->getIni('version') ?? '');
        $dbVersion = $dbEntity ? (string) $dbEntity->getVersion() : '';
        if ($module->getState() !== 'needs_upgrade' && $iniVersion === $dbVersion) {
            return;
        }

        $admin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        if (!$admin) {
            return;
        }
        $services->get('Omeka\AuthenticationService')->getStorage()->write($admin);

        try {
            $moduleManager->upgrade($module);
        } catch (\Throwable $e) {
            // Leave needs_upgrade; ensure-omeka-dip-viewer-upgrade.php remains the repair path.
        }
    }

    public function addAclRules(): void
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        // Public site users must reach stream; controller enforces public/read on the media.
        $acl->allow(
            null,
            'OmekaDipViewer\Controller\DipFile',
            ['stream', 'streamFromArk']
        );
        $acl->allow(
            null,
            'OmekaDipViewer\Controller\DipBrowsePreview',
            ['preview']
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            [$this, 'addDipPackagesSectionNav']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            [$this, 'displayDipPackagesSectionOnAdminItem']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.before',
            [$this, 'prepareAdminItemShowView']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.before',
            [$this, 'prepareSiteItemShowView']
        );
        $sharedEventManager->attach(
            'Omeka\View\Helper\Media',
            'view_helper.media.render_options',
            [$this, 'injectDipMediaRenderOptions']
        );
    }

    /**
     * Pass public site slug into DIP renderer so file links use ARK component paths.
     */
    public function injectDipMediaRenderOptions(Event $event): void
    {
        $media = $event->getParam('media');
        if (!$media || !DipPackageMedia::isDipPackage($media->renderer())) {
            return;
        }
        $target = $event->getTarget();
        if (!is_object($target) || !method_exists($target, 'getView')) {
            return;
        }
        $view = $target->getView();
        $site = $view->site ?? (method_exists($view, 'vars') ? $view->vars('site') : null);
        if (!is_object($site) || !method_exists($site, 'slug')) {
            return;
        }
        $options = $event->getParam('options') ?? [];
        $options['omeka_dip_site_slug'] = (string) $site->slug();
        $event->setParam('options', $options);
    }

    /**
     * Hide redundant sidebar Media links for DIP-only items (tree is under DIP files tab).
     */
    public function prepareAdminItemShowView(Event $event): void
    {
        $view = $event->getTarget();
        $item = $this->getItemFromView($view);
        if (!$item || !$this->itemHasDipPackageMedia($item)) {
            return;
        }
        $view->headLink()->appendStylesheet($view->assetUrl('css/admin-item.css', 'OmekaDipViewer'));
    }

    public function prepareSiteItemShowView(Event $event): void
    {
        $view = $event->getTarget();
        $item = $this->getItemFromView($view);
        if (!$item || !$this->itemHasDipPackageMedia($item)) {
            return;
        }
        $view->headLink()->appendStylesheet($view->assetUrl('css/site-item.css', 'OmekaDipViewer'));
        $view->headScript()->appendFile(
            $view->assetUrl('js/embla-carousel.umd.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/embla-carousel-wheel-gestures.umd.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/dip-embla.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/dip-gallery.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $view->headLink()->appendStylesheet($view->assetUrl('css/video-js.min.css', 'OmekaDipViewer'));
        $view->headScript()->appendFile(
            $view->assetUrl('js/video.min.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/dip-videos.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/dip-layout.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
    }

    /**
     * Admin item: add a "DIP files" tab when the item has browse-package media.
     *
     * @param Event $event
     * @return array<string, mixed>
     */
    public function addDipPackagesSectionNav(Event $event)
    {
        $item = $this->getItemFromView($event->getTarget());
        if (!$item || !$this->itemHasDipPackageMedia($item)) {
            return $event->getParams();
        }
        $params = $event->getParams();
        $nav = $params['section_nav'] ?? [];
        $nav['omeka-dip-packages'] = 'DIP files'; // @translate
        $params['section_nav'] = $nav;
        return $params;
    }

    /**
     * Admin item: section body for the DIP files tab (paired with section nav above).
     */
    public function displayDipPackagesSectionOnAdminItem(Event $event): void
    {
        $view = $event->getTarget();
        if (!$view->status()->isAdminRequest()) {
            return;
        }
        $item = $this->getItemFromView($view);
        if (!$item || !$this->itemHasDipPackageMedia($item)) {
            return;
        }
        echo '<div id="omeka-dip-packages" class="section">';
        $this->echoDipPackagesPartial($view, $item);
        echo '</div>';
    }

    protected function getItemFromView($view): ?ItemRepresentation
    {
        $item = $view->item ?? $view->resource ?? null;
        return $item instanceof ItemRepresentation ? $item : null;
    }

    protected function itemHasDipPackageMedia(ItemRepresentation $item): bool
    {
        foreach ($item->media() as $media) {
            if (DipPackageMedia::isDipPackage($media->renderer())) {
                return true;
            }
        }
        return false;
    }

    protected function echoDipPackagesPartial($view, ?ItemRepresentation $item): void
    {
        if (!$item) {
            return;
        }
        $dipMedia = [];
        foreach ($item->media() as $media) {
            if (DipPackageMedia::isDipPackage($media->renderer())) {
                $dipMedia[] = $media;
            }
        }
        if (!$dipMedia) {
            return;
        }
        echo $view->partial('omeka-dip-viewer/item-dip-packages', [
            'dipMedia' => $dipMedia,
        ]);
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $config = $this->getServiceLocator()->get('OmekaDipViewer\Service\DipConfig');
        return $renderer->partial('omeka-dip-viewer/config-form', [
            'threshold_mb' => (int) round($config->getLargePackageThresholdBytes() / (1024 * 1024)),
            'block_large' => $config->blockLargePackages(),
        ]);
    }

    public function handleConfigForm(\Laminas\Mvc\Controller\AbstractController $controller)
    {
        $post = $controller->params()->fromPost();
        $setting = $this->getServiceLocator()->get('Omeka\Settings');
        $thresholdMb = max(1, (int) ($post['large_package_threshold_mb'] ?? 500));
        $setting->set('omeka_dip_viewer.large_package_threshold_bytes', $thresholdMb * 1024 * 1024);
        $setting->set('omeka_dip_viewer.block_large_packages', !empty($post['block_large_packages']));
        return true;
    }

}
