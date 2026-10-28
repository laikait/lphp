<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleDiscovery;
use App\Engine\Module\ModuleException;
use App\Engine\Support\Path;

/**
 * Create modules/<Name>/module.php, the one file a module needs.
 *
 * **The exception to "the console generates nothing", kept as small as it can
 * be.** What it writes is the skeleton modules.md already documents, and
 * nothing else: no folders for later, no sample handler, no options. A module
 * is still one hand-written file; this saves typing the first six lines of it.
 *
 * **The name is the module id**, so it has to be a name discovery would accept:
 * ModuleDefinition::NAME_PATTERN, checked here and not copied. That one rule
 * also rules out `..` and drive letters.
 *
 * **One group level is allowed**: `Plugins/Billing` is modules/Plugins/Billing,
 * and its id is `Billing`, because discovery names a module after its own
 * folder. Such a module is only found when its group folder is itself a root in
 * modules.paths. That is a setting somebody else owns, so the command creates
 * config/modules.php when there is none, and otherwise says what to add and
 * never edits the file.
 *
 * **It never overwrites**, and it counts a folder that differs only by case as
 * taken: on Windows and macOS `billing` and `Billing` are the same directory,
 * and on Linux they are two modules whose ids are easily confused.
 *
 * **Success is printed last.** A failed write removes what this run created, so
 * a half-made module cannot be left looking like a finished one.
 */
final class ModuleMakeCommand
{
    public const FILE = 'module.php';

    public const CONFIG = 'config/modules.php';

    public function __construct(private readonly Application $application) {}

    public function __invoke(Output $output, string $name): int
    {
        $segments = \explode('/', trim(\str_replace('\\', '/', $name), '/'));

        if (\count($segments) > 2 || \array_filter(
            $segments,
            static fn(string $segment): bool => \preg_match(ModuleDefinition::NAME_PATTERN, $segment) !== 1,
        ) !== []) {
            $output->error(\sprintf(
                '"%s" is not a module name. Use Billing or Plugins/Billing: each part a capital letter, then letters, digits or underscores.',
                \addcslashes($name, "\0..\37"),
            ));

            return 1;
        }

        $id = $segments[\count($segments) - 1];
        $group = \count($segments) === 2 ? $segments[0] : null;

        $clash = $this->idInUse($id);

        if ($clash !== null) {
            $output->error(\sprintf('A module called %s already exists (%s), and ids must be unique.', $id, $clash));

            return 1;
        }

        $modules = $this->application->basePath('modules');
        $created = [];
        $target = $modules;

        if ($group !== null) {
            $folder = $this->existing($modules, $group);

            // A group folder is reused as it is, but not under other letters:
            // that is a second group on Linux and the same one on Windows.
            if ($folder !== null && $folder !== $group) {
                $output->error(\sprintf('modules/%s already exists, so modules/%s was not used. Spell it the same way.', $folder, $group));

                return 1;
            }

            $target .= DIRECTORY_SEPARATOR . $group;
        }

        $taken = $this->existing($target, $id);

        if ($taken !== null) {
            $output->error(\sprintf('%s already exists, so %s was not created.', self::display($target, $taken), $name));

            return 1;
        }

        foreach ([$modules, $target, $target . DIRECTORY_SEPARATOR . $id] as $directory) {
            if (\is_dir($directory)) {
                continue;
            }

            if (!@\mkdir($directory, 0o775) && !\is_dir($directory)) {
                self::undo($created);
                $output->error(\sprintf('Could not create %s. Nothing was created.', $directory));

                return 1;
            }

            \array_unshift($created, $directory);
        }

        $file = $target . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . self::FILE;

        if (@\file_put_contents($file, self::source($id)) === false) {
            self::undo($created, $file);
            $output->error(\sprintf('Could not write %s. Nothing was created.', $file));

            return 1;
        }

        $shown = Path::relativeTo($this->application->basePath(), $file);

        $output->success(\sprintf('Created %s', $shown));
        $output->line('Modules are found automatically; nothing needs registering. Check it with: php laika module:list');

        $this->registerGroup($output, $id, \dirname(\dirname($file)));

        return 0;
    }

    /** Where, if anywhere, a module of this id is already installed. */
    private function idInUse(string $id): ?string
    {
        try {
            $found = ModuleDiscovery::fromConfig($this->application->config(), $this->application->basePath())->scan();
        } catch (ModuleException) {
            // Somebody else's broken module is for module:list to report.
            return null;
        }

        foreach ($found as $definition) {
            if (\strcasecmp($definition->id, $id) === 0) {
                return Path::relativeTo($this->application->basePath(), $definition->path);
            }
        }

        return null;
    }

    /**
     * Make sure the module's parent folder is a root, when that can be done safely.
     *
     * With no config/modules.php there is nothing to damage, so one is written
     * -- but only when the paths in force are the default, because a file that
     * lists only the new group would replace `modules` (config merges lists by
     * position) and a file written over paths set some other way would undo
     * them. An existing file is hand-written PHP, so it is never rewritten: the
     * line to add is printed instead.
     */
    private function registerGroup(Output $output, string $id, string $parent): void
    {
        $base = $this->application->basePath();
        $roots = ModuleDiscovery::fromConfig($this->application->config(), $base)->roots();
        $wanted = \rtrim(Path::normalize($parent), '/');

        foreach ($roots as $root) {
            if (\strcasecmp(\rtrim($root, '/'), $wanted) === 0) {
                return;
            }
        }

        $relative = Path::relativeTo($base, $wanted);
        $file = $this->application->basePath(self::CONFIG);
        $default = [Path::normalize($this->application->basePath('modules'))];

        if (!\is_file($file) && $roots === $default && self::write($file, self::config($relative))) {
            $output->line(\sprintf('Added %s to modules.paths by creating %s.', $relative, self::CONFIG));

            return;
        }

        $output->warning(\sprintf(
            '%s is not in modules.paths, so %s will not load. In %s: \'paths\' => [..., \'%s\']',
            $relative,
            $id,
            self::CONFIG,
            $relative,
        ));
    }

    private static function write(string $file, string $contents): bool
    {
        $directory = \dirname($file);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o775) && !\is_dir($directory)) {
            return false;
        }

        return @\file_put_contents($file, $contents) !== false;
    }

    /** $group is a validated identifier, so it is safe inside a PHP string. */
    private static function config(string $group): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'paths' => ['modules', '{$group}'],
            ];

            PHP;
    }

    /** The entry in $directory that is $name, or the same name in other letters. */
    private function existing(string $directory, string $name): ?string
    {
        $entries = \is_dir($directory) ? \scandir($directory) : [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if (\strcasecmp($entry, $name) === 0) {
                return $entry;
            }
        }

        return null;
    }

    private function display(string $directory, string $entry): string
    {
        return Path::relativeTo($this->application->basePath(), $directory . DIRECTORY_SEPARATOR . $entry);
    }

    /**
     * Remove what this run made, innermost first.
     *
     * @param list<string> $directories
     */
    private static function undo(array $directories, ?string $file = null): void
    {
        if ($file !== null && \is_file($file)) {
            @\unlink($file);
        }

        foreach ($directories as $directory) {
            if (\is_dir($directory)) {
                @\rmdir($directory);
            }
        }
    }

    /** $id is a validated identifier, so it is safe inside a PHP string. */
    private static function source(string $id): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use App\\Engine\\Module\\ModuleContext;

            return static function (ModuleContext \$module): void {
                \$module
                    ->name('{$id}')
                    ->version('0.1.0')
                    ->description('TODO: describe {$id}.');
            };

            PHP;
    }
}
