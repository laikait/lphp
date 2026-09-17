# MCP Integration Plan

> **Repository notes.** This plan was written before it met the repository, and these
> points override anything below that disagrees:
>
> - **Configuration** is `config/mcp.php`, one file per namespace, cached by `cache:warm`
>   like every other setting — not a root `config.php`. The file name is the key, so
>   `config/mcp.php` returns the inner array of the example in section 19.
> - **Permissions** (`->permission()`, MCP-09) are the existing authorization capabilities —
>   `AccessRegistry`, capabilities and roles as data; see
>   [Authentication and authorization](../reference/auth.md). There is no second permission system.
> - **Plugin modules live in `modules/Plugins/<Name>/`.**
> - **Specification §54 still applies**: no facades, service providers, middleware,
>   gates/policies or `make:*` generators. PHP 8.2 is the floor.
> - **The project owner commits.** A phase ends with the quality gate green and a proposed
>   commit message.

## Purpose

Add first-class **Model Context Protocol (MCP)** support to the framework without creating a second application architecture.

The framework remains **module-first**. MCP is an interface through which AI clients access explicitly registered application capabilities.

Core rule:

> HTTP, CLI, background jobs, scheduled tasks, and MCP should call the same application capabilities instead of duplicating business logic.

MCP must never become a generic interface to arbitrary PHP methods, SQL, filesystem operations, or shell commands.

---

## 1. Architecture

```text
                         Application
                              |
                           Kernel
                              |
          +-------------------+-------------------+
          |                   |                   |
         HTTP                CLI                 MCP
          |                   |                   |
        Router             Commands          MCP Server
          |                   |                   |
          +-------------------+-------------------+
                              |
                    Application Capability
                              |
                +-------------+-------------+
                |             |             |
             Service         Data          Hooks
                |             |             |
                +-------------+-------------+
                              |
                         Infrastructure
```

MCP is an **interface layer**, not a business-logic layer.

---

## 2. Goals

Implement:

- MCP server support
- MCP protocol handling
- tools
- resources
- prompts
- tool input schemas
- tool execution
- resource reading
- prompt retrieval
- STDIO transport
- HTTP transport
- authentication integration
- authorization
- input validation
- output normalization
- module-owned MCP capabilities
- hooks and filters
- capability discovery
- production manifests
- logging and auditing
- security boundaries
- tests and documentation

MCP must be optional and must not impose significant overhead on applications that do not enable it.

---

## 3. Non-Goals

Do NOT:

- expose every model automatically
- expose the database directly
- expose arbitrary PHP methods
- expose arbitrary filesystem operations
- expose arbitrary shell commands
- create automatic CRUD tools for every model
- duplicate business logic inside MCP tools
- require an ORM
- make MCP the center of the framework
- create static facades
- copy Laravel/Symfony architecture
- create speculative abstractions

---

## 4. Target Directory

Add:

```text
engine/
└── MCP/
    ├── McpServer.php
    ├── McpManager.php
    ├── McpContext.php
    ├── McpRequest.php
    ├── McpResponse.php
    ├── McpError.php
    ├── Protocol/
    ├── Tool/
    ├── Resource/
    ├── Prompt/
    ├── Validation/
    ├── Authorization/
    ├── Transport/
    └── Discovery/
```

Do not create every file immediately. Create files only when required by the current implementation phase.

---

## 5. Module-Owned MCP

Example:

```text
modules/Plugins/Customer/
├── module.php
├── Routes/
├── MCP/
│   ├── Tools/
│   │   ├── ListCustomers.php
│   │   ├── GetCustomer.php
│   │   └── CreateCustomer.php
│   ├── Resources/
│   │   └── CustomerResource.php
│   └── Prompts/
│       └── CustomerSupport.php
├── Api/
├── Commands/
├── Model/
├── Schema/
├── Data/
├── Hooks/
└── Filters/
```

A module without MCP capabilities does not need an `MCP/` directory.

Registration must be explicit:

```php
$module->mcp(function (McpRegistry $mcp): void {
    $mcp->tool('customer.get', GetCustomer::class);
});
```

The exact API can evolve during implementation, but these rules cannot:

- explicit registration
- known module ownership
- duplicate-name detection
- deterministic registration
- disabled modules cannot expose capabilities
- module dependencies are respected
- capability metadata is available
- no automatic filesystem-based exposure

---

## 6. Tools

A tool represents an explicit operation an MCP client may invoke.

Conceptual example:

```php
final class GetCustomer
{
    public function name(): string
    {
        return 'customer.get';
    }

    public function description(): string
    {
        return 'Retrieve a customer by ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $input): mixed
    {
        // Delegate to an application capability.
    }
}
```

Preferred:

```text
MCP Tool
    |
    v
Application Service
    |
    v
Query / Repository
    |
    v
Database
```

Avoid putting large amounts of business logic directly into MCP tools.

---

## 7. Tool Metadata

Tools should support metadata such as:

```php
$mcp->tool('customer.get', GetCustomer::class)
    ->permission('customer.view')
    ->tag('customer');
```

Potential metadata:

- name
- description
- input schema
- permission
- module owner
- tags
- read-only/write classification
- destructive-operation classification
- timeout
- rate-limit policy
- audit policy

Only implement metadata with a concrete architectural use.

---

## 8. Resources

Resources provide read-oriented application context.

Examples:

```text
customer://123
invoice://456
server://web-01
```

Conceptually:

```php
$mcp->resource(
    'customer://{id}',
    CustomerResource::class
);
```

Support:

- resource discovery
- URI matching
- URI validation
- metadata
- reading
- structured content where appropriate
- text/binary content where appropriate
- authorization
- audit logging

Never allow arbitrary filesystem URIs.

---

## 9. Prompts

Modules may expose reusable MCP prompts.

Examples:

```text
customer.support
invoice.explanation
server.diagnosis
```

Prompts are explicitly registered and should not silently execute application operations.

---

## 10. MCP Context

Introduce an execution context containing only necessary request metadata.

Possible information:

```text
request ID
client metadata
transport
authenticated principal
```

Do not turn `McpContext` into a global service locator.

---

## 11. Request Lifecycle

Target tool lifecycle:

```text
Transport
    |
MCP Protocol Parser
    |
MCP Request
    |
Capability Resolution
    |
Authentication
    |
Authorization
    |
Input Validation
    |
mcp.tool.before
    |
Tool Execution
    |
mcp.tool.after
    |
Result Normalization
    |
MCP Response
    |
Transport
```

Authorization and validation must occur before the business operation.

---

## 12. Hooks and Filters

Integrate with the framework hook system.

Recommended hooks:

```text
mcp.request.received
mcp.request.failed
mcp.tool.registered
mcp.tool.before
mcp.tool.after
mcp.tool.failed
mcp.resource.registered
mcp.resource.read
mcp.resource.failed
mcp.prompt.registered
mcp.prompt.loaded
mcp.prompt.failed
mcp.response.created
```

Potential filters:

```text
mcp.tool.input
mcp.tool.output
mcp.tool.description
mcp.tool.schema
mcp.resource.output
mcp.prompt.output
mcp.response
```

Hooks and filters must never bypass security checks.

---

## 13. Authentication and Authorization

MCP should integrate with the framework's existing authentication/security architecture.

Target:

```text
MCP Client
    |
Authentication
    |
Authenticated Principal
    |
MCP Context
```

Protected tools must support permissions:

```php
$mcp->tool('customer.delete', DeleteCustomer::class)
    ->permission('customer.delete');
```

Never expose generic capabilities such as:

```text
system.execute
shell.execute
php.execute
database.query
filesystem.write
```

If an application genuinely needs a sensitive capability, it must be narrowly scoped, explicitly implemented, authenticated, and authorized.

---

## 14. Input Validation

Validate tool input against its declared schema before execution.

Validate:

- required fields
- types
- nested structures
- allowed values
- malformed input
- unexpected values where forbidden
- input-size limits

Do not rely only on PHP type declarations.

Flow:

```text
MCP Input
    |
Schema Validation
    |
Normalized Input
    |
Tool
```

---

## 15. Output Handling

Do not automatically serialize arbitrary PHP objects.

Prefer explicit output:

```php
return [
    'id' => $customer->id,
    'name' => $customer->name,
    'email' => $customer->email,
];
```

This prevents accidental exposure of internal properties, credentials, database objects, and services.

---

## 16. Transport

Keep transport separate from protocol:

```text
MCP Server
    |
    +-- Protocol
    +-- Registry
    +-- Execution
    |
    +-- Transport
         +-- STDIO
         +-- HTTP
```

STDIO should be implemented first if local MCP clients are the first target.

HTTP follows after protocol and security are stable.

---

## 17. STDIO

Requirements:

- JSON message processing according to the supported MCP protocol
- request/response correlation
- clean stdout protocol output
- diagnostics through stderr/framework logging
- malformed-message handling
- graceful shutdown
- process lifecycle handling

Never print debug output to stdout during MCP STDIO operation.

---

## 18. HTTP

Reuse the framework's existing HTTP infrastructure where practical.

Target:

```text
HTTP Request
    |
index.php
    |
HTTP Kernel
    |
MCP endpoint/adapter
    |
MCP Server
```

MCP protocol semantics must remain separate from ordinary REST routing.

The endpoint should be explicit and configurable, for example:

```text
/mcp
```

Do not automatically expose MCP simply because the framework is installed.

---

## 19. Configuration

Use `config/mcp.php`, which returns the contents of the `'mcp'` key below.

Example:

```php
return [
    'mcp' => [
        'enabled' => true,

        'server' => [
            'name' => 'My Application',
            'version' => '1.0.0',
        ],

        'transports' => [
            'stdio' => true,
            'http' => false,
        ],

        'http' => [
            'path' => '/mcp',
        ],
    ],
];
```

`.env` is not mandatory.

Environment variables may optionally be read from `config/mcp.php` through `Env`.

---

## 20. Capability Discovery and Production Manifest

Development:

```text
Modules
   |
Registration
   |
MCP Registry
```

Production:

```text
Compiled MCP Manifest
   |
MCP Registry
```

Avoid repeated filesystem scans in production.

The manifest should include only explicitly registered capabilities.

Regenerate it when relevant modules/capabilities change.

Provide a CLI command for rebuilding it, using the framework's final CLI naming convention.

---

## 21. Database/Data Integration

MCP uses the existing architecture:

```text
Model
Schema
Data
    +-- Query
    +-- Repository
Database
```

Example:

```text
customer.get
      |
Customer Service
      |
Customer Query
      |
Database
```

For lists/reports, prefer efficient read/query paths instead of hydrating large collections of full models.

---

## 22. REST and CLI Integration

Do not duplicate business logic.

Preferred:

```text
                    Customer Capability
                    /        |                           /         |                         REST        CLI        MCP
```

For example:

```text
POST /customers
customer:create
customer.create
```

may all call:

```text
CustomerService::create()
```

Interfaces remain protocol-specific.

MCP should not invoke CLI commands internally just to reuse logic.

---

## 23. Logging and Auditing

Integrate with the existing logging system.

For sensitive operations, record where appropriate:

- timestamp
- request ID
- client identity
- authenticated principal
- capability name
- module
- success/failure
- authorization outcome

Do not log secrets or complete sensitive payloads by default.

Support configurable redaction.

---

## 24. Performance

Measure before optimizing.

Measure:

- startup time
- capability discovery
- manifest loading
- tool resolution
- tool execution overhead
- memory usage

Potential optimizations:

- compiled capability manifest
- lazy tool instantiation
- reduced reflection
- cached schema processing
- minimal MCP boot path

Avoid loading every module's complete dependency graph when unnecessary.

---

## 25. Security Invariants

These are mandatory:

```text
MCP != arbitrary PHP execution
MCP != arbitrary SQL
MCP != arbitrary filesystem access
MCP != arbitrary shell access
MCP != automatic model exposure
```

Every exposed capability must be intentional.

Tool input must be validated.

Sensitive operations must be authorized.

Results must be explicitly shaped.

---

# Implementation Phases

## MCP-01 — Architecture and Contracts

Tasks:

- define MCP subsystem boundaries
- define protocol abstraction
- define tool/resource/prompt contracts
- define registry contracts
- define transport contract
- define execution context
- define error model

Tests:

- contracts
- registry contracts

Done when the boundaries are documented and tested.

---

## MCP-02 — Protocol Foundation

Tasks:

- supported MCP protocol structures
- request handling
- response handling
- notifications
- protocol errors
- version handling
- JSON serialization/deserialization

Tests:

- valid messages
- invalid messages
- errors
- request IDs
- notifications

---

## MCP-03 — Capability Registry

Tasks:

- ToolRegistry
- ResourceRegistry
- PromptRegistry
- duplicate detection
- module ownership
- deterministic registration
- lookup

Tests:

- registration
- lookup
- duplicates
- ownership
- disabled modules

---

## MCP-04 — Tool System

Tasks:

- tool definition
- execution
- input schema
- result
- output normalization
- metadata

Tests:

- successful execution
- validation errors
- execution errors
- output handling

---

## MCP-05 — Resource System

Tasks:

- resource definition
- URI resolution
- execution
- result
- metadata

Tests:

- URI matching
- valid reads
- invalid reads
- authorization integration

---

## MCP-06 — Prompt System

Tasks:

- prompt definition
- registry
- retrieval
- arguments

Tests:

- registration
- lookup
- arguments
- errors

---

## MCP-07 — Module Integration

Tasks:

- module MCP registration
- ownership
- dependencies
- enable/disable behavior
- discovery

Tests:

- multiple modules
- dependency order
- disabled modules
- duplicate capability names

---

## MCP-08 — Execution Context

Tasks:

- request ID
- client metadata
- transport metadata
- authenticated principal integration
- context lifecycle

Tests:

- context creation
- request isolation
- metadata handling

---

## MCP-09 — Authentication and Authorization

Tasks:

- integrate existing authentication
- MCP authorization
- capability permissions
- authorization errors
- sensitive operation handling

Tests:

- authenticated calls
- unauthenticated calls
- permitted calls
- denied calls

Perform a security review.

---

## MCP-10 — Input Validation

Tasks:

- schema validation
- normalized input
- validation errors
- input limits

Tests:

- types
- required fields
- nested structures
- invalid values
- oversized input

---

## MCP-11 — STDIO Transport

Tasks:

- STDIO transport
- protocol message loop
- stdout isolation
- stderr diagnostics
- lifecycle handling
- graceful shutdown

Tests:

- valid request
- invalid request
- multiple requests
- errors
- shutdown

This phase should make local MCP clients usable.

---

## MCP-12 — HTTP Transport

Tasks:

- HTTP transport adapter
- MCP endpoint
- HTTP authentication
- request/response mapping
- configuration

Tests:

- valid request
- malformed request
- authentication
- authorization
- protocol errors

---

## MCP-13 — Hooks and Filters

Tasks:

Implement MCP lifecycle hooks and appropriate filters.

Tests:

- callbacks
- priority ordering
- transformations
- failure behavior
- security ordering

---

## MCP-14 — Configuration

Tasks:

- integrate `config/mcp.php`
- defaults
- validation
- enable/disable
- transport configuration

Tests:

- defaults
- invalid configuration
- disabled MCP
- transport settings

---

## MCP-15 — Production Manifest

Tasks:

- capability discovery
- manifest generation
- cache generation
- cache loading
- invalidation
- CLI rebuild command

Tests:

- generation
- module changes
- capability changes
- stale manifest handling

---

## MCP-16 — Logging and Observability

Tasks:

- request logging
- tool execution logging
- authorization logging
- error logging
- request IDs
- sensitive-data redaction

Tests:

- success
- failure
- denied operation
- redaction

---

## MCP-17 — Security Hardening

Perform a dedicated security review.

Test:

- arbitrary tool access
- arbitrary class invocation
- SQL injection through tool inputs
- path traversal
- filesystem exposure
- command execution exposure
- oversized input
- malicious schemas
- unauthorized operations
- sensitive output leakage
- disabled module access
- transport abuse

No MCP release is complete until this phase passes.

---

## MCP-18 — Performance

Measure:

- startup
- manifest loading
- capability resolution
- tool execution
- memory

Optimize only measured bottlenecks.

---

## MCP-19 — Demo Module

Create:

```text
modules/Plugins/McpDemo/
├── module.php
└── MCP/
    ├── Tools/
    │   ├── Echo.php
    │   └── GetStatus.php
    ├── Resources/
    │   └── StatusResource.php
    └── Prompts/
        └── Diagnostics.php
```

Verify:

- registration
- discovery
- invocation
- resource reading
- prompt retrieval
- permissions
- hooks
- filters
- STDIO
- HTTP if implemented

---

## MCP-20 — Documentation and Release

Document:

- architecture
- module integration
- tool creation
- resource creation
- prompt creation
- configuration
- STDIO
- HTTP
- permissions
- security
- testing
- production manifest
- troubleshooting

Add a complete working example.

---

# Claude Code Workflow

For every MCP phase:

1. Read this plan completely.
2. Read the current framework architecture.
3. Inspect the existing implementation.
4. Identify the current MCP phase.
5. Identify already implemented dependencies.
6. Implement only the current phase.
7. Do not redesign unrelated components.
8. Write automated tests.
9. Run the relevant test suite.
10. Run static analysis.
11. Run coding standards.
12. Fix failures.
13. Review public APIs.
14. Review security.
15. Review performance.
16. Update documentation.
17. Mark completed tasks in this file.
18. Verify the implementation did not introduce Laravel/Symfony-style architecture.
19. Propose a commit message; the project owner commits.

Never implement future phases prematurely.

---

# Definition of Done

MCP support is complete only when:

- protocol works
- tools work
- resources work
- prompts work
- module registration works
- STDIO works
- HTTP works if enabled
- authentication integrates
- authorization works
- input validation works
- output shaping works
- hooks work
- filters work
- `config/mcp.php` configuration works
- production manifest works
- logging works
- sensitive information is protected
- security tests pass
- performance has been measured
- documentation exists
- static analysis passes
- coding standards pass
- integration tests pass

---

# Final Architectural Invariants

1. MCP is optional.
2. Modules own MCP capabilities.
3. Capabilities are explicitly registered.
4. MCP does not own business logic.
5. MCP tools call application capabilities.
6. MCP does not expose arbitrary PHP.
7. MCP does not expose arbitrary SQL.
8. MCP does not expose arbitrary filesystem access.
9. MCP does not expose arbitrary shell execution.
10. Authentication is separate from authorization.
11. Protected operations require authorization.
12. Input is validated before execution.
13. Output is explicitly shaped.
14. Transport is separate from protocol.
15. STDIO and HTTP share the MCP server implementation.
16. HTTP reuses existing HTTP infrastructure where practical.
17. MCP uses existing module, DI, hook, filter, logging, configuration, and security systems.
18. MCP does not require an ORM.
19. MCP does not require `.env`.
20. `config/mcp.php` is where MCP is configured.
21. Production avoids repeated filesystem discovery.
22. Heavy operations prefer efficient queries/read models.
23. MCP must not become a second framework.
24. Do not add abstractions without a concrete need.
25. Do not copy Laravel/Symfony architecture.
