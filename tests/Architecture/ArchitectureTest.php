<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Engine\Config\ConfigCache;
use App\Engine\Error\ErrorContext;
use App\Engine\Error\ErrorDocument;
use App\Engine\Error\ErrorPage;
use App\Engine\Logging\Level;
use App\Engine\Support\Extensions;
use App\Tests\Support\TestCase;
use App\Tests\Unit\Cache\StoreConformanceTest;
use App\Tests\Unit\Queue\StoreConformanceTest as QueueStoreConformanceTest;
use App\Tests\Unit\Scheduler\LockConformanceTest;
use App\Tests\Unit\Support\HelpersTest;

/**
 * Executable architecture rules.
 *
 * These are the invariants that erode quietly. Each one is cheap to violate in
 * a single commit and expensive to walk back a year later, so each one gets a
 * test rather than a paragraph in a document nobody re-reads.
 */
final class ArchitectureTest extends TestCase
{
    /** @return list<string> absolute paths of every PHP file under engine/ */
    private function engineFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath('engine'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return \str_replace([$this->basePath() . \DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }

    /**
     * Function calls made in the global namespace from a PHP file.
     *
     * A token scan rather than a regex: it will not be fooled by the function's
     * name appearing inside a string, a comment or a docblock.
     *
     * @return list<string>
     */
    private function globalFunctionCalls(string $path): array
    {
        $source = \file_get_contents($path);
        self::assertIsString($source);

        $tokens = \token_get_all($source);
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING) {
                continue;
            }

            // Must be followed by "(" to be a call.
            $next = $tokens[$index + 1] ?? null;

            if ($next !== '(') {
                continue;
            }

            // Look back past whitespace and comments, so that "function add_hook("
            // is recognised as a declaration rather than counted as a call.
            $previous = null;

            for ($i = $index - 1; $i >= 0; --$i) {
                $candidate = $tokens[$i];

                if (\is_array($candidate)
                    && \in_array($candidate[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                $previous = $candidate;

                break;
            }

            // Skip anything qualified or declared: Foo::bar(), $x->bar(),
            // new Bar(), Ns\bar(), function bar().
            if (\is_array($previous) && \in_array(
                $previous[0],
                [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_NEW, \T_FUNCTION, \T_NAME_QUALIFIED, \T_NS_SEPARATOR],
                true,
            )) {
                continue;
            }

            $calls[] = $token[1];
        }

        return $calls;
    }

    /**
     * A file's source with its comments and docblocks removed.
     *
     * A rule about what the code does should not be tripped by a docblock
     * explaining it. Documentation that shows a SELECT is documentation.
     */
    private function codeWithoutComments(string $path): string
    {
        $source = \file_get_contents($path);
        self::assertIsString($source);

        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ---- the facade ban ---------------------------------------------------

    /**
     * Engine code takes HookEngine and FilterEngine by constructor injection.
     * The moment engine code starts calling add_hook(), the global bridge has
     * become load-bearing infrastructure rather than an author convenience, and
     * the argument that it is not a facade stops holding.
     */
    public function test_no_engine_file_calls_a_global_helper(): void
    {
        foreach ($this->engineFiles() as $path) {
            // Separators differ between the directory iterator and basePath(),
            // so compare the normalised relative path rather than the absolute one.
            if ($this->relative($path) === 'engine/Support/helpers.php') {
                continue;
            }

            $offenders = \array_intersect($this->globalFunctionCalls($path), HelpersTest::PERMITTED);

            self::assertSame(
                [],
                \array_values($offenders),
                \sprintf(
                    '%s calls %s. Engine code must take HookEngine/FilterEngine by constructor injection.',
                    $this->relative($path),
                    \implode(', ', $offenders),
                ),
            );
        }
    }

    /**
     * __callStatic resolving arbitrary services IS the facade pattern. There is
     * no legitimate use for it in this framework.
     */
    public function test_no_engine_class_uses_magic_static_dispatch(): void
    {
        foreach ($this->engineFiles() as $path) {
            $source = \file_get_contents($path);
            self::assertIsString($source);

            self::assertStringNotContainsString(
                'function __callStatic',
                $source,
                \sprintf('%s declares __callStatic. That is the facade pattern.', $this->relative($path)),
            );
        }
    }

    public function test_no_engine_class_is_named_like_a_facade(): void
    {
        foreach ($this->engineFiles() as $path) {
            self::assertStringNotContainsString(
                'Facade',
                \basename($path),
                \sprintf('%s is named like a facade.', $this->relative($path)),
            );
        }
    }

    /**
     * Extensions is the single permitted piece of static framework state, and
     * its safety rests on being closed and on every member being justified.
     *
     * The justification is the same one for all of them: the specification says
     * authors reach hooks, filters, assets and templates globally, from
     * module.php files and from templates themselves, where there is no
     * constructor to inject into. A member
     * without that justification would make this a service locator with a
     * fixed key set, which is a facade wearing a different hat.
     *
     * So the test asserts the names rather than the count. A count is only ever
     * one commit away from being the next number; a name has to be argued for.
     */
    public function test_extensions_exposes_exactly_the_justified_subsystems(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(Extensions::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        \sort($methods);

        self::assertSame(
            ['assets', 'filters', 'hooks', 'init', 'isInitialised', 'reset', 'templates'],
            $methods,
            'Extensions gained a member. Name the line in the specification that says authors reach it globally, '
            . 'or inject it like everything else in the framework.',
        );
    }

    /**
     * The other half of that argument: no dispatch by name, ever.
     *
     * A lookup that takes a string is what turns a closed holder into a service
     * locator, whatever the class happens to be called.
     */
    public function test_extensions_resolves_nothing_by_name(): void
    {
        foreach ((new \ReflectionClass(Extensions::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                self::assertNotSame(
                    'string',
                    $type instanceof \ReflectionNamedType ? $type->getName() : null,
                    \sprintf(
                        'Extensions::%s() takes a string. A lookup by name is a service locator.',
                        $method->getName(),
                    ),
                );
            }
        }
    }

    public function test_the_global_function_set_is_exactly_the_permitted_names(): void
    {
        $source = \file_get_contents($this->basePath('engine/Support/helpers.php'));
        self::assertIsString($source);

        $declared = [];

        foreach (\token_get_all($source) as $index => $token) {
            if (\is_array($token) && $token[0] === \T_FUNCTION) {
                $declared[] = $index;
            }
        }

        $tokens = \token_get_all($source);
        $names = [];

        foreach ($declared as $index) {
            for ($i = $index + 1, $end = \count($tokens); $i < $end; ++$i) {
                $token = $tokens[$i];

                if (\is_array($token) && $token[0] === \T_STRING) {
                    $names[] = $token[1];

                    break;
                }
            }
        }

        \sort($names);
        $permitted = HelpersTest::PERMITTED;
        \sort($permitted);

        self::assertSame(
            $permitted,
            $names,
            'helpers.php declares a different set of functions than the permitted list. '
            . 'Adding one is a deliberate design decision, not a drive-by commit.',
        );
    }

    // ---- layering ---------------------------------------------------------

    /**
     * The engine provides infrastructure; modules provide business capability.
     * A reference in the other direction would mean the framework has grown an
     * opinion about a particular application.
     */
    public function test_the_engine_never_references_a_module(): void
    {
        foreach ($this->engineFiles() as $path) {
            $source = \file_get_contents($path);
            self::assertIsString($source);

            self::assertStringNotContainsString(
                'App\\Modules',
                $source,
                \sprintf('%s references a module. The engine must not know about application code.', $this->relative($path)),
            );
        }
    }

    /**
     * HTTP is a transport concern and routing is a dispatch concern. Keeping
     * Request and Response ignorant of routing is what lets both be reused and
     * tested without a router, and is an explicit architectural invariant.
     */
    public function test_the_http_layer_does_not_know_about_routing(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Http/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach (['App\\Engine\\Routing', 'App\\Engine\\Dispatch', 'App\\Engine\\Module'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s references %s. HTTP must not be coupled to the router.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    // ---- exceptions -------------------------------------------------------

    /**
     * Catching FrameworkException should mean "the framework said no". The two
     * container exceptions are the documented carve-out: they implement PSR-11
     * interfaces instead, which is the entire point of using PSR-11.
     */
    public function test_engine_exceptions_are_catchable_as_a_group(): void
    {
        $psrExempt = [
            \App\Engine\Container\ContainerException::class,
            \App\Engine\Container\EntryNotFoundException::class,
        ];

        foreach ($this->engineFiles() as $path) {
            if (!\str_ends_with($path, 'Exception.php')) {
                continue;
            }

            $class = $this->classNameFor($path);

            if (!\class_exists($class) || \in_array($class, $psrExempt, true)) {
                continue;
            }

            self::assertTrue(
                \is_subclass_of($class, \App\Engine\Error\FrameworkException::class)
                    || $class === \App\Engine\Error\FrameworkException::class,
                \sprintf('%s does not extend FrameworkException and is not a documented PSR carve-out.', $class),
            );
        }
    }

    private function classNameFor(string $path): string
    {
        $relative = \substr($this->relative($path), \strlen('engine/'), -\strlen('.php'));

        return 'App\\Engine\\' . \str_replace('/', '\\', $relative);
    }

    // ---- the model layer --------------------------------------------------

    /**
     * The engine provides model infrastructure. A business entity living here
     * would mean the framework has decided what your application's domain is,
     * and every application that disagreed would be fighting it.
     */
    public function test_no_business_model_lives_in_the_engine(): void
    {
        $infrastructure = [
            'Attributes.php',
            'Model.php',
            'ModelCollection.php',
            'ModelException.php',
            'ModelManager.php',
            'ReadModel.php',
            'Relation.php',
            'RelationManager.php',
            'RelationType.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Model/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame(
            $infrastructure,
            $found,
            'engine/Model/ holds infrastructure only. User, Customer, Invoice and Payment belong to modules.',
        );
    }

    /**
     * The line between "a model" and "an ORM entity". Any one of these methods
     * on the base class would put persistence back inside the domain object,
     * which is the architecture this framework was specified to avoid.
     */
    public function test_the_model_base_class_has_no_persistence_api(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Model\Model::class))->getMethods(),
        );

        foreach ([
            'save', 'delete', 'create', 'update', 'insert', 'persist', 'refresh',
            'find', 'findAll', 'first', 'all', 'where', 'query', 'with', 'load',
        ] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $methods,
                \sprintf('Model::%s() would make this an active record.', $forbidden),
            );
        }
    }

    /**
     * A domain model is not an API representation. Serialising one is how
     * internal state reaches a public payload without anyone deciding to.
     */
    public function test_a_domain_model_is_not_serialisable(): void
    {
        self::assertFalse(
            \is_subclass_of(\App\Engine\Model\Model::class, \JsonSerializable::class),
            'Model implements JsonSerializable. Project it into a ReadModel instead.',
        );
    }

    /**
     * The model layer knows nothing about transport or storage. That is what
     * lets it be used and tested without either, and what keeps Model, Schema,
     * Repository, Query and Database separate concepts rather than one.
     */
    public function test_the_model_layer_is_independent_of_transport_and_storage(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Model/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Container',
                'App\\Engine\\Data',
                'App\\Engine\\Database',
                'App\\Engine\\Schema',
                // Leading backslash on purpose: that is how a global class is
                // referenced from a namespaced file, so prose about PDO in a
                // docblock does not trip the rule but "new \PDO" does.
                '\\PDO',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s references %s. The model layer must not depend on it.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    // ---- the schema layer -------------------------------------------------

    public function test_no_business_schema_lives_in_the_engine(): void
    {
        $infrastructure = [
            'Field.php',
            'FieldType.php',
            'Schema.php',
            'SchemaException.php',
            'ValidationError.php',
            'ValidationResult.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Schema/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame(
            $infrastructure,
            $found,
            'engine/Schema/ holds infrastructure only. CustomerSchema and its kind belong to modules.',
        );
    }

    /**
     * The structural difference between a schema and a form-request object.
     *
     * A FormRequest is resolved out of a handler signature, knows it is an HTTP
     * request, and throws a status code. If the schema layer cannot see HTTP at
     * all, it cannot grow into one no matter who is in a hurry.
     */
    public function test_the_schema_layer_cannot_become_a_form_request(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Schema/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Model',
                'App\\Engine\\Container',
                'App\\Engine\\Module',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf(
                        '%s references %s. A schema describes data; it must not know how the data arrived.',
                        $this->relative($path),
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Nothing to extend and nothing to implement. A schema is a value built by
     * a factory, which is the other half of not being a form-request base class.
     */
    public function test_the_schema_types_are_final(): void
    {
        foreach ([
            \App\Engine\Schema\Schema::class,
            \App\Engine\Schema\Field::class,
            \App\Engine\Schema\ValidationResult::class,
            \App\Engine\Schema\ValidationError::class,
        ] as $class) {
            self::assertTrue(
                (new \ReflectionClass($class))->isFinal(),
                \sprintf('%s is not final. A schema is declared, not subclassed.', $class),
            );
        }
    }

    // ---- the data layer ---------------------------------------------------

    public function test_no_business_repository_lives_in_the_engine(): void
    {
        $infrastructure = [
            'ArraySource.php',
            'Criterion.php',
            'DataException.php',
            'DataSource.php',
            'Direction.php',
            'Operator.php',
            'Order.php',
            'Page.php',
            'Query.php',
            'Repository.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Data/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame(
            $infrastructure,
            $found,
            'engine/Data/ holds infrastructure only. CustomerRepository and its kind belong to modules.',
        );
    }

    /**
     * The repository base contributes no API, which is what makes every public
     * method on a real repository one somebody named after something the
     * application does. A find()/findAll()/save()/delete() base is a table
     * gateway with a longer name.
     */
    public function test_the_repository_base_publishes_nothing_but_its_constructor(): void
    {
        $public = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Data\Repository::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame(
            ['__construct'],
            $public,
            'Repository gained a public method. Every repository would then have it, whether it meant anything or not.',
        );
    }

    /**
     * The data layer never sees a connection, a statement or a dialect.
     *
     * DataSource is the whole seam. Keeping SQL out of this directory is what
     * lets the same repository run against an array in a test and a database in
     * production, and it is what keeps the database phase free to own
     * connections, transactions and drivers without anything above it caring.
     */
    public function test_the_data_layer_knows_nothing_about_sql_or_transport(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Data/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Database',
                '\\PDO',
                'SELECT ',
                'INSERT ',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s references %s. The data layer speaks through DataSource only.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    /**
     * Transactions, connections and nested-transaction strategy belong to the
     * database layer. A transaction() here would make every source pretend to
     * have one, including the array in a test.
     */
    public function test_the_data_source_does_not_reach_into_the_database_layer(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Data\DataSource::class))->getMethods(),
        );

        \sort($methods);

        self::assertSame(['count', 'delete', 'fetch', 'insert', 'update'], $methods);
    }

    // ---- the database layer -----------------------------------------------

    public function test_the_database_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'Connection.php',
            'ConnectionConfig.php',
            'ConnectionManager.php',
            'DatabaseException.php',
            'Grammar.php',
            'SqlSource.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Database/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * "Database should remain usable independently of Model."
     *
     * A Connection is for a report, a migration or a one-off script as much as
     * for a repository. If it knew what a model was, the data layer would be
     * the only way in.
     */
    public function test_a_connection_knows_nothing_about_models(): void
    {
        foreach (['Connection.php', 'ConnectionConfig.php', 'ConnectionManager.php'] as $file) {
            $source = \file_get_contents($this->basePath('engine/Database/' . $file));
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Model\\',
                'App\\Engine\\Data\\',
                'App\\Engine\\Schema\\',
                'App\\Engine\\Http\\',
                'App\\Engine\\Config\\',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('engine/Database/%s references %s; the database must stand on its own.', $file, $forbidden),
                );
            }
        }
    }

    /**
     * Only the Grammar builds SQL, and only from checked identifiers.
     *
     * Everything else in the database layer passes SQL through from a caller or
     * from the Grammar. Keeping statement construction in one file is what
     * makes the injection boundary one file wide.
     */
    public function test_only_the_grammar_builds_statements(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Database/') || \str_ends_with($relative, 'Grammar.php')) {
                continue;
            }

            foreach (['SELECT ', 'INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $statement) {
                self::assertStringNotContainsString(
                    $statement,
                    $this->codeWithoutComments($path),
                    \sprintf('%s builds SQL. Statement construction belongs in Grammar, where identifiers are checked.', $relative),
                );
            }
        }
    }

    /**
     * The framework never decides which source an application reads through.
     *
     * Bootstrap wires the connections; a module chooses whether to read from a
     * database, from memory, or from something else entirely.
     */
    public function test_bootstrap_does_not_choose_the_applications_data_source(): void
    {
        $source = \file_get_contents($this->basePath('engine/Bootstrap/Bootstrap.php'));
        self::assertIsString($source);

        foreach (['DataSource::class', 'SqlSource::class', 'ArraySource::class'] as $binding) {
            self::assertStringNotContainsString(
                $binding,
                $source,
                'Bootstrap binds a data source. Which source an application reads through is its own decision.',
            );
        }
    }

    // ---- the asset layer --------------------------------------------------

    public function test_the_asset_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'AssetException.php',
            'AssetKind.php',
            'AssetManager.php',
            'AssetReference.php',
            'AssetRegistry.php',
            'AssetResolver.php',
            'AssetServer.php',
            'AssetSource.php',
            'AssetVersioning.php',
            'Manifest.php',
            'MimeTypes.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Asset/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * "Separate asset resolution from asset delivery."
     *
     * The specification asks for it, and the reason is that they have different
     * lifetimes: a URL is generated in a template, a CLI job or a queued mail,
     * where there is no request at all, while delivery is one HTTP handler that
     * a web server should eventually take over. If AssetManager could see a
     * Request the two would grow together and neither could be replaced.
     */
    public function test_asset_resolution_cannot_see_http(): void
    {
        foreach (['AssetManager.php', 'AssetRegistry.php', 'AssetResolver.php', 'AssetSource.php', 'Manifest.php'] as $file) {
            $source = \file_get_contents($this->basePath('engine/Asset/' . $file));
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Container',
                'App\\Engine\\Module',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf(
                        'engine/Asset/%s references %s. Resolution builds a URL; only AssetServer delivers one.',
                        $file,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Every path that becomes a file goes through AssetResolver.
     *
     * This is the rule that keeps the traversal, containment, symlink and
     * extension checks in one readable place. A second file in this directory
     * opening something by path would be a second place to get it wrong, and
     * the one nobody remembers to look at.
     */
    public function test_only_the_resolver_turns_an_asset_path_into_a_file(): void
    {
        // AssetReference is the resolver's own result type: holding one already
        // means every check passed, which is the entire point of the type.
        $permitted = ['AssetResolver.php', 'AssetReference.php', 'AssetSource.php', 'Manifest.php'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Asset/') || \in_array(\basename($path), $permitted, true)) {
                continue;
            }

            foreach (['file_get_contents', 'is_file', 'realpath', 'glob', 'scandir', 'opendir'] as $call) {
                self::assertStringNotContainsString(
                    $call . '(',
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s reaches the filesystem directly. Asset paths become files in AssetResolver, '
                        . 'where traversal, containment and extension are checked.',
                        $relative,
                    ),
                );
            }
        }
    }

    /**
     * PHP is not an asset, and neither is anything else that runs.
     *
     * The served set is an allow list rather than a deny list, which is what
     * makes this assertion possible at all: a deny list can only be tested
     * against the extensions somebody thought of.
     */
    public function test_no_executable_or_configuration_extension_is_servable(): void
    {
        foreach ([
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc',
            'env', 'ini', 'htaccess', 'sh', 'bat', 'cmd', 'exe', 'dll', 'so',
            'sql', 'lock', 'log', 'yml', 'yaml', 'html', 'htm', 'xhtml',
        ] as $extension) {
            self::assertFalse(
                \App\Engine\Asset\MimeTypes::isServable($extension),
                \sprintf('.%s is on the served list. It should not be reachable through an asset URL.', $extension),
            );
        }
    }

    // ---- the template layer -----------------------------------------------

    public function test_the_template_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'Escaper.php',
            'PhpTemplateEngine.php',
            'TemplateEngine.php',
            'TemplateException.php',
            'TemplateFile.php',
            'TemplateManager.php',
            'TemplateRegistry.php',
            'TemplateSource.php',
            'TemplateView.php',
            'TwigTemplateEngine.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Template/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * Rendering produces a string, not a response.
     *
     * That is what lets the same template be rendered into an email, a PDF
     * pipeline or a test assertion. A render() that returned a Response would
     * make HTTP the only destination, and a template system with one
     * destination is a view layer bolted to a controller.
     */
    public function test_the_template_layer_cannot_see_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Template/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Container',
                'App\\Engine\\Data',
                'App\\Engine\\Database',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf(
                        '%s references %s. A template renders to a string; the handler decides what to do with it.',
                        $this->relative($path),
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * "Twig must remain optional." Optional means one file.
     *
     * If Twig appeared anywhere else -- a type hint on the manager, a check in
     * the registry -- then removing the package would break the framework, and
     * "optional" would mean "installed by default".
     */
    public function test_twig_is_confined_to_its_own_engine(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Template/') || \str_ends_with($relative, 'TwigTemplateEngine.php')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            self::assertStringNotContainsString(
                'Twig\\',
                $source,
                \sprintf('%s references Twig. Only TwigTemplateEngine may.', $relative),
            );
        }
    }

    /**
     * Twig is a development dependency, so an application that never renders a
     * Twig template never ships it. The engine asks before touching it.
     */
    public function test_twig_is_not_a_runtime_requirement(): void
    {
        $composer = \file_get_contents($this->basePath('composer.json'));
        self::assertIsString($composer);

        /** @var array{require: array<string, string>, require-dev: array<string, string>} $manifest */
        $manifest = \json_decode($composer, true, 16, \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('twig/twig', $manifest['require']);
        self::assertArrayHasKey('twig/twig', $manifest['require-dev']);
    }

    /**
     * A template must not be able to resolve arbitrary services.
     *
     * TemplateView is everything a template can reach. If it could hand out a
     * container, a template could run a query, and "what does this page do"
     * would stop having an answer you can read in the handler.
     */
    public function test_a_template_cannot_reach_the_container(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Template\TemplateView::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        \sort($methods);

        self::assertSame(
            ['__construct', 'asset', 'data', 'escaper', 'exists', 'get', 'has', 'render', 'withData'],
            $methods,
            'TemplateView gained a method. Everything a template can reach is listed here on purpose.',
        );
    }

    // ---- the REST surface -------------------------------------------------

    /**
     * "Do not build a completely separate API framework."
     *
     * The strongest possible statement of that is structural: there is no
     * engine/Rest/ and no engine/Api/ directory, because the specified layout
     * does not have one. Everything the REST phase added lives in Http/ and
     * Error/ next to the things it extends, and a module's Api/ directory holds
     * the handlers -- which is the only place the word Api appears.
     */
    public function test_there_is_no_separate_api_framework(): void
    {
        foreach (['engine/Rest', 'engine/Api', 'engine/Resource', 'engine/Serializer'] as $directory) {
            self::assertDirectoryDoesNotExist(
                $this->basePath($directory),
                $directory . '/ exists. REST uses the same kernel, router and dispatcher as everything else.',
            );
        }
    }

    /**
     * No base class for an API handler, and nothing an API handler must
     * implement.
     *
     * ApiResponse is six static factories; the moment it gains a constructor or
     * a protected method it has become something to extend, and the next thing
     * after that is an ApiController.
     */
    public function test_the_api_conveniences_are_not_something_to_inherit(): void
    {
        foreach ([
            \App\Engine\Http\ApiResponse::class,
            \App\Engine\Http\Negotiator::class,
            \App\Engine\Http\MediaType::class,
            \App\Engine\Error\ErrorDocument::class,
        ] as $class) {
            $reflection = new \ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), $class . ' is not final.');

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PROTECTED) as $method) {
                self::fail(\sprintf('%s::%s() is protected, which only matters to a subclass.', $class, $method->getName()));
            }
        }

        // Nothing for a handler to extend or implement means every one of them
        // is a plain class that happens to return a Response.
        self::assertSame(
            [],
            (new \ReflectionClass(\App\Engine\Http\ApiResponse::class))->getInterfaceNames(),
        );
    }

    /**
     * The envelope does not know what a Page is.
     *
     * ApiResponse::page() would be the obvious convenience and would put the
     * data layer inside the HTTP layer. $page->meta() at the call site costs
     * eleven characters and keeps transport ignorant of storage.
     */
    public function test_the_http_layer_still_knows_nothing_about_storage(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Http/')) {
                continue;
            }

            // Comments stripped: ApiResponse's docblock explains at length why
            // it does not take a Page, and prose about a rule should not trip
            // the rule.
            $code = $this->codeWithoutComments($path);

            foreach (['App\\Engine\\Data', 'App\\Engine\\Database', 'App\\Engine\\Model', 'App\\Engine\\Schema'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s references %s. HTTP carries answers; it does not know where they came from.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    /**
     * One error shape, produced in one place.
     *
     * Before ErrorDocument existed, a handler's validation failure and the
     * kernel's uncaught exception were built by two pieces of code that agreed
     * on a shape by coincidence. A second literal 'error' => [...] anywhere in
     * the engine is how that would come back.
     */
    public function test_only_the_error_document_builds_an_error_body(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_ends_with($relative, 'ErrorDocument.php')) {
                continue;
            }

            self::assertStringNotContainsString(
                "'error' => [",
                $this->codeWithoutComments($path),
                \sprintf('%s builds an error body by hand. ErrorDocument is the one shape.', $relative),
            );
        }
    }

    // ---- the contract with the web server ---------------------------------

    /**
     * The specified layout puts index.php, vendor/, engine/ and modules/ in the
     * same web-served directory, which makes .htaccess the only thing standing
     * between a default Apache and the source. Deleting it is a one-line commit
     * with a serious consequence, so it gets a test.
     */
    public function test_the_htaccess_still_denies_access_to_application_internals(): void
    {
        $path = $this->basePath('.htaccess');

        self::assertFileExists($path, 'The front controller alone does not protect files Apache can serve directly.');

        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        foreach (['engine', 'modules', 'templates', 'config', 'system', 'vendor', 'tests', 'bin'] as $directory) {
            self::assertStringContainsString(
                $directory,
                $contents,
                \sprintf('.htaccess no longer mentions %s/; it may be web-readable.', $directory),
            );
        }

        self::assertStringContainsString('[F,L]', $contents, '.htaccess has no deny rule.');
        self::assertStringContainsString('index.php', $contents, '.htaccess no longer routes to the front controller.');
        self::assertStringContainsString('composer', $contents, '.htaccess no longer denies the project metadata.');
    }

    /**
     * The web server and the asset manager have to agree about what is public.
     *
     * This rule exists because the two disagreed: .htaccess denied every *.json
     * anywhere, while MimeTypes listed .json as servable -- so an import map or
     * an i18n bundle would have been refused by Apache before a single line of
     * framework code ran, and the failure would have looked like a permissions
     * problem rather than a policy. A blanket deny by extension is what causes
     * that, so the rule is that the deny list names files, not extensions.
     */
    public function test_the_web_server_does_not_blanket_deny_a_servable_extension(): void
    {
        $contents = \file_get_contents($this->basePath('.htaccess'));
        self::assertIsString($contents);

        \preg_match_all('/FilesMatch\s+"([^"]+)"/', $contents, $matches);

        foreach ($matches[1] as $pattern) {
            // A pattern that begins by matching a dot is matching an extension
            // and nothing else, wherever the file happens to be.
            self::assertStringStartsNotWith(
                '\\.',
                $pattern,
                \sprintf(
                    '.htaccess denies "%s", which is a blanket deny by extension. That refuses published assets '
                    . 'of that type before any framework check runs. Deny metadata by name instead.',
                    $pattern,
                ),
            );
        }

        // The two the asset manager is most likely to publish, and the two a
        // blanket extension deny would have caught.
        self::assertTrue(\App\Engine\Asset\MimeTypes::isServable('json'));
        self::assertTrue(\App\Engine\Asset\MimeTypes::isServable('map'));
    }

    /**
     * The development server refuses exactly what Apache refuses.
     *
     * Two deny lists in two languages, and nothing but a habit keeps them in
     * step -- which is how config/ ended up denied in one of them and not the
     * other. The consequence is not theoretical: a .env is not a .php file, so
     * `php -S` hands one over as plain text to anybody on the same network as
     * the developer.
     */
    public function test_the_development_server_denies_what_the_web_server_denies(): void
    {
        $htaccess = \file_get_contents($this->basePath('.htaccess'));
        $router = \file_get_contents($this->basePath('server.php'));

        self::assertIsString($htaccess);
        self::assertIsString($router);

        if (\preg_match('/RewriteRule \^\(([a-z|]+)\)\(/', $htaccess, $apache) !== 1) {
            self::fail('.htaccess no longer denies a list of directories in the shape this rule can read.');
        }

        if (\preg_match('/foreach \(\[([^\]]+)\] as \$private\)/', $router, $builtIn) !== 1) {
            self::fail('server.php no longer denies a list of directories in the shape this rule can read.');
        }

        $denied = \explode('|', $apache[1]);
        $mirrored = \array_map(
            static fn(string $entry): string => \trim(\trim($entry), "'"),
            \explode(',', $builtIn[1]),
        );

        \sort($denied);
        \sort($mirrored);

        self::assertSame($denied, $mirrored, 'the two deny lists have drifted apart.');

        // And the metadata, which the built-in server would otherwise serve as
        // plain text rather than execute.
        self::assertStringContainsString('.env', $router, 'server.php no longer refuses a .env file.');
    }

    // ---- the console ------------------------------------------------------

    /**
     * The console layer holds infrastructure only.
     *
     * Commands/ is the exception in the list below: those are the framework's
     * own commands, and they are here rather than in a module because there is
     * no module the framework could put them in. They are still ordinary
     * classes registered through the ordinary collector.
     */
    public function test_the_console_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Cli/Command.php',
            'engine/Cli/CommandArgument.php',
            'engine/Cli/CommandCollector.php',
            'engine/Cli/CommandDispatcher.php',
            'engine/Cli/CommandOption.php',
            'engine/Cli/CommandRegistry.php',
            'engine/Cli/ConsoleException.php',
            'engine/Cli/ConsoleKernel.php',
            'engine/Cli/CoreCommands.php',
            'engine/Cli/Input.php',
            'engine/Cli/Output.php',
            'engine/Cli/Commands/AboutCommand.php',
            'engine/Cli/Commands/AssetListCommand.php',
            'engine/Cli/Commands/CacheClearCommand.php',
            'engine/Cli/Commands/ConfigCacheCommand.php',
            'engine/Cli/Commands/ConfigListCommand.php',
            'engine/Cli/Commands/HelpCommand.php',
            'engine/Cli/Commands/LogStatusCommand.php',
            'engine/Cli/Commands/ModuleListCommand.php',
            'engine/Cli/Commands/QueueFailedCommand.php',
            'engine/Cli/Commands/QueueStatusCommand.php',
            'engine/Cli/Commands/QueueWorkCommand.php',
            'engine/Cli/Commands/RouteListCommand.php',
            'engine/Cli/Commands/ScheduleListCommand.php',
            'engine/Cli/Commands/ScheduleRunCommand.php',
            'engine/Cli/Commands/ScheduleUnlockCommand.php',
            'engine/Cli/Commands/TemplateListCommand.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Cli/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * There is nothing to extend and nothing to implement.
     *
     * This is the whole of "do not create an Artisan clone", stated in the one
     * form that cannot drift. A Command base class is how a command stops being
     * an ordinary object: it arrives with $this->argument(), then $this->output,
     * then $this->info(), and then a module's command can only be written one
     * way. Here a command handler is whatever a route handler is.
     */
    public function test_there_is_nothing_in_the_console_for_a_command_to_extend(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cli/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            self::assertStringNotContainsString(
                'abstract class',
                $code,
                \sprintf('%s declares an abstract class. A command must not have a base class.', $relative),
            );

            self::assertStringNotContainsString(
                'interface ',
                $code,
                \sprintf(
                    '%s declares an interface. A command handler is a callable resolved through the container, '
                    . 'exactly as a route handler is; an interface would make it a second kind of thing.',
                    $relative,
                ),
            );

            self::assertStringNotContainsString(
                'protected ',
                $code,
                \sprintf('%s has a protected member, which only a subclass could want.', $relative),
            );
        }
    }

    /**
     * A command is not a request handler.
     *
     * The console shares the bootstrap, the container, the modules and the
     * hooks -- everything except HTTP. If Request or Response appeared here it
     * would mean a command was being answered as though it were a web request,
     * and the two contexts would start having to agree about things they have
     * no reason to agree about.
     */
    public function test_the_console_does_not_reach_for_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cli/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['App\Engine\Http', 'App\Engine\Core\HttpKernel'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s references %s. A command is not a request.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * The console generates nothing.
     *
     * make:controller and friends are how a framework stops being a library you
     * call and becomes a thing you live inside: the generated file has a shape
     * the framework then quietly depends on, and the shape is undocumented
     * because the generator is the documentation. Every command here answers a
     * question instead.
     */
    public function test_no_command_writes_source_code(): void
    {
        $commands = [
            'about',
            'help',
            'module:list',
            'queue:failed',
            'queue:status',
            'queue:work',
            'route:list',
            'schedule:list',
            'schedule:run',
            'schedule:unlock',
            'asset:list',
            'template:list',
            'log:status',
            'cache:clear',
            'config:cache',
            'config:list',
        ];

        $registry = new \App\Engine\Cli\CommandRegistry();
        \App\Engine\Cli\CoreCommands::register($registry);

        $names = $registry->names();
        \sort($names);
        \sort($commands);

        self::assertSame(
            $commands,
            $names,
            'The framework\'s own command list changed. Adding one is a deliberate act; '
            . 'adding a generator is a different kind of framework.',
        );

        foreach ($names as $name) {
            self::assertStringStartsNotWith('make:', $name);
            self::assertStringStartsNotWith('generate:', $name);
        }
    }

    // ---- errors -----------------------------------------------------------

    /**
     * An exception that quotes a message it did not write must withhold it.
     *
     * This is the tripwire for the one mistake in this area that costs
     * something real. A factory taking $previous and interpolating
     * $previous->getMessage() produces a message that may contain a DSN, a
     * credential or a path -- PDO quotes the DSN back verbatim on a connection
     * failure -- and nothing downstream can tell that apart from a sentence the
     * framework wrote itself.
     *
     * The check is per file rather than per factory, which is coarse in the
     * right direction: a class with one wrapping factory and one safe one still
     * has to say so, and saying so is one call.
     */
    public function test_an_exception_that_quotes_another_withholds_its_message(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_ends_with($relative, 'Exception.php')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            if (!\str_contains($code, 'previous->getMessage()')) {
                continue;
            }

            self::assertStringContainsString(
                'withheld()',
                $code,
                \sprintf(
                    '%s interpolates another exception\'s message but never calls withheld(). That message came '
                    . 'from outside this framework and may carry a credential, so it must not be repeated.',
                    $relative,
                ),
            );
        }
    }

    /**
     * Error rendering and error recording stay independent.
     *
     * The specification asks for logging to be independent of error rendering,
     * and what keeps that true is that this layer has nowhere to write. Every
     * handled error fires error.reported and a logger is a listener on it. The
     * moment a file handle or an error_log() call appears here, rendering and
     * recording are one thing again, and changing either means touching both.
     *
     * Writing to STDERR is not recording -- that is the console rendering, and
     * it is the console's only way to reach a person.
     */
    public function test_the_error_layer_records_nothing(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Error/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['error_log(', 'syslog(', 'openlog(', 'file_put_contents(', 'fopen('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf(
                        '%s calls %s. Errors are announced on the error.reported hook and written by a '
                        . 'listener; this layer renders and does not record.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * The page of last resort asks the application for nothing.
     *
     * An error page that links a stylesheet renders unstyled, or not at all,
     * exactly when it is needed -- which is when something is broken, quite
     * possibly the asset pipeline itself. An application is welcome to supply
     * its own template; the fallback has to survive without one.
     */
    public function test_the_built_in_error_page_has_no_dependencies(): void
    {
        $code = $this->codeWithoutComments($this->basePath('engine/Error/ErrorPage.php'));

        foreach (['AssetManager', 'Router', 'Config', 'Container'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                \sprintf('ErrorPage references %s. The fallback page must render when nothing else works.', $forbidden),
            );
        }

        $html = (new ErrorPage())->render(ErrorDocument::of(500));

        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('http', $html);
    }

    /**
     * Every context an error can be rendered into is one of the three named.
     *
     * The specification lists browser, REST and console, and production is a
     * fourth item on a different axis: it decides how much is said, not to
     * whom. A fourth case here would mean that distinction had collapsed.
     */
    public function test_the_error_audiences_are_exactly_the_three_the_specification_names(): void
    {
        self::assertSame(
            ['api', 'browser', 'console'],
            \array_values(\array_map(
                static fn(ErrorContext $context): string => $context->value,
                (static function (): array {
                    $cases = ErrorContext::cases();
                    \usort($cases, static fn(ErrorContext $a, ErrorContext $b): int => $a->value <=> $b->value);

                    return $cases;
                })(),
            )),
        );
    }

    // ---- logging -----------------------------------------------------------

    /**
     * The logging layer holds infrastructure only.
     */
    public function test_the_logging_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Logging/Context.php',
            'engine/Logging/ErrorLog.php',
            'engine/Logging/Level.php',
            'engine/Logging/LineFormatter.php',
            'engine/Logging/LogManager.php',
            'engine/Logging/LogRecord.php',
            'engine/Logging/LogWriter.php',
            'engine/Logging/Logger.php',
            'engine/Logging/LoggingException.php',
            'engine/Logging/ScheduleLog.php',
            'engine/Logging/Writers/FileWriter.php',
            'engine/Logging/Writers/StreamWriter.php',
            'engine/Logging/Writers/SyslogWriter.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Logging/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * The dependency runs one way: logging knows about errors, never the
     * reverse.
     *
     * "Logging must be independent from error rendering" is checkable, and this
     * is the check. The moment engine/Error mentions a logger, the two are one
     * subsystem again: changing where records go means touching the code that
     * renders a page, and an application cannot have one without the other.
     * What connects them instead is a hook, and a hook has no compile-time
     * direction at all.
     */
    public function test_error_rendering_knows_nothing_about_logging(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Error/')) {
                continue;
            }

            foreach (['Logging', 'Logger', 'LogManager', 'log('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s refers to %s. Errors are announced on the error.reported hook; '
                        . 'a logger listens to it and is not a dependency of this layer.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Only one file in the logging layer knows what an error is.
     *
     * ErrorLog is the bridge and is meant to be deletable. If the rest of the
     * layer started reaching for HttpException or ErrorContext, deleting it
     * would stop being a one-line decision.
     */
    public function test_the_bridge_is_the_only_part_that_knows_about_errors(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Logging/') || \str_ends_with($relative, 'ErrorLog.php')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['ErrorContext', 'HttpException', 'ErrorDocument'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s refers to %s. Only ErrorLog bridges the two.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * Nothing but the manager decides what happens when a writer fails.
     *
     * "Logging must not cause application failure" is enforced in exactly one
     * place. A writer that caught its own exceptions would look safer and be
     * worse: the manager could no longer tell that it had stopped working, and
     * log:status would report a healthy writer that writes nothing.
     */
    public function test_only_the_manager_swallows_a_writer_failure(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Logging/Writers/')) {
                continue;
            }

            self::assertStringNotContainsString(
                'catch (',
                $this->codeWithoutComments($path),
                \sprintf(
                    '%s catches its own failure. A writer reports; the manager decides, retires it '
                    . 'and records why, which is what keeps log:status honest.',
                    $relative,
                ),
            );
        }
    }

    /**
     * A log file is never reachable over HTTP.
     *
     * This is the sharpest edge the logging phase adds. The default file writer
     * puts records under system/Logs, and those records contain exactly what
     * the error layer spent a phase keeping out of responses: DSNs, messages,
     * paths, request context. One misconfigured directory and the thing that
     * exists to record an incident becomes one.
     *
     * The deny already covers system/, so this is a regression guard on both
     * halves at once -- the directory the writer chooses and the rule that
     * refuses it.
     */
    public function test_the_log_directory_is_denied_by_the_web_server(): void
    {
        $directory = 'system' . \DIRECTORY_SEPARATOR . 'Logs';

        self::assertDirectoryExists(
            $this->basePath($directory),
            'the default file writer targets this directory, so it has to be the one that is denied',
        );

        foreach (['.htaccess', 'server.php'] as $file) {
            $contents = \file_get_contents($this->basePath($file));
            self::assertIsString($contents);

            self::assertStringContainsString(
                'system',
                $contents,
                \sprintf('%s no longer refuses system/, so log files may be readable over HTTP.', $file),
            );
        }
    }

    /**
     * The level set is the eight RFC 5424 severities and nothing else.
     *
     * The specification's list also contains "log", which is the name of the
     * method that takes a level rather than a level itself. A ninth case would
     * have no place in the ordering, and "is this record severe enough to
     * write" would stop having an answer.
     */
    public function test_the_levels_are_the_eight_standard_severities(): void
    {
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            Level::names(),
        );

        // The backing values are the RFC 5424 codes, which is what a log line
        // and every parser of one expects.
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], \array_map(
            static fn(Level $level): int => $level->value,
            Level::cases(),
        ));

        // And what goes to syslog() is the platform\'s own constant, which is
        // not the same number everywhere. On Windows there is no syslog, so PHP
        // collapses the eight onto the event log's three types -- passing the
        // backing value there would file every record under the wrong severity
        // on the one platform where nothing would look broken.
        foreach ([
            'emergency' => \LOG_EMERG,
            'alert' => \LOG_ALERT,
            'critical' => \LOG_CRIT,
            'error' => \LOG_ERR,
            'warning' => \LOG_WARNING,
            'notice' => \LOG_NOTICE,
            'info' => \LOG_INFO,
            'debug' => \LOG_DEBUG,
        ] as $label => $priority) {
            self::assertSame(
                $priority,
                Level::fromName($label)->priority(),
                \sprintf('%s must reach syslog() as this platform\'s own priority', $label),
            );
        }
    }
    // ---- the cache -------------------------------------------------------------

    /**
     * The cache layer holds infrastructure only.
     */
    public function test_the_cache_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Cache/Cache.php',
            'engine/Cache/CacheEntry.php',
            'engine/Cache/CacheException.php',
            'engine/Cache/CacheStore.php',
            'engine/Cache/Stores/ArrayStore.php',
            'engine/Cache/Stores/FileStore.php',
            'engine/Cache/Stores/NullStore.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Cache/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * There is no static cache API.
     *
     * The specification bans one by name, next to DB::table() and User::find(),
     * and the ban is worth more than the convenience it costs. Cache::get()
     * would mean a class that caches has a dependency its constructor does not
     * mention, cannot be handed a store that keeps nothing, and cannot be
     * tested without whatever the static resolves to at that moment.
     *
     * Constants are not an API; a static method is. So this checks methods.
     * The exception class is exempt, because a named constructor on a throwable
     * is how every exception in this framework is built and has nothing to do
     * with reaching a cache without being handed one.
     */
    public function test_the_cache_offers_nothing_statically(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cache/') || $relative === 'engine/Cache/CacheException.php') {
                continue;
            }

            $class = 'App\\Engine\\' . \str_replace(
                ['engine/', '/', '.php'],
                ['', '\\', ''],
                $relative,
            );

            if (!\class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                self::assertFalse(
                    $method->isStatic(),
                    \sprintf(
                        '%s::%s() is static. A cache is injected -- that is the whole difference between '
                        . 'this and the facade the specification refuses.',
                        $relative,
                        $method->getName(),
                    ),
                );
            }
        }
    }

    /**
     * Every store is held to the same contract.
     *
     * A cache backend is easy to write and easy to write subtly differently: a
     * stored null that reads as a miss, an expired entry that reads as a value,
     * a namespace flush that takes somebody else's keys with it. The
     * conformance test is where those are pinned, and this makes adding a store
     * without running it a failing test rather than a discovery in production.
     *
     * NullStore is the deliberate exception, and the only one: it conforms to
     * the interface and keeps nothing, which is exactly what it is for.
     */
    public function test_every_store_runs_the_conformance_suite(): void
    {
        $stores = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Cache/Stores/')) {
                $stores[] = \basename($relative, '.php');
            }
        }

        // NullStore keeps nothing, so the contract about keeping things cannot
        // be asked of it. Everything else has to be in the provider.
        $exercised = ['NullStore'];

        foreach (StoreConformanceTest::stores() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        \sort($stores);
        \sort($exercised);

        self::assertSame(
            $stores,
            $exercised,
            'a cache store exists that the conformance test never runs against',
        );
    }

    /**
     * The cache is below everything that uses it.
     *
     * It is handed to the asset manager, the template manager and to modules,
     * and it must not know that any of them exist -- otherwise "which store"
     * stops being a configuration decision and becomes a dependency graph.
     */
    public function test_the_cache_layer_depends_on_nothing_above_it(): void
    {
        $allowed = ['App\\Engine\\Cache', 'App\\Engine\\Support', 'App\\Engine\\Error\\FrameworkException'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cache/')) {
                continue;
            }

            \preg_match_all('/App\\\\Engine\\\\[A-Za-z\\\\]+/', $this->codeWithoutComments($path), $matches);

            foreach ($matches[0] as $reference) {
                $permitted = false;

                foreach ($allowed as $prefix) {
                    if (\str_starts_with($reference, $prefix)) {
                        $permitted = true;
                    }
                }

                self::assertTrue($permitted, \sprintf(
                    '%s refers to %s, which is a layer that should be asking the cache rather than the other way round.',
                    $relative,
                    $reference,
                ));
            }
        }
    }

    // ---- the queue ---------------------------------------------------------------

    /**
     * The queue layer holds infrastructure only.
     */
    public function test_the_queue_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Queue/Backoff.php',
            'engine/Queue/Job.php',
            'engine/Queue/JobOutcome.php',
            'engine/Queue/JobRunner.php',
            'engine/Queue/Queue.php',
            'engine/Queue/QueueException.php',
            'engine/Queue/QueueStore.php',
            'engine/Queue/QueuedJob.php',
            'engine/Queue/Stores/FileStore.php',
            'engine/Queue/Stores/MemoryStore.php',
            'engine/Queue/Stores/SyncStore.php',
            'engine/Queue/Worker.php',
            'engine/Queue/WorkerOptions.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Queue/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * No job ships with the framework.
     *
     * Jobs are module-owned, which is the same rule that keeps business models,
     * schemas and repositories out of engine/. A framework that ships a job has
     * decided what an application's background work is, and the moment one
     * exists the next is easier to justify.
     */
    public function test_no_job_lives_in_the_engine(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_starts_with($relative, 'engine/Queue/')) {
                continue;
            }

            self::assertStringNotContainsString(
                'implements Job',
                $this->codeWithoutComments($path),
                \sprintf('%s declares a job. Jobs belong to the module that owns the work.', $relative),
            );
        }
    }

    /**
     * A job runs where there is no request.
     *
     * The whole point of queueing work is that it happens somewhere else --
     * usually in a process started from a cron line with no HTTP anywhere near
     * it. A queue that reached for Request or Response would be a queue that
     * only works when a browser is waiting, which is the opposite of the
     * requirement.
     */
    public function test_the_queue_layer_cannot_see_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Queue/')) {
                continue;
            }

            foreach (['App\\Engine\\Http', 'Request', 'Response', '$_SERVER', '$_POST'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf('%s refers to %s. A worker runs with no request in sight.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * Work is dispatched through an injected queue, never a static one.
     *
     * Same argument as the cache, which the specification makes for us: a class
     * that queues work has a dependency, and a dependency belongs in a
     * constructor where a test can replace it with a memory store and read what
     * was queued. Queue::push() as a static would take that away.
     *
     * Only the class an application touches is held to this. Named constructors
     * elsewhere in the layer -- WorkerOptions::once(), Backoff::fixed() -- are
     * ways of building a value, not ways of reaching a queue.
     */
    public function test_the_queue_is_never_reached_statically(): void
    {
        foreach ((new \ReflectionClass(\App\Engine\Queue\Queue::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertFalse(
                $method->isStatic(),
                \sprintf(
                    'Queue::%s() is static. Dispatching work is done through an injected Queue.',
                    $method->getName(),
                ),
            );
        }
    }

    /**
     * Every store is held to the same contract.
     *
     * More important here than it is for the cache. The ways queue backends
     * quietly differ -- handing one job to two workers, reserving a delayed job
     * early, losing an abandoned reservation -- all look fine in development
     * and cost money in production, so a store that the conformance suite never
     * runs against is a failing test rather than a discovery later.
     *
     * SyncStore is the deliberate exception: it keeps nothing, because it runs
     * the job instead of storing it.
     */
    public function test_every_queue_store_runs_the_conformance_suite(): void
    {
        $stores = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Queue/Stores/')) {
                $stores[] = \basename($relative, '.php');
            }
        }

        $exercised = ['SyncStore'];

        foreach (QueueStoreConformanceTest::stores() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        \sort($stores);
        \sort($exercised);

        self::assertSame(
            $stores,
            $exercised,
            'a queue store exists that the conformance test never runs against',
        );
    }

    /**
     * Queued work is not readable over HTTP.
     *
     * A job file holds a serialised object -- an invoice id, an email address,
     * whatever the work needs -- and it is written into a directory the web
     * server can see. Worse, it is a payload that something unserialises, so a
     * directory anybody can write to is a directory that can hand a worker an
     * object of its choosing.
     */
    public function test_the_queue_directory_is_denied_by_the_web_server(): void
    {
        foreach (['.htaccess', 'server.php'] as $file) {
            $contents = \file_get_contents($this->basePath($file));
            self::assertIsString($contents);

            self::assertStringContainsString(
                'system',
                $contents,
                \sprintf('%s no longer refuses system/, so queued payloads may be readable.', $file),
            );
        }
    }

    // ---- the scheduler -----------------------------------------------------------

    /**
     * The scheduler layer holds infrastructure only.
     */
    public function test_the_scheduler_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Scheduler/CronExpression.php',
            'engine/Scheduler/Locks/FileLock.php',
            'engine/Scheduler/Locks/MemoryLock.php',
            'engine/Scheduler/Schedule.php',
            'engine/Scheduler/ScheduleCollector.php',
            'engine/Scheduler/ScheduleLock.php',
            'engine/Scheduler/ScheduleOutcome.php',
            'engine/Scheduler/ScheduleRegistry.php',
            'engine/Scheduler/ScheduleResult.php',
            'engine/Scheduler/ScheduleTarget.php',
            'engine/Scheduler/Scheduler.php',
            'engine/Scheduler/SchedulerException.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Scheduler/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * No schedule ships with the framework.
     *
     * The same rule that keeps jobs, models and repositories out of engine/,
     * and the one with the sharpest consequence: a framework that ships a
     * schedule has decided that something runs on somebody else's machine at an
     * hour they did not choose. Every schedule in an application is declared by
     * a module, which is a file its author wrote and can delete.
     */
    public function test_no_schedule_is_declared_in_the_engine(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_starts_with($relative, 'engine/Scheduler/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['ScheduleCollector $', '->dailyAt(', '->everyMinute(', '->cron('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s declares scheduled work. When something runs is the application\'s decision.', $relative),
                );
            }
        }
    }

    /**
     * A scheduled task runs where there is no request.
     *
     * The strongest form of the rule the queue already has. Scheduled work
     * happens in a process cron started at 3am with no browser anywhere near
     * it, so a scheduler that reached for a Request would work only when run by
     * hand -- which is exactly the condition under which somebody would test it
     * and conclude it was fine.
     */
    public function test_the_scheduler_layer_cannot_see_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/')) {
                continue;
            }

            foreach (['App\\Engine\\Http', 'Request', 'Response', '$_SERVER', '$_POST'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf('%s refers to %s. A scheduled task runs with no request in sight.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * Nothing in the scheduler sleeps, loops on the clock, or shells out.
     *
     * Three temptations, one rule. sleep() and a while-true would turn this
     * into a daemon -- a process to supervise, restart on deployment and watch
     * for having silently died -- when cron already solves that and is already
     * installed. exec() would turn a scheduled command into a second PHP
     * process with its own configuration, its own log and a command line that
     * has to be quoted correctly on two operating systems.
     *
     * The check is a token scan, so a docblock explaining why there is no
     * sleep() does not trip the rule that says there is none.
     */
    public function test_the_scheduler_is_not_a_daemon(): void
    {
        $forbidden = ['sleep', 'usleep', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/')) {
                continue;
            }

            foreach ($this->globalFunctionCalls($path) as $call) {
                self::assertNotContains(
                    $call,
                    $forbidden,
                    \sprintf(
                        '%s calls %s(). The scheduler is cron\'s shape: it decides, it runs what is due, '
                        . 'it exits. It does not hold a process open and it does not start one.',
                        $relative,
                        $call,
                    ),
                );
            }
        }
    }

    /**
     * Every lock is held to the same contract.
     *
     * The cache and queue conformance suites again, and here the stakes are at
     * their plainest: a lock implementation that is not atomic, or that forgets
     * to expire, fails in exactly one way -- two copies of the billing run --
     * and it fails at three in the morning on a machine under load, which is
     * the one condition a developer never reproduces.
     */
    public function test_every_schedule_lock_runs_the_conformance_suite(): void
    {
        $locks = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Scheduler/Locks/')) {
                $locks[] = \basename($relative, '.php');
            }
        }

        $exercised = [];

        foreach (LockConformanceTest::locks() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        \sort($locks);
        \sort($exercised);

        self::assertSame(
            $locks,
            $exercised,
            'a schedule lock exists that the conformance test never runs against',
        );
    }

    /**
     * There is no lock that does not lock.
     *
     * CacheStore has a NullStore and QueueStore has a sync store, because "do
     * not cache" and "run it here" are both things somebody legitimately wants.
     * The equivalent here would be a lock that always says yes, and it is not
     * shipped on purpose: it has exactly one behaviour, two copies of a task
     * running at once, and its existence would make the most dangerous
     * configuration in the scheduler the easiest one to reach for.
     *
     * A schedule that may safely overlap says so with ->allowOverlapping(),
     * where the decision sits next to the task it affects and is visible in
     * `schedule:list`.
     */
    public function test_no_lock_implementation_is_a_no_op(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/Locks/')) {
                continue;
            }

            self::assertStringNotContainsString(
                'Null',
                \basename($relative),
                \sprintf('%s looks like a lock that grants everything. See this test for why there is none.', $relative),
            );
        }
    }

    /**
     * Only the bridge in the logging layer knows the scheduler exists.
     *
     * ErrorLog's rule, applied to ScheduleLog. The scheduler announces on
     * schedule.finished and something listens; if the scheduler itself reached
     * for a Logger, "logging is independent" would stop being true the second
     * time it was claimed.
     */
    public function test_the_scheduler_does_not_reach_for_a_logger(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/')) {
                continue;
            }

            foreach (['Logging', 'Logger', 'LogManager'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s refers to %s. Schedules are announced on schedule.finished; '
                        . 'Logging\\ScheduleLog listens to it and is not a dependency of this layer.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Schedule locks are not readable over HTTP.
     *
     * A lock file names a task, a host and a process id, which is a small but
     * real disclosure of what a machine does at night and when it is busy.
     * Worse is the other direction: a lock directory anybody could write to is
     * a directory where anybody could hold every schedule permanently, which
     * stops the billing run without producing a single error.
     */
    public function test_the_schedule_directory_is_denied_by_the_web_server(): void
    {
        foreach (['.htaccess', 'server.php'] as $file) {
            $contents = \file_get_contents($this->basePath($file));
            self::assertIsString($contents);

            self::assertStringContainsString(
                'system',
                $contents,
                \sprintf('%s no longer refuses system/, so schedule locks may be writable.', $file),
            );
        }
    }

    // ---- configuration -------------------------------------------------------

    /**
     * The configuration layer holds infrastructure only.
     */
    public function test_the_configuration_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Config/Config.php',
            'engine/Config/ConfigCache.php',
            'engine/Config/ConfigLoader.php',
            'engine/Config/ConfigurationException.php',
            'engine/Config/DotEnv.php',
            'engine/Config/Env.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Config/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * One file reads the environment.
     *
     * Everything in the environment is text, and turning text into a setting
     * has real edge cases -- "false" is a non-empty string, an empty variable is
     * one somebody meant to fill in. Done in eighteen places, two of them end up
     * disagreeing, and the disagreement is found in production.
     *
     * It also buys the configuration cache its invalidation: because every read
     * goes through one class, that class can record what it was asked, and that
     * record is the fingerprint which notices the environment has moved.
     */
    public function test_only_one_file_reads_the_environment(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if ($relative === 'engine/Config/Env.php') {
                continue;
            }

            self::assertNotContains(
                'getenv',
                $this->globalFunctionCalls($path),
                \sprintf(
                    '%s reads the environment directly. Env is the one place that does, which is what '
                    . 'keeps the conversions consistent and lets the cache know when they have changed.',
                    $relative,
                ),
            );

            // DotEnv is the writer: a .env file fills gaps in $_ENV, which is
            // visible to this process only and costs nothing.
            if ($relative === 'engine/Config/DotEnv.php') {
                continue;
            }

            self::assertStringNotContainsString(
                '$_ENV',
                $this->codeWithoutComments($path),
                \sprintf('%s reaches into $_ENV rather than asking Env.', $relative),
            );
        }
    }

    /**
     * Every variable the engine reads is written down in .env.example.
     *
     * The environment is the one part of the configuration surface with no
     * defaults file to read: config/ shows what an application has chosen, and
     * Bootstrap::defaults() shows what the framework assumes, but the list of
     * variable names exists only inside the calls that read them. Somebody
     * setting up a deployment needs that list, and a list maintained by hand is
     * a list that is wrong within two phases.
     */
    public function test_every_environment_variable_is_documented(): void
    {
        $documented = \file_get_contents($this->basePath('.env.example'));
        self::assertIsString($documented, 'the environment surface has no documentation at all');

        $names = [];

        foreach ($this->engineFiles() as $path) {
            \preg_match_all(
                '/Env::(?:raw|has|string|bool|int|list)\(\s*\'([A-Z][A-Z0-9_]*)\'/',
                $this->codeWithoutComments($path),
                $matches,
            );

            foreach ($matches[1] as $name) {
                $names[$name] = $this->relative($path);
            }
        }

        self::assertNotSame([], $names, 'no environment variable is read anywhere, which cannot be right');

        foreach ($names as $name => $where) {
            self::assertStringContainsString(
                $name,
                $documented,
                \sprintf('%s reads %s, which .env.example does not mention.', $where, $name),
            );
        }
    }

    /**
     * Nothing writes to the environment.
     *
     * putenv() is documented as not thread-safe, this framework is developed on
     * a ZTS build, and a process that mutates its own environment is a process
     * whose configuration depends on what has already run.
     */
    public function test_nothing_writes_to_the_environment(): void
    {
        foreach ($this->engineFiles() as $path) {
            self::assertNotContains(
                'putenv',
                $this->globalFunctionCalls($path),
                $this->relative($path) . ' calls putenv(), which is not thread-safe and mutates a global.',
            );
        }
    }

    /**
     * Configuration is the bottom of the stack and depends on nothing above it.
     *
     * It is built before the container exists, before a module has been found
     * and before anything can be logged, so a reference to a layer above is
     * either a cycle or a thing that is not there yet. Support is allowed
     * because Path is string handling, and the exception base class is allowed
     * because an exception has to have one.
     */
    public function test_the_configuration_layer_depends_on_nothing_above_it(): void
    {
        $allowed = ['App\\Engine\\Config', 'App\\Engine\\Support', 'App\\Engine\\Error\\FrameworkException'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Config/')) {
                continue;
            }

            \preg_match_all('/App\\\\Engine\\\\[A-Za-z\\\\]+/', $this->codeWithoutComments($path), $matches);

            foreach ($matches[0] as $reference) {
                $permitted = false;

                foreach ($allowed as $prefix) {
                    if (\str_starts_with($reference, $prefix)) {
                        $permitted = true;
                    }
                }

                self::assertTrue($permitted, \sprintf(
                    '%s refers to %s, which is read after this layer rather than before it.',
                    $relative,
                    $reference,
                ));
            }
        }
    }

    /**
     * The compiled configuration is not readable over HTTP.
     *
     * It is the one file in the project that holds every setting at once,
     * database credentials included, and it is written into a directory the web
     * server can see. Both halves are checked here: where it is written, and
     * what refuses it.
     */
    public function test_the_configuration_cache_is_denied_by_the_web_server(): void
    {
        self::assertStringContainsString(
            'system',
            ConfigCache::file($this->basePath()),
            'the cache has moved out of system/, which is the directory the deny rules name',
        );

        foreach (['.htaccess', 'server.php'] as $file) {
            $contents = \file_get_contents($this->basePath($file));
            self::assertIsString($contents);

            self::assertStringContainsString(
                'system',
                $contents,
                \sprintf('%s no longer refuses system/, so the compiled configuration may be readable.', $file),
            );
        }
    }
}
