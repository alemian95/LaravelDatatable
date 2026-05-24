# Design — Searchable columns: opt-in dichiarativo e auto-discovery configurabile

**Data:** 2026-05-24
**Stato:** Proposto
**Area interessata:** `src/SearchApplier.php`, nuovi contracts/concerns/exceptions, `DatatableServiceProvider`, `DatatableApi`, `config/laraveldatatable.php`.

## 1. Contesto e problema

Oggi `SearchApplier::resolveSearchColumns()` (`src/SearchApplier.php:58`) implementa un fallback che:

1. Estrae tutte le colonne della tabella base via `Schema::getColumnListing($table)`.
2. Per ogni relazione in `$builder->getEagerLoads()` aggiunge tutte le colonne della tabella collegata, prefissate con `relation.column`.
3. Applica `orWhereLike('%term%')` su ognuna (`src/SearchApplier.php:39-55`).

Il comportamento è documentato nel README come limite (sez. *Advanced & known limits*, punto 1), ma resta **default attivo**. I problemi concreti:

- **Sicurezza**: colonne come `password`, `remember_token`, `*_secret`, `*_token`, `api_key` entrano nella `WHERE` clause. Anche senza data leak diretto, espongono side-channel (timing, error-based) e bypassano la disciplina applicativa.
- **Correttezza SQL**: `LIKE %term%` su colonne non-string (`uuid`, `jsonb`, `int`, `timestamp`) esplode in PostgreSQL e fa cast impliciti pericolosi in MySQL.
- **Performance**: `LIKE %term%` è non-sargable; moltiplicato per N colonne base + M colonne per ogni relazione eager-loaded genera full scan ad ogni request, più `EXISTS` per ogni `orWhereHas`.
- **Schema introspection runtime**: `Schema::getColumnListing` colpisce `information_schema` ad ogni request senza cache.
- **Contract instabile**: una migration aggiunge automaticamente la nuova colonna alle ricerche, senza che nessuno se ne accorga.
- **Asimmetria**: l'auto-discovery esiste per `search` ma non per `sort` o `filter`, rompendo la coerenza mentale del package.

## 2. Obiettivi

- Rendere la selezione delle colonne ricercabili **esplicita e dichiarativa** sul Model (o sull'istanza `DatatableApi` per i raw `QueryBuilder`).
- Mantenere l'auto-discovery come **opt-in via config**, per ergonomia in prototipazione e retrocompatibilità.
- Garantire che, quando esiste una whitelist dichiarata, sia **autoritativa** anche rispetto ai `search_columns` passati dal client (il client non può mai allargare la whitelist).
- Far fallire **in modo esplicito** (eccezione) i casi in cui la libreria non sa cosa cercare, invece di silenziose `LIKE` su tutto.
- Organizzare il codice secondo i pattern delle librerie Laravel mature (contracts + concerns + service provider con binding sostituibile), rispettando SRP/OCP/DIP per supportare crescita futura.

## 3. Non obiettivi

- Non si modifica il pipeline di `sort` o `filter`.
- Non si introducono cache sullo schema introspection (può essere un follow-up separato).
- Non si introduce supporto per tipi di relazione aggiuntivi nel join di search/sort (resta il limite `BelongsTo` per il sort, fuori scope).
- Non si tocca la convention `$hidden` di Eloquent: resta esclusivamente uno strumento di serializzazione, come da semantica nativa Laravel.
- Non si introduce full-text search o integrazione con Scout: il package resta un wrapper `LIKE`-based.

## 4. Regole di decisione

La regola unica che governa la risoluzione delle colonne ricercabili:

```
Whitelist autoritativa = primo non-vuoto tra:
  1. DatatableApi::withSearchableColumns(...)   (livello istanza)
  2. Model::getSearchableColumns()              (se il Model implementa HasSearchableColumns)
  → altrimenti: nessuna whitelist

Colonne effettive da cercare:
  Se esiste whitelist autoritativa:
    Se request.search_columns presente → intersezione(request.search_columns, whitelist)
    Altrimenti                          → whitelist
  Se NON esiste whitelist:
    Se config.search.auto_discover_columns == true:
      Auto-discovery (Schema introspection, con blacklist di config)
      (request.search_columns vince se presente, senza filtro)
    Se config.search.auto_discover_columns == false:
      → throw SearchColumnsNotConfiguredException
```

**Note importanti:**

- Quando l'intersezione (whitelist ∩ request.search_columns) è **vuota**, la search **non viene applicata** (la clausola `WHERE` di search è omessa). Non si lancia eccezione: il client ha chiesto colonne non autorizzate, il server le ignora — è il comportamento più sicuro e meno disruptive. Il caso può essere loggato a livello `debug` per troubleshooting.
- L'auto-discovery, anche quando attiva, applica sempre due filtri minimi:
  - solo colonne di tipo string/text/char (via `Schema::getColumnType`), per evitare i crash Postgres su `LIKE` con tipi non-string;
  - esclusione dei nomi/pattern definiti in `config.search.auto_discovery_blacklist`.
- Per Eloquent con eager-loads, l'auto-discovery mantiene il comportamento attuale (include colonne delle relazioni), perché disattivarlo sarebbe un cambio funzionale oltre allo scope di questo design.

## 5. API pubblica

### 5.1 Contract `HasSearchableColumns`

```php
namespace AleMian95\Datatable\Contracts;

interface HasSearchableColumns
{
    /**
     * Colonne autorizzate per la search di questo Model.
     * Supporta dot-notation per relazioni (es. 'author.name').
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array;
}
```

### 5.2 Concern (trait) `HasSearchableColumns`

```php
namespace AleMian95\Datatable\Concerns;

trait HasSearchableColumns
{
    /**
     * Default convention-based: legge dalla property $searchable.
     * Override il metodo per logica dinamica (es. dipendente da ruolo utente).
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array
    {
        return $this->searchable ?? [];
    }
}
```

Esempio d'uso lato consumer:

```php
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;

class User extends Model implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected array $searchable = ['first_name', 'last_name', 'email', 'profile.bio'];
}
```

Lo sviluppatore può:
- usare il trait + property `$searchable` (caso comune),
- usare solo l'interfaccia e implementare `getSearchableColumns()` a mano (logica dinamica),
- combinare il trait con override del metodo (default + caso speciale).

Pattern speculare a `Authenticatable` (interfaccia + trait), familiare per chi usa Laravel.

### 5.3 Metodo builder `DatatableApi::withSearchableColumns(array $columns)`

Per:
- raw `QueryBuilder` (nessun Model dietro a cui appendere il trait);
- override per-endpoint di una dichiarazione sul Model (es. endpoint pubblico più restrittivo dell'admin).

Esempi:

```php
// QueryBuilder puro
return new DatatableApi()
    ->fromQuery(DB::table('users'))
    ->withSearchableColumns(['name', 'email']);

// Override su Eloquent
return new DatatableApi()
    ->fromQuery(User::query())
    ->withSearchableColumns(['email']); // sovrascrive User::$searchable per questa request
```

### 5.4 Eccezione

```php
namespace AleMian95\Datatable\Exceptions;

class SearchColumnsNotConfiguredException extends \LogicException
{
}
```

È un errore di configurazione/programmazione, non di runtime user-input. Messaggio esplicito:

> *"The model `App\Models\Foo` does not implement `HasSearchableColumns` and `auto_discover_columns` is disabled. Either implement the contract on the model, call `withSearchableColumns()` on the `DatatableApi` instance, or enable `auto_discover_columns` in `config/laraveldatatable.php`."*

Per raw `QueryBuilder` il messaggio cita il `from` table invece del Model.

### 5.5 Config

```php
// config/laraveldatatable.php
return [
    'default' => [
        'per_page' => 15,
    ],

    'search' => [
        // Quando true: se il Model non implementa HasSearchableColumns
        // e non viene chiamato withSearchableColumns(), si fa fallback
        // su Schema introspection (filtrata per tipo + blacklist).
        // Quando false: dichiarare la whitelist è obbligatorio,
        // altrimenti si lancia SearchColumnsNotConfiguredException.
        'auto_discover_columns' => true,

        // Usata solo dall'auto-discovery: nomi o pattern di colonne
        // sempre esclusi (matching case-insensitive; il wildcard '*'
        // matcha qualunque sequenza di caratteri).
        'auto_discovery_blacklist' => [
            'password',
            'remember_token',
            'api_token',
            '*_token',
            '*_secret',
            '*_hash',
            '*_key',
        ],
    ],
];
```

## 6. Architettura interna

### 6.1 Struttura file

```
src/
├── Contracts/
│   ├── QueryApplier.php                              (esistente)
│   ├── HasSearchableColumns.php                      (NEW)
│   └── SearchColumnResolver.php                      (NEW)
├── Concerns/
│   └── HasSearchableColumns.php                      (NEW, trait)
├── Exceptions/
│   └── SearchColumnsNotConfiguredException.php       (NEW)
├── Search/
│   ├── DefaultSearchColumnResolver.php               (NEW, orchestratore)
│   └── Sources/
│       ├── ApiDeclaredColumnSource.php               (NEW)
│       ├── ModelDeclaredColumnSource.php             (NEW)
│       └── AutoDiscoveryColumnSource.php             (NEW)
├── SearchApplier.php                                 (refactor: dipende dal resolver)
├── DatatableApi.php                                  (aggiunge withSearchableColumns)
├── DatatableRequest.php                              (invariato)
├── DatatableServiceProvider.php                      (binding + config merge)
└── ... (resto invariato)
```

### 6.2 Responsabilità delle classi (SRP)

**`Contracts\SearchColumnResolver`** — interfaccia del punto di estensione principale:

```php
interface SearchColumnResolver
{
    /**
     * Risolve le colonne effettive su cui applicare la search.
     *
     * @return array<int, string>  Vuoto = niente search da applicare.
     * @throws SearchColumnsNotConfiguredException
     */
    public function resolve(
        Builder $builder,
        DatatableRequest $request,
        ?array $apiDeclaredColumns,
    ): array;
}
```

**`Search\DefaultSearchColumnResolver`** — unico posto in tutta la libreria a conoscere la **regola di decisione** (cap. 4). Riceve in DI le tre `Sources` + la config; produce la lista finale o lancia l'eccezione.

**`Search\Sources\*`** — ognuna sa estrarre colonne da una sorgente, ignora le altre. Sono il "low-level" del resolver. Pure: dato un input, ritornano colonne (o array vuoto).

- `ApiDeclaredColumnSource`: ritorna le colonne passate via `DatatableApi::withSearchableColumns()`.
- `ModelDeclaredColumnSource`: se `$builder` è Eloquent e `$model instanceof HasSearchableColumns`, ritorna `getSearchableColumns()`, altrimenti `[]`.
- `AutoDiscoveryColumnSource`: introspezione `Schema`, filtro per tipo string/text, esclusione blacklist. Mantiene il supporto eager-loads attuale.

**`SearchApplier`** — dopo il refactor non sa più "come si scoprono le colonne". Riceve in DI il `SearchColumnResolver`, gli chiede `resolve()` e applica `LIKE` / `orWhereHas`. La logica di intersezione whitelist-vs-request vive nel resolver, non qui.

**`DatatableApi`** — espone `withSearchableColumns(array $columns): self` che memorizza la lista e la passa al `SearchApplier` (che a sua volta la inoltra al resolver come `$apiDeclaredColumns`).

**`DatatableServiceProvider`** — binda `SearchColumnResolver::class => DefaultSearchColumnResolver::class`. Lo sviluppatore avanzato può sostituire il binding con la propria implementazione (DIP + OCP).

### 6.3 Flusso

```
HTTP request
   │
   ▼
DatatableApi::fromQuery(...)->withSearchableColumns(...)?->...
   │
   ▼
DatatableApi (al rendering JSON)
   │ delega a:
   ▼
SearchApplier::apply($builder, $request)
   │ chiede al resolver:
   ▼
DefaultSearchColumnResolver::resolve($builder, $request, $apiDeclaredColumns)
   │
   │ (1) prova ApiDeclaredColumnSource          → whitelist se non vuota
   │ (2) prova ModelDeclaredColumnSource        → whitelist se non vuota
   │ (3) se whitelist trovata:
   │        intersezione con request.search_columns (se presente)
   │ (4) se nessuna whitelist:
   │        config.auto_discover_columns == true  → AutoDiscoveryColumnSource
   │        config.auto_discover_columns == false → throw
   │
   ▼
array<string>  →  SearchApplier costruisce la WHERE
```

### 6.4 Aderenza SOLID

- **SRP**: ogni `Source` ha una sola fonte; il `Resolver` ha una sola responsabilità (decidere); l'`Applier` ha una sola responsabilità (costruire SQL).
- **OCP**: una nuova sorgente di colonne = nuova `Source` + modifica minima al resolver. Lo sviluppatore può sostituire l'intero resolver via service container binding senza toccare il package.
- **LSP**: tutte le `Sources` rispettano un contratto comune (potrebbe diventare un'interfaccia `ColumnSource` se in futuro si vuole una catena pluggabile, ma non è necessario ora).
- **ISP**: `HasSearchableColumns` espone un solo metodo; `SearchColumnResolver` un solo metodo.
- **DIP**: `SearchApplier` dipende dall'astrazione `SearchColumnResolver`, non dall'implementazione.

### 6.5 Estensioni future facilitate da questa struttura

- Sostituzione totale del resolver (es. una versione che integra Scout / full-text).
- Cache delle colonne per Model (banale aggiungere un decoratore attorno a `ModelDeclaredColumnSource`).
- Cache di `Schema::getColumnListing` (decoratore attorno a `AutoDiscoveryColumnSource`).
- Whitelist per ruolo utente (override del metodo `getSearchableColumns()` su Model che legge `Auth::user()`).

## 7. Retrocompatibilità e migrazione

- **Default `auto_discover_columns => true`**: chi aggiorna senza fare nulla mantiene il comportamento attuale, con due unici cambi rispetto all'auto-discovery odierna:
  - le colonne non-string vengono escluse (fix di un bug latente, non breaking);
  - la blacklist nomi rimuove `password`/`*_token`/ecc. (fix di sicurezza, non breaking in alcun caso ragionevole).
- Chi vuole il nuovo comportamento strict: imposta `auto_discover_columns => false`, dichiara `HasSearchableColumns` sui Model rilevanti o usa `withSearchableColumns()` puntualmente.
- Nessuna API esistente viene rimossa o cambiata nella firma.

## 8. Testing

Suite minima da coprire:

- `DefaultSearchColumnResolver`:
  - whitelist da API vince su whitelist da Model;
  - whitelist da Model usata se API non ha dichiarato;
  - intersezione corretta con `search_columns` della request;
  - intersezione vuota → nessuna search applicata (verifica indiretta tramite assenza di clausola `WHERE`);
  - nessuna whitelist + auto-discovery ON → usa `AutoDiscoveryColumnSource`;
  - nessuna whitelist + auto-discovery OFF → lancia `SearchColumnsNotConfiguredException`.
- `AutoDiscoveryColumnSource`:
  - esclude colonne non-string (creare migrazione di test con int/json/timestamp/uuid);
  - applica blacklist con e senza wildcard;
  - mantiene supporto eager-loads come oggi.
- `ModelDeclaredColumnSource`:
  - ritorna `[]` se il Model non implementa il contract;
  - ritorna `getSearchableColumns()` se implementato.
- `ApiDeclaredColumnSource`:
  - ritorna l'array passato a `withSearchableColumns()`, o `[]` se non chiamato.
- Integration test end-to-end su `DatatableApi`:
  - request con `search_columns` non autorizzati → vengono droppati;
  - QueryBuilder + `withSearchableColumns()` produce una query con `LIKE` solo sulle colonne dichiarate.

Setup test: usare SQLite in-memory già configurato nel package (`composer test`).

## 9. Documentazione

- Aggiornare la sezione *Advanced & known limits* del README:
  - punto 1 (Automatic column discovery) → riscrivere come "Searchable columns: dichiarazione esplicita (raccomandato)" con esempio del trait;
  - aggiungere paragrafo su `withSearchableColumns()`;
  - documentare la config `search.auto_discover_columns` e `search.auto_discovery_blacklist`;
  - documentare l'eccezione e quando viene lanciata.
- Aggiornare la sezione *HTTP request contract* per chiarire che `search_columns` dalla request è filtrato contro la whitelist quando questa è dichiarata.

## 10. Domande aperte

Nessuna al momento. Tutte le decisioni di design sono state risolte nei Nodi 1 e 2 della discussione preliminare.
