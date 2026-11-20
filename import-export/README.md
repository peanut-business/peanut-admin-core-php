# Import and export

`SpreadsheetWriter::write($path, $rows, $sheetName)` and `SpreadsheetReader::read($path)` select XLSX or CSV from the extension. Rows are consumed lazily. Strings are explicit XLSX text; CSV protects formula prefixes including leading whitespace. Formula cells read from XLSX are returned as formula text and are never evaluated. Unsupported input is rejected; file and parser errors are propagated separately.

`ColumnTemplate` validates one named row and returns field errors. A host can call `StreamingExport::execute` from its existing `JobHandlerAdapter` callback after authorization; the host owns queue submission, private storage, and download permissions.

Lock and rate limiter stores are passed explicitly: use Symfony `FlockStore` for a local lock, `RedisStore` with a selected Redis connection for shared locking, and `CacheStorage` with the selected PSR-6 pool for limiter state; construct `ConcurrencyServices::locks` and `rateLimiter` with those objects. The caller chooses storage and distributed locking suitable for the deployment; there is no fallback. Idempotent business commands continue to use `IdempotencyService`.

`RedisSequenceStore` accepts an existing Redis connection with `incr`, and a namespace; each namespace/key pair shares one atomic counter. The host owns namespace selection and persistence of the selected Redis deployment. It does not use a host business table.
