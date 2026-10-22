<?php

use App\Plugins\AbstractPlugin;
use App\Plugins\PluginContext;
use FastFind\Seo\SeoService;

require_once __DIR__.'/src/SeoService.php';

return new class extends AbstractPlugin {
    public function register(PluginContext $context): void
    {
        $seo = new SeoService($context);
        $context->pageFields('FastFind SEO', SeoService::pageFields(), $seo->validatePage(...));
        $context->settingsFields(SeoService::settingsFields(), $seo->validateSettings(...));
        $context->head($seo->head(...));
        $context->adminView('seo', 'FastFind SEO');
        $context->route('GET', 'redirects', 'settings.manage', $seo->redirects(...));
        $context->route('PUT', 'redirects', 'settings.manage', $seo->saveRedirects(...));
        $context->publicRoute('/sitemap.xml', $seo->sitemapIndex(...));
        $context->publicRoute('/sitemap-pages.xml', $seo->sitemap(...));
        $context->publicResponse($seo->redirect(...));
    }

    public function activate(PluginContext $context): void { $context->migrate(); }
    public function upgrade(PluginContext $context, string $fromVersion): void { $context->migrate(); }
    public function uninstall(PluginContext $context, bool $purge): void
    {
        if ($purge && \Illuminate\Support\Facades\Schema::hasTable('fastfind_seo_redirects')) \Illuminate\Support\Facades\DB::table('fastfind_seo_redirects')->delete();
        // Page/revision extension data is intentionally retained even on plugin purge.
    }
};
