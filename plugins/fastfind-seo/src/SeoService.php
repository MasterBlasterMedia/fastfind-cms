<?php

namespace FastFind\Seo;

use App\Models\AuditEvent;
use App\Models\Page;
use App\Plugins\HeadMetadata;
use App\Plugins\PluginContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SeoService
{
    private const ROBOTS = ['index,follow', 'noindex,follow', 'noindex,nofollow'];
    public function __construct(private PluginContext $context) {}

    public static function pageFields(): array
    {
        return [
            ['key' => 'title', 'label' => 'Search title', 'type' => 'text'],
            ['key' => 'description', 'label' => 'Meta description', 'type' => 'textarea'],
            ['key' => 'canonical', 'label' => 'Canonical URL (absolute HTTP or HTTPS)', 'type' => 'url'],
            ['key' => 'robots', 'label' => 'Search indexing', 'type' => 'select', 'options' => self::ROBOTS, 'default' => 'index,follow'],
            ['key' => 'og_title', 'label' => 'Social title', 'type' => 'text'],
            ['key' => 'og_description', 'label' => 'Social description', 'type' => 'textarea'],
            ['key' => 'og_image', 'label' => 'Social image URL', 'type' => 'url'],
            ['key' => 'twitter_card', 'label' => 'Social card style', 'type' => 'select', 'options' => ['summary', 'summary_large_image'], 'default' => 'summary'],
            ['key' => 'structured_type', 'label' => 'Structured data', 'type' => 'select', 'options' => ['none', 'WebPage', 'Article', 'Organization'], 'default' => 'none'],
            ['key' => 'structured_name', 'label' => 'Structured data name', 'type' => 'text'],
            ['key' => 'structured_author', 'label' => 'Article author', 'type' => 'text'],
        ];
    }

    public static function settingsFields(): array
    {
        return [
            ['key' => 'title_suffix', 'label' => 'Title suffix', 'type' => 'text', 'default' => '| FastFind'],
            ['key' => 'default_description', 'label' => 'Default meta description', 'type' => 'textarea'],
            ['key' => 'default_robots', 'label' => 'Default robots directive', 'type' => 'select', 'options' => self::ROBOTS, 'default' => 'index,follow'],
            ['key' => 'sitemap_enabled', 'label' => 'Enable XML sitemaps', 'type' => 'boolean', 'default' => true],
        ];
    }

    public function validatePage(array $values): array
    {
        $allowed = array_column(self::pageFields(), 'key');
        if (array_diff(array_keys($values), $allowed)) throw ValidationException::withMessages(['extensions.fastfind/seo' => 'Unknown SEO field.']);
        $data = Validator::make($values, [
            'title' => ['nullable', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:320'],
            'canonical' => ['nullable', 'string', 'max:1500'], 'robots' => ['nullable', Rule::in(self::ROBOTS)],
            'og_title' => ['nullable', 'string', 'max:180'], 'og_description' => ['nullable', 'string', 'max:320'],
            'og_image' => ['nullable', 'string', 'max:1500'], 'twitter_card' => ['nullable', Rule::in(['summary', 'summary_large_image'])],
            'structured_type' => ['nullable', Rule::in(['none', 'WebPage', 'Article', 'Organization'])],
            'structured_name' => ['nullable', 'string', 'max:180'], 'structured_author' => ['nullable', 'string', 'max:180'],
        ])->validate();
        foreach (['canonical', 'og_image'] as $key) {
            if (!empty($data[$key]) && !HeadMetadata::httpUrl($data[$key])) throw ValidationException::withMessages(['extensions.fastfind/seo.'.$key => 'Use an absolute HTTP or HTTPS URL without credentials.']);
        }
        if (($data['structured_type'] ?? '') === 'Article' && empty($data['structured_author'])) throw ValidationException::withMessages(['structured_author' => 'An article requires an author.']);
        return $data;
    }

    public function validateSettings(array $values): array
    {
        if (array_diff(array_keys($values), array_column(self::settingsFields(), 'key'))) throw ValidationException::withMessages(['settings' => 'Unknown SEO setting.']);
        return Validator::make($values, ['title_suffix' => ['nullable', 'string', 'max:80'], 'default_description' => ['nullable', 'string', 'max:320'],
            'default_robots' => ['required', Rule::in(self::ROBOTS)], 'sitemap_enabled' => ['required', 'boolean']])->validate();
    }

    public function head(array $published): array
    {
        $data = $published['extensions'][$this->context->id] ?? [];
        $settings = $this->context->settings();
        $title = ($data['title'] ?? null) ?: ($published['seo_title'] ?? null) ?: ($published['title'] ?? '');
        $description = ($data['description'] ?? null) ?: ($published['seo_description'] ?? null) ?: ($published['summary'] ?? null) ?: ($settings['default_description'] ?? '');
        $canonical = ($data['canonical'] ?? null) ?: rtrim(config('app.url'), '/').'/p/'.$published['slug'];
        $suffix = trim($settings['title_suffix'] ?? '| FastFind');
        $head = ['title' => $title.($suffix !== '' ? ' '.$suffix : ''), 'description' => $description, 'canonical' => $canonical,
            'meta' => ['robots' => ($data['robots'] ?? null) ?: ($settings['default_robots'] ?? 'index,follow'), 'og:title' => ($data['og_title'] ?? null) ?: $title,
                'og:description' => ($data['og_description'] ?? null) ?: $description, 'og:url' => $canonical,
                'og:type' => ($data['structured_type'] ?? '') === 'Article' ? 'article' : 'website', 'twitter:card' => ($data['twitter_card'] ?? null) ?: 'summary']];
        $head['meta']['twitter:title'] = $head['meta']['og:title'];
        $head['meta']['twitter:description'] = $head['meta']['og:description'];
        if (!empty($data['og_image'])) $head['meta']['og:image'] = $head['meta']['twitter:image'] = $data['og_image'];
        if (in_array($data['structured_type'] ?? '', ['WebPage', 'Article', 'Organization'], true)) {
            $head['structured_data'] = ['@context' => 'https://schema.org', '@type' => $data['structured_type'], 'name' => ($data['structured_name'] ?? null) ?: $title, 'description' => $description, 'url' => $canonical];
            if ($data['structured_type'] === 'Article') {
                $head['structured_data']['headline'] = $title;
                $head['structured_data']['author'] = ['@type' => 'Person', 'name' => $data['structured_author']];
            }
        }
        return $head;
    }

    public function redirects() { return response()->json(['redirects' => DB::table('fastfind_seo_redirects')->orderBy('source')->get(['source', 'destination', 'status'])]); }

    public function saveRedirects(Request $request)
    {
        $data = $request->validate(['redirects' => ['present', 'array', 'max:200'], 'redirects.*.source' => ['required', 'string', 'max:500', 'distinct'],
            'redirects.*.destination' => ['required', 'string', 'max:1500'], 'redirects.*.status' => ['required', Rule::in([301, 302, 307, 308])]]);
        $sources = array_column($data['redirects'], 'destination', 'source');
        $targets = [];
        foreach ($sources as $source => $destination) {
            if (!$this->localPath($source) || parse_url($source, PHP_URL_PATH) !== $source || $this->pathIdentity($source) !== $source
                || preg_match('~^/(api|studio|storage|build|up|sitemap\.xml|sitemap-pages\.xml)(/|$)~', $source)) throw ValidationException::withMessages(['redirects' => 'Redirect sources must be canonical public paths outside reserved routes, without query strings or fragments.']);
            if (!$this->localPath($destination) && !HeadMetadata::httpUrl($destination)) throw ValidationException::withMessages(['redirects' => 'Redirect destinations must be safe local paths or absolute HTTP/HTTPS URLs.']);
            $targets[$source] = $this->localTarget($destination);
        }
        foreach ($targets as $source => $destination) {
            $visited = [$source => true]; $next = $destination;
            while ($next !== null && array_key_exists($next, $targets)) {
                if (isset($visited[$next])) throw ValidationException::withMessages(['redirects' => 'Redirect cycles are not permitted.']);
                $visited[$next] = true; $next = $targets[$next];
            }
            if ($next !== null && isset($visited[$next])) throw ValidationException::withMessages(['redirects' => 'Redirect cycles are not permitted.']);
        }
        DB::transaction(function () use ($data, $request) {
            DB::table('fastfind_seo_redirects')->delete();
            foreach ($data['redirects'] as $row) DB::table('fastfind_seo_redirects')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'plugin.seo.redirects_saved', 'subject' => 'plugin', 'context' => ['count' => count($data['redirects'])]]);
        });
        return $this->redirects();
    }

    private function localPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !preg_match('/[\\\\\x00-\x20\x7f<>"\']/', $path)
            && !preg_match('/%(?:2f|5c|0[0-9a-f]|1[0-9a-f]|7f)|%(?![0-9a-f]{2})/i', $path);
    }

    private function localTarget(string $url): ?string
    {
        if ($this->localPath($url)) return $this->pathIdentity($url);
        $target = parse_url($url);
        $site = parse_url(config('app.url'));
        if (!is_array($target) || !is_array($site)) return null;
        $port = fn (array $parts) => $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        if (strtolower($target['scheme'] ?? '') !== strtolower($site['scheme'] ?? '')
            || strtolower($target['host'] ?? '') !== strtolower($site['host'] ?? '') || $port($target) !== $port($site)) return null;
        return $this->pathIdentity($url);
    }

    /** Match the request path after browser URL normalization; query and fragment do not escape a loop. */
    private function pathIdentity(string $url): string
    {
        $path = rawurldecode(parse_url($url, PHP_URL_PATH) ?? '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') continue;
            if ($segment === '..') { if (count($segments) > 1) array_pop($segments); continue; }
            $segments[] = $segment;
        }
        $normalized = implode('/', $segments);
        return $normalized === '' ? '/' : $normalized;
    }

    public function redirect(Request $request)
    {
        $redirect = DB::table('fastfind_seo_redirects')->where('source', $request->getPathInfo())->first();
        return $redirect ? redirect()->to($redirect->destination, $redirect->status) : null;
    }

    private function sitemapPages()
    {
        abort_unless($this->context->settings()['sitemap_enabled'] ?? true, 404);
        $default = $this->context->settings()['default_robots'] ?? 'index,follow';
        return Page::whereNotNull('published_snapshot')->whereNotNull('published_slug')->where('published_in_search', true)->orderBy('id')->get(['published_snapshot', 'published_slug', 'published_at'])
            ->filter(fn ($page) => !str_contains($page->publishedView()['extensions'][$this->context->id]['robots'] ?? $default, 'noindex'))->values();
    }

    public function sitemapIndex()
    {
        $count = max(1, (int) ceil($this->sitemapPages()->count() / 1000));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        for ($page = 1; $page <= $count; $page++) $xml .= '<sitemap><loc>'.$this->xml(rtrim(config('app.url'), '/').'/sitemap-pages.xml?page='.$page).'</loc></sitemap>';
        return response($xml.'</sitemapindex>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemap(Request $request)
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $all = $this->sitemapPages();
        $pageNumber = (int) ($input['page'] ?? 1);
        abort_if($pageNumber > max(1, (int) ceil($all->count() / 1000)), 404);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($all->slice(($pageNumber - 1) * 1000, 1000) as $page) $xml .= '<url><loc>'.$this->xml(rtrim(config('app.url'), '/').'/p/'.$page->published_slug).'</loc><lastmod>'.$this->xml($page->published_at?->toAtomString() ?? '').'</lastmod></url>';
        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
}
