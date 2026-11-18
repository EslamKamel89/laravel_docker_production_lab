## **File 3 of 4 —&#x20;**`docs/architecture/03-request-and-job-flows.md`

This document specifies the execution flows connecting HTTP requests, Nginx, PHP-FPM, Laravel, PostgreSQL/pgvector, Redis, Horizon, persistent document storage, and the external embedding provider.

It also defines the failure boundaries and consistency guarantees that the implementation must satisfy.

# Request and Job Execution Flows — Laravel Knowledge API

**Status:** Approved architectural design — implementation pending\
**Project:** Laravel Docker Production Lab\
**Scope:** Development, testing, and production\
**Related decisions:** ADR-001 through ADR-009

**Related documents:**

- `01-development-architecture.md`
- `02-production-architecture.md`
- `decisions/README.md`

---

## 1. Purpose

This document defines how requests, commands, jobs, and data move through the Laravel Knowledge API.

It describes the interactions among:

- Nginx and PHP-FPM.
- Laravel's HTTP request lifecycle.
- PostgreSQL with pgvector.
- Redis and Laravel Horizon.
- Persistent document storage.
- The external embedding provider.
- Laravel Scheduler.
- Docker networking and process boundaries.

The goal is to make the system's runtime behavior explicit before implementation.

For every important flow, this document identifies:

1. The initiating event.
2. The components involved.
3. The execution sequence.
4. The state changes.
5. The expected result.
6. The possible failure points.
7. The recovery or consistency requirements.

**Core principle:** An HTTP request, a database transaction, a Redis queue operation, and a background job are separate execution boundaries. They do not automatically succeed or fail together.

## 2. System Execution Model

The Laravel Knowledge API is one application with multiple process roles.

```
flowchart TB
    Client["HTTP Client"]

    subgraph HTTP["HTTP Execution"]
        Nginx["Nginx"]
        FPM["PHP-FPM"]
        Laravel["Laravel Application"]
    end

    subgraph Async["Asynchronous Execution"]
        Redis["Redis Queue"]
        Horizon["Horizon Worker"]
        Scheduler["Laravel Scheduler"]
    end

    subgraph State["Persistent State"]
        PG[("PostgreSQL + pgvector")]
        Files[("Document Storage")]
    end

    Provider["External Embedding Provider"]

    Client -->|"HTTP / HTTPS"| Nginx
    Nginx -->|"FastCGI"| FPM
    FPM --> Laravel

    Laravel --> PG
    Laravel --> Files
    Laravel --> Redis

    Redis --> Horizon
    Horizon --> PG
    Horizon --> Files
    Horizon --> Provider

    Scheduler --> Redis
    Scheduler --> PG
```

### 2.1 Execution boundaries

| Boundary             | Responsible component | Important characteristic                                 |
| -------------------- | --------------------- | -------------------------------------------------------- |
| HTTP connection      | Nginx                 | Client-facing request and response                       |
| PHP execution        | PHP-FPM               | Runs Laravel's HTTP entrypoint                           |
| Database transaction | PostgreSQL            | Atomicity within one database transaction                |
| File write           | Storage filesystem    | Separate from database transactions                      |
| Queue publication    | Redis                 | Separate from PostgreSQL transactions                    |
| Background execution | Horizon worker        | Independent process and failure lifecycle                |
| External API call    | Embedding provider    | Remote dependency with variable latency and availability |
| Scheduled execution  | Laravel Scheduler     | Time-triggered execution independent of HTTP requests    |

These boundaries explain why successful execution at one layer does not guarantee success at another.

For example, an HTTP upload can succeed while background document processing later fails.

## 3. Flow A — HTTP Request Through Nginx and PHP-FPM

### 3.1 Scenario

A client requests document metadata:

```
GET /api/documents/42
Accept: application/json
```

The request must reach Laravel through Nginx and PHP-FPM.

### 3.2 Sequence diagram

```
sequenceDiagram
    autonumber
    participant C as HTTP Client
    participant N as Nginx
    participant F as PHP-FPM
    participant L as Laravel
    participant P as PostgreSQL

    C->>N: GET /api/documents/42
    N->>F: FastCGI request
    F->>L: Execute public/index.php
    L->>L: Bootstrap, middleware, routing
    L->>P: Query document metadata
    P-->>L: Document row
    L-->>F: JSON response
    F-->>N: FastCGI response
    N-->>C: HTTP 200 + JSON
```

### 3.3 Internal execution sequence

**Step 1 — Client connection**

In production, the client resolves the public domain and establishes an HTTPS connection to the configured ingress endpoint.

In development, the client normally connects to a locally published HTTP port.

The request ultimately reaches Nginx through Docker's port-publishing mechanism.

The exact packet path depends on the host operating system and Docker networking configuration.

**Step 2 — Nginx request handling**

Nginx:

1. Accepts the connection.
2. Parses the HTTP request.
3. Selects the configured server and location.
4. Determines whether the request targets a public static file or Laravel.
5. Forwards Laravel requests to PHP-FPM.

Laravel's public entrypoint is:

```
public/index.php
```

The Nginx document root must not expose the entire Laravel project.

**Step 3 — FastCGI forwarding**

Nginx forwards the request using FastCGI.

FastCGI is not HTTP.

Nginx sends request parameters such as:

- `REQUEST_METHOD`
- `REQUEST_URI`
- `QUERY_STRING`
- `SCRIPT_FILENAME`
- `CONTENT_TYPE`
- `CONTENT_LENGTH`

It also forwards relevant HTTP headers using FastCGI parameter conventions.

The exact configuration must ensure that `SCRIPT_FILENAME` resolves to the correct application path inside the PHP-FPM container.

**Step 4 — PHP-FPM execution**

PHP-FPM maintains a master process and worker pool.

An available worker receives the FastCGI request and executes Laravel's front controller.

The worker runs inside the PHP container's process, filesystem, and network namespaces.

**Step 5 — Laravel request lifecycle**

Laravel:

1. Loads its bootstrap configuration.
2. Resolves the application and request.
3. Executes the configured middleware pipeline.
4. Matches the route.
5. Resolves the controller or action.
6. Executes application logic.
7. Generates an HTTP response.

Laravel's exact bootstrap and middleware configuration will follow the selected framework version.

**Step 6 — Database access**

Laravel connects to PostgreSQL using the configured database service hostname.

Example:

```
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
```

The hostname is resolved through Docker's service discovery when the containers share an appropriate network.

**Step 7 — Response**

Laravel returns JSON.

PHP-FPM sends the response through FastCGI.

Nginx constructs and sends the client-facing HTTP response.

### 3.4 Failure scenarios

| Failure                      | Expected behavior                       | Investigation                             |
| ---------------------------- | --------------------------------------- | ----------------------------------------- |
| Nginx unavailable            | Connection failure or upstream error    | Container state, published port, logs     |
| PHP-FPM unavailable          | Typically HTTP 502                      | FastCGI upstream, process state, network  |
| Incorrect `SCRIPT_FILENAME`  | PHP execution failure or missing script | Nginx FastCGI configuration               |
| Laravel exception            | Application error response              | Laravel logs and exception handling       |
| PostgreSQL unavailable       | Database-dependent request fails        | DNS, TCP, authentication, database health |
| No available PHP-FPM workers | Requests wait or fail under pressure    | Worker pool and resource metrics          |

### 3.5 Architectural invariants

- Nginx does not execute Laravel PHP code.
- PHP-FPM is not publicly exposed.
- Laravel uses internal service discovery for database connections.
- Static public assets can be served without executing Laravel.
- Application source code and private configuration are not publicly accessible.

## 4. Flow B — Document Upload

### 4.1 Scenario

A client uploads a document for asynchronous processing.

```
POST /api/documents
Content-Type: multipart/form-data
```

The application must validate the upload, persist the original document, record its processing state, and arrange background ingestion.

The upload request should not wait for embedding generation.

### 4.2 Proposed API response

```
{
  "id": 42,
  "status": "pending",
  "message": "Document accepted for processing"
}
```

The expected response is:

```
HTTP/1.1 202 Accepted
```

This response is appropriate only after the application has durably recorded the processing intent according to its chosen reliability contract.

### 4.3 Sequence diagram

```
sequenceDiagram
    autonumber
    participant C as Client
    participant L as Laravel HTTP
    participant S as Document Storage
    participant P as PostgreSQL
    participant R as Redis

    C->>L: POST /api/documents
    L->>L: Authenticate and validate
    L->>S: Store original document
    S-->>L: Storage path
    L->>P: Begin transaction
    L->>P: Insert document (pending)
    L->>P: Commit transaction
    P-->>L: Commit successful
    L->>R: Publish ingestion job
    R-->>L: Queue publication result
    L-->>C: 202 Accepted
```

This diagram represents the straightforward initial approach. It does not imply that filesystem writes, PostgreSQL commits, and Redis publication form one atomic operation.

### 4.4 Proposed document states

| State        | Meaning                                              |
| ------------ | ---------------------------------------------------- |
| `pending`    | Document accepted; ingestion not yet completed       |
| `processing` | Worker is actively processing the document           |
| `completed`  | Required chunks and embeddings are available         |
| `failed`     | Processing failed and requires retry or intervention |

These are initial domain states.

The implementation may introduce more detailed states when justified by retry and reconciliation requirements.

### 4.5 Critical consistency problem

The upload operation touches three independent systems:

1. Document storage.
2. PostgreSQL.
3. Redis.

There is no automatic transaction spanning all three.

Consider:

```
File stored successfully
        |
        v
PostgreSQL record committed
        |
        v
Redis unavailable
```

The document exists, but its processing job was not published.

If Laravel simply returns an error and forgets the committed record, the system may retain a document that never gets processed.

This is a **dual-write consistency problem**.

### 4.6 Initial reliability strategy

The architecture must preserve a recoverable processing intent.

Two approaches will be evaluated during implementation.

**Option A — After-commit dispatch with reconciliation**

Laravel commits the document record and dispatches the job after the transaction.

A reconciliation process identifies documents that remain pending without successful processing progress and republishes work safely.

This is simpler to implement but requires careful recovery logic.

**Option B — Transactional outbox**

Laravel inserts both the document record and an outbox event into PostgreSQL in one transaction.

A separate publisher reads committed outbox events and publishes jobs to Redis.

```
PostgreSQL transaction
    |
    +-- Insert document
    |
    +-- Insert ingestion event
    |
    +-- Commit
             |
             v
       Outbox publisher
             |
             v
          Redis
```

The outbox approach provides a durable record of the publication intent.

It still requires idempotent consumers because publishing and acknowledging an outbox event can be interrupted.

**Initial decision:** Use after-commit dispatch with an explicit reconciliation requirement unless implementation evidence justifies adopting the transactional outbox immediately.

This is an implementation choice to validate in Phase 6, not a guarantee already provided by Laravel.

### 4.7 File/database consistency

The document file and its metadata must remain consistent.

Possible failure:

```
File write succeeds
    |
    v
Database transaction fails
```

The file becomes orphaned.

The implementation must support safe cleanup of orphaned files and must not delete files still referenced by valid records.

Document storage paths should use collision-resistant identifiers rather than trusting original filenames.

## 5. Flow C — Background Document Ingestion

### 5.1 Scenario

A Horizon worker consumes an ingestion job and converts the uploaded document into searchable vector data.

### 5.2 Sequence diagram

```
sequenceDiagram
    autonumber
    participant R as Redis Queue
    participant H as Horizon Worker
    participant P as PostgreSQL
    participant S as Document Storage
    participant E as Embedding Provider

    H->>R: Reserve ingestion job
    R-->>H: Job payload
    H->>P: Load document and processing state
    H->>P: Claim processing attempt
    H->>S: Read original document
    S-->>H: Document bytes
    H->>H: Extract and normalize text
    H->>H: Split into chunks
    H->>E: Generate embeddings
    E-->>H: Embedding vectors
    H->>P: Persist chunks and vectors
    H->>P: Mark document completed
    H->>R: Acknowledge successful job
```

The precise ordering of persistence operations, transactions, and acknowledgments will be implemented to support retries and recovery.

### 5.3 Worker execution lifecycle

A Horizon worker is a long-running Laravel CLI process.

Unlike PHP-FPM, it does not receive HTTP requests.

It continuously requests work from Redis.

When a job is available:

1. The worker reserves the job.
2. Laravel deserializes the job payload.
3. The job handler executes.
4. The worker records success or failure.
5. The queue transport updates the job's delivery state.

The exact reservation and acknowledgment behavior depends on the Laravel queue driver.

For Redis-backed Laravel queues, job visibility and retry timing are controlled by queue configuration.

### 5.4 Document processing stages

**Stage 1 — Load and claim**

The worker loads the document record.

It must determine whether the document:

- Requires processing.
- Is already completed.
- Is being processed by another valid attempt.
- Requires recovery from a stale attempt.

A simple status check without concurrency control may be insufficient when multiple workers execute the same job.

**Stage 2 — Read document**

The worker reads the original document from persistent storage.

Both PHP-FPM and Horizon must use compatible storage configuration and access the same underlying document content.

**Stage 3 — Extract text**

The worker extracts textual content.

The exact extraction library and supported document formats will be selected during application implementation.

Document parsing must treat uploaded content as untrusted.

**Stage 4 — Normalize and chunk**

Extracted text is normalized and divided into chunks.

Chunking affects:

- Search relevance.
- Embedding costs.
- Number of database rows.
- Vector index size.
- Retrieval granularity.

Chunk identifiers must be stable enough to support retries and reindexing.

**Stage 5 — Generate embeddings**

The worker calls the embedding provider through an application-level interface.

The interface should isolate:

- Provider-specific request formats.
- Authentication.
- Timeouts.
- Rate limits.
- Embedding dimensions.
- Error handling.
- Test doubles.

**Stage 6 — Persist results**

The worker writes document chunks and vectors into PostgreSQL.

Persistence must avoid duplicate rows when a job is retried.

**Stage 7 — Complete processing**

The document status changes to `completed` only when all required output is durably available.

The queue job is then acknowledged according to the queue driver's lifecycle.

### 5.5 Retry and idempotency

Queue execution should be treated as **at least once**, not exactly once.

Consider:

```
Worker stores embeddings
        |
        v
Database commit succeeds
        |
        v
Worker crashes
        |
        v
Job acknowledgment never occurs
        |
        v
Job becomes eligible for retry
```

A retry may repeat the same logical operation.

The application must therefore ensure repeated execution does not create duplicate or inconsistent results.

Possible mechanisms include:

- Unique database constraints.
- Stable document and chunk identifiers.
- Processing attempt identifiers.
- Idempotent upserts.
- Conditional status transitions.
- Transactional writes.
- Locks where appropriate.

Locks alone do not guarantee idempotency.

They reduce concurrent execution but cannot prevent replay after a lock is released.

### 5.6 Retry timing

Three different concepts must be distinguished:

| Mechanism            | Purpose                                                              |
| -------------------- | -------------------------------------------------------------------- |
| Job timeout          | Limits how long a worker may execute a job                           |
| Queue `retry_after`  | Controls when an unacknowledged reserved job becomes available again |
| Retry/backoff policy | Controls how failed attempts are delayed                             |

These values must be coordinated.

For ordinary Redis queue processing, `retry_after` should exceed the effective worker job timeout by a suitable margin.

Otherwise, the queue may make a job available to another worker while the original worker is still executing.

The exact values will depend on embedding-provider latency and document-processing workload measurements.

### 5.7 Graceful worker shutdown

During deployment, Horizon workers must stop accepting new work and terminate according to the chosen shutdown strategy.

A worker should not be forcibly killed during every deployment.

However, graceful shutdown has limits: a container's stop timeout may expire while a long-running job is still active.

The deployment configuration must coordinate:

- Horizon termination.
- Container stop grace period.
- Job timeout.
- Retry behavior.
- Idempotency.

## 6. Flow D — Semantic Similarity Search

### 6.1 Scenario

A client searches indexed documents using natural language.

```
POST /api/search
Content-Type: application/json
```

Example request:

```
{
  "query": "How are background jobs retried?",
  "limit": 10
}
```

### 6.2 Sequence diagram

```
sequenceDiagram
    autonumber
    participant C as Client
    participant L as Laravel HTTP
    participant E as Embedding Provider
    participant P as PostgreSQL + pgvector

    C->>L: POST /api/search
    L->>L: Authenticate and validate
    L->>E: Generate query embedding
    E-->>L: Query vector
    L->>P: Similarity query + access filters
    P-->>L: Ranked document chunks
    L->>L: Format authorized results
    L-->>C: JSON search results
```

### 6.3 Query embedding

The search query must be transformed into a vector compatible with the stored document embeddings.

The query and document embeddings must use compatible model semantics and dimensions.

Changing the embedding model may require re-embedding stored documents.

Vector dimensions are a schema and compatibility concern, not merely an API parameter.

### 6.4 Similarity search

PostgreSQL stores vectors using pgvector.

For cosine distance, pgvector provides the `<=>` operator.

Conceptually:

```
SELECT
    document_id,
    content,
    embedding <=> :query_vector AS distance
FROM document_chunks
WHERE document_id IN (
    SELECT id
    FROM documents
    WHERE owner_id = :owner_id
)
ORDER BY embedding <=> :query_vector
LIMIT 10;
```

This is conceptual SQL. The implementation must provide correctly typed vector parameters and validated query bindings.

A smaller cosine distance indicates greater similarity.

Cosine similarity can be derived as:

[\
\text{cosine similarity} = 1 - \text{cosine distance}\
]

The query must apply authorization constraints to the database result set, not merely hide unauthorized records after retrieving them.

### 6.5 Exact versus approximate search

The initial implementation should establish correctness using exact nearest-neighbor search.

Later, approximate indexes such as HNSW or IVFFlat may be introduced.

Approximate indexes trade some retrieval accuracy for improved query performance.

They require evaluation of:

- Recall.
- Latency.
- Index size.
- Build time.
- Memory consumption.
- Filtering behavior.
- Query plans.

The correct index strategy depends on measured workload characteristics.

### 6.6 Search failure behavior

Search currently depends on an external embedding provider.

If the provider is unavailable, Laravel may be unable to generate a query embedding.

The API should return a controlled error rather than hanging indefinitely.

Possible future optimizations include caching query embeddings or using a locally hosted model.

These are not initial requirements.

## 7. Flow E — Laravel Scheduler

### 7.1 Purpose

The scheduler initiates periodic application work independently of HTTP traffic.

Potential scheduled tasks include:

- Cleaning abandoned temporary files.
- Reconciling stale document-processing states.
- Dispatching reprocessing jobs.
- Performing maintenance.

### 7.2 Execution flow

```
sequenceDiagram
    autonumber
    participant S as Scheduler
    participant L as Laravel Schedule
    participant P as PostgreSQL
    participant R as Redis
    participant H as Horizon

    S->>L: Evaluate scheduled tasks
    L->>P: Inspect stale processing states
    P-->>L: Eligible records
    L->>R: Dispatch recovery jobs
    R-->>H: Jobs available
    H->>P: Reconcile document processing
```

### 7.3 Scheduler responsibilities

The scheduler decides **when work should begin**.

Horizon workers perform the asynchronous work.

A scheduled task may execute application logic directly or dispatch jobs.

Long-running, resource-intensive tasks should generally be delegated to the queue where appropriate.

### 7.4 Duplicate execution

The initial Compose deployment should run one scheduler instance.

If multiple scheduler instances are introduced, duplicate execution must be controlled.

Laravel scheduling locks can help prevent overlapping or duplicate task execution when configured correctly.

Distributed locking requires a shared coordination backend and careful consideration of lock expiration.

## 8. Flow F — Container Startup and Dependency Readiness

### 8.1 Why startup order is insufficient

Docker Compose can start containers in dependency order.

However, starting PostgreSQL does not mean it is immediately ready to accept application connections.

Similarly, a running PHP-FPM process does not prove that Laravel can access its dependencies.

### 8.2 Startup sequence

```
sequenceDiagram
    autonumber
    participant O as Docker Compose
    participant D as Docker Engine
    participant P as PostgreSQL
    participant R as Redis
    participant A as Laravel Services

    O->>D: Create networks and volumes
    O->>D: Create infrastructure containers
    D->>P: Start PostgreSQL process
    D->>R: Start Redis process
    P->>P: Initialize or recover database
    R->>R: Initialize runtime state
    O->>P: Evaluate configured health check
    P-->>O: Healthy
    O->>R: Evaluate configured health check
    R-->>O: Healthy
    O->>D: Start dependent application services
    D->>A: Start PHP-FPM / Horizon / Scheduler
```

This is a conceptual sequence for services configured with health-based dependency conditions. Compose does not wait for health unless the configuration explicitly requests it.

### 8.3 Health categories

| Category              | Meaning                                            | Example                                 |
| --------------------- | -------------------------------------------------- | --------------------------------------- |
| Liveness              | The expected process is alive                      | PHP-FPM master exists                   |
| Readiness             | The service can perform required work              | PostgreSQL accepts intended connections |
| Dependency health     | A required dependency is usable                    | Redis responds to application commands  |
| Application health    | Laravel can execute essential application behavior | Health endpoint succeeds                |
| External availability | Real clients can access the application            | HTTPS smoke test succeeds               |

Health checks should not create unnecessary cascading failures.

For example, an optional embedding provider outage should not automatically cause the HTTP application process to be restarted.

## 9. Flow G — Production Deployment and Worker Replacement

### 9.1 Deployment problem

A production release may change:

- Laravel code.
- Queue job handlers.
- Database schema.
- Public assets.
- Runtime configuration.

HTTP containers and workers may temporarily run different application versions during deployment.

This creates compatibility risks.

### 9.2 Conceptual release sequence

```
sequenceDiagram
    autonumber
    participant CI as GitHub Actions
    participant G as GHCR
    participant D as Deployment Host
    participant P as PostgreSQL
    participant A as Laravel Runtime
    participant H as Horizon

    CI->>CI: Run quality gates
    CI->>G: Publish versioned images
    D->>G: Pull approved image digests
    D->>P: Run compatible migrations
    D->>H: Initiate graceful worker replacement
    D->>A: Replace application containers
    D->>H: Start new release workers
    D->>A: Run health and smoke tests
```

This sequence is illustrative. The final deployment order must account for the selected migration and availability strategy.

### 9.3 Database compatibility

Application rollback does not automatically roll back database schema changes.

Destructive migrations can make older application releases unusable.

The production strategy should favor expand-and-contract migrations:

1. Introduce compatible schema changes.
2. Deploy code supporting old and new structures.
3. Backfill or migrate data.
4. Verify compatibility.
5. Remove obsolete structures in a later release.

### 9.4 Queue payload compatibility

Queued jobs can outlive the application release that created them.

A new worker may consume a job serialized by an older application version.

Therefore, job payloads and handlers require compatibility planning.

Avoid embedding unnecessary mutable application state in serialized job payloads.

Prefer stable identifiers and loading authoritative state from PostgreSQL when appropriate.

## 10. Failure Boundaries and Recovery Matrix

| Failure point      | Possible outcome                          | Required recovery behavior                        |
| ------------------ | ----------------------------------------- | ------------------------------------------------- |
| Nginx              | HTTP ingress unavailable                  | Restart and investigate                           |
| PHP-FPM            | HTTP requests fail                        | Restart and validate application health           |
| PostgreSQL         | Database-dependent operations fail        | Recover database and verify integrity             |
| Redis              | Dispatch or queue processing fails        | Recover Redis and reconcile pending work          |
| Document storage   | Upload or processing fails                | Restore access and reconcile metadata             |
| Embedding provider | Processing/search delayed or unavailable  | Timeouts, bounded retries, controlled errors      |
| Horizon worker     | Job interrupted                           | Retry safely with idempotency                     |
| Scheduler          | Periodic work delayed                     | Resume and reconcile missed work                  |
| Image deployment   | New release fails                         | Restore compatible previous release               |
| Host failure       | Entire single-host deployment unavailable | Restore on replacement host from off-host backups |

A container restart policy is not a complete recovery strategy.

It cannot correct invalid application configuration, corrupted data, or an incompatible schema.

## 11. Observability and Correlation

The system should make each important operation traceable across process boundaries.

### 11.1 HTTP correlation

Assign or propagate a request identifier.

Record it in relevant HTTP and application logs.

### 11.2 Document correlation

Each ingestion operation should be traceable through:

```
Request ID
    |
    v
Document ID
    |
    v
Ingestion Job ID / Attempt
    |
    v
Embedding Provider Request
    |
    v
Database Processing Result
```

The exact identifier format will be selected during implementation.

### 11.3 Required observations

| Flow               | Useful observations                              |
| ------------------ | ------------------------------------------------ |
| HTTP               | Request ID, route, status, latency               |
| Upload             | Document ID, file size, processing state         |
| Queue dispatch     | Job identity, publication result                 |
| Worker             | Attempt count, duration, failure reason          |
| Embedding provider | Request latency, rate limits, errors             |
| PostgreSQL         | Query latency, connection pressure, slow queries |
| Scheduler          | Execution time, skipped/failed tasks             |
| Deployment         | Release digest, migration result, health status  |

Logs must not contain API keys, sensitive document contents, or unnecessary personal information.

## 12. Verification and Failure Experiments

The implementation must prove these flows through executable tests and controlled failures.

### 12.1 HTTP verification

- Nginx forwards Laravel requests through FastCGI.
- Static public assets are served correctly.
- PHP-FPM is not directly accessible from the public network.
- PostgreSQL-backed endpoints work.
- Incorrect upstream configuration produces diagnosable failures.

### 12.2 Upload verification

- Uploaded files persist outside disposable container layers.
- Database records correctly reference stored files.
- The API returns the documented asynchronous response.
- Failed database writes do not leave uncontrolled orphaned files.
- Failed job publication leaves recoverable processing intent.

### 12.3 Queue verification

- Horizon consumes ingestion jobs.
- Workers can read uploaded documents.
- Embeddings are persisted in PostgreSQL.
- Duplicate job execution does not duplicate final results.
- Worker crashes produce recoverable job state.
- Redis outages do not silently lose document-processing intent.

### 12.4 Search verification

- Query embeddings use a compatible model and dimension.
- Similarity queries return correctly ordered results.
- Authorization filters are enforced in the query.
- Provider timeouts produce controlled API failures.
- Approximate index behavior is benchmarked before adoption.

### 12.5 Deployment verification

- All Laravel runtime roles use the intended release.
- Migrations are compatible with the deployment strategy.
- Existing queued jobs remain processable during upgrades.
- Workers terminate gracefully where possible.
- Rollback restores a compatible application release.

## 13. Architectural Invariants

The following rules must remain true throughout the project.

**HTTP boundary**

Nginx owns HTTP ingress. PHP-FPM executes Laravel through FastCGI.

**Application boundary**

HTTP, Horizon, scheduler, and Artisan are roles of one Laravel application, not separate business services.

**Database boundary**

PostgreSQL is authoritative for relational records, document-processing state, and stored embeddings.

**Queue boundary**

Redis transports jobs. Horizon workers execute them. Queue publication and database commits are not one atomic transaction.

**Storage boundary**

Original document files live outside immutable application images and disposable container layers.

**Embedding boundary**

The provider integration is replaceable and must not leak provider-specific behavior throughout the domain model.

**Reliability boundary**

A successful HTTP response does not imply that asynchronous document processing has completed.

**Deployment boundary**

Application releases must be versioned, traceable, and compatible with persistent state.

**Security boundary**

Only intended HTTP(S) ingress is public. PostgreSQL, Redis, and PHP-FPM remain private.

## 14. Relationship to the Course

This document supports several implementation phases.

| Phase    | Flows implemented or investigated                 |
| -------- | ------------------------------------------------- |
| Phase 3  | Nginx, PHP-FPM, development request handling      |
| Phase 4  | PostgreSQL startup, readiness, persistence        |
| Phase 5  | Uploads, embeddings, vector search                |
| Phase 6  | Redis, Horizon, retries, idempotency, scheduler   |
| Phase 7  | Networking, HTTP, FastCGI, TLS                    |
| Phase 8  | Integration testing and failure injection         |
| Phase 11 | Production service startup                        |
| Phase 12 | Deployment and release compatibility              |
| Phase 13 | Logging, metrics, request correlation             |
| Phase 14 | Failure recovery, backup restoration, reliability |

The flows are architectural contracts.

Their implementation details will be refined through tests and operational experiments.

---

## Document Status

**Architecture:** Approved design\
**Implementation:** Pending\
**Runtime verification:** Pending\
**Next document:** `decisions/README.md`

This document defines the intended execution behavior of the Laravel Knowledge API. The authoritative GitHub repository and observed runtime behavior will determine what has actually been implemented.

**Output:** Third architecture document drafted, with HTTP, upload, ingestion, semantic search, scheduler, startup, and deployment flows.

No GitHub files have been modified.

**Next file:** `docs/architecture/decisions/README.md` — the register of the nine approved architectural decisions, including their rationale, consequences, alternatives, and criteria for reconsideration.
