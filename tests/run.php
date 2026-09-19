<?php

declare(strict_types=1);

namespace Grav\Common {
    class Plugin
    {
        public mixed $grav;
        public mixed $config;
    }
}

namespace {
    require_once dirname(__DIR__) . '/virtual-collections.php';

    final class FakeHeader
    {
        public function __construct(public array $taxonomy = [])
        {
        }
    }

    final class FakePage
    {
        public function __construct(
            private bool $isPublished,
            private string $pageTemplate,
            private string $pageTitle,
            private int $pageModified = 0,
            private array $pageTaxonomy = [],
        ) {
        }

        public function published(): bool
        {
            return $this->isPublished;
        }

        public function template(): string
        {
            return $this->pageTemplate;
        }

        public function title(): string
        {
            return $this->pageTitle;
        }

        public function modified(): int
        {
            return $this->pageModified;
        }

        public function header(): FakeHeader
        {
            return new FakeHeader($this->pageTaxonomy);
        }
    }

    final class FakePages
    {
        /** @param list<FakePage> $items */
        public function __construct(private array $items)
        {
        }

        /** @return list<FakePage> */
        public function all(): array
        {
            return $this->items;
        }

        public function dispatch(string $route, bool $raw = false): ?FakePage
        {
            return $this->items[0] ?? null;
        }
    }

    final class FakeConfig
    {
        public function __construct(private array $values)
        {
        }

        public function get(string $path, mixed $default = null): mixed
        {
            if (array_key_exists($path, $this->values)) {
                return $this->values[$path];
            }

            $value = $this->values;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value;
        }
    }

    final class FakeGrav implements \ArrayAccess
    {
        public function __construct(private array $services)
        {
        }

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists($offset, $this->services);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->services[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->services[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->services[$offset]);
        }
    }

    $checks = 0;
    $failures = [];

    function check_contract(bool $condition, string $message): void
    {
        global $checks, $failures;
        ++$checks;
        if (!$condition) {
            $failures[] = $message;
        }
    }

    function expect_runtime_exception(callable $callback, string $message): void
    {
        global $checks, $failures;
        ++$checks;
        try {
            $callback();
            $failures[] = $message;
        } catch (\RuntimeException) {
        }
    }

    function invoke_private(object $instance, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($instance, ...$arguments);
    }

    function make_plugin(array $collections = [], array $configValues = [], array $pages = []): object
    {
        $reflection = new \ReflectionClass(\Grav\Plugin\VirtualCollectionsPlugin::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $config = new FakeConfig(array_replace_recursive(
            ['plugins' => ['virtual-collections' => ['collections' => $collections]]],
            $configValues,
        ));
        $instance->config = $config;
        $instance->grav = new FakeGrav([
            'config' => $config,
            'pages' => new FakePages($pages),
        ]);

        return $instance;
    }

    $root = dirname(__DIR__);
    $source = (string) file_get_contents($root . '/virtual-collections.php');
    $blueprint = (string) file_get_contents($root . '/blueprints.yaml');
    $readme = (string) file_get_contents($root . '/README.md');
    $composer = (string) file_get_contents($root . '/composer.json');

    check_contract(str_contains($blueprint, 'slug: virtual-collections'), 'Slug del plugin assente.');
    check_contract(str_contains($source, "'onPagesInitialized' => ['onPagesInitialized', 1100]"), 'Priorità di inizializzazione non esplicita.');
    check_contract(str_contains($source, 'auto_from_pages'), 'Generazione opzionale dalle pagine assente.');
    check_contract(str_contains($source, 'taxonomyValues'), 'Normalizzazione dei valori tassonomici assente.');
    check_contract(str_contains($source, 'Collisione di route'), 'Controllo collisioni route assente.');
    check_contract(!str_contains($source, 'eval('), 'Il plugin non deve eseguire codice dinamico.');
    check_contract(str_contains($readme, 'virtualRoutes()'), 'Contratto sitemap non documentato.');
    check_contract(str_contains($composer, 'voidlabs/grav-plugin-virtual-collections'), 'Nome Composer del pacchetto assente.');

    $reflection = new \ReflectionClass(\Grav\Plugin\VirtualCollectionsPlugin::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $normalizeRoute = $reflection->getMethod('normalizeRoute');
    $normalizeRoute->setAccessible(true);
    check_contract($normalizeRoute->invoke($instance, '/categoria/test/') === '/categoria/test', 'Trailing slash non normalizzato.');
    check_contract($normalizeRoute->invoke($instance, '/') === '/', 'La route root deve restare valida.');
    check_contract($normalizeRoute->invoke($instance, '/categoria/test?x=1') === null, 'Query accettata nella route virtuale.');
    check_contract($normalizeRoute->invoke($instance, '/categoria/%3Fx=1') === null, 'Query codificata accettata nella route virtuale.');
    check_contract($normalizeRoute->invoke($instance, 'https://evil.test/x') === null, 'URL esterna accettata nella route virtuale.');
    check_contract($normalizeRoute->invoke($instance, '/a/../b') === null, 'Dot segment accettato nella route virtuale.');
    check_contract($normalizeRoute->invoke($instance, '/page:2') === null, 'La route ambigua page:2 deve essere rifiutata.');

    $slug = $reflection->getMethod('slug');
    $slug->setAccessible(true);
    check_contract($slug->invoke($instance, ' Caffè & tè ') === 'caffè-tè', 'Slug Unicode non deterministico.');

    $interpolate = $reflection->getMethod('interpolate');
    $interpolate->setAccessible(true);
    check_contract(
        $interpolate->invoke($instance, 'Archivio {month_name} {year}', ['month_name' => 'Gennaio', 'year' => '2026']) === 'Archivio Gennaio 2026',
        'Interpolazione dei token errata.'
    );

    $taxonomyValues = $reflection->getMethod('taxonomyValues');
    $taxonomyValues->setAccessible(true);
    check_contract($taxonomyValues->invoke($instance, null) === [], 'Valore tassonomico nullo non normalizzato.');
    check_contract($taxonomyValues->invoke($instance, '') === [], 'Valore tassonomico vuoto non normalizzato.');
    check_contract($taxonomyValues->invoke($instance, 'tag') === ['tag'], 'Valore tassonomico scalare non normalizzato.');
    check_contract($taxonomyValues->invoke($instance, ['a', 'b']) === ['a', 'b'], 'Valori tassonomici multipli non conservati.');

    $pages = [
        new FakePage(true, 'article', 'Apple', 100, ['tag' => 'News']),
        new FakePage(true, 'article', 'Éclair', 200, ['tag' => ['News', 'Dessert']]),
        new FakePage(false, 'article', 'Ignored', 300, ['tag' => 'News']),
    ];
    $taxonomyDefinition = [
        'type' => 'taxonomy',
        'taxonomy' => 'tag',
        'route_map_config' => 'site.virtual_collections.tags',
        'source_template' => 'article',
        'limit' => 12,
        'pagination' => false,
        'order_by' => 'title',
        'order_dir' => 'asc',
    ];
    $taxonomyPlugin = make_plugin(
        ['tags' => $taxonomyDefinition],
        ['site' => ['virtual_collections' => [
            'tags' => ['News' => '/tag/news', 'Dessert' => '/tag/dessert'],
            'colliding' => ['One' => '/same', 'Two' => '/same'],
        ]]],
        $pages,
    );
    check_contract($taxonomyPlugin->routeFor('tags', 'News') === '/tag/news', 'Mappa tassonomica non risolta.');
    check_contract($taxonomyPlugin->routeFor('missing', 'News') === '', 'Collezione inesistente non gestita.');
    $counts = $taxonomyPlugin->taxonomyCounts('tags');
    check_contract($counts === [
        ['value' => 'News', 'route' => '/tag/news', 'count' => 2],
        ['value' => 'Dessert', 'route' => '/tag/dessert', 'count' => 1],
    ], 'Conteggi tassonomici errati.');

    $collectionContent = $reflection->getMethod('collectionContent');
    $collectionContent->setAccessible(true);
    $taxonomyContent = $collectionContent->invoke($taxonomyPlugin, $taxonomyDefinition, 'News');
    check_contract($taxonomyContent['limit'] === 12, 'Limite della collezione non conservato.');
    check_contract($taxonomyContent['pagination'] === false, 'Impostazione pagination non conservata.');
    check_contract($taxonomyContent['order'] === ['by' => 'title', 'dir' => 'asc'], 'Ordinamento della collezione errato.');
    check_contract($taxonomyContent['filter'] === ['type' => 'article'], 'Filtro template della collezione errato.');
    check_contract($taxonomyContent['items'] === ['@taxonomy.tag' => 'News'], 'Query tassonomica errata.');

    $dateContent = $collectionContent->invoke($taxonomyPlugin, [
        'type' => 'date',
        'value_format' => 'Ym',
        'source_route' => '/blog',
        'limit' => 10,
    ], '202602');
    check_contract($dateContent['dateRange'] === [
        'start' => '2026-02-01 00:00:00',
        'end' => '2026-02-28 23:59:59',
    ], 'Intervallo date mensile errato.');
    check_contract($dateContent['items'] === ['@page.descendants' => '/blog'], 'Query date errata.');
    $dateDefinition = [
        'type' => 'date',
        'value_format' => 'Ym',
        'route_map_config' => 'site.virtual_collections.months',
        'month_names' => ['Gennaio', 'Febbraio'],
    ];
    $datePlugin = make_plugin(
        ['months' => $dateDefinition],
        ['site' => ['virtual_collections' => ['months' => ['202602' => '/archivio/2026-02', '202613' => '/archivio/invalid']]]],
    );
    $resolvedDate = invoke_private($datePlugin, 'resolve', '/archivio/2026-02', $dateDefinition);
    check_contract($resolvedDate['tokens']['month_name'] === 'Febbraio', 'Token date non risolti.');
    check_contract(invoke_private($datePlugin, 'resolve', '/archivio/invalid', $dateDefinition) === null, 'Valore date impossibile accettato.');

    $initialDefinition = [
        'type' => 'initial',
        'route_prefix' => '/autori',
        'source_template' => 'article',
        'value_pattern' => '[A-Z]',
    ];
    $initialPlugin = make_plugin([], [], $pages);
    $initialRoutes = invoke_private($initialPlugin, 'initialRoutes', $initialDefinition);
    check_contract($initialRoutes === ['/autori/A', '/autori/%C3%89'], 'Route iniziali non generate correttamente.');
    $resolvedInitial = invoke_private($initialPlugin, 'resolve', '/autori/A', $initialDefinition);
    check_contract($resolvedInitial['value'] === 'A', 'Route iniziale non risolta.');

    expect_runtime_exception(
        fn() => invoke_private($taxonomyPlugin, 'routesForDefinition', [
            'type' => 'taxonomy',
            'route_map_config' => 'site.virtual_collections.colliding',
        ]),
        'Collisione nella mappa statica non rilevata.',
    );
    $collisionPlugin = make_plugin([], [], [
        new FakePage(true, 'article', 'Caffe!', 0, ['tag' => 'Caffe!']),
        new FakePage(true, 'article', 'Caffe?', 0, ['tag' => 'Caffe?']),
    ]);
    expect_runtime_exception(
        fn() => invoke_private($collisionPlugin, 'routesForDefinition', [
            'type' => 'taxonomy',
            'taxonomy' => 'tag',
            'route_prefix' => '/tag',
            'auto_from_pages' => true,
        ]),
        'Collisione di slug generata automaticamente non rilevata.',
    );

    $invalidRoutePlugin = make_plugin(
        ['invalid' => ['type' => 'taxonomy', 'route_map_config' => 'site.virtual_collections.invalid']],
        ['site' => ['virtual_collections' => ['invalid' => ['Tag' => '/tag?query=1']]]],
    );
    expect_runtime_exception(
        fn() => $invalidRoutePlugin->routeFor('invalid', 'Tag'),
        'Route con query nella mappa non rifiutata.',
    );

    $sitemapPlugin = make_plugin(
        [
            'visible' => ['type' => 'taxonomy', 'route_map_config' => 'site.maps.visible'],
            'hidden' => ['type' => 'taxonomy', 'route_map_config' => 'site.maps.hidden', 'sitemap' => false],
        ],
        ['site' => ['maps' => ['visible' => ['News' => '/tag/news'], 'hidden' => ['Draft' => '/tag/draft']]]],
    );
    check_contract($sitemapPlugin->virtualRoutes() === [
        ['route' => '/tag/news', 'modified' => 0, 'sitemap' => true],
    ], 'Flag sitemap non applicato correttamente.');

    $crossCollectionPlugin = make_plugin(
        [
            'first' => ['type' => 'taxonomy', 'route_map_config' => 'site.maps.first'],
            'second' => ['type' => 'taxonomy', 'route_map_config' => 'site.maps.second'],
        ],
        ['site' => ['maps' => ['first' => ['A' => '/same'], 'second' => ['B' => '/same']]]],
    );
    expect_runtime_exception(
        fn() => $crossCollectionPlugin->virtualRoutes(),
        'Collisione tra collezioni virtuali non rilevata.',
    );

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
        }
        exit(1);
    }

    echo "OK: {$checks} virtual-collections checks." . PHP_EOL;
}
