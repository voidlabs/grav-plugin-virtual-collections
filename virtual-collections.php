<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;

final class VirtualCollectionsPlugin extends Plugin
{
    /** @var array<string,array<string,string>> */
    private array $routeCache = [];

    /** @var array<string,list<array{value:string,route:string,count:int}>> */
    private array $taxonomyCountCache = [];

    public static function getSubscribedEvents(): array
    {
        // Resolve virtual pages before broad legacy redirect rules (which may
        // contain catch-all patterns such as /blog/[^/]+$).
        return [
            'onPagesInitialized' => ['onPagesInitialized', 1100],
            'onTwigInitialized' => ['onTwigInitialized', 0],
        ];
    }

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig;
        $twig->addFunction(new \Twig\TwigFunction('void_collection_route', [$this, 'routeFor']));
        $twig->addFunction(new \Twig\TwigFunction('void_collection_taxonomy_counts', [$this, 'taxonomyCounts']));
    }

    public function onPagesInitialized(): void
    {
        $path = $this->normalizeRoute((string) $this->grav['uri']->path()) ?? '/';
        foreach ((array) $this->config->get('plugins.virtual-collections.collections', []) as $name => $definition) {
            if (!is_array($definition) || ($definition['enabled'] ?? true) === false) {
                continue;
            }
            $resolved = $this->resolve($path, $definition);
            if ($resolved !== null) {
                $this->dispatch((string) $name, $path, $definition, $resolved);
                return;
            }
        }
    }

    /** @return array{value:string,tokens:array<string,string>}|null */
    private function resolve(string $path, array $definition): ?array
    {
        $type = (string) ($definition['type'] ?? '');
        $prefix = '/' . trim((string) ($definition['route_prefix'] ?? ''), '/');

        if ($type === 'initial') {
            if ($prefix === '/') {
                return null;
            }
            if (preg_match('#^' . preg_quote($prefix, '#') . '/([^/]+)$#u', $path, $match) !== 1) {
                return null;
            }
            $value = $this->upper(rawurldecode($match[1]));
            $pattern = (string) ($definition['value_pattern'] ?? '[A-Z]');
            if (preg_match('#^(?:' . $pattern . ')$#u', $value) !== 1 || !$this->initialExists($value, $definition)) {
                return null;
            }
            return ['value' => $value, 'tokens' => ['value' => $value, 'value_upper' => $value]];
        }

        $routes = $this->routesForDefinition($definition);
        $value = array_search($path, $routes, true);
        if ($value === false) {
            return null;
        }
        $value = (string) $value;
        $tokens = ['value' => $value, 'value_upper' => $this->upper($value)];
        if ($type === 'date') {
            $format = (string) ($definition['value_format'] ?? 'Ym');
            $date = $this->dateFromValue($format, $value);
            if (!$date) {
                return null;
            }
            $monthNames = array_values((array) ($definition['month_names'] ?? []));
            $tokens += [
                'year' => $date->format('Y'),
                'month' => $date->format('m'),
                'month_name' => (string) ($monthNames[(int) $date->format('n') - 1] ?? $date->format('m')),
            ];
        }
        return ['value' => $value, 'tokens' => $tokens];
    }

    /** @return array<string,string> */
    private function routesFromPages(array $definition): array
    {
        $taxonomy = trim((string) ($definition['taxonomy'] ?? ''));
        $template = trim((string) ($definition['source_template'] ?? ''));
        $prefix = '/' . trim((string) ($definition['route_prefix'] ?? ''), '/');
        if ($taxonomy === '' || $prefix === '/') return [];
        $routes = [];
        $routeOwners = [];
        foreach ($this->grav['pages']->all() as $page) {
            if (!$page->published() || ($template !== '' && (string) $page->template() !== $template)) continue;
            $values = $this->taxonomyValues(($page->header()->taxonomy ?? [])[$taxonomy] ?? null);
            foreach ($values as $value) {
                $value = trim((string) $value);
                $slug = $this->slug($value);
                if ($value === '' || $slug === '') continue;
                $route = $prefix . '/' . $slug;
                if (isset($routeOwners[$route]) && $routeOwners[$route] !== $value) {
                    throw new \RuntimeException("Collisione slug nella collezione virtuale: {$route}.");
                }
                $routeOwners[$route] = $value;
                $routes[$value] = $route;
            }
        }
        return $routes;
    }

    /**
     * Return materialized virtual routes for integrations such as sitemaps.
     * @return list<array{route:string,modified:int,sitemap:bool}>
     */
    public function virtualRoutes(): array
    {
        $entries = [];
        $allRoutes = [];
        foreach ((array) $this->config->get('plugins.virtual-collections.collections', []) as $definition) {
            if (!is_array($definition) || ($definition['enabled'] ?? true) === false) {
                continue;
            }
            $routes = ($definition['type'] ?? '') === 'initial'
                ? $this->initialRoutes($definition)
                : $this->routesForDefinition($definition);
            $modified = 0;
            $sourceTemplate = trim((string) ($definition['source_template'] ?? ''));
            if ($sourceTemplate !== '') {
                foreach ($this->grav['pages']->all() as $page) {
                    if ($page->published() && (string) $page->template() === $sourceTemplate) {
                        $modified = max($modified, (int) $page->modified());
                    }
                }
            }
            foreach ($routes as $route) {
                if (is_string($route) && $route !== '') {
                    $route = $this->normalizeRoute($route);
                    if ($route === null) {
                        throw new \RuntimeException('Route non valida nella collezione virtuale.');
                    }
                    if (isset($allRoutes[$route])) {
                        throw new \RuntimeException("Collisione di route tra collezioni virtuali: {$route}.");
                    }
                    $allRoutes[$route] = true;
                    // Static route maps belong to the sitemap unless a
                    // collection opts out explicitly.
                    if (($definition['sitemap'] ?? true) !== true) continue;
                    $entries[$route] = ['route' => $route, 'modified' => $modified, 'sitemap' => true];
                }
            }
        }
        return array_values($entries);
    }

    public function routeFor(string $name, string $value): string
    {
        $definition = $this->definition($name);
        if ($definition === null) return '';
        $value = trim($value);
        $routes = $this->routesForDefinition($definition);
        return $value !== '' && isset($routes[$value]) ? (string) $routes[$value] : '';
    }

    /** @return list<array{value:string,route:string,count:int}> */
    public function taxonomyCounts(string $name): array
    {
        $definition = $this->definition($name);
        if ($definition === null || (string) ($definition['type'] ?? '') !== 'taxonomy') return [];
        $cacheKey = $this->definitionKey($definition);
        if (isset($this->taxonomyCountCache[$cacheKey])) return $this->taxonomyCountCache[$cacheKey];
        $taxonomy = trim((string) ($definition['taxonomy'] ?? ''));
        $template = trim((string) ($definition['source_template'] ?? ''));
        if ($taxonomy === '') return [];
        $routes = $this->routesForDefinition($definition);
        $counts = [];
        foreach ($this->grav['pages']->all() as $page) {
            if (!$page->published() || ($template !== '' && (string) $page->template() !== $template)) continue;
            $values = (($page->header()->taxonomy ?? [])[$taxonomy] ?? null);
            foreach ($this->taxonomyValues($values) as $value) {
                $value = trim((string) $value);
                if ($value === '' || !isset($routes[$value])) continue;
                if (!isset($counts[$value])) {
                    $counts[$value] = ['value' => $value, 'route' => (string) $routes[$value], 'count' => 0];
                }
                ++$counts[$value]['count'];
            }
        }
        return $this->taxonomyCountCache[$cacheKey] = array_values($counts);
    }

    private function initialExists(string $initial, array $definition): bool
    {
        $template = (string) ($definition['source_template'] ?? '');
        foreach ($this->grav['pages']->all() as $page) {
            if (($template === '' || (string) $page->template() === $template) && $page->published()) {
                $title = (string) $page->title();
                $first = function_exists('mb_substr') ? mb_substr($title, 0, 1, 'UTF-8') : substr($title, 0, 1);
                if ($this->upper($first) === $initial) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function definition(string $name): ?array
    {
        $definition = $this->config->get('plugins.virtual-collections.collections.' . $name);
        return is_array($definition) && ($definition['enabled'] ?? true) !== false ? $definition : null;
    }

    /** @return array<string,string> */
    private function routesForDefinition(array $definition): array
    {
        $cacheKey = $this->definitionKey($definition);
        if (isset($this->routeCache[$cacheKey])) return $this->routeCache[$cacheKey];
        $mapKey = (string) ($definition['route_map_config'] ?? '');
        $routes = $mapKey === '' ? [] : (array) $this->grav['config']->get($mapKey, []);
        if (($definition['auto_from_pages'] ?? false) === true) {
            foreach ($this->routesFromPages($definition) as $value => $route) {
                if (array_key_exists($value, $routes)) {
                    if ((string) $routes[$value] !== (string) $route) {
                        throw new \RuntimeException("Collisione di route nella collezione virtuale: {$route}.");
                    }
                    continue;
                }
                if (in_array($route, $routes, true)) {
                    throw new \RuntimeException("Collisione di route nella collezione virtuale: {$route}.");
                }
                $routes[$value] = $route;
            }
        }
        $normalized = [];
        $owners = [];
        foreach ($routes as $value => $route) {
            $value = trim((string) $value);
            $route = $this->normalizeRoute((string) $route);
            if ($value === '' || $route === null) {
                throw new \RuntimeException('Route non valida nella mappa della collezione virtuale.');
            }
            if (isset($owners[$route]) && $owners[$route] !== $value) {
                throw new \RuntimeException("Collisione di route nella collezione virtuale: {$route}.");
            }
            $owners[$route] = $value;
            $normalized[$value] = $route;
        }
        return $this->routeCache[$cacheKey] = $normalized;
    }

    /** @return list<string> */
    private function initialRoutes(array $definition): array
    {
        $prefix = '/' . trim((string) ($definition['route_prefix'] ?? ''), '/');
        if ($prefix === '/') return [];
        $letters = [];
        foreach ($this->grav['pages']->all() as $page) {
            if (!$page->published() || (($template = trim((string) ($definition['source_template'] ?? ''))) !== '' && (string) $page->template() !== $template)) continue;
            $first = function_exists('mb_substr') ? mb_substr((string) $page->title(), 0, 1, 'UTF-8') : substr((string) $page->title(), 0, 1);
            $letter = $this->upper($first);
            if ($letter !== '') $letters[$letter] = $prefix . '/' . rawurlencode($letter);
        }
        return array_values($letters);
    }

    private function definitionKey(array $definition): string
    {
        return sha1(serialize($definition));
    }

    /** @param array{value:string,tokens:array<string,string>} $resolved */
    private function dispatch(string $name, string $path, array $definition, array $resolved): void
    {
        $base = $this->grav['pages']->dispatch((string) ($definition['index_route'] ?? ''), true);
        if (!$base) {
            return;
        }
        $page = clone $base;
        $page->route($path);
        $page->rawRoute($path);
        $page->template((string) ($definition['template'] ?? $base->template()));
        $title = $this->interpolate((string) ($definition['title'] ?? '{value}'), $resolved['tokens']);
        $page->title($title);
        $page->modifyHeader('title', $title);
        $page->modifyHeader('virtual_collection', $name);
        $page->modifyHeader('collection_value', $resolved['value']);
        foreach ((array) ($definition['headers'] ?? []) as $key => $value) {
            $page->modifyHeader((string) $key, $this->interpolate((string) $value, $resolved['tokens']));
        }

        $content = $this->collectionContent($definition, $resolved['value']);
        if ($content !== null) {
            $page->modifyHeader('content', $content);
        }
        unset($this->grav['page']);
        $this->grav['page'] = $page;
    }

    /** @return array<string,mixed>|null */
    private function collectionContent(array $definition, string $value): ?array
    {
        $type = (string) ($definition['type'] ?? '');
        if ($type === 'initial') {
            return null;
        }
        $content = [
            'order' => [
                'by' => (string) ($definition['order_by'] ?? 'date'),
                'dir' => (string) ($definition['order_dir'] ?? 'desc'),
            ],
            'limit' => (int) ($definition['limit'] ?? 10),
            'pagination' => (bool) ($definition['pagination'] ?? true),
        ];
        $sourceTemplate = trim((string) ($definition['source_template'] ?? ''));
        if ($sourceTemplate !== '') {
            $content['filter'] = ['type' => $sourceTemplate];
        }
        if ($type === 'taxonomy') {
            $content['items'] = ['@taxonomy.' . (string) ($definition['taxonomy'] ?? '') => $value];
            return $content;
        }
        if ($type === 'date') {
            $format = (string) ($definition['value_format'] ?? 'Ym');
            $date = $this->dateFromValue($format, $value);
            if (!$date) {
                return null;
            }
            $sourceTaxonomy = trim((string) ($definition['source_taxonomy'] ?? ''));
            $content['items'] = $sourceTaxonomy === ''
                ? ['@page.descendants' => (string) ($definition['source_route'] ?? '/')]
                : ['@taxonomy.' . $sourceTaxonomy => (string) ($definition['source_value'] ?? '')];
            $content['dateRange'] = [
                'start' => $date->format('Y-m-d H:i:s'),
                'end' => $date->modify('+1 month -1 second')->format('Y-m-d H:i:s'),
            ];
            return $content;
        }
        return null;
    }

    private function dateFromValue(string $format, string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    /** @param array<string,string> $tokens */
    private function interpolate(string $value, array $tokens): string
    {
        $replace = [];
        foreach ($tokens as $name => $token) {
            $replace['{' . $name . '}'] = $token;
        }
        return strtr($value, $replace);
    }

    private function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function slug(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? $value;
        return trim($value, '-');
    }

    /** @return list<mixed> */
    private function taxonomyValues(mixed $value): array
    {
        if ($value === null || $value === '') return [];
        return is_array($value) ? array_values($value) : [$value];
    }

    private function normalizeRoute(string $route): ?string
    {
        if ($route === '' || !str_starts_with($route, '/') || str_starts_with($route, '//')
            || preg_match('//u', $route) !== 1
            || preg_match('/[\x00-\x20\x7f]|%(?![0-9A-Fa-f]{2})/u', $route) === 1) return null;
        $parts = parse_url($route);
        if (!is_array($parts) || array_intersect_key($parts, array_flip(['scheme', 'host', 'query', 'fragment'])) !== [] || str_contains($route, "\0")) return null;
        $decoded = rawurldecode((string) ($parts['path'] ?? ''));
        if (preg_match('/[?#\x00-\x20\x7f]/u', $decoded) === 1
            || preg_match('~(?:^|/)page:\\d+(?:/|$)~iu', $decoded) === 1) return null;
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') return null;
        }
        $path = '/' . trim($decoded, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
