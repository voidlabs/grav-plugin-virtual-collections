# Virtual Collections

Plugin Grav 2 per esporre route virtuali configurabili per tassonomie, periodi
mensili e indici alfabetici. Le pagine virtuali vengono costruite a partire da
una pagina indice esistente e non modificano la struttura dei contenuti.

## Requisiti e installazione

- Grav 2.0 o superiore;
- PHP 8.3 o superiore;
- `voidlabs/grav-plugin-void-metadata` è opzionale e abilita l'inclusione delle
  route virtuali nella sitemap del sito.

Installare il pacchetto nella root del sito Grav:

```sh
composer require voidlabs/grav-plugin-virtual-collections
```

Le definizioni delle collezioni e le mappe delle route appartengono alla
configurazione del sito che usa il plugin, non a questo repository.

## Configurazione

Una collezione ha almeno `type`, `index_route` e una sorgente di route. Le
mappe statiche vengono lette dal percorso Grav indicato da `route_map_config`:

```yaml
plugins:
  virtual-collections:
    collections:
      tags:
        type: taxonomy
        taxonomy: tag
        route_map_config: site.virtual_collections.tags
        index_route: /archivio
        template: collection
        title: 'Tag: {value}'
        source_template: article
        limit: 12
        pagination: true
        sitemap: true

site:
  virtual_collections:
    tags:
      News: /tag/news
      Guide: /tag/guide
```

Per generare automaticamente la mappa dai contenuti pubblicati, impostare
`auto_from_pages: true` e mantenere `taxonomy`, `route_prefix` e,
facoltativamente, `source_template`:

```yaml
plugins:
  virtual-collections:
    collections:
      topics:
        type: taxonomy
        taxonomy: topic
        route_prefix: /topic
        auto_from_pages: true
        index_route: /archivio
        source_template: article
```

I valori tassonomici possono essere una stringa o un array. Gli slug vuoti,
le route non locali, le route con query o fragment, i dot-segment e le
collisioni di route vengono rifiutati con un errore esplicito.

## Tipi di collezione

- `taxonomy`: costruisce una lista Grav filtrata per `taxonomy` e valore.
- `date`: usa valori nel formato `value_format` (predefinito `Ym`) e applica
  un intervallo dal primo all'ultimo istante del mese.
- `initial`: espone le iniziali presenti nei titoli pubblicati, ad esempio
  `/autori/A`; usare `value_pattern` per restringere le iniziali ammesse.

Le route iniziali richiedono `route_prefix` e `source_template` è opzionale.
Per i tipi `taxonomy` e `date`, `source_template`, `limit`, `pagination`,
`order_by` e `order_dir` controllano la collezione Grav generata. Il flag
`pagination` è attivo per impostazione predefinita.

Nel titolo e negli header si possono usare `{value}`, `{value_upper}` e, per
le collezioni `date`, `{year}`, `{month}` e `{month_name}`.

## Sitemap e Twig

`virtualRoutes()` restituisce le route materializzate per le integrazioni come
`void-metadata`. `sitemap` vale `true` per impostazione predefinita; impostarlo
a `false` per mantenere una route raggiungibile ma fuori dalla sitemap.

I temi possono usare:

- `void_collection_route(nome, valore)`, che restituisce la route o una stringa
  vuota se la collezione o il valore non esistono;
- `void_collection_taxonomy_counts(nome)`, che restituisce una lista vuota se
  la collezione non esiste o non è di tipo `taxonomy`.

Il listener `onPagesInitialized` usa priorità 1100, così le route virtuali
possono essere risolte prima di regole di redirect più generiche.

## Sviluppo e verifica

```sh
composer validate --strict
composer lint
composer test
php tests/clean-grav.php /path/to/clean/grav
```

La pipeline CI ripete i controlli su PHP 8.3, 8.4 e 8.5 e carica il plugin
contro una nuova installazione Grav 2.

Per il rilascio seguire [RELEASING.md](RELEASING.md).
