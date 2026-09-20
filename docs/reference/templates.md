# Templates

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

A *template* is a file that produces HTML. The site's templates live in
`templates/`; a module can ship its own.

## Render one

```php
template()->render('profile', $data);                              // templates/profile.twig
template()->render('customer/profile', ['customer' => $customer]); // templates/customer/profile.twig
template()->render('@plugin.Example/invoice', $data);              // the Example plugin's invoice
```

A name is a path under `templates/`, without the extension. Folders are just
part of the name: `admin/panel/user` is `templates/admin/panel/user.twig`.

**No extension is written at the call site.** That is not a convenience: it is
what lets a template move from PHP to Twig, or a Twig one be replaced by a PHP
one, without a single caller changing. The manager knows every extension any
registered engine claims, and tries them all.

**Rendering produces a string, never a response.** Your handler wraps it:

```php
return (new Response($this->templates->render('layout', $data)))->withContentType('text/html');
```

which is what lets the same template be rendered into an email, a PDF pipeline
or a test assertion. An architecture test keeps this layer from seeing `Request`
or `Response` at all.

## Layouts

A Twig page uses Twig's own inheritance, as the shipped pages do:

```twig
{% extends "layout.twig" %}
{% block title %}Welcome{% endblock %}
{% block content %}<h1>Your application is running</h1>{% endblock %}
```

The PHP engine has no `@extends`, `@section` or `@yield`, and will not grow
them — that is the line between a template engine and a reimplementation of
Blade. A PHP page renders to a string and hands it to the layout:

```php
$content = $this->templates->render('@plugin.Example/customers', ['customers' => $rows]);
$html    = $this->templates->render('layout', ['title' => 'Customers', 'content' => $content]);
```

The shipped `layout.twig` prints a `content` value when no block replaces it, so
it works for PHP pages too. `content` is printed unescaped, because it is markup
another template already escaped; every other value in the layout is escaped by
Twig.

## Resolution, and how overriding works

First hit wins, in this order:

| | Folder |
|---|---|
| 1. the application | `templates/` |
| 2. the override of a namespace | `templates/<namespace>/` |
| 3. the module itself | `modules/Plugins/Example/Templates/` |

So a site replaces a plugin's invoice by creating

```
templates/plugin.Example/invoice.php
```

and the plugin is never edited, asked or told. Its own copy stays as the
fallback, which is what makes it safe for the plugin to keep shipping one.

Only `templates/` takes part in rule 2. A module must not be able to override
another module by guessing a folder name, or which template wins would come down
to discovery order.

The search is **folder-major, not extension-major**: every extension is tried in
the highest-precedence folder before dropping to the next. So the site's `.php`
beats a module's `.twig`, and the other loop order would let the module win by
virtue of its file extension.

`php laika template:list` prints the whole search path in order, which is most
of the answer to "which file is actually being rendered".

### Module namespaces

A module with a `Templates/` folder gets a namespace automatically. There is
nothing about templates in any `module.php` — the same bargain as assets.

| Module | Namespace |
|---|---|
| `modules/Shared/Templates/` | `@shared/…` |
| `modules/Plugins/Example/Templates/` | `@plugin.Example/…` |
| `modules/Gateways/Stripe/Templates/` | `@gateway.Stripe/…` |

The dot is not decoration: a Twig namespace cannot contain a slash, and the two
engines have to agree on how a template is named.

The shared module *does* get a namespace here, unlike in the asset layer — a
shared partial is an ordinary thing to want, and unlike a URL, there is a name
for it.

## Twig by default, PHP second

`twig/twig` is a runtime dependency and the default engine. Bootstrap registers
the Twig engine first and the PHP engine second, and **the order is the
precedence**: where one folder holds both `home.twig` and `home.php`, the Twig
file renders.

Folder precedence still comes first — the site's `.php` beats a module's
`.twig` — so the engine order only decides ties inside one folder. An
architecture test pins both the dependency and the order.

Twig is the default because it escapes everything it prints unless told not to,
which turns a forgotten escape from a hole into a visible `&lt;`. PHP templates
stay because a module that already ships `.php` views should keep working, and
because they need nothing compiled or cached before they run.

Nothing outside `TwigTemplateEngine.php` mentions Twig, and an architecture test
says so. The manager talks to engines through `TemplateEngine`, so replacing
Twig later is one class and one line in Bootstrap.

Twig's loader mirrors the registry — the same folders, the same order, module
namespaces as Twig namespaces — so `{% extends "layout.twig" %}` and
`{% include "@plugin.Example/row.twig" %}` resolve exactly where the manager
would have resolved them, override rule included. If the two disagreed, a
template found by one would be missing to the other.

## What a PHP template gets

Every key of the data as a local variable, plus `$view` and `$e`. Nothing else:
the file is included from a static closure, so `$this` does not exist inside a
template and the engine's internals cannot be reached from one.

```php
<h1><?= $e($title) ?></h1>
<?php foreach ($customers as $customer): ?>
    <li><?= $e($customer->name()) ?></li>
<?php endforeach ?>
<?= $view->render('partials/pager', ['page' => $page]) ?>
```

**`$e` is short on purpose.** PHP templates do not escape anything on their own,
and that is the single largest hazard in using them. The framework does not fix
it by inventing a syntax — that road ends at a compiler nobody asked for — it
fixes it by making the correct call short enough that there is no excuse.

Escaping is by context, because it is not one operation:

```php
<?= $e($text) ?>                          <!-- between tags -->
<a title="<?= $e->attr($tip) ?>">         <!-- an attribute -->
<script>const id = <?= $e->js($id) ?>;</script>
<a href="?q=<?= $e->url($term) ?>">
<?= $e->raw($trustedHtml) ?>              <!-- named so it shows in a diff -->
```

Data can never overwrite `$e` or `$view`. A data key called `e` would otherwise
turn every escape call in the application into a call to whatever the handler
passed, which is a security bug with a very long fuse. The shadowed value is
still readable as `$view->get('e')`.

`$view` is deliberately tiny: `render()`, `exists()`, `asset()`, `escaper()`,
`has()`, `get()`, `data()`. It is **not** a handle on the container. A template
that can resolve arbitrary services is a template that can run a query, and then
"what does this page do" stops having an answer you can read in the handler. If
a template needs something, the handler passes it in.

A partial gets exactly the data it is given and never sees its parent's
variables, because a partial that could is a partial whose contract is
"whatever happened to be in scope".

## The default pages

```
templates/layout.twig        shared by every page below
templates/home.twig          GET /, declared by modules/Shared
templates/errors/404.twig    any path nothing answers
templates/errors/error.twig  every other error status
templates/assets/css/theme.css   their stylesheet, /assets/template/css/theme.css
```

The front page is an ordinary route in the shared module — there is no global
routes file for it to live in — rendering `home` with the request's `home` URL.
It says nothing about the machine or the version, because a default page is
public by definition.

It is meant to be replaced, and replacing it needs no edit to the framework:

- **Declare `/` in your own module.** Every other module registers after shared,
  and the router keeps the last route declared for a method and path, so yours
  answers. Give it a name other than `home`: route names are unique, and that
  one is taken.
- **Or edit `templates/home.twig`.**

The 404 page is what any unmatched path gets from a browser. A client that asks
for JSON gets the error document instead, and debug mode shows the built-in
diagnostic page, so a trace is never hidden behind a pretty one.

## Templates are not web-readable

`templates/` is outside the document root, alongside `engine/` and `modules/`,
for exactly the same reason: a PHP view the web server can reach is a PHP file
it will execute, and a Twig view it can reach is source it will hand out.

`templates/assets/` stays reachable as `/assets/template/…` through the asset
manager, which is the only way in — and it is never a view. `render('assets/…')`
is refused, so a `.php` file among the static files cannot run as a template.

A request for `/templates`, or anything under it, reaches the application like
any other path, so a module may declare a route there. A path with no route is
the application's 404, whether or not a file of that name exists.

## Settings

| Key | Default | What it does |
|---|---|---|
| `templates.cache` | `false` | Twig's compilation cache, under `system/Cache/templates`. PHP templates never need it — opcache already has them. |

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| The wrong template renders | Another folder earlier in the search path has that name | `php laika template:list` prints the order |
| "is not a plain name", for `assets/…` | `templates/assets/` holds static files, never views | Move the template elsewhere under `templates/` |
| A template is not found | The name is a path under `templates/`, without the extension | Check the path, not the extension |
| A module's override is ignored | Only `templates/<namespace>/` overrides a module | Put the file there, not in another module |
| `<b>` shows as text in a PHP template | Twig escapes; PHP does not, and `$e()` escaped it | Use `$e->raw()` for markup you trust |
| A partial cannot see a variable | Partials get only what they are passed | Pass it explicitly |
| An edited template does not change | Twig's compilation cache is on | `php laika cache:clear` |

<!-- {% endraw %} -->
