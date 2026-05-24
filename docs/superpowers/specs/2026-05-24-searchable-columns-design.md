# Design — Searchable columns: opt-in dichiarativo e auto-discovery configurabile

**Data:** 2026-05-24
**Stato:** Implementato (revisione post-review applicata 2026-05-24)
**Area interessata:** `src/SearchApplier.php`, nuovi contracts/concerns/exceptions, `DatatableServiceProvider`, `DatatableApi`, `config/laraveldatatable.php`.

> Nota: questo documento è stato aggiornato dopo la code review finale per riflettere alcuni rifinimenti applicati durante l'iterazione post-implementazione. Vedi **§10 Revision history** per il dettaglio dei cambi rispetto al design originale.

## 1. Contesto e problema

Il vecchio `SearchApplier::resolveSearchColumns()` (pre-refactor) implementava un fallback che:

1. Estraeva tutte le colonne della tabella base via `Schema::getColumnListing($table)`.
2. Per ogni relazione in `$builder->getEagerLoads()` aggiungeva tutte le colonne della tabella collegata, prefissate con `relation.column`.
3. Applicava `orWhereLike('%term%')` su ognuna.

Il comportamento era documentato nel README come limite ma restava **default attivo**. I problemi concreti:

- **Sicurezza**: colonne come `password`, `remember_token`, `*_secret`, `*_token`, `api_key` entravano nella `WHERE` clause. Anche senza data leak diretto, esponevano side-channel (timing, error-based) e bypassavano la disciplina applicativa.
- **Correttezza SQL**: `LIKE %term%` su colonne non-string (`uuid`, `jsonb`, `int`, `timestamp`) esplodeva in PostgreSQL e faceva cast impliciti pericolosi in MySQL.
- **Performance**: `LIKE %term%` è non-sargable; moltiplicato per N colonne base + M colonne per ogni relazione eager-loaded generava full scan ad ogni request, più `EXISTS` per ogni `orWhereHas`.
- **Schema introspection runtime**: `Schema::getColumnListing` colpiva `information_schema` ad ogni request senza cache.
- **Contract instabile**: una migration aggiungeva automaticamente la nuova colonna alle ricerche, senza che nessuno se ne accorgesse.
- **Asimmetria**: l'auto-discovery esisteva per `search` ma non per `sort` o `filter`, rompendo la coerenza mentale del package.

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

La regola unica che governa la risoluzione delle colonne ricercabili. Le sorgenti distinguono `null` ("nessuna opinione, prova la prossima") da `[]` ("whitelist autoritativa vuota — blocca la search").

```
Whitelist autoritativa = primo non-null tra:
  1. DatatableApi::withSearchableColumns(...)   (livello istanza)
  2. Model::getSearchableColumns()              (se il Model implementa HasSearchableColumns)
  → altrimenti: nessuna whitelist (null)

Colonne effettive da cercare:
  Se esiste whitelist autoritativa (anche se vuota):
    Se request.search_columns presente → intersezione(request.search_columns, whitelist)
    Altrimenti                          → whitelist
    (whitelist vuota ⇒ intersezione vuota ⇒ clausola di search omessa: dataset non filtrato dal termine, tutte le righe ritornate)

  Se NON esiste whitelist:
    Se config.search.auto_discover_columns == true:
      auto = AutoDiscoveryColumnSource(builder)   // filtrato per tipo + blacklist
      Se request.search_columns presente → intersezione(request.search_columns, auto)
      Altrimenti                          → auto
    Se config.search.auto_discover_columns == false:
      → throw SearchColumnsNotConfiguredException
      (incondizionatamente, anche se request.search_columns è presente)
```

**Note importanti:**

- **Whitelist vuota autoritativa.** `withSearchableColumns([])` e un Model che ritorna `[]` da `getSearchableColumns()` sono un segnale autoritativo per **omettere la clausola di search**: nessun `LIKE` viene aggiunto e il dataset viene ritornato senza filtro per il termine di ricerca (paginazione, sort e altri filtri restano applicati). Questa è una scelta esplicita del developer (es. disabilita la search su un endpoint specifico) e si distingue da `null` (= "non ho dichiarato nulla, fall-through").
- **Intersezione vuota = nessuna search applicata.** Quando l'intersezione (whitelist ∩ request.search_columns) è vuota, la search non viene applicata: il client ha chiesto colonne non autorizzate, il server le ignora. Non si lancia eccezione — è il comportamento meno disruptive e più sicuro.
- **Auto-discovery + request.** Anche nel branch auto-discovery, `request.search_columns` viene intersecato col risultato della discovery (già filtrato per tipo string + blacklist). Quindi la `auto_discovery_blacklist` protegge anche dai tentativi del client di passare colonne sensibili come `password`. Questa è una semantica più stretta rispetto al design originale ("request vince senza filtro") — vedi §10.
- **Auto-discovery: filtri minimi sempre attivi.** Anche quando attiva, l'auto-discovery applica:
  - solo colonne di tipo string-like (`string`, `text`, `char`, `varchar`, `tinytext`, `mediumtext`, `longtext`, `uuid`, `guid`) via `Schema::getColumnType`, per evitare crash Postgres su `LIKE` con tipi non-string;
  - esclusione dei nomi/pattern definiti in `config.search.auto_discovery_blacklist` (matching case-insensitive con wildcard `*`).
- **Eager-loads.** Per Eloquent con eager-loads, l'auto-discovery include colonne delle relazioni (con prefisso `relation.colonna`). I segmenti dei nomi relation sono trattati difensivamente: un eventuale suffisso ` as alias` viene strippato prima di risolvere il method name (stock Laravel non emette tali chiavi via `with()`, ma il guard previene drop silenziosi se un giorno succedesse).

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
     * Emette Log::warning se la property è assente o ha tipo errato — segnale
     * di un probabile typo (es. $searchabel) o di un trait mixato senza
     * dichiarare la property. Una whitelist deliberatamente vuota
     * ($searchable = []) NON logga: è il caso autoritativo legittimo.
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array
    {
        if (! property_exists($this, 'searchable')) {
            Log::warning(...); // include la classe del Model
            return [];
        }

        if (! is_array($this->searchable)) {
            Log::warning(...); // include la classe e il tipo trovato
            return [];
        }

        return $this->searchable;
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
- usare il trait + property `$searchable` (caso comune);
- usare solo l'interfaccia e implementare `getSearchableColumns()` a mano (logica dinamica);
- combinare il trait con override del metodo (default + caso speciale).

Pattern speculare a `Authenticatable` (interfaccia + trait), familiare per chi usa Laravel.

### 5.3 Metodo builder `DatatableApi::withSearchableColumns(array $columns)`

Per:
- raw `QueryBuilder` (nessun Model dietro a cui appendere il trait);
- override per-endpoint di una dichiarazione sul Model (es. endpoint pubblico più restrittivo dell'admin);
- disabilitare la search su un endpoint specifico passando `[]` (vedi §4 — whitelist vuota autoritativa).

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

// Disabilitazione esplicita della search
return new DatatableApi()
    ->fromQuery(User::query())
    ->withSearchableColumns([]); // clausola di search omessa: dataset non filtrato dal termine
```

### 5.4 Eccezione

```php
namespace AleMian95\Datatable\Exceptions;

class SearchColumnsNotConfiguredException extends \LogicException
{
    public static function forModel(string $modelClass): self;
    public static function forTable(string $table): self;
}
```

È un errore di configurazione/programmazione, non di runtime user-input. Due named constructor per i due casi (Eloquent vs raw QueryBuilder). Messaggi tipici:

> *"The model [App\Models\Foo] does not implement HasSearchableColumns and auto_discover_columns is disabled. Either implement the contract on the model, call withSearchableColumns() on the DatatableApi instance, or enable auto_discover_columns in config/laraveldatatable.php."*

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

        // Applicata sia al fallback auto-discovery sia all'intersezione
        // di request.search_columns nel branch auto-discovery. Pattern con
        // '*' come wildcard. Matching case-insensitive.
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

**`Search\DefaultSearchColumnResolver`** — unico posto in tutta la libreria a conoscere la **regola di decisione** (cap. 4). Riceve in DI le tre `Sources` + il flag `autoDiscoverEnabled`; produce la lista finale o lancia l'eccezione.

**`Search\Sources\*`** — ognuna sa estrarre colonne da una sorgente, ignora le altre. Sono il "low-level" del resolver. Pure: dato un input, ritornano `?array` (`null` = nessuna opinione; `array` anche vuoto = whitelist autoritativa di questa source).

- `ApiDeclaredColumnSource`: ritorna le colonne passate via `DatatableApi::withSearchableColumns()` (`null` se mai chiamato).
- `ModelDeclaredColumnSource`: se `$builder` è Eloquent/Relation e `$model instanceof HasSearchableColumns`, ritorna `getSearchableColumns()`; altrimenti `null`.
- `AutoDiscoveryColumnSource`: introspezione `Schema`, filtro per tipo string-like, esclusione blacklist. Include colonne delle relazioni eager-loaded con stripping difensivo di eventuali ` as alias` nei segmenti. Ritorna sempre `array` (può essere vuoto se nessuna colonna passa i filtri — non `null`, perché ha "esaminato" il builder).

**`SearchApplier`** — dopo il refactor non sa più "come si scoprono le colonne". Riceve in DI il `SearchColumnResolver`, gli chiede `resolve()` e applica `LIKE` / `orWhereHas`. Pre-filtra le entries dot-notation quando il builder è un raw `QueryBuilder` (non supporta `orWhereHas`): le droppa e emette `Log::warning` nominando le colonne ignorate, evitando il silent drop che produrrebbe zero match senza diagnostica.

**`DatatableApi`** — espone `withSearchableColumns(array $columns): self` che memorizza la lista e la passa al `SearchApplier` (che a sua volta la inoltra al resolver come `$apiDeclaredColumns`).

**`DatatableServiceProvider`** — binda `SearchColumnResolver::class => DefaultSearchColumnResolver::class` con `$app->scoped(...)` in `registeringPackage()`. Il binding `scoped` (anziché `singleton`) garantisce che ogni HTTP request / queue job ottenga un resolver fresco costruito dai valori `laraveldatatable.search.*` attivi al momento — utile per setup multi-tenant che cambiano config per request. Il resolver è stateless, quindi il costo per-request è trascurabile. Lo sviluppatore avanzato può sostituire il binding con la propria implementazione (DIP + OCP).

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
   │ (1) prova ApiDeclaredColumnSource          → whitelist se non-null (anche [])
   │ (2) prova ModelDeclaredColumnSource        → whitelist se non-null (anche [])
   │ (3) se whitelist trovata:
   │        intersezione con request.search_columns (se presente)
   │ (4) se nessuna whitelist:
   │        auto_discover ON  → intersezione tra request.search_columns
   │                            e AutoDiscoveryColumnSource (oppure solo
   │                            l'auto-discovery se request è vuota)
   │        auto_discover OFF → throw incondizionato
   │
   ▼
SearchApplier
   │ pre-filtra entries dot-notation se builder non-Eloquent (Log::warning)
   │ costruisce la WHERE con LIKE / orWhereHas
```

### 6.4 Aderenza SOLID

- **SRP**: ogni `Source` ha una sola fonte; il `Resolver` ha una sola responsabilità (decidere); l'`Applier` ha una sola responsabilità (costruire SQL).
- **OCP**: una nuova sorgente di colonne = nuova `Source` + modifica minima al resolver. Lo sviluppatore può sostituire l'intero resolver via service container binding senza toccare il package.
- **LSP**: le tre `Sources` non condividono un'interfaccia formale (le signature `columns(...)` divergono per il tipo di input — `?array` per Api, `Builder` per le altre) — scelta deliberata: introdurre `ColumnSource` astratto sarebbe astrazione prematura per tre implementazioni con shape diversa.
- **ISP**: `HasSearchableColumns` espone un solo metodo; `SearchColumnResolver` un solo metodo.
- **DIP**: `SearchApplier` dipende dall'astrazione `SearchColumnResolver`, non dall'implementazione.

### 6.5 Estensioni future facilitate da questa struttura

- Sostituzione totale del resolver (es. una versione che integra Scout / full-text).
- Cache delle colonne per Model (banale aggiungere un decoratore attorno a `ModelDeclaredColumnSource`).
- Cache di `Schema::getColumnListing` (decoratore attorno a `AutoDiscoveryColumnSource`).
- Whitelist per ruolo utente (override del metodo `getSearchableColumns()` su Model che legge `Auth::user()`).

## 7. Retrocompatibilità e migrazione

- **Default `auto_discover_columns => true`**: chi aggiorna senza fare nulla mantiene il comportamento generale (auto-discovery attivo), con questi cambi rispetto al vecchio comportamento:
  - le colonne non-string vengono escluse (fix di un bug latente — silenzioso cast in MySQL, crash in Postgres; non breaking in alcun caso ragionevole);
  - la blacklist nomi rimuove `password`/`*_token`/ecc. (fix di sicurezza; non breaking in alcun caso ragionevole);
  - le colonne `uuid`/`guid` sono incluse esplicitamente in `SEARCHABLE_TYPES` (su MySQL/Postgres `Schema::getColumnType` ritorna `'guid'` per `$table->uuid()`);
  - **breaking parziale**: client che passavano `search_columns` con nomi blacklisted o di tipo errato ora ottengono empty result (intersezione vuota → no WHERE → tutte le righe) invece di eseguire `LIKE` su quelle colonne. Uso legittimo (colonne string non-blacklisted) non impattato.
- Chi vuole il nuovo comportamento strict: imposta `auto_discover_columns => false`, dichiara `HasSearchableColumns` sui Model rilevanti o usa `withSearchableColumns()` puntualmente.
- Nessuna API esistente viene rimossa o cambiata nella firma.

## 8. Testing

Suite coperta dal `tests/` finale (49 test totali, 90 asserzioni):

- **`DefaultSearchColumnResolver`** (11 test):
  - whitelist da API vince su whitelist da Model (con asserzione "non contiene Model columns");
  - whitelist da Model usata se API non ha dichiarato;
  - intersezione corretta con `search_columns` della request;
  - intersezione vuota → nessuna search applicata;
  - nessuna whitelist + auto-discovery ON → usa `AutoDiscoveryColumnSource`, con intersezione se request presente;
  - nessuna whitelist + auto-discovery OFF → lancia `SearchColumnsNotConfiguredException` (anche se request ha `search_columns`);
  - whitelist autoritativa vuota da API blocca la search;
  - whitelist autoritativa vuota da Model blocca la search;
  - intersezione con auto-discovery droppa colonne blacklisted dalla request;
  - intersezione con auto-discovery droppa colonne non-string dalla request.

- **`AutoDiscoveryColumnSource`** (7 test):
  - esclude colonne non-string (int, bigint, timestamp; SQLite non distingue JSON da TEXT — limite documentato nel test);
  - applica blacklist con exact match e con wildcard;
  - matching case-insensitive;
  - discovery delle relazioni eager-loaded con dot notation;
  - supporto raw `QueryBuilder` via `from` table;
  - sopravvive a chiavi eager-load con suffisso ` as alias` (regressione difensiva).

- **`ModelDeclaredColumnSource`** (4 test):
  - ritorna le colonne dichiarate se il Model implementa il contract;
  - ritorna `null` se il Model non implementa;
  - ritorna `null` per raw `QueryBuilder`;
  - ritorna `[]` (autoritative empty) se il Model implementa con `$searchable = []`.

- **`ApiDeclaredColumnSource`** (3 test):
  - ritorna le colonne passate;
  - ritorna `null` se input è `null`;
  - preserva `[]` come whitelist autoritativa vuota.

- **`HasSearchableColumns` trait** (4 test):
  - ritorna le colonne quando la property `$searchable` esiste ed è array;
  - ritorna `[]` senza warning quando la property è `[]` deliberato;
  - logga warning e ritorna `[]` quando la property è assente;
  - logga warning e ritorna `[]` quando la property ha tipo errato.

- **`SearchApplier`** (8 test):
  - skip se request non ha search term;
  - delega al `customSearch` closure se presente (bypassa resolver);
  - emette `LIKE` sulle colonne tornate dal resolver;
  - skip WHERE se resolver torna array vuoto;
  - propaga `apiDeclaredColumns` al resolver;
  - logga warning e droppa entries dot-notation su raw `QueryBuilder`;
  - skip WHERE se tutte le entries sono dotted su raw `QueryBuilder`;
  - processa dotted normalmente su Eloquent (no warning).

- **Integration end-to-end** su `DatatableApi` (7 test):
  - match dalla whitelist Model quando request omette `search_columns`;
  - drop di colonne non autorizzate da `request.search_columns`;
  - `withSearchableColumns()` sovrascrive la whitelist Model;
  - supporto raw `QueryBuilder` via `withSearchableColumns()`;
  - throw se nessuna whitelist e auto-discovery off;
  - uso auto-discovery se nessuna whitelist e flag on;
  - `withSearchableColumns([])` blocca la search end-to-end;
  - drop blacklisted columns end-to-end via auto-discovery.

Setup test: SQLite in-memory configurato nel package (`composer test`), con fixtures `TestUser`/`TestPost` e migrazioni in `tests/Database/Migrations/`.

## 9. Documentazione

- Sezione "Searchable columns: declarative opt-in (recommended)" nel README (riscrive il vecchio limite #1).
- Esempi: trait + property, `withSearchableColumns()` per Eloquent override e per raw `QueryBuilder`.
- Documentazione di `search.auto_discover_columns` e `search.auto_discovery_blacklist`.
- Documentazione di `SearchColumnsNotConfiguredException`.
- Nota su "Resolver lifecycle" (binding `scoped` + escape hatch `forgetInstance` per i casi mid-request).
- Limite #2 (Dot-notation search) aggiornato per menzionare il `Log::warning` emesso dal `SearchApplier` quando le dotted entries vengono droppate su raw `QueryBuilder`.

## 10. Revision history

Cambi applicati a questo design durante l'iterazione post-implementazione (final code review):

1. **Whitelist vuota autoritativa.** Le sorgenti `Api` e `Model` ora distinguono `null` (nessuna opinione) da `[]` (whitelist autoritativa che omette la clausola di search). Il design originale collassava entrambi a "non dichiarato".
2. **Intersezione con auto-discovery anche nel branch fallback.** Quando non c'è whitelist e auto-discovery è on, `request.search_columns` viene intersecato col risultato dell'auto-discovery (e quindi soggetto a blacklist + filtro tipo), invece di vincere senza filtro come previsto nel design originale. Chiude un buco di sicurezza che permetteva al client di forzare colonne blacklisted.
3. **Strict mode incondizionato.** Quando `auto_discover_columns=false` e non c'è whitelist, l'eccezione è lanciata anche se `request.search_columns` è presente. Il design originale aveva una concessione per il "raw QueryBuilder ad-hoc" che è risultata bypassabile.
4. **Inclusione di `uuid`/`guid`** in `SEARCHABLE_TYPES`. Senza, le colonne `$table->uuid()` venivano silenziosamente escluse su MySQL/Postgres.
5. **Defensive stripping di ` as alias`** nei segmenti dei nomi relation eager-loaded. Stock Laravel non emette tali chiavi, ma il guard previene drop silenziosi se future versioni o constraint string anomale dovessero produrli.
6. **Binding `scoped` invece di `singleton`** per `SearchColumnResolver` nel service provider. Multi-tenant friendly + Octane-safe.
7. **Warning nel trait** quando `$searchable` è assente o di tipo errato (typo come `$searchabel`). Diagnostica importante dato che con la nuova semantica `[]` blocca la search.
8. **Warning nel `SearchApplier`** quando entries dot-notation vengono droppate su raw `QueryBuilder`. Sostituisce il silent drop precedente che produceva zero match senza diagnostica.
