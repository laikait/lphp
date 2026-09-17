# Laika Framework — System / Server Operations Plan

> **Repository notes.** This plan was written before it met the repository, and these
> points override anything below that disagrees:
>
> - **Configuration** is `config/system.php`, one file per namespace, cached by
>   `cache:warm` like every other setting — not a root `config.php`. The file name is the
>   key, so `config/system.php` returns the inner array of the examples in SYSTEM-15.
> - **Capabilities** (SYSTEM-12) build on the existing authorization model —
>   `AccessRegistry`, capabilities and roles as data, `meta(['can' => …])` on routes; see
>   [Authentication and authorization](../reference/auth.md). There is no second permission system.
> - **The scheduler command is `schedule:run`**, and plugin modules live in
>   `modules/Plugins/<Name>/`.
> - **Specification §54 still applies**: no facades, service providers, middleware,
>   gates/policies or `make:*` generators. PHP 8.2 is the floor.
> - **Linux-first work is verified on Linux** — CI, and WSL Ubuntu on the development machine.
> - **The project owner commits.** A phase ends with the quality gate green and a proposed
>   commit message.

## 1. Purpose

Build a dedicated `engine/System/` subsystem for controlled operating-system and server operations.

The subsystem provides framework-level primitives for:

- External program execution
- Explicit shell/script execution
- Process management
- OS cron management
- System service management
- System information
- Controlled server filesystem operations
- Permission management where explicitly supported
- Execution policies, timeouts, resource limits, and audit logging

Core principle:

```text
engine/System
    = HOW the operating system is controlled

Application Module
    = WHAT the application wants to accomplish

CLI
    = Admin/operator interface

MCP
    = AI/client interface to selected capabilities
```

`engine/System` must never become an unrestricted remote shell.

---

## 2. Architectural Position

```text
                         Application
                              |
             +----------------+----------------+
             |                |                |
            HTTP             CLI              MCP
             |                |                |
             +----------------+----------------+
                              |
                    Application Capabilities
                              |
                    +---------+---------+
                    |                   |
                 Modules            Services
                                        |
                                  engine/System
                                        |
                                        v
                                      OS
```

System operations are infrastructure capabilities, not business logic.

---

## 3. Architectural Rules

### 3.1 No generic public `exec()`

Do not make this the primary API:

```php
$system->exec($command);
```

Prefer structured commands:

```php
$command = new Command(
    executable: 'systemctl',
    arguments: ['restart', 'nginx'],
);

$result = $executor->run($command);
```

Arguments must remain separate from the executable.

### 3.2 Separate executable execution from shell execution

Direct executable:

```text
systemctl restart nginx
```

Explicit shell:

```text
bash backup.sh
```

Do not automatically run every command through `sh -c`.

### 3.3 No arbitrary command exposure through MCP

Never expose:

```text
system.execute("whatever")
```

Instead expose controlled capabilities:

```text
MCP
 ↓
server.service.restart
 ↓
ServerService
 ↓
CommandExecutor
 ↓
systemctl restart nginx
```

---

# 4. Target Directory Structure

Create directories/files only when required by the implementation phase.

```text
engine/
└── System/
    ├── Command/
    │   ├── Command.php
    │   ├── CommandExecutor.php
    │   ├── CommandResult.php
    │   ├── CommandException.php
    │   ├── CommandOptions.php
    │   └── CommandPolicy.php
    ├── Process/
    │   ├── Process.php
    │   ├── ProcessManager.php
    │   ├── ProcessResult.php
    │   └── ProcessException.php
    ├── Cron/
    │   ├── CronJob.php
    │   ├── CronSchedule.php
    │   ├── CronManager.php
    │   └── CronException.php
    ├── Service/
    │   ├── Service.php
    │   ├── ServiceManager.php
    │   └── ServiceException.php
    ├── Filesystem/
    │   ├── SystemFilesystem.php
    │   ├── FilesystemPolicy.php
    │   └── FilesystemException.php
    ├── Permission/
    │   ├── PermissionManager.php
    │   └── PermissionException.php
    ├── SystemInfo/
    │   ├── SystemInfo.php
    │   └── SystemInfoProvider.php
    ├── Security/
    │   ├── Capability.php
    │   ├── CapabilityPolicy.php
    │   └── SystemAuthorizer.php
    └── Audit/
        └── SystemAuditLogger.php
```

Do not implement the entire tree in one phase.

---

# 5. Phase SYSTEM-01 — Architecture and Contracts

Define the System subsystem boundaries before implementing OS behavior.

Tasks:

1. Create `engine/System/`.
2. Define namespaces.
3. Define interfaces and value objects.
4. Define exceptions.
5. Define execution result contracts.
6. Define capability boundaries.
7. Document Linux-first assumptions.
8. Keep OS-specific implementations behind explicit boundaries where useful.

Tests:

- Value-object construction
- Invalid arguments
- Exception types
- Immutable state where appropriate

---

# 6. Phase SYSTEM-02 — Command Model

Create a safe structured representation of an executable command.

```php
$command = new Command(
    executable: 'systemctl',
    arguments: ['restart', 'nginx'],
);
```

Support, where useful:

- executable
- arguments
- working directory
- environment
- timeout
- stdin
- output capture
- shell mode
- output limits

Do not internally create unsafe command strings when the process API can accept an executable and arguments separately.

Tests:

- Executable validation
- Argument handling
- Spaces in arguments
- Special characters
- Empty arguments
- Working directory
- Environment
- Timeout options
- Explicit shell mode

---

# 7. Phase SYSTEM-03 — Command Executor

Target API:

```php
$result = $executor->run($command);
```

Result API:

```php
$result->exitCode();
$result->stdout();
$result->stderr();
$result->successful();
$result->failed();
$result->duration();
```

Support:

- Synchronous execution
- stdout
- stderr
- exit code
- timeout
- working directory
- environment
- failure detection
- execution duration

Timeout must terminate the process, preserve available output, return/throw predictably, and be auditable.

Tests:

- Successful execution
- Non-zero exit
- stderr
- timeout
- missing executable
- working directory
- environment
- output handling

Do not depend exclusively on system-specific binaries in unit tests.

---

# 8. Phase SYSTEM-04 — Explicit Shell Execution

Shell execution must be explicit.

Possible API:

```php
$command = ShellCommand::bash(
    script: '/path/to/script.sh',
);
```

Support:

- bash
- script path
- controlled arguments
- timeout
- environment
- working directory

Never interpolate untrusted input directly into shell source.

Tests:

- Shell mode must be explicit
- Script execution
- Argument handling
- Timeout
- Failure
- stderr

---

# 9. Phase SYSTEM-05 — Process Management

Provide process lifecycle management:

- start
- inspect
- terminate
- signal
- wait
- running state
- PID
- exit code

Example:

```php
$process = $processManager->start($command);

$process->pid();
$process->isRunning();

$process->terminate();
```

Do not build a full service supervisor.

Tests should use deterministic PHP processes and cover start, running state, exit, PID, timeout, termination, and exit status.

---

# 10. Phase SYSTEM-06 — Cron System

Provide structured OS cron management.

```php
$cron->job('billing:invoice-check')
    ->schedule('*/5 * * * *')
    ->install();
```

Responsibilities:

- create
- update
- remove
- list managed jobs
- inspect
- validate schedule
- install
- uninstall

Framework-managed cron entries must have recognizable ownership/metadata.

Never overwrite unrelated user cron entries.

Tests:

- Cron expression validation
- Serialization
- Job identity
- Install/update/remove
- Ownership detection
- Unrelated entries remain unchanged

Use an isolated fake cron source for unit tests.

---

# 11. Phase SYSTEM-07 — Scheduler Integration

System Cron and Framework Scheduler are different:

```text
System Cron
    = operating-system trigger

Framework Scheduler
    = application scheduling engine
```

Preferred flow:

```text
Linux Cron
   ↓
bin/console schedule:run
   ↓
Framework Scheduler
   ↓
Application Task
```

System/Cron owns the OS mechanism.

Scheduler owns:

- task registration
- schedule evaluation
- locking
- execution
- retries
- task state

Do not duplicate Scheduler functionality in System.

---

# 12. Phase SYSTEM-08 — Service Management

Linux-first implementation can target `systemd`.

Example:

```php
$services->restart('nginx');
```

Potential operations:

- status
- start
- stop
- restart
- reload
- enable
- disable

Service names and actions must be validated.

Policies must be able to restrict operations such as:

```text
restart nginx
restart php8.3-fpm
```

while denying prohibited operations such as:

```text
stop ssh
```

Tests should use a fake backend/mocked executor rather than requiring production services.

---

# 13. Phase SYSTEM-09 — System Information

Provide read-only server information:

- OS
- kernel
- architecture
- hostname
- CPU count
- memory
- load average
- uptime
- disk information
- PHP version
- available binaries where appropriate

Example:

```php
$info->os();
$info->kernel();
$info->architecture();
$info->cpuCount();
```

Prefer PHP/system APIs over spawning processes when possible.

---

# 14. Phase SYSTEM-10 — System Filesystem

Provide controlled server filesystem operations:

- inspect
- create directory
- write
- move
- copy
- delete
- permissions
- ownership where supported

Every path requires:

- normalization
- traversal prevention
- allowed-root restrictions
- symlink handling
- permission checks
- ownership restrictions

Never allow path escape such as:

```text
../../etc/passwd
```

unless explicitly permitted by a tightly controlled policy.

---

# 15. Phase SYSTEM-11 — Permission Management

Possible operations:

```php
$permissions->chmod($path, 0644);
```

Potentially:

- chmod
- chown
- chgrp

These are privileged operations.

Rules:

- Validate permissions.
- Restrict ownership changes.
- Require authorization.
- Audit changes.
- Fail safely when privileges are insufficient.

---

# 16. Phase SYSTEM-12 — Capability and Security Policy

Separate technical ability from authorization.

Example capabilities:

```text
system.command.execute
system.shell.execute

system.process.start
system.process.terminate

system.cron.read
system.cron.manage

system.service.read
system.service.start
system.service.stop
system.service.restart

system.filesystem.read
system.filesystem.write
system.filesystem.delete

system.permission.chmod
system.permission.chown

system.info.read
```

Policies may restrict both capability and target.

Example:

```text
system.service.restart: nginx
```

does not imply:

```text
system.service.stop: ssh
```

---

# 17. Phase SYSTEM-13 — Command Allowlisting

Support optional executable allowlists.

Example:

```php
'system' => [
    'commands' => [
        'allowed' => [
            '/usr/bin/rsync',
            '/usr/bin/tar',
            '/usr/bin/systemctl',
        ],
    ],
],
```

Executable allowlisting alone is not sufficient. Policies may also restrict:

- arguments
- working directory
- environment
- user
- operation

Avoid insecure prefix matching.

---

# 18. Phase SYSTEM-14 — Audit Logging

Privileged/mutating operations should produce audit records when enabled.

Record:

```text
timestamp
request_id
principal
module
capability
operation
target
arguments metadata
result
exit_code
duration
success/failure
```

Never log:

- passwords
- tokens
- private keys
- secret environment variables

Possible events:

```text
system.command.started
system.command.completed
system.command.failed
system.command.timeout

system.service.changed

system.cron.created
system.cron.updated
system.cron.deleted

system.filesystem.changed
system.permission.changed
```

Integrate with the framework Logging subsystem.

---

# 19. Phase SYSTEM-15 — Configuration

Use `config/system.php`, which returns the contents of the `'system'` key below.

Example:

```php
'system' => [
    'enabled' => true,

    'execution' => [
        'default_timeout' => 30,
        'max_output' => 1024 * 1024,
    ],

    'shell' => [
        'enabled' => false,
        'binary' => '/bin/bash',
    ],

    'security' => [
        'require_authorization' => true,
    ],

    'audit' => [
        'enabled' => true,
    ],

    'cron' => [
        'enabled' => true,
    ],
],
```

Treat this as an initial schema, not an immutable API.

Configuration must have safe defaults and validation.

---

# 20. Phase SYSTEM-16 — Resource Limits

Protect against runaway execution.

Support where practical:

- timeout
- maximum output
- concurrent execution limits
- process count limits
- working directory restrictions
- environment restrictions

Future possibilities:

- CPU limits
- memory limits
- cgroups
- nice/priority
- user switching

Do not implement advanced isolation until basic execution is stable.

---

# 21. Phase SYSTEM-17 — Module Integration

Modules own business intent.

Example:

```text
modules/Plugins/Backup/
├── Services/
│   └── BackupService.php
├── Commands/
│   └── BackupCreate.php
├── MCP/
│   └── Tools/
│       └── BackupCreateTool.php
└── ...
```

Flow:

```text
Backup Module
    ↓
BackupService
    ↓
System Command Executor
    ↓
tar / rsync / backup binary
```

The module owns WHAT.

System owns HOW.

---

# 22. Phase SYSTEM-18 — CLI Integration

Provide explicit administrative commands:

```text
system:info
system:service:status
system:service:restart
system:cron:list
system:cron:install
system:cron:remove
```

Avoid making this the primary production interface:

```text
system:exec <anything>
```

If a diagnostic execution command exists, it should be explicitly restricted and intended for trusted administration/development.

---

# 23. Phase SYSTEM-19 — MCP Integration

MCP exposes selected capabilities, not System internals.

Good examples:

```text
server.info
server.service.status
server.service.restart
server.cron.list
server.cron.install
```

Avoid unrestricted tools such as:

```text
server.execute
server.shell
server.filesystem.write_anywhere
```

Every MCP operation must:

1. Authenticate.
2. Authorize.
3. Validate input.
4. Apply System policy.
5. Execute through System APIs.
6. Audit the operation.
7. Return normalized results.

Flow:

```text
MCP Tool
    ↓
Authentication
    ↓
Authorization
    ↓
Application Service
    ↓
System Capability
    ↓
Policy
    ↓
Executor
    ↓
OS
```

---

# 24. Phase SYSTEM-20 — Hooks and Filters

Integrate with the existing Hook/Filter system.

Possible hooks:

```text
system.command.before
system.command.after
system.command.failed
system.command.timeout

system.process.started
system.process.finished

system.cron.created
system.cron.updated
system.cron.deleted

system.service.before
system.service.after
system.service.failed

system.filesystem.before
system.filesystem.after
system.filesystem.failed
```

Possible filters:

```text
system.command.arguments
system.command.environment
system.command.timeout
system.command.result

system.cron.job
system.service.action
system.filesystem.path
```

Hooks/filters must never provide an authorization bypass.

---

# 25. Phase SYSTEM-21 — OS Abstraction

Start Linux-first.

Avoid scattering Linux-specific shell syntax throughout business code.

Possible abstractions:

```text
SystemdServiceManager
LinuxCronManager
LinuxSystemInfoProvider
```

Do not build unused platform abstractions merely for theoretical portability.

---

# 26. Phase SYSTEM-22 — Testing Hardening

Testing layers:

### Unit

Test:

- commands
- arguments
- policies
- capability checks
- cron schedules
- results
- path validation
- service validation

### Integration

Test:

- real process execution
- temporary filesystem
- shell execution
- timeout
- process lifecycle

### Linux integration

Where CI permits:

- system information
- cron manager
- systemd adapter

### Security

Explicitly test:

- command injection
- shell injection
- path traversal
- symlink escape
- unauthorized command
- unauthorized service
- unauthorized filesystem path
- unauthorized permission changes
- excessive output
- timeout abuse
- environment leakage

---

# 27. Phase SYSTEM-23 — Performance

Rules:

- Do not spawn processes for information available through APIs.
- Avoid repeated shell calls.
- Cache read-only system information where appropriate.
- Avoid filesystem scans where possible.
- Do not block HTTP requests with long operations.
- Use Queue/Worker for long tasks.

Preferred:

```text
HTTP/MCP
   ↓
Application Service
   ↓
Queue
   ↓
Worker
   ↓
System Operation
```

Not:

```text
HTTP
 ↓
10-minute shell command
 ↓
HTTP response waits
```

---

# 28. Phase SYSTEM-24 — Long-Running Operations

For:

- backups
- migrations
- package operations
- large synchronization
- deployment

prefer:

```text
Request
   ↓
Create Job
   ↓
Queue
   ↓
Worker
   ↓
System Operation
   ↓
Audit/Result
```

System provides the execution primitive.

Queue/Worker owns asynchronous execution.

---

# 29. Phase SYSTEM-25 — Error Model

Define predictable exceptions:

```text
CommandException
CommandNotFoundException
CommandTimeoutException
CommandPolicyException

ProcessException
ProcessTimeoutException

CronException
CronValidationException

ServiceException
ServiceNotFoundException

FilesystemException
FilesystemPolicyException
PathTraversalException

PermissionException
SystemAuthorizationException
```

Errors must not leak secrets.

---

# 30. Phase SYSTEM-26 — Observability

Integrate with Logging and Observability.

Possible metrics:

```text
system_commands_total
system_commands_failed
system_commands_timeout
system_command_duration
system_processes_started
system_processes_failed
system_service_operations
system_cron_operations
```

Use request IDs/correlation IDs when available.

---

# 31. Phase SYSTEM-27 — Demo Server Module

Create a small demonstration module after the core subsystem is stable.

Example:

```text
modules/Plugins/Server/
├── module.php
├── Services/
│   └── ServerService.php
├── Commands/
│   ├── ServerInfo.php
│   └── ServiceRestart.php
├── MCP/
│   └── Tools/
│       ├── ServerInfoTool.php
│       └── ServiceRestartTool.php
└── ...
```

Demonstrate:

```text
server.info
server.service.status
server.service.restart
server.cron.list
```

Do not include arbitrary shell execution in the demo.

---

# 32. Phase SYSTEM-28 — Documentation and Release

Document:

- command execution
- process management
- cron
- services
- system information
- filesystem operations
- permissions
- capability policies
- audit logging
- CLI integration
- MCP integration

Security documentation must explain:

- trusted vs untrusted input
- shell risks
- command allowlisting
- filesystem restrictions
- service restrictions
- privilege requirements
- audit requirements
- production recommendations

Explain clearly:

```text
System
≠ CLI
≠ MCP
≠ Module
≠ Scheduler
≠ Queue
```

---

# 33. Definition of Done

A phase is complete only when:

- Implementation exists.
- Public API is documented.
- Unit tests exist.
- Integration tests exist where applicable.
- Security tests exist for relevant behavior.
- Static analysis passes.
- Coding standards pass.
- Existing framework tests pass.
- Error handling exists.
- Logging/audit behavior is defined.
- Performance implications are reviewed.
- Public API is reviewed for unnecessary magic.
- Architecture remains module-first.
- No Laravel-style clone has been introduced.
- Documentation is updated.
- `docs/plans/system.md` is updated.

---

# 34. Claude Code Execution Protocol

Claude Code must follow this process for every phase.

## Step 1 — Read plans

Read:

```text
C:\Users\nuren\Downloads\new-framework.md   (the specification, outside the repository)
docs/plans/system.md
docs/plans/mcp.md
```

If a document does not exist, inspect the repository documentation and do not invent its contents.

## Step 2 — Inspect repository

Before changing code, inspect:

- engine structure
- namespaces
- composer.json
- tests
- coding standards
- bootstrap
- configuration
- logging
- hooks/filters
- CLI
- MCP if present

Do not assume the repository exactly matches this plan.

## Step 3 — Identify current phase

Find the first incomplete `SYSTEM-*` phase.

Do not skip phases unless explicitly instructed.

## Step 4 — Check dependencies

Identify existing dependencies.

Examples:

```text
System Command
    → Container
    → Error
    → Logging

System Cron
    → Command
    → Configuration
    → Logging

System Authorization
    → Security
    → Configuration
    → Logging
```

If a dependency is missing, implement only the minimum necessary contract or report the dependency.

Do not silently implement unrelated framework subsystems.

## Step 5 — Implement only current phase

Keep changes focused.

Do not implement future features merely because they appear later in this document.

## Step 6 — Write tests

For every behavior:

1. Unit test.
2. Integration test when required.
3. Security test for privileged behavior.

## Step 7 — Validate

Run the project's actual configured tools:

```text
PHPUnit
PHPStan/Psalm if configured
PHP_CodeSniffer
Composer validation
```

Do not disable tests or weaken analysis rules just to pass.

## Step 8 — Security review

Ask:

```text
Can untrusted input reach this operation?
Can arguments be injected?
Can paths escape policy boundaries?
Can authorization be bypassed?
Can secrets leak?
Can a process run indefinitely?
Can this unexpectedly modify the host?
```

## Step 9 — API review

Ask:

- Is the API explicit?
- Is it testable?
- Is it understandable?
- Is it too magical?
- Does it encourage unsafe behavior?
- Does it belong in System or a module?
- Does it duplicate another subsystem?

## Step 10 — Performance review

Ask:

- Does this spawn unnecessary processes?
- Does it scan the filesystem unnecessarily?
- Does it block HTTP?
- Can read-only data be cached?
- Should long work use Queue/Worker?

## Step 11 — Documentation

Update relevant documentation and examples.

## Step 12 — Update plan

Mark the phase complete and record only decisions actually implemented.

## Step 13 — Propose a commit

The project owner commits. Propose one focused commit message, for example:

```text
feat(system): add structured command execution
```

Do not mix unrelated changes.

---

# 35. Architectural Invariants

Claude Code must not violate these without explicit approval.

### Invariant 1

```text
engine/System = infrastructure
```

### Invariant 2

```text
Module = business intent/capability
```

### Invariant 3

```text
MCP = interface to approved capabilities
```

### Invariant 4

```text
CLI = administrative interface
```

### Invariant 5

```text
System must not become an unrestricted remote shell
```

### Invariant 6

```text
Do not automatically execute everything through sh -c
```

### Invariant 7

```text
Do not expose arbitrary command execution through MCP
```

### Invariant 8

```text
Do not allow filesystem traversal outside policy boundaries
```

### Invariant 9

```text
Do not modify unrelated cron entries
```

### Invariant 10

```text
Do not put business logic into engine/System
```

### Invariant 11

```text
Do not duplicate Scheduler or Queue functionality inside System
```

### Invariant 12

```text
Do not add abstractions solely to imitate Laravel/Symfony
```

### Invariant 13

```text
Prefer explicit capability APIs over magic
```

### Invariant 14

```text
Privileged operations must be auditable
```

---

# 36. Final Target Architecture

```text
                         +----------------+
                         |      HTTP      |
                         +-------+--------+
                                 |
                         +-------v--------+
                         |     Kernel     |
                         +-------+--------+
                                 |
              +------------------+------------------+
              |                  |                  |
             CLI                MCP               HTTP
              |                  |                  |
              +------------------+------------------+
                                 |
                      Application Capability
                                 |
                         +-------v--------+
                         |     Module     |
                         |    Service     |
                         +-------+--------+
                                 |
                         +-------v--------+
                         |  engine/System |
                         +-------+--------+
                                 |
          +----------+-----------+-----------+----------+
          |          |           |           |          |
       Command    Process      Cron       Service   Filesystem
          |          |           |           |          |
          +----------+-----------+-----------+----------+
                                 |
                              Linux OS
```

Long-running work:

```text
HTTP / CLI / MCP
       ↓
Application Service
       ↓
Queue
       ↓
Worker
       ↓
System Operation
       ↓
OS
```

Scheduled work:

```text
Linux Cron
    ↓
bin/console schedule:run
    ↓
Framework Scheduler
    ↓
Application Task
    ↓
System Operation
```

AI-controlled server operations:

```text
MCP Client
    ↓
MCP Tool
    ↓
Authentication
    ↓
Authorization
    ↓
Application Service
    ↓
System Capability
    ↓
Policy
    ↓
System Executor
    ↓
Linux OS
    ↓
Audit Log
```

---

# 37. Implementation Priority

Implement in this order:

```text
SYSTEM-01  Architecture / Contracts
SYSTEM-02  Command Model
SYSTEM-03  Command Executor
SYSTEM-04  Explicit Shell Execution
SYSTEM-05  Process Management
SYSTEM-06  Cron
SYSTEM-07  Scheduler Integration
SYSTEM-08  Service Management
SYSTEM-09  System Information
SYSTEM-10  System Filesystem
SYSTEM-11  Permissions
SYSTEM-12  Capability / Security Policy
SYSTEM-13  Command Allowlisting
SYSTEM-14  Audit Logging
SYSTEM-15  Configuration
SYSTEM-16  Resource Limits
SYSTEM-17  Module Integration
SYSTEM-18  CLI Integration
SYSTEM-19  MCP Integration
SYSTEM-20  Hooks / Filters
SYSTEM-21  OS Abstraction
SYSTEM-22  Testing Hardening
SYSTEM-23  Performance
SYSTEM-24  Long-Running Operations
SYSTEM-25  Error Model
SYSTEM-26  Observability
SYSTEM-27  Demo Server Module
SYSTEM-28  Documentation / Release
```

The order may be adjusted only when an actual dependency requires it.

# End
