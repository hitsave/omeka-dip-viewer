<?php
namespace OmekaDipViewer\ViewHelper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Settings\SettingsInterface;
/**
 * Public URL for a file inside a DIP (ARK component path when Ark is active).
 */
class DipFileUrl extends AbstractHelper
{
    protected SettingsInterface $settings;
    protected ModuleManager $moduleManager;

    public function __construct(SettingsInterface $settings, ModuleManager $moduleManager)
    {
        $this->settings = $settings;
        $this->moduleManager = $moduleManager;
    }

    /**
     * @param 'original'|'access'|string|null $variant ARK variant suffix (e.g. access) or query on fallback URL
     */
    public function __invoke(
        int $itemId,
        int $mediaId,
        string $fileKey,
        ?string $variant = null,
        ?string $siteSlug = null
    ): string {
        $view = $this->getView();
        $variant = $variant ?: 'original';

        if ($this->canUseArkUrls()) {
            $siteSlug = $siteSlug ?: $this->resolveSiteSlug();
            $naan = (string) $this->settings->get('ark_naan');
            if ($siteSlug !== null && $naan !== '') {
                $fileKeyParam = $fileKey;
                if ($variant !== 'original') {
                    $fileKeyParam = $fileKey . '.' . $variant;
                }

                return $view->url('site/ark/dip-component', [
                    'site-slug' => $siteSlug,
                    'naan' => $naan,
                    'name' => $itemId,
                    'media_id' => $mediaId,
                    'file_key' => $fileKeyParam,
                ]);
            }
        }

        $options = [];
        if ($variant !== 'original') {
            $options['query'] = ['variant' => $variant];
        }

        return $view->url('omeka-dip-file', [
            'media_id' => $mediaId,
            'file_key' => $fileKey,
        ], $options);
    }

    protected function canUseArkUrls(): bool
    {
        $ark = $this->moduleManager->getModule('Ark');
        if (!$ark || $ark->getState() !== 'active') {
            return false;
        }

        return (string) $this->settings->get('ark_naan') !== '';
    }

    protected function resolveSiteSlug(): ?string
    {
        $view = $this->getView();
        $site = $view->site ?? null;
        if (is_object($site) && method_exists($site, 'slug')) {
            return (string) $site->slug();
        }

        if (method_exists($view, 'vars')) {
            $site = $view->vars('site');
            if (is_object($site) && method_exists($site, 'slug')) {
                return (string) $site->slug();
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#/s/([^/]+)/#', $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
