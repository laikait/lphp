# Getting started

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

This tutorial builds one small module from nothing: **Notes**, which keeps short
notes in a database, shows them on a page, lists them as JSON, and adds them
from the command line. On the way it touches the pieces every module is made of —
`module.php`, a model, a repository, a route, a template, a command, a filter, a
hook, configuration and a test.

It assumes you know PHP and Composer, and nothing about this framework. Every
file below is complete, and the whole module has been run exactly as written.

> **Before you start:** PHP 8.2 or newer with `pdo_sqlite`, and Composer 2.
> `php -m | grep -i sqlite` should print `pdo_sqlite`.

## 1. Install and run

```bash
composer install
composer serve
```

Open `http://127.0.0.1:8080/`. You should see **Your application is running**.
Under XAMPP the same application also answers at `http://localhost/framework/`
with no configuration.

A fresh install has one module, `shared`, and nothing else:

```bash
php laika module:list
php laika route:list
```

Leave `composer serve` running in its own terminal and use a second one for the
commands below.

## 2. Create the module

A module is a directory with a `module.php` in it. Create
`modules/Plugins/Notes/module.php`:

```php
<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

return static function (ModuleContext $module): void {
    $module
        ->name('Notes')
        ->version('0.1.0')
        ->description('Short notes, on a page and on the command line.');

    // The DataSource every repository writes through is bound by shared.
    $module->requires('shared', '^0.1');
};
```

```bash
php laika module:list
```

```
  ID             KIND     NAME    VERSION  ROUTES  COMMANDS  HOOKS  FILTERS  REQUIRES
  shared         shared   Shared  0.1.0    5       0         0      3        -
  plugins/Notes  plugins  Notes   0.1.0    0       0         0      0        shared ^0.1
```

Nothing was registered anywhere. The framework found the directory, and the
module's id, `plugins/Notes`, comes from where it lives.

Three things worth knowing now:

- **The closure only records.** When it returns, nothing has been bound, routed
  or hooked. The framework replays every module's declarations afterwards, in a
  fixed order, which is why the order modules run in never depends on the
  filesystem.
- **Classes autoload from the directory name.** `modules/Plugins/Notes/Model/Note.php`
  is `App\Modules\Plugins\Notes\Model\Note`, with no `composer.json` change. The
  case must match exactly — Windows will not tell you when it does not, Linux
  will.
- **There is nothing to extend.** `ModuleContext` is the whole API. See
  [Modules](reference/modules.md) for everything it can declare.

## 3. A model and a repository

A **model** is domain state with the rules that protect it. It does not save
itself. Create `modules/Plugins/Notes/Model/Note.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Plugins\Notes\Model;

use App\Engine\Model\Model;

final class Note extends Model
{
    public function __construct(
        private ?int $id,
        private string $body,
    ) {
        if (\trim($body) === '') {
            throw new \InvalidArgumentException('A note needs something written in it.');
        }
    }

    public function identity(): ?int
    {
        return $this->id;
    }

    public function body(): string
    {
        return $this->body;
    }
}
```

Rows become models by matching columns to the **constructor's parameter names**,
so the table below has the columns `id` and `body`.

A **repository** is where storage is reached. It inherits no `find()` or `save()`:
every public method is one you name after something the application does.
Create `modules/Plugins/Notes/Data/NoteRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Plugins\Notes\Data;

use App\Engine\Data\Repository;
use App\Modules\Plugins\Notes\Model\Note;

final class NoteRepository extends Repository
{
    protected function model(): string
    {
        return Note::class;
    }

    protected function collection(): string
    {
        return 'notes';
    }

    /** @return list<Note> the newest first */
    public function latest(int $limit): array
    {
        $notes = [];

        foreach ($this->query()->orderByDesc('id')->limit($limit)->get() as $note) {
            if ($note instanceof Note) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    public function write(string $body): Note
    {
        $stored = $this->persist(new Note(null, $body));

        return $stored instanceof Note
            ? $stored
            : throw new \LogicException('The repository stored something other than a Note.');
    }
}
```

`persist()` returns the stored note, because a new note has no id until the
database gives it one. More in [Models](reference/models.md) and
[Repositories and queries](reference/data.md).

## 4. A database, and two commands

Without a database, repositories run against memory that lasts for one request —
useful in tests, useless for notes you want to keep. Create a `.env` file in the
project root with an **absolute** path to a SQLite file:

```bash
# .env  (Windows: DB_DSN=sqlite:C:/xampp/htdocs/framework/system/Runtime/notes.sqlite)
DB_DSN=sqlite:/path/to/framework/system/Runtime/notes.sqlite
```

`.env` is ignored by git, and `system/` is refused by the web server. A real
environment variable always beats the file.

The framework has no migrations yet, so the module creates its own table with a
command. Create `modules/Plugins/Notes/Commands/InstallNotes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Plugins\Notes\Commands;

use App\Engine\Cli\Output;
use App\Engine\Database\ConnectionManager;

final class InstallNotes
{
    public function __construct(private readonly ConnectionManager $connections) {}

    public function __invoke(Output $output): int
    {
        if (!$this->connections->isConfigured()) {
            $output->error('No database is configured. Set DB_DSN first.');

            return 1;
        }

        $this->connections->connection()->execute(
            'CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)',
        );

        $output->success('The notes table is ready.');

        return 0;
    }
}
```

And `modules/Plugins/Notes/Commands/AddNote.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Plugins\Notes\Commands;

use App\Engine\Cli\Output;
use App\Engine\Hook\HookEngine;
use App\Modules\Plugins\Notes\Data\NoteRepository;

final class AddNote
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HookEngine $hooks,
    ) {}

    public function __invoke(Output $output, string $body): int
    {
        if (\trim($body) === '') {
            $output->error('A note needs something written in it.');

            return 2;
        }

        $note = $this->notes->write($body);

        $this->hooks->do('note.added', $note);

        $output->success(\sprintf('Note %d written.', $note->identity() ?? 0));

        return 0;
    }
}
```

A command is an ordinary class. Its collaborators arrive in the constructor; what
the user typed arrives as `__invoke()` parameters, matched **by name** to what the
module declares. The line `$this->hooks->do('note.added', $note)` announces that
a note exists — step 7 uses it.

Now tell the module about all of this. Replace `module.php` with:

```php
<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Modules\Plugins\Notes\Commands\AddNote;
use App\Modules\Plugins\Notes\Commands\InstallNotes;
use App\Modules\Plugins\Notes\Data\NoteRepository;

return static function (ModuleContext $module): void {
    $module
        ->name('Notes')
        ->version('0.1.0')
        ->description('Short notes, on a page and on the command line.');

    // The DataSource every repository writes through is bound by shared.
    $module->requires('shared', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(NoteRepository::class);
        $services->bind(InstallNotes::class);
        $services->bind(AddNote::class);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('notes:install', InstallNotes::class)
            ->describe('Create the notes table in the configured database.');

        $commands->add('notes:add', AddNote::class)
            ->describe('Write a note.')
            ->argument('body', 'What the note says.');
    });
};
```

```bash
php laika notes:install
php laika notes:add "Buy milk"
php laika notes:add "Call Ada"
php laika notes:add --help
php laika notes:add ""; echo $?     # 2: the command line was wrong
```

`singleton()` shares one repository; `bind()` builds a fresh command each time.
The registrar can only **write** to the container, never read from it, so a module
cannot go looking for services while it is still being registered. The help text
is generated from the declaration. Exit codes follow the shell: `0` worked, `1`
failed, `2` was used wrongly, `127` no such command — see
[CLI](reference/console.md).

## 5. A page

A module's `Templates/` directory becomes a template namespace on its own:
`modules/Plugins/Notes/Templates/` is `@plugin.Notes`. Create
`modules/Plugins/Notes/Templates/index.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}{{ title }}{% endblock %}

{% block content %}
<h1>{{ title }}</h1>

{% for note in notes %}
    <p>{{ note.body }}</p>
{% else %}
    <p>No notes yet. Add one with <code>php laika notes:add "Hello"</code>.</p>
{% endfor %}
{% endblock %}
```

`layout.twig` is the default template's layout, the one the home page uses.
Twig escapes everything it prints, so a note containing `<script>` shows up as
text.

A handler turns a request into a response. There is no controller base class.
Create `modules/Plugins/Notes/Http/NotesPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Plugins\Notes\Http;

use App\Engine\Config\Config;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Template\TemplateManager;
use App\Modules\Plugins\Notes\Data\NoteRepository;
use App\Modules\Plugins\Notes\Model\Note;

final class NotesPage
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly TemplateManager $templates,
        private readonly FilterEngine $filters,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $html = $this->templates->render('@plugin.Notes/index', [
            'title' => $this->filters->apply('notes.title', 'Notes'),
            'notes' => $this->notes->latest($this->pageSize()),
            'home' => $request->basePath() . '/',
        ]);

        return (new Response($html))->withContentType('text/html');
    }

    /** @return array{data: list<array{id: int|null, body: string}>} */
    public function json(): array
    {
        return ['data' => \array_map(
            static fn(Note $note): array => ['id' => $note->identity(), 'body' => $note->body()],
            $this->notes->latest($this->pageSize()),
        )];
    }

    private function pageSize(): int
    {
        return $this->config->int('plugins/Notes.page_size', 20) ?? 20;
    }
}
```

Rendering returns a string, never a response, so the same template could go into
an email. `home` is handed to the layout so its home link is right even when the
application lives in a subdirectory.

The handler reads two things this tutorial has not declared yet — the
`notes.title` filter and the `page_size` setting — and works without either:
`apply()` returns `'Notes'` when nobody filters it, and `int()` falls back to 20.

## 6. Routes

Add the handler to `services()` and declare the routes. In `module.php`, add
`use App\Engine\Routing\RouteCollector;` and
`use App\Modules\Plugins\Notes\Http\NotesPage;` to the imports, add
`$services->bind(NotesPage::class);` inside `services()`, and add after it:

```php
    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/notes', NotesPage::class)->name('notes.index');
        $routes->get('/api/notes', [NotesPage::class, 'json'])->name('notes.api');
    });
```

Open `http://127.0.0.1:8080/notes`, then:

```bash
curl http://127.0.0.1:8080/api/notes
# {"data":[{"id":2,"body":"Call Ada"},{"id":1,"body":"Buy milk"}]}
php laika route:list
```

The first route uses the class's `__invoke()`; the second names a method. A
handler that returns an array gets a JSON response; a string, HTML; `null`, a
204. The route belongs to `plugins/Notes` — `route:list` says so — because there
is no global routes file for it to live in. See [Routing](reference/routing.md).

## 7. A filter and a hook

These are how modules change each other without depending on each other.

A **filter** passes a value through every listener and uses what comes back. The
page title went through `notes.title`. Add this at the end of the closure in
`module.php`:

```php
    $module->filter('notes.title', static fn(string $title): string => $title . ' for ' . date('l'));
```

Reload `/notes`: the heading reads *Notes for Tuesday*, or whichever day it is.
Any module could have declared that line — this one, a plugin written by
someone else, or a gateway — and `NotesPage` would not change.

A **hook** announces that something happened and ignores what listeners return.
`AddNote` fires `note.added` with the note. Another module listens by declaring:

```php
$module->hook('note.added', [NoteMailer::class, 'onAdded']);
```

where `NoteMailer::onAdded(Note $note)` is a **static** method. To listen with
an object that needs dependencies, register from `onBoot()` instead, where they
are injected. The next step proves the hook fires. See
[Hooks and filters](reference/hooks-and-filters.md).

## 8. Configuration

`NotesPage` reads `plugins/Notes.page_size`. Give the module a default, next to
its other declarations:

```php
    $module->config(['page_size' => 20]);
```

That is the **module's** default. The **installation's** decision goes in a file
named after the module's id — `config/plugins/Notes.php`:

```php
<?php

return ['page_size' => 1];
```

Reload `/notes` and only the newest note is left. The file wins over the module,
always: whoever runs the application decides, whoever wrote the module suggests.
`php laika config:list` shows what resolved and from where. Delete the file
when you are done. See [Configuration](reference/configuration.md).

## 9. A test

Tests boot the real application. Create `tests/Feature/NotesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Modules\Plugins\Notes\Commands\AddNote;
use App\Modules\Plugins\Notes\Commands\InstallNotes;
use App\Modules\Plugins\Notes\Model\Note;
use App\Tests\Support\TestCase;

final class NotesTest extends TestCase
{
    public function test_a_note_added_on_the_command_line_is_on_the_page(): void
    {
        $app = $this->notes();

        $status = $app->container()->get(AddNote::class)($this->console(), 'Buy milk');
        $page = $app->handle(Request::create('GET', '/notes'));

        self::assertSame(0, $status);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('<p>Buy milk</p>', $page->body());
    }

    public function test_adding_a_note_announces_it(): void
    {
        $app = $this->notes();
        $heard = [];

        add_hook('note.added', static function (Note $note) use (&$heard): void {
            $heard[] = $note->body();
        });

        $app->container()->get(AddNote::class)($this->console(), 'Call Ada');

        self::assertSame(['Call Ada'], $heard);
    }

    public function test_the_api_lists_the_newest_note_first(): void
    {
        $app = $this->notes();
        $add = $app->container()->get(AddNote::class);
        $add($this->console(), 'first');
        $add($this->console(), 'second');

        $response = $app->handle(Request::create('GET', '/api/notes'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(
            ['data' => [['id' => 2, 'body' => 'second'], ['id' => 1, 'body' => 'first']]],
            $response->data(),
        );
    }

    /** The application as it ships, plus this module, on a database that lives for one test. */
    private function notes(): Application
    {
        $app = $this->shippedApplication([
            'app' => ['debug' => false],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();

        $app->container()->get(InstallNotes::class)($this->console());

        return $app;
    }

    /** Somewhere for a command to print that is not the test runner's screen. */
    private function console(): Output
    {
        $stream = \fopen('php://memory', 'w');
        self::assertIsResource($stream);

        return new Output($stream);
    }
}
```

```bash
vendor/bin/phpunit tests/Feature/NotesTest.php
```

`shippedApplication()` boots everything in `modules/`, with the settings you pass
layered on top — here an in-memory database, so every test starts empty and
nothing touches your notes file. No request goes over the network:
`$app->handle()` runs the kernel directly.

> Name your helper something other than `output()`. PHPUnit's `TestCase` already
> has a final method by that name, and PHP refuses to load the test.

The module's own code is checked by the same tools the framework uses:

```bash
vendor/bin/phpstan analyse modules/Plugins/Notes tests/Feature/NotesTest.php
vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override modules/Plugins/Notes
```

`composer check` runs both over `engine/` and `tests/` only, plus the whole test
suite. That suite is the **framework's**, and one of its tests describes the
framework exactly as distributed:
`DefaultPagesSliceTest::test_the_framework_ships_the_shared_module_and_nothing_else`
fails as soon as `modules/` holds anything but `shared` — and the front page
tests beside it fail once a module of yours claims `/`. Neither means your module
is wrong. An application built on the framework adapts or removes the tests that
describe a fresh install.

## What you built

```
modules/Plugins/Notes/
  module.php                 everything the module contributes, in one file
  Model/Note.php             a note, and the rule that it cannot be empty
  Data/NoteRepository.php    latest() and write(), and nothing inherited
  Commands/InstallNotes.php  notes:install
  Commands/AddNote.php       notes:add, which fires note.added
  Http/NotesPage.php         GET /notes and GET /api/notes
  Templates/index.twig       @plugin.Notes/index
tests/Feature/NotesTest.php
```

Deleting `modules/Plugins/Notes/` removes all of it — routes, commands, the
filter, the template namespace. Nothing outside the directory knew it existed.

## Where next

- **Build something real:** the [guides](README.md#guides) — pages and forms, a
  JSON API, storing data, background work, logins and permissions.
- **Look something up:** the [reference](README.md#reference), one page per
  subsystem.
- **See a larger module:** `tests/Fixtures/Showcase/Plugins/Example/` uses every
  subsystem at once. It is a test fixture rather than an application, and reading
  its `module.php` is the fastest tour there is.

<!-- {% endraw %} -->
