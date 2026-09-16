# Pages and forms

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

How to put HTML in front of people: pages rendered from a module, layouts and
partials, styles and scripts, forms that survive CSRF checks and bad input,
uploads, and changing the look of pages you did not write.

Assumes you have done [Getting started](../getting-started.md). The full rules
are in [Templates](../reference/templates.md) and [Assets](../reference/assets.md).

## Render a page

Three pieces, all inside your module:

| | |
|---|---|
| `Templates/profile.twig` | the markup; its name is `@plugin.<Name>/profile` |
| a handler | renders it and wraps the string in a `Response` |
| a route | in `module.php` |

```php
public function __invoke(Request $request): Response
{
    $html = $this->templates->render('@plugin.Desk/profile', [
        'user' => $user,
        'home' => $request->basePath() . '/',
    ]);

    return (new Response($html))->withContentType('text/html');
}
```

`TemplateManager` is injected. Never write the extension: `render('x')` finds
`x.twig` or `x.php`, so a template can change engine without its callers
changing. Pass `home` if the page uses the default layout, so its home link
works when the application lives in a subdirectory.

In `module.php` and in templates there is no constructor, so the global
`template()` helper returns the same manager: `template()->render(...)`.

## Use the layout

The default template's `layout.twig` has three blocks: `title`, `content` and
`footer`. A Twig page extends it:

```twig
{% extends "layout.twig" %}

{% block title %}Contact us{% endblock %}

{% block content %}
<h1>Contact us</h1>
{% endblock %}
```

Twig escapes every value it prints. To print markup you already trust, write
`{{ value|raw }}` — and let that word stand out in review.

A **PHP** template cannot extend anything. Render the page to a string, then hand
it to the layout as `content`:

```php
$content = $this->templates->render('@plugin.Desk/legacy', ['rows' => $rows]);
$html = $this->templates->render('layout', ['title' => 'Legacy', 'content' => $content]);
```

## Partials

```twig
{% include "@plugin.Desk/partials/message.twig" with {message: message} only %}
```

In a PHP template:

```php
<?= $view->render('@plugin.Desk/partials/message', ['message' => $message]) ?>
```

A PHP partial sees only what it is passed, never its parent's variables.

## Escaping in PHP templates

PHP templates escape nothing on their own. Every template gets `$e`, and the
escape depends on where the value goes:

```php
<p><?= $e($message->body()) ?></p>
<a title="<?= $e->attr($tip) ?>" href="?q=<?= $e->url($term) ?>">
<script>const id = <?= $e->js($id) ?>;</script>
<?= $e->raw($trustedHtml) ?>
```

Prefer Twig for anything new; this is the reason. See
[What a PHP template gets](../reference/templates.md#what-a-php-template-gets).

## Stylesheets, scripts and images

Put them in your module's `assets/` directory. It is published because it
exists — nothing to declare:

```
modules/Plugins/Desk/assets/css/desk.css   →   /assets/plugin/Desk/css/desk.css?v=…
```

In Twig:

```twig
{% block content %}
<link rel="stylesheet" href="{{ view.asset().plugin('Desk', 'css/desk.css') }}">
{% endblock %}
```

In PHP, `$view->asset()->plugin('Desk', 'css/desk.css')`; in `module.php`,
`asset()->plugin(...)`. The `?v=` is a hash of the file's contents, so browsers
may cache it for a year and still see every change. Files for the whole
application go in the top-level `assets/` and are addressed with
`asset()->core('css/app.css')`.

`modules/` is refused by the web server; the URL works because the framework
serves those files itself, after checking the path. See
[Why PHP serves them at all](../reference/assets.md#why-php-serves-them-at-all).

## A form

A form needs four things the framework is strict about: the CSRF token, a schema
for the input, a way to show errors, and a redirect when it worked. This one is
complete.

The schema — `Schema/MessageSchema.php`:

```php
final class MessageSchema
{
    public static function input(): Schema
    {
        return Schema::of(
            'message.input',
            Field::string('email')->check(
                'an email address',
                static fn(mixed $value): bool => \is_string($value) && \filter_var($value, \FILTER_VALIDATE_EMAIL) !== false,
            ),
            Field::string('body')->length(1, 2000),
        );
    }
}
```

The handler — `Http/ContactForm.php`:

```php
final class ContactForm
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Csrf $csrf,
        private readonly Router $router,
        private readonly MessageRepository $messages,
        private readonly Queue $queue,
    ) {}

    public function show(Request $request, Session $session): Response
    {
        return $this->page($request, [
            'status' => $session->get('status'),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function send(Request $request, Session $session): Response
    {
        $input = ['email' => $request->input('email'), 'body' => $request->input('body')];
        $result = MessageSchema::input()->validate($input);

        if (!$result->isValid()) {
            return $this->page($request, [
                'status' => null,
                'errors' => $result->messages(),
                'old' => $input,
            ])->withStatus(422);
        }

        /** @var array{email: string, body: string} $attributes */
        $attributes = MessageSchema::input()->deserialize($input);
        $message = $this->messages->receive($attributes);

        $this->queue->push(new AcknowledgeMessage($message->identity() ?? 0));
        $session->flash('status', 'Thank you. We will reply soon.');

        return new RedirectResponse($this->router->url('contact.show'), 303);
    }

    /** @param array<string, mixed> $data */
    private function page(Request $request, array $data): Response
    {
        $html = $this->templates->render('@plugin.Desk/contact', $data + [
            'action' => $this->router->url('contact.send'),
            'token' => $this->csrf->token($request),
            'home' => $request->basePath() . '/',
        ]);

        return (new Response($html))->withContentType('text/html');
    }
}
```

The template — `Templates/contact.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}Contact us{% endblock %}

{% block content %}
<h1>Contact us</h1>

{% if status %}<p class="status">{{ status }}</p>{% endif %}

<form method="post" action="{{ action }}">
    <input type="hidden" name="_token" value="{{ token }}">

    <label>Email <input name="email" value="{{ old.email|default('') }}"></label>
    {% for message in errors.email|default([]) %}<p class="error">Email {{ message }}</p>{% endfor %}

    <label>Message <textarea name="body">{{ old.body|default('') }}</textarea></label>
    {% for message in errors.body|default([]) %}<p class="error">Message {{ message }}</p>{% endfor %}

    <button type="submit">Send</button>
</form>
{% endblock %}
```

The routes:

```php
$routes->get('/contact', [ContactForm::class, 'show'])->name('contact.show');
$routes->post('/contact', [ContactForm::class, 'send'])
    ->name('contact.send')
    ->meta(['rate_limit' => '5/1m']);
```

What each part is doing:

- **CSRF is already on.** Every `POST`, `PUT`, `PATCH` and `DELETE` is refused
  with 403 unless it echoes the token from the `XSRF-TOKEN` cookie as `_token`
  (or an `X-CSRF-TOKEN` header). `Csrf::token()` is the value to print.
- **`validate()` collects every problem**, keyed by field, so the form shows all
  of them at once. 422 tells the browser the page is a refusal, not a success.
- **`deserialize()` drops keys the schema does not declare**, so a visitor cannot
  set a field you did not put on the form.
- **Redirect after a successful post**, with 303, so reloading the next page does
  not submit again. The flash message lives for exactly one more request.
- **`Session` is a handler parameter**, not a constructor argument: it is this
  request's session. Never inject it into a singleton.

> **Known issue in 0.1.0:** on a browser's *first* visit — before it holds an
> `XSRF-TOKEN` cookie — the token `Csrf::token()` returns and the cookie the
> framework sets are generated separately and do not match, so that first submit
> is refused with 403. From the second page view on they agree. A fix belongs in
> the framework, not in your form.

See [CSRF is opt-out](../reference/security.md#csrf-is-opt-out) and
[Flash data is ordinary data](../reference/sessions.md#flash-data-is-ordinary-data).

## Uploads

```php
$policy = UploadPolicy::images();
$problems = $policy->check($request->file('avatar'));

if ($problems === []) {
    $path = $policy->store($request->file('avatar'), $directory);
}
```

Nothing is checked automatically, because only the endpoint knows what it
accepts. `check()` returns every problem; `store()` generates the stored name.
It needs `ext-fileinfo`. Store uploads outside the web root, or under
`system/`. See [Uploads](../reference/security.md#uploads).

## Change a page you did not write

**Another module's template.** Copy it into the active template under the
module's namespace, and edit the copy:

```
modules/Plugins/Billing/Templates/invoice.twig          the module's own
templates/default/views/plugin.Billing/invoice.twig     yours, which wins
```

The module is never edited. `php bin/console template:list` prints the search
order. See [Resolution, and how overriding works](../reference/templates.md#resolution-and-how-overriding-works).

**The front page.** Declare `/` in your module under a route name other than
`home`. Every plugin registers after `shared`, so your route answers.

**Error pages.** `templates/default/views/errors/404.twig` is shown for pages
that do not exist and `errors/error.twig` for everything else. A file named for
a status, such as `errors/503.twig`, wins for that status. Each gets `error`
(with `status`, `title`, `message`) and `home`. Error pages are never used in
debug mode — turn `APP_DEBUG` off to see yours. See
[The application's own error page](../reference/errors.md#the-applications-own-error-page).

**The whole look.** Copy `templates/default/` to `templates/<name>/` and set
`APP_TEMPLATE=<name>`. A template has `views/` and `assets/`; anything it does
not have is not found — there is no fallback to `default`.

<!-- {% endraw %} -->
