# Localization

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

Translations are PHP files. The application has its own in `lang/`, and every
module can ship its own in its `lang/` directory. Templates translate with the
`local` filter, and PHP code uses the `Localization` service.

## Translate in a template

```twig
{{ 'updated'|local }}
{{ 'user_update_success'|local({user: user.name}) }}
{{ 'plugin.Billing.invoice_created'|local }}
```

With `lang/en.php`:

```php
<?php

declare(strict_types=1);

return [
    'updated' => 'Updated',
    'user_update_success' => ':user updated successfully',
];
```

the second line prints `Some User updated successfully`.

The result is text, and Twig escapes it like every other value: a parameter
holding `<script>` prints as `&lt;script&gt;`, and so does HTML written into
the translation itself. There is no way to mark a translation as trusted HTML.

In a PHP template, `$view->local()` does the same and you escape it yourself:

```php
<?= $view->escaper()->html($view->local('user_update_success', ['user' => $name])) ?>
```

## Translate in PHP

Inject `Localization`:

```php
use App\Engine\Localization\Localization;

final class InvoiceMailer
{
    public function __construct(private readonly Localization $localization) {}

    public function subject(string $user): string
    {
        return $this->localization->get('plugin.Billing.invoice_for', ['user' => $user]);
    }
}
```

| Method | Does |
|---|---|
| `get($key, $parameters = [])` | the translation, or `$key` itself when there is none |
| `has($key)` | whether a translation exists (in the active locale or a fallback) |
| `locale()` | the active locale, decided on first use |
| `setLocale('bn')` | use `bn` from now on; throws `LocalizationException` if `lang/bn.php` is missing |
| `available()` | every locale in `lang/` |

`setLocale()` is for a command, a job, an email in the recipient's language or
a test. It does not write a cookie.

## Translation files

```
lang/
  en.php           the application's English, and the fallback for every language
  bn.php
  pt-BR.php
  countries.php    country → language policy (see below)
modules/Plugins/Billing/lang/
  en.php
  bn.php
```

- A file returns a flat array of strings. Anything else stops the request with
  a `LocalizationException` naming the file.
- **The files are the list of languages.** `lang/de.php` existing is what makes
  `de` available; there is no list to keep in step with it.
- A file is named by its canonical tag: `en`, `bn`, `pt-BR`, `es-419`,
  `zh-Hant`. `pt_BR.php` or `EN.php` is not read.
- Only the active language's files are loaded, once per request, command or
  job. Edits show on the next request; there is nothing to clear.

## Module translations

A module that has a `lang/` directory has translations. Nothing in `module.php`
declares them: the directory is the declaration, the same as `Templates/`.

A module's keys are prefixed with the same name its templates have:

| Module | Its key `invoice_created` is |
|---|---|
| `modules/Shared/lang/` | `shared.invoice_created` |
| `modules/Plugins/Billing/lang/` | `plugin.Billing.invoice_created` |
| `modules/Gateways/Stripe/lang/` | `gateway.Stripe.invoice_created` |

Every other key is looked up in the application's `lang/`. A module cannot
replace an application key, and two modules cannot collide, because no two
modules have the same name. Remove or disable a module and its keys stop
resolving; nothing else has to change.

## Which language is used

The first of these that names an available language wins:

1. **The `language` cookie.** The visitor chose.
2. **The visitor's country**, through `lang/countries.php`.
3. **The browser's `Accept-Language`**, in its order of preference.
4. **`en`.** If there is no `lang/en.php`, the first language that exists.

Each value is tried from most to least specific, so a cookie of `bn-BD` is
satisfied by `lang/bn.php`. A value with no file, or one that is not a language
tag at all (`../../config`), is skipped. Only the application's `lang/` decides
what is available.

The order is deliberate: a visitor in Bangladesh whose browser was installed in
English sees Bengali when `countries.php` says `'BD' => 'bn'`, until they choose
otherwise.

A command or a job has no request, so it runs in `en` unless it calls
`setLocale()`. A queue worker starts every job in `en` again.

## Missing translations

A key is looked up in the active language, then each less specific form of it
(`bn-BD`, then `bn`), then `en`. If all of them miss, the key itself is shown,
so a missing translation is visible on the page rather than blank. Modules
follow the same rule.

A placeholder with no parameter stays as it is: `:user updated successfully`.

With `APP_DEBUG=true`, each missing key is logged once per request at `debug`
level, with the key and the locale.

## Letting a visitor choose

Set the `language` cookie from your own route. Check the value first:

```php
use App\Engine\Http\Cookie;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;

$routes->post('/language', static function (Request $request, Localization $localization): RedirectResponse {
    $locale = (string) $request->input('locale');

    if (!\in_array($locale, $localization->available(), true)) {
        $locale = $localization->locale();
    }

    return (new RedirectResponse('/'))->withCookie(new Cookie(
        'language',
        $locale,
        expires: \time() + 31_536_000,
        secure: $request->isSecure(),
    ));
})->name('language.switch');
```

The cookie is `HttpOnly` and `SameSite=Lax` by default. The framework does not
render a language picker; that is your template's job.

## Country detection

Off by default. Behind a CDN or proxy that sends the visitor's country, name the
header and list the proxy as trusted:

```php
// config/localization.php
return ['country_header' => 'CF-IPCountry'];

// config/http.php
return ['trusted_proxies' => ['173.245.48.1']];
```

or `LOCALIZATION_COUNTRY_HEADER=CF-IPCountry` in the environment.

**The header is read only when the request came from a trusted proxy** — the
same `http.trusted_proxies` list that decides whether `X-Forwarded-For` is
believed. From anyone else it is ignored, so a client cannot pick its own
country. `XX` and anything that is not a two-letter code count as unknown.

Then map countries to languages in `lang/countries.php`:

```php
<?php

declare(strict_types=1);

return [
    'BD' => 'bn',
    'US' => 'en',
    'DE' => 'de',
];
```

This is the application's policy for visitors from each country, not a claim
that a country has one language, and a language named here is used only if its
file exists.

### Your own country source

To use a local GeoIP database or another provider, bind `CountryResolver` in a
module:

```php
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Http\Request;
use App\Engine\Localization\CountryResolver;

$module->services(static function (ServiceRegistrar $services): void {
    $services->singleton(CountryResolver::class, MaxMindCountryResolver::class);
});

final class MaxMindCountryResolver implements CountryResolver
{
    public function country(Request $request): ?string
    {
        // Look up $request->ip() in a local database; null when unknown.
    }
}
```

Keep it local: it runs for any request that translates something and has no
language cookie.

## Caches in front of the application

A response that translated something using the request's language carries:

```
Vary: Cookie, Accept-Language
```

plus the country header when one is configured, so a CDN or page cache keeps
one copy per language instead of serving the first visitor's to everyone. A
response that translated nothing, or used `setLocale()`, is not marked.

## If it doesn't work

| Symptom | Cause | Fix |
|---|---|---|
| The key is printed instead of the text | No file in the chain has it, or the prefix is wrong | Check the key in `lang/en.php`; a module key starts with `plugin.<Name>.` |
| `Unknown "local" filter` | A Twig engine built outside `Bootstrap` | Render through the application's `TemplateManager` |
| The cookie is ignored | No `lang/<value>.php`, or the value is not a tag | Name the file exactly as the tag: `pt-BR.php` |
| The country is ignored | The request did not come from `http.trusted_proxies`, or no `country_header` | Configure both |
| `pt_BR.php` is not a language | File names are canonical tags | Rename it `pt-BR.php` |

<!-- {% endraw %} -->
