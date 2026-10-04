# Repository review — 2026-10-04

Review di backend (`src/`) e frontend (`react/`) alla v0.1.0. Ogni voce sotto "Ticket" è pensata per diventare una issue GitHub: titolo = intestazione, corpo = testo, etichette suggerite tra parentesi quadre.

Stato verifica: **B2 confermato** con test reale (`SQLSTATE[HY000]: ambiguous column name: created_at`). Gli altri punti derivano dalla lettura del codice.

Piano per B1–B3: `docs/superpowers/plans/2026-10-04-fix-b1-b2-b3.md`.

Issue GitHub: mappa v0.2 #20 (ticket #21–#30); punti di esecuzione #31–#41 (numero accanto al titolo).

---

## Ticket — bug

### B1. React non legge la risposta di `returnResource()` [bug] [react] — #42
Con `returnResource(...)` Laravel risponde `{ data, links, meta: { current_page, last_page, per_page, total } }`. `useDatatable` legge `last_page`/`total` al primo livello (`react/src/use-datatable.ts:24`) → `pageCount=0`, `total=0`, paginazione rotta. Le due metà del pacchetto non funzionano insieme col caso d'uso più comune.
**Fix:** normalizzare entrambi gli envelope in `useDatatable`.

### B2. Colonne di ricerca non qualificate → "ambiguous column" con sort su relazione [bug] [backend] — #43
`SearchApplier` emette `"created_at" like ?` senza tabella (`src/SearchApplier.php:60`). `sort_by=author.first_name` aggiunge `left join test_users as author` → errore SQL se entrambe le tabelle hanno la colonna. Vale anche per i `withCustomFilters` dell'utente (da documentare).
**Riproduzione:** `withSearchableColumns(['title','created_at'])` + `withSortableColumns(['author.first_name'])`, richiesta `?search=x&sort_by=author.first_name`.
**Fix:** qualificare le colonne flat con tabella base o alias.

### B3. Nascondere tutte le colonne `searchable` allarga la ricerca invece di restringerla [bug] [react] — #44
Se tutte le colonne con `meta.searchable` sono nascoste, `search_columns` non viene inviato e il backend cerca su tutta la whitelist.
**Fix:** in quel caso disabilitare la ricerca (input disabilitato, termine non inviato).

### B4. Opzioni per-page hardcoded [bug] [react] — #31
`[15,25,50]` fisse (`react/src/data-table.tsx:257`): `defaultPerPage=10` lascia il select vuoto; con `max_per_page<50` il server limita il valore senza dirlo e la UI mostra un valore diverso.
**Fix:** prop `perPageOptions`, includere sempre `defaultPerPage`.

### B5. `RelationSearch` usa il nome tabella reale su query con alias [bug] [backend] — #32
Su `DB::table('books as b')` la subquery EXISTS referenzia `books.author_id`, non valido in quello scope. Stessa radice di B2 (tabella vs alias).

---

## Ticket — sicurezza / default

### S1. `auto_discover_columns` attivo di default [security] [breaking]
Permette `LIKE` su qualsiasi colonna di testo, anche quelle che la Resource non espone (email, telefono, CF…): un client può dedurne il contenuto carattere per carattere. La blacklist copre solo i token; ogni `with()` estende la ricerca alle tabelle collegate.
**Proposta:** default `false` in v0.2 (breaking, nota nel CHANGELOG).

### S2. Sort su qualunque colonna se `withSortableColumns` non è impostato [security] [breaking]
Ordinare su `password`/`salary` permette di dedurre dati nascosti.
**Proposta:** whitelist obbligatoria di default (o derivata dalle colonne cercabili) con opzione legacy esplicita.

### S3. Wildcard `%` e `_` non escapati nel termine di ricerca [security] [backend] — #33
`search=%` restituisce tutto con scansione completa della tabella.
**Fix:** escape di `%`, `_`, `\` prima della `LIKE`.

### S4. README non aggiornato sul logging SQL [docs] — #34
"Known limits #3" dice che l'SQL viene loggato fuori produzione; ora dipende da `debug.log_sql`.

---

## Ticket — architettura backend

### A1. `DatatableApi` legge `request()` globale nel costruttore [refactor]
`src/DatatableApi.php:45`. Non si può passare un'altra Request né usare la classe fuori da HTTP. Proposta: `DatatableApi::fromRequest(Request)` o argomento opzionale.

### A2. Query eseguita in `jsonSerialize()` con mutazione del builder [refactor]
Serializzare due volte applica search/sort due volte. Proposta: implementare `Responsable` + `toPaginator()` esplicito e idempotente.

### A3. Semantica incoerente dei metodi `with*` [refactor]
`withCustomFilters` accumula, `withCustomSorts`/`withRelationSearch` sostituiscono. Uniformare e documentare.

### A4. Nessun ordinamento di riserva sulla chiave primaria [bug] [backend] — #35
Ordinando su una colonna con valori duplicati, cambiando pagina alcune righe si ripetono e altre si perdono. Aggiungere `orderBy(pk)` come tiebreaker.

### A5. Solo `LengthAwarePaginator` [enhancement]
`COUNT(*)` a ogni richiesta. Opzione `simplePaginate` / `cursorPaginate` per tabelle grandi.

### A6. Auto-discovery interroga lo schema a ogni richiesta [performance] — #36
`Schema::getColumnType` per colonna (`AutoDiscoveryColumnSource.php:65`), senza cache.

### A7. Ricerca a termine unico [enhancement]
"mario rossi" non trova `first_name=mario` + `last_name=rossi`. Valutare la divisione in parole (AND tra token, OR tra colonne) e le modalità prefisso / full-text.

### A8. Più classi in `SearchApplier.php` [chore] — #37
`DottedEntry`, `SpecDottedEntry`, `LegacyHasDottedEntry` violano PSR-4: spostarle in `src/Search/`.

### A9. Qualità [chore] — #38
Nessun `declare(strict_types=1)` in `src`; PHPStan livello 5; `composer.json` description "This is my package laraveldatatable"; blocco "Support us" di Spatie nel README; `vendor/` installato con PHP 8.4 mentre il minimo è `^8.3` (valutare `config.platform.php`).

---

## Ticket — frontend (configurabilità)

### F1. Testi e traduzioni [enhancement] [react]
"Search…", "Loading…", "No results.", "Page X of Y", "Columns", "Filters"… fissi in inglese. Prop `labels` / `messages`.

### F2. Header `Accept: application/json` di default [bug] [react] — #39
Senza, a sessione scaduta Laravel risponde 302 verso il login e la tabella mostra solo "Couldn't load the data". Aggiungere `Accept` e `X-Requested-With` di default, uniti agli header dell'utente. Passare anche il `signal` di TanStack Query a `fetch` per annullare le richieste superate.

### F3. Stato iniziale e modalità controllata [enhancement] [react]
Mancano `initialSorting`, `initialFilters`, `initialColumnVisibility` e `state`/`onStateChange` (oppure un hook `useDataTableState`). Prerequisito per F4 e per le viste salvate.

### F4. Stato nell'URL [enhancement] [react]
Pagina, filtri e ordinamento si perdono al refresh o con il tasto indietro. Opzione `syncWithUrl`.

### F5. Il menu delle colonne mostra l'id, non l'header [bug] [react] — #40
`react/src/toolbar.tsx:106`. Usare l'header se è una stringa, oppure `meta.label`.

### F6. Tema [enhancement] [react]
Palette `gray-*` fissa, non usa le variabili CSS di shadcn dell'app. Proposta: classi semantiche (`bg-background`, `border-border`…) oppure `className` / slot.

### F7. La ricerca sparisce quando ci sono righe selezionate [ux] [react] — #41
La barra delle azioni di gruppo sostituisce l'input di ricerca invece di affiancarlo.

### F8. Slot e override [enhancement] [react]
Toolbar extra, stato vuoto, caricamento, `onRowClick`, `className`.

---

## Ticket — tabelle complesse

### C1. Filtri dichiarativi lato server [enhancement] [backend] [needs-ADR]
Il frontend emette `filter[...]` ma il backend non ha un meccanismo per applicarli: ogni filtro va scritto a mano e il README suggerisce `request()` senza whitelist. Proposta: `withFilters(['status' => Filter::exact(), 'created_at' => Filter::dateRange(), …])` con whitelist. Decisione architetturale → ADR prima di implementare.

### C2. Filtri lato client più ricchi [enhancement] [react]
Selezione multipla, intervallo numerico, booleano, opzioni caricate dal server, renderer personalizzato. Dipende da C1 per il contratto dei parametri.

### C3. Selezione su tutte le pagine [enhancement] [react]
Le azioni di gruppo lavorano solo sulla pagina corrente. Passare all'handler la query corrente (oltre alle righe) per le azioni su tutto il risultato.

### C4. Ordinamento su più colonne [enhancement]
`sort_by` singolo. Valutare `sort[]=col:dir`.

### C5. Ordinamento per relazioni diverse da `BelongsTo` [enhancement] [backend]
Oggi solo tramite `withCustomSorts`. Valutare `HasOne` (join) e aggregati (`withMax`/`withCount`) per `HasMany`.

### C6. Integrazione Inertia [enhancement] [needs-ADR]
Il componente usa solo `fetch` verso un endpoint API dedicato. Valutare una modalità Inertia (props della pagina + `router.reload({ only })`) per non dover mantenere una route API separata.

---

## Priorità consigliate

1. **B1, B2, B3** — piano pronto.
2. **S1, S2** — rendere sicuri i default in v0.2 (breaking change, ancora accettabile in v0.x).
3. **S3, A4, F2** — escape delle wildcard, ordinamento di riserva, header `Accept`.
4. **F1, B4** — testi configurabili e opzioni per-page.
5. **C1** — filtri dichiarativi: ADR prima del codice.
