<?php

declare(strict_types=1);

namespace App\Engine\Bootstrap;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetServer;
use App\Engine\Asset\AssetSource;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheStore;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\FileStore;
use App\Engine\Cache\Stores\NullStore;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\CoreCommands;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Config\ConfigLoader;
use App\Engine\Config\ConfigurationException;
use App\Engine\Config\DotEnv;
use App\Engine\Config\Env;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Core\HttpKernel;
use App\Engine\Database\ConnectionManager;
use App\Engine\Dispatch\Dispatcher;
use App\Engine\Error\ErrorHandler;
use App\Engine\Error\ErrorPage;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Logging\Context;
use App\Engine\Logging\ErrorLog;
use App\Engine\Logging\Level;
use App\Engine\Logging\Logger;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogWriter;
use App\Engine\Logging\ScheduleLog;
use App\Engine\Logging\Writers\FileWriter;
use App\Engine\Logging\Writers\StreamWriter;
use App\Engine\Logging\Writers\SyslogWriter;
use App\Engine\Model\ModelManager;
use App\Engine\Model\RelationManager;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Queue\Backoff;
use App\Engine\Queue\JobRunner;
use App\Engine\Queue\Queue;
use App\Engine\Queue\QueueStore;
use App\Engine\Queue\Stores\FileStore as QueueFileStore;
use App\Engine\Queue\Stores\MemoryStore;
use App\Engine\Queue\Stores\SyncStore;
use App\Engine\Queue\Worker;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\Locks\FileLock;
use App\Engine\Scheduler\Locks\MemoryLock;
use App\Engine\Security\CounterStore;
use App\Engine\Security\Counters\FileStore as CounterFileStore;
use App\Engine\Security\Counters\MemoryStore as CounterMemoryStore;
use App\Engine\Security\Csrf;
use App\Engine\Security\Guard;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\RequestLimits;
use App\Engine\Security\SecurityHeaders;
use App\Engine\Security\Signer;
use App\Engine\Scheduler\ScheduleLock;
use App\Engine\Scheduler\Scheduler;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Support\Extensions;
use App\Engine\Support\Path;
use App\Engine\Template\Escaper;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Engine\Template\TwigTemplateEngine;

/**
 * Builds the container and hands back an application that has not run yet.
 *
 * Pure wiring: no discovery, no routing, no I/O beyond reading the environment.
 * Everything that could fail interestingly happens in Application::boot(),
 * which makes a bootstrap failure easy to tell apart from a module failure.
 *
 * The entry script that calls this lives at engine/bootstrap.php rather than
 * inside this directory, because Windows filesystems are case-insensitive and
 * "bootstrap.php" next to "Bootstrap.php" is the same file there.
 */
final class Bootstrap
{
    /** @param array<string, mixed> $config overrides merged over the defaults */
    public static function create(string $basePath, ExecutionContext $context, array $config = []): Application
    {
        $container = new Container();
        $settings = new Config(self::settings($basePath, $config));

        // Before anything formats a date. An ERP that books an invoice against
        // the wrong day because the server was installed in a different
        // country is a support call nobody enjoys, and PHP's own default is
        // whatever the ini file says.
        self::applyTimezone($settings);

        $hooks = new HookEngine();
        $filters = new FilterEngine((bool) $settings->get('app.debug', false));
        $router = new Router();

        // The cache, before anything that might want one. It is built from
        // configuration and it is never asked for statically: everything that
        // caches is handed its own namespace here, which is what makes each of
        // them testable with a store that keeps nothing.
        //
        // Note which caches are NOT this one. Module discovery and the compiled
        // configuration keep their own var_export files, and for good reasons:
        // configuration is read before this object can exist, and both hold
        // plain data that changes only at deploy time, which is exactly what
        // opcache is better at than anything written here could be.
        $cache = self::cache($settings, $basePath);

        // Which template is in use. One name, and it decides both where views
        // are found and which assets the unnamed template namespace points at.
        $template = self::activeTemplate($settings);

        // The asset layer. The registry starts with the two sources that are
        // facts about the layout rather than declarations by anybody: the
        // application's own assets/ and the active template's. Module sources
        // are published during module registration, because that is when the
        // set of modules is known.
        //
        // asset()->template('css/app.css') means the ACTIVE template, which is
        // why the unnamed namespace resolves to templates/<active>/assets/
        // rather than to templates/assets/. A named one, template('admin', ...),
        // reaches templates/admin/assets/ the same way.
        $assets = new AssetRegistry();
        $assets->register(new AssetSource(AssetKind::Core, null, $basePath . '/assets'));
        $assets->register(new AssetSource(
            AssetKind::Template,
            null,
            Path::join($basePath, 'templates', $template, 'assets'),
        ));
        $assets->register(new AssetSource(
            AssetKind::Template,
            $template,
            Path::join($basePath, 'templates', $template, 'assets'),
        ));

        $manager = new AssetManager(
            $assets,
            $resolver = new AssetResolver(),
            self::assetBaseUrl($settings, $context),
            AssetVersioning::parse($settings->get('assets.versioning')),
            (bool) $settings->get('assets.manifests', true),
            // Strict in development only. A missing asset should be loud while
            // somebody is writing the page and quiet once it is in production,
            // where a broken stylesheet is a smaller problem than a blank page.
            (bool) $settings->get('app.debug', false),
            // Version tokens survive the request that computed them, which for
            // content hashing is the difference between hashing every asset on
            // every page and hashing it once per deployment. Not in debug: a
            // stylesheet being edited has to bust its own URL.
            $cache->namespace('assets'),
        );

        // The template layer. The active template's views/ is registered at
        // override precedence, which is what lets a site replace a module's
        // markup by dropping a file into its own theme; module directories are
        // registered during module registration, below that.
        $views = new TemplateRegistry();
        $views->add(null, Path::join($basePath, 'templates', $template, 'views'), TemplateSource::OVERRIDE);

        // Template resolution is a filesystem search per name, and a page with
        // a layout and six partials does it seven times. The manager memoises
        // within a request on its own; the cache is what carries the answer
        // into the next one.
        $templates = new TemplateManager($views, $manager, new Escaper(), $cache->namespace('templates'));

        // PHP always. It needs nothing installed, which is what makes the Twig
        // engine genuinely optional rather than nominally so.
        $templates->addEngine(new PhpTemplateEngine());

        if (TwigTemplateEngine::isAvailable()) {
            $templates->addEngine(new TwigTemplateEngine(
                $views,
                (bool) $settings->get('templates.cache', false)
                    ? Path::join($basePath, 'system', 'Cache', 'templates')
                    : null,
                (bool) $settings->get('app.debug', false),
            ));
        }

        // The error handler is built here rather than earlier because it
        // renders through the template layer: an application that ships a
        // views/errors/404 template gets its own page, in its own layout,
        // instead of the framework's. It is given the real hook engine, because
        // error.reported is how the logging phase will hear about failures and
        // a private engine would fire into nothing.
        $errors = new ErrorHandler($settings, $hooks, new ErrorPage($templates));

        // The log. It listens to the error handler rather than being called by
        // it: error.reported is a hook, ErrorLog is an ordinary listener, and
        // the dependency runs one way only. Remove this line and errors stop
        // being logged without anything else changing, which is what
        // "logging must be independent from error rendering" has to mean if it
        // means anything.
        $logs = self::logging($settings, $basePath);
        $hooks->add('error.reported', (new ErrorLog($logs))(...), 10, 'engine');

        // The global helpers are a bridge to these four instances, and to
        // nothing else. See Support\Extensions for why that is not a facade.
        Extensions::init($hooks, $filters, $manager, $templates);

        $container->instance(Container::class, $container);
        $container->instance(Config::class, $settings);
        $container->instance(Cache::class, $cache);
        $container->instance(CacheStore::class, $cache->store());
        $container->instance(HookEngine::class, $hooks);
        $container->instance(FilterEngine::class, $filters);
        $container->instance(Router::class, $router);
        $container->instance(ErrorHandler::class, $errors);
        $container->instance(LogManager::class, $logs);
        // Injecting a Logger gets the default channel; a module that wants its
        // own asks the manager for it by name.
        $container->instance(Logger::class, $logs->channel());
        $container->instance(ExecutionContext::class, $context);
        $container->instance(AssetRegistry::class, $assets);
        $container->instance(AssetResolver::class, $resolver);
        $container->instance(AssetManager::class, $manager);
        $container->instance(TemplateRegistry::class, $views);
        $container->instance(TemplateManager::class, $templates);

        // The console. The framework's own commands are registered here through
        // the same collector a module uses, so there is nothing the kernel
        // treats as a built-in. Registering them in an HTTP request too is
        // deliberate: a half-populated registry that depends on how the process
        // started is a debugging trap worth more than the seven objects it
        // would save.
        $commands = new CommandRegistry();
        CoreCommands::register($commands);

        $container->instance(CommandRegistry::class, $commands);
        $container->singleton(CommandDispatcher::class);

        // The schedules. Empty until modules register; the registry exists
        // first because the module manager fills it and the console reads it.
        $schedules = new ScheduleRegistry();
        $container->instance(ScheduleRegistry::class, $schedules);

        // Lazy on purpose: constructing this opens a stream, and an HTTP
        // request has no business holding one.
        $container->singleton(Output::class);

        $container->instance(AssetServer::class, new AssetServer(
            $assets,
            $resolver,
            $filters,
            (bool) $settings->get('app.debug', false),
            \is_int($maxAge = $settings->get('assets.max_age', AssetServer::IMMUTABLE_MAX_AGE)) ? $maxAge : null,
        ));

        // Model infrastructure. Both are stateful for the life of the request
        // -- one holds the identity map, the other the relation declarations --
        // so they are shared rather than rebuilt per injection.
        $container->singleton(ModelManager::class);
        $container->singleton(RelationManager::class);

        // The configured connections. Nothing is opened here and nothing is
        // opened when this resolves either: a Connection dials on first use, so
        // a request that never reads the database never opens a socket.
        //
        // Note what is NOT bound: DataSource. Which source an application reads
        // through is an application decision, made in a module, not something
        // the framework decides on its behalf.
        $container->singleton(ConnectionManager::class, static function () use ($settings): ConnectionManager {
            /** @var mixed $connections */
            $connections = $settings->get('database.connections', []);
            /** @var mixed $default */
            $default = $settings->get('database.default');

            return ConnectionManager::fromArray(
                \is_array($connections) ? $connections : [],
                \is_string($default) ? $default : null,
            );
        });

        $container->singleton(Dispatcher::class);
        $container->singleton(HttpKernel::class);
        $container->singleton(ConsoleKernel::class);

        $container->instance(ModuleManager::class, new ModuleManager(
            $container,
            $settings,
            $router,
            $hooks,
            $filters,
            $assets,
            $views,
            $commands,
            $schedules,
            new ModuleRegistry(),
            $basePath,
        ));

        // The queue. Its runner needs the container, and the sync store needs
        // the runner, so this is built here rather than resolved lazily -- and
        // the store is chosen exactly the way the cache's and the log's are,
        // from configuration, because where background work waits is a
        // deployment decision.
        $runner = new JobRunner($container, $hooks);
        $queue = new Queue(
            self::queueStore($settings, $basePath, $runner),
            $runner,
            $hooks,
            $settings->string('queue.queue', Queue::DEFAULT) ?? Queue::DEFAULT,
        );

        $container->instance(JobRunner::class, $runner);
        $container->instance(Queue::class, $queue);
        $container->instance(QueueStore::class, $queue->store());
        $container->instance(Backoff::class, self::backoff($settings));
        $container->singleton(Worker::class);

        // The scheduler. It is built after the queue because a scheduled job is
        // pushed rather than run, and after the console because a scheduled
        // command is dispatched through the same registry a person types at.
        $lock = self::scheduleLock($settings, $basePath);

        $container->instance(ScheduleLock::class, $lock);

        // Lazy, unlike the queue: a web request never schedules anything, and
        // resolving this one would pull the command dispatcher in behind it.
        $container->singleton(Scheduler::class, static fn(): Scheduler => new Scheduler(
            $schedules,
            $lock,
            $container,
            $commands,
            $container->get(CommandDispatcher::class),
            $queue,
            $hooks,
            self::schedulerTimezone($settings),
            $settings->int('scheduler.lock_ttl', Scheduler::DEFAULT_LOCK_SECONDS)
                ?? Scheduler::DEFAULT_LOCK_SECONDS,
        ));

        // The second listener on the logging side, for the same reason as the
        // first: work that runs with nobody watching has to leave a record, or
        // a schedule that stopped six weeks ago is indistinguishable from one
        // with nothing to do.
        $hooks->add('schedule.finished', (new ScheduleLog($logs))(...), 10, 'engine');

        // Security. Built here rather than resolved lazily because its whole
        // job is to be attached to the request before anything else runs; a
        // guard that is only constructed when something asks for it is a guard
        // that never runs.
        $signer = Signer::fromEnvironment($settings->string('security.key'));
        $csrf = new Csrf(
            $signer,
            $settings->bool('security.csrf.check_origin', true),
            $settings->int('security.csrf.lifetime', 7200) ?? 7200,
        );
        $limiter = new RateLimiter(self::counters($settings, $basePath));
        $limits = new RequestLimits(
            $settings->int('security.max_request_bytes', RequestLimits::DEFAULT_BYTES)
                ?? RequestLimits::DEFAULT_BYTES,
        );
        $guard = new Guard($csrf, $limiter, $limits, $settings->bool('security.csrf.enabled', true));

        $container->instance(Signer::class, $signer);
        $container->instance(Csrf::class, $csrf);
        $container->instance(RateLimiter::class, $limiter);
        $container->instance(CounterStore::class, $limiter->store());
        $container->instance(RequestLimits::class, $limits);
        $container->instance(Guard::class, $guard);

        $headers = self::securityHeaders($settings);
        $container->instance(SecurityHeaders::class, $headers);

        // Three listeners, on hooks the kernel already fires. This is what the
        // ban on middleware looks like in practice: named events rather than a
        // pipeline, and a refusal is an HttpException the kernel already knows
        // how to render.
        //
        // The request limit runs at priority 1 so that it is ahead of anything
        // an application attaches -- there is no point authenticating a body
        // that is about to be refused for being too large.
        $hooks->add('request.received', $guard->onRequest(...), 1, 'engine');
        $hooks->add('dispatch.before', $guard->onDispatch(...), 5, 'engine');
        $filters->add('response.instance', $guard->onResponse(...), 20, 'engine');
        // Last, so that it sees every header a module decided to set and does
        // not overwrite one. See SecurityHeaders.
        $filters->add('response.instance', $headers(...), 90, 'engine');

        $application = new Application($container, $context, $basePath);
        $container->instance(Application::class, $application);

        if ((bool) $settings->get('app.handle_errors', true)) {
            $errors->register();
        }

        return $application;
    }


    /**
     * The configuration, assembled from every source, in order.
     *
     * Defaults, then config/*.php, then whatever the caller passed in -- which
     * is a test forcing module roots, or an embedding application that builds
     * its own. The environment is not a fourth layer here: config files read it
     * themselves, through Env, so that the precedence is visible in the file
     * somebody is looking at rather than hidden in this merge.
     *
     * A .env file is loaded first if there is one, and fills gaps in the real
     * environment rather than replacing it. See DotEnv.
     *
     * The cache short-circuits the middle of that: if a valid one exists it is
     * the defaults and the files already merged. Caller overrides are applied
     * on top either way, so a cache built on a live server cannot change what a
     * test asked for. It is used whenever it exists rather than behind a
     * setting, because the setting would have to be read out of the
     * configuration this is building.
     *
     * @param array<string, mixed> $overrides
     * @param bool                 $cached  false forces a read from disk, which is what
     *                                      the command that builds the cache needs
     *
     * @return array<string, mixed>
     */
    public static function settings(string $basePath, array $overrides = [], bool $cached = true): array
    {
        DotEnv::load(Path::join($basePath, DotEnv::FILE));

        $items = ($cached ? ConfigCache::read(ConfigCache::file($basePath)) : null)
            ?? self::merge(self::defaults(), (new ConfigLoader(Path::join($basePath, ConfigLoader::DIRECTORY)))->load());

        return self::merge($items, $overrides);
    }

    /**
     * The configured timezone, checked rather than trusted.
     *
     * date_default_timezone_set() answers false and raises a warning for an
     * identifier it does not know, and then every date in the application is
     * silently in whatever zone the ini file named. A boot that stops and says
     * which value is wrong costs less than that.
     */
    private static function applyTimezone(Config $settings): void
    {
        $timezone = $settings->string('app.timezone', 'UTC') ?? 'UTC';

        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw ConfigurationException::unknownTimezone($timezone);
        }

        \date_default_timezone_set($timezone);
    }

    /**
     * The cache, assembled from configuration.
     *
     * Which store is a deployment decision, exactly like which log writers, and
     * for the same reason: one application runs on one machine and wants files,
     * the next runs on four and wants something they share. Neither is a thing
     * the code should have to know.
     *
     * The default is memory, which lives for one process. It is a real cache --
     * a page that resolves the same template from six partials pays for one
     * search -- and it writes nothing to a disk nobody asked about. Crossing
     * requests is what CACHE_STORE=file opts into.
     *
     * An unknown name falls back to memory rather than failing: a typo in a
     * deployment's configuration should not stop an application from starting,
     * and cache:clear reports which store is actually in use.
     */
    private static function cache(Config $settings, string $basePath): Cache
    {
        $ttl = $settings->int('cache.ttl');

        $store = match ($settings->string('cache.store', 'array')) {
            'file' => new FileStore(Path::join($basePath, 'system', 'Cache', 'data')),
            'null', 'none' => new NullStore(),
            default => new ArrayStore(),
        };

        return new Cache($store, $settings->string('cache.namespace', '') ?? '', $ttl);
    }

    /**
     * Where queued work waits.
     *
     * Sync by default, which is the absence of a queue rather than a queue:
     * jobs run where they are dispatched and exceptions reach the caller. The
     * alternatives both have a way of losing work quietly on a machine nobody
     * has set up yet -- memory drops the job at the end of the request, and a
     * file queue holds it until a worker that may not exist comes along -- and
     * an application that works before anybody has read the deployment notes is
     * worth more than a default that is technically a queue.
     */
    private static function queueStore(Config $settings, string $basePath, JobRunner $runner): QueueStore
    {
        return match ($settings->string('queue.store', 'sync')) {
            'file' => new QueueFileStore(Path::join($basePath, 'system', 'Queue')),
            'memory', 'array' => new MemoryStore(),
            default => new SyncStore($runner),
        };
    }

    /**
     * Where rate-limit counts live.
     *
     * A file by default and not the cache, for the reason CounterStore gives:
     * a cache may forget, and a counter that forgets is not a limit. Memory is
     * offered for tests and is never right in production -- a web request is a
     * process that ends, so counts held in it have already been forgotten by
     * the time the next request arrives.
     *
     * An unknown name falls back to the file store rather than to memory,
     * because a typo in a deployment's configuration must not quietly turn
     * rate limiting off.
     */
    private static function counters(Config $settings, string $basePath): CounterStore
    {
        return match ($settings->string('security.counters', 'file')) {
            'memory', 'array' => new CounterMemoryStore(),
            default => new CounterFileStore(Path::join($basePath, 'system', 'Security')),
        };
    }

    /**
     * The headers every response carries.
     *
     * Note what is not defaulted: a Content-Security-Policy. A generic one is
     * either too loose to be a policy or too strict to survive the first page
     * with an inline handler, and the one that gets turned off in a hurry is
     * worse than the one that was never claimed. security:check says so out
     * loud rather than leaving the absence to be noticed.
     */
    private static function securityHeaders(Config $settings): SecurityHeaders
    {
        $extra = [];

        foreach ($settings->array('security.headers.extra') as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $extra[$name] = $value;
            }
        }

        return new SecurityHeaders(
            $extra,
            $settings->string('security.headers.csp', '') ?? '',
            $settings->int('security.headers.hsts_days', 0) ?? 0,
            $settings->bool('security.headers.hsts_subdomains', false),
        );
    }

    /**
     * Where a schedule's lock lives.
     *
     * A file by default, and unlike the cache and the queue there is no
     * meaningful alternative on one machine. Memory is offered for tests and is
     * the one configuration that must never reach production: the scheduler's
     * process is started fresh by cron and exits, so a lock held in its memory
     * has never excluded anything. An unknown name falls back to the file store
     * rather than to memory, because a typo in a deployment's configuration
     * should cost nothing rather than silently switch overlap protection off.
     */
    private static function scheduleLock(Config $settings, string $basePath): ScheduleLock
    {
        return match ($settings->string('scheduler.lock', 'file')) {
            'memory', 'array' => new MemoryLock(),
            default => new FileLock(Path::join($basePath, 'system', 'Schedule')),
        };
    }

    /**
     * The clock a schedule is read against.
     *
     * app.timezone unless something says otherwise, so "daily at 02:00" means
     * the same thing here as every other date in the application. It is
     * separable because the two genuinely differ: an application may present
     * itself in a local timezone while its unattended work runs on UTC, which
     * is the only clock that does not repeat an hour twice a year.
     */
    private static function schedulerTimezone(Config $settings): string
    {
        foreach ([$settings->string('scheduler.timezone'), $settings->string('app.timezone')] as $candidate) {
            if ($candidate !== null && \in_array($candidate, \timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private static function backoff(Config $settings): Backoff
    {
        return new Backoff(
            base: $settings->int('queue.backoff.base', 5) ?? 5,
            multiplier: $settings->int('queue.backoff.multiplier', 2) ?? 2,
            cap: $settings->int('queue.backoff.cap', 600) ?? 600,
            jitter: $settings->float('queue.backoff.jitter', 0.0) ?? 0.0,
        );
    }

    /**
     * The log, assembled from configuration.
     *
     * Writers are named in configuration rather than constructed by an
     * application, because where records go is a deployment decision and a
     * deployment does not get to edit code. The three that exist need nothing
     * installed: a file under system/Logs, an open stream for a container, and
     * the machine's own system logger.
     *
     * Nothing is opened here. FileWriter opens on its first record, so a request
     * that logs nothing touches no file, and syslog connects on demand.
     */
    private static function logging(Config $settings, string $basePath): LogManager
    {
        $debug = (bool) $settings->get('app.debug', false);

        // Resolved once and handed to both. Working it out separately in the
        // manager and in each writer is how the two end up disagreeing about
        // what "the configured level" means.
        $minimum = Level::fromName($settings->get('logging.level'), $debug ? Level::Debug : Level::Info);

        $logs = new LogManager($minimum, new Context(self::stringList($settings->get('logging.redact'))));

        foreach (self::stringList($settings->get('logging.writers')) as $name) {
            $writer = self::writer($name, $minimum, $settings, $basePath);

            if ($writer !== null) {
                $logs->add($writer);
            }
        }

        return $logs;
    }

    private static function writer(string $name, Level $minimum, Config $settings, string $basePath): ?LogWriter
    {
        return match ($name) {
            'file' => new FileWriter(
                Path::join($basePath, 'system', 'Logs'),
                $minimum,
                \is_string($prefix = $settings->get('logging.file.prefix', 'app')) ? $prefix : 'app',
                \is_int($days = $settings->get('logging.file.retention_days', 0)) ? $days : 0,
            ),
            // php://stderr rather than STDERR: the constant only exists under
            // the CLI SAPI, and a container running php-fpm wants this too.
            'stderr' => \is_resource($stream = @\fopen('php://stderr', 'w'))
                ? new StreamWriter($stream, $minimum, name: 'stderr')
                : null,
            'syslog' => new SyslogWriter(
                \is_string($identity = $settings->get('logging.syslog.identity', 'app')) ? $identity : 'app',
                $minimum,
            ),
            // An unknown name is ignored rather than fatal. A typo in a
            // deployment's configuration must not stop the application from
            // starting, and log:status reports what is actually attached.
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter($value, \is_string(...)));
    }

    /**
     * The framework's own defaults: every key it reads, with a working value.
     *
     * This stays the single description of the configuration surface even now
     * that config/ exists. A file overrides what it names and nothing else, so
     * an application configures the two settings it cares about rather than
     * copying a hundred lines it does not, and a key added here reaches every
     * existing installation without anybody editing a file.
     *
     * Environment variables are read here rather than merged as a separate
     * layer, because a default that comes from the environment is still a
     * default -- APP_DEBUG is what to do when no file says otherwise.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function defaults(array $overrides = []): array
    {
        $defaults = [
            'app' => [
                'env' => Env::string('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                // Explicit, and UTC unless somebody says otherwise. PHP falls
                // back to whatever php.ini names, which differs between a
                // developer's machine and the server, and a billing period that
                // changes shape depending on where the code is running is a bug
                // found at month end.
                'timezone' => Env::string('APP_TIMEZONE', 'UTC'),
                // Tests assert on responses rather than on PHP's own error
                // output, so they switch this off instead of having the handler
                // fight the test runner for set_error_handler().
                'handle_errors' => true,
            ],
            'http' => [
                // null means "derive from SCRIPT_NAME", which is right for both
                // Apache in a subdirectory and the built-in server.
                'base_path' => null,
                'trusted_proxies' => [],
            ],
            'database' => [
                // Named connections, so that a reporting replica or a legacy
                // system being migrated from is a name rather than a special
                // case. The list is usually empty here and filled by the
                // application in config/database.php; DB_DSN covers the
                // single-database case with no file at all.
                'default' => Env::string('DB_CONNECTION', 'default'),
                'connections' => self::connectionsFromEnv(),
            ],
            'assets' => [
                // null means "the same prefix the application is served under",
                // which is what a subdirectory install needs. A CDN origin goes
                // here instead.
                'url' => null,
                // Content hashing by default; see AssetVersioning for why not
                // modification time.
                'versioning' => AssetVersioning::Content->value,
                'manifests' => true,
                'max_age' => AssetServer::IMMUTABLE_MAX_AGE,
            ],
            'cache' => [
                // Memory by default: real within a request, gone after it, and
                // it writes nothing anywhere. "file" crosses requests, "null"
                // takes the cache out of the picture entirely, which is what
                // somebody chasing a stale value wants.
                'store' => Env::string('CACHE_STORE', 'array'),
                // The lifetime an entry gets when its caller does not say. An
                // hour rather than forever, because an entry nobody can name
                // is an entry nobody will clear.
                'ttl' => Env::int('CACHE_TTL', 3600),
                // A prefix for every key, for when several installations share
                // one backend. Nothing needs it while the store is a file.
                'namespace' => '',
            ],
            'queue' => [
                // sync, file or memory. See queueStore() for why sync is the
                // default and what the other two cost on a machine with no
                // worker running.
                'store' => Env::string('QUEUE_STORE', 'sync'),
                'queue' => Env::string('QUEUE_NAME', Queue::DEFAULT),
                // What queue:work uses when nothing is said on the command line.
                'tries' => Env::int('QUEUE_TRIES', 3),
                'timeout' => Env::int('QUEUE_TIMEOUT', 60),
                'sleep' => 1,
                // Waiting is the right answer to most reasons a job fails: a
                // rate limit, a failover, a host restarting. See Queue\Backoff.
                'backoff' => [
                    'base' => 5,
                    'multiplier' => 2,
                    'cap' => 600,
                    // Worth raising above zero for anything that talks to a
                    // shared service, so that jobs which failed together do not
                    // retry together.
                    'jitter' => 0.0,
                ],
            ],
            'security' => [
                // The one secret the framework itself needs. Without it tokens
                // are unsigned -- still double-submit, still cross-origin
                // safe, but a sibling subdomain could plant a matching pair.
                // security:check reports which mode is running.
                'key' => Env::string('APP_KEY'),
                'csrf' => [
                    'enabled' => Env::bool('CSRF_ENABLED', true),
                    // Checked when present, absent means "cannot tell". See
                    // Security\Csrf for why Referer is not checked too.
                    'check_origin' => true,
                    // Two hours. Long enough that an ordinary form does not go
                    // stale while somebody writes; short enough to matter.
                    'lifetime' => 7200,
                ],
                // file or memory. Never the cache: a cache may forget, and a
                // counter that forgets is not a limit. See CounterStore.
                'counters' => 'file',
                // Refused with 413 before anything reads the body.
                'max_request_bytes' => Env::int('MAX_REQUEST_BYTES', RequestLimits::DEFAULT_BYTES),
                'headers' => [
                    // Deliberately empty. A useful policy names this
                    // application's own sources; a generic one is the kind
                    // that gets switched off. See Bootstrap::securityHeaders().
                    'csp' => '',
                    // Off, and only ever sent over HTTPS. It is the one header
                    // here that cannot be taken back -- a browser that has
                    // seen it refuses plain HTTP for the whole max-age.
                    'hsts_days' => 0,
                    'hsts_subdomains' => false,
                    // Added to the four defaults; an empty value removes one.
                    'extra' => [],
                ],
            ],
            'scheduler' => [
                // file or memory. Memory is for tests only and is never right
                // in production: schedule:run is a fresh process every minute,
                // so a lock in its memory has excluded nothing by the time it
                // exits. See Bootstrap::scheduleLock().
                'lock' => 'file',
                // How long a lock outlives the process that took it. It exists
                // because a killed process releases nothing, so the cost of a
                // crash has to be a bounded number of skipped runs rather than
                // a task that never runs again. A schedule with a longer run
                // than this says so with ->withoutOverlapping($seconds).
                'lock_ttl' => Env::int('SCHEDULER_LOCK_TTL', 3600),
                // Unset means app.timezone. Worth setting to UTC on its own:
                // a wall clock repeats an hour every autumn, and a task that
                // must run exactly once should not be read against one.
                'timezone' => Env::string('SCHEDULER_TIMEZONE'),
            ],
            'logging' => [
                // Nothing by default. A framework that starts writing files to
                // a directory nobody asked about is a framework that fills a
                // disk on somebody else's machine, and the first thing a
                // deployment does is decide where its logs go.
                'writers' => [],
                // null means debug in development, info in production.
                'level' => Env::string('LOG_LEVEL'),
                // Extra context keys to replace with [redacted], on top of the
                // list in Logging\Context.
                'redact' => [],
                'file' => [
                    'prefix' => 'app',
                    // 0 keeps everything. Deleting an audit trail because a
                    // default said so is worse than a large directory.
                    'retention_days' => 0,
                ],
                'syslog' => [
                    'identity' => 'app',
                ],
            ],
            'templates' => [
                // The active template: templates/<active>/views/ and
                // templates/<active>/assets/. One name, two directories.
                'active' => Env::string('APP_TEMPLATE', 'default'),
                // Twig's compilation cache. Off by default, like the module
                // cache, because a cache with no invalidation story is a
                // deployment decision rather than a default. PHP templates
                // never need it -- opcache already has them.
                'cache' => false,
            ],
            'modules' => [
                'paths' => [
                    'shared' => 'modules/shared',
                    'plugins' => 'modules/plugins',
                    'gateways' => 'modules/gateways',
                ],
                // Off by default: the cache has no automatic invalidation, so
                // opting in is a deployment decision rather than a default.
                'cache' => false,
            ],
        ];

        return self::merge($defaults, $overrides);
    }

    /**
     * Which template is active.
     *
     * Checked rather than trusted: it becomes a directory name, and a
     * configuration value that becomes a path is worth one regex even when the
     * only person who can set it already has the config file.
     */
    private static function activeTemplate(Config $settings): string
    {
        /** @var mixed $name */
        $name = $settings->get('templates.active', 'default');

        return \is_string($name) && \preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) === 1
            ? $name
            : 'default';
    }

    /**
     * The prefix every generated asset URL starts with.
     *
     * Configured wins, because a CDN origin is not derivable from anything.
     * Otherwise it is whatever prefix the application is mounted under, taken
     * from the same derivation a Request would use -- an asset URL built in a
     * CLI job has to come out identical to one built while serving a page, and
     * a second implementation of that derivation would eventually disagree with
     * the first.
     */
    private static function assetBaseUrl(Config $settings, ExecutionContext $context): string
    {
        /** @var mixed $configured */
        $configured = $settings->get('assets.url');

        if (\is_string($configured) && $configured !== '') {
            return \rtrim($configured, '/');
        }

        /** @var mixed $configuredBase */
        $configuredBase = $settings->get('http.base_path');

        if (\is_string($configuredBase)) {
            return \rtrim($configuredBase, '/');
        }

        return Request::basePathFrom($context->server);
    }

    /**
     * A single connection from the environment.
     *
     * The one-database case, configured without a file at all, which is what a
     * container image wants. More than one, or anything with options, goes in
     * config/database.php -- where this is still reachable, because a config
     * file may call Env itself.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function connectionsFromEnv(): array
    {
        $dsn = Env::string('DB_DSN');

        if ($dsn === null) {
            return [];
        }

        return [
            Env::string('DB_CONNECTION', 'default') ?? 'default' => [
                'dsn' => $dsn,
                'username' => Env::string('DB_USERNAME'),
                'password' => Env::string('DB_PASSWORD'),
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
