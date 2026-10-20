<?php

declare(strict_types=1);

namespace App\Engine\Asset;

/**
 * The three logical asset namespaces.
 *
 * These are the *only* namespaces that exist. An asset URL cannot name a
 * directory, only a namespace and a path inside it, which is what "assets must
 * never expose physical application directories" means in practice: there is no
 * spelling of an asset URL that says anything about where the file lives.
 *
 * The value is both the URL segment and the registry key prefix.
 */
enum AssetKind: string
{
    /** The application's own assets: public/assets/ on disk, /assets/core/... in a URL. */
    case Core = 'core';

    /**
     * The site's look: any web asset under templates/, at /template/ plus the
     * same path in a URL. Never named: a template folder is part of the path
     * -- template('admin/panel/x/css/app.css') -- so one URL always means one
     * file.
     */
    case Template = 'template';

    /** A module's assets/ directory: /assets/module/Billing/... */
    case Module = 'module';

    /** Whether a source of this kind is meaningless without a name. */
    public function requiresName(): bool
    {
        return $this === self::Module;
    }

    public function allowsName(): bool
    {
        return $this === self::Module;
    }

    /**
     * Where this namespace starts in a URL, without slashes: "assets/core",
     * "assets/module", and "template" for the template.
     */
    public function urlPrefix(): string
    {
        return $this === self::Template ? 'template' : 'assets/' . $this->value;
    }

    /** A human label for error messages: "module 'Billing'", "core". */
    public function describe(?string $name = null): string
    {
        return $name === null || $name === '' ? $this->value : $this->value . " '" . $name . "'";
    }
}
