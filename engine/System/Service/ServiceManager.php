<?php

declare(strict_types=1);

namespace App\Engine\System\Service;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;

/**
 * systemd services: their status, and the changes a policy allows.
 *
 *     $services = new ServiceManager($executor, ServicePolicy::none()->allow('nginx', ServiceAction::Reload));
 *
 *     $services->status('nginx')->isActive();
 *     $services->reload('nginx');
 *     $services->stop('ssh');          // ServiceException: nothing permits "stop ssh.service"
 *
 * **Checked in order, before anything runs:** the name is a valid service
 * unit, the policy allows this action on it, the platform has systemd. Only
 * then is systemctl run, as
 *
 *     systemctl --no-pager --no-ask-password <verb> -- <name>.service
 *
 * with the unit after `--`, so it can never be read as an option, and
 * --no-ask-password, so a missing privilege fails at once instead of waiting
 * for a password prompt nobody will answer.
 *
 * **Status needs no permission.** It changes nothing, and it is how anyone
 * decides whether to ask for a change.
 *
 * **Privilege is the operating system's.** Starting and stopping services
 * needs root or a polkit rule for the user PHP runs as. This class neither
 * escalates nor works around that; a refusal from systemd is a failed action
 * whose message says where to look.
 *
 * **Not a supervisor.** It asks systemd to do what systemd does. Watching,
 * restarting on failure and dependencies are systemd's, configured in unit
 * files, not here.
 */
final class ServiceManager
{
    /** systemctl waits for the job to finish; a slow stop is still a stop. */
    public const DEFAULT_TIMEOUT = 120.0;

    private const PROPERTIES = 'LoadState,ActiveState,SubState,UnitFileState';

    /** @param ?SystemAudit $audit when given, every change and every refusal is recorded (system.service.changed, .refused) */
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly ServicePolicy $policy,
        private readonly string $systemctl = 'systemctl',
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly ?SystemAudit $audit = null,
    ) {}

    public function status(string $service): ServiceStatus
    {
        $unit = ServicePolicy::unit($service);
        $result = $this->executor->run($this->command(['show', '--property=' . self::PROPERTIES, '--', $unit]));

        if (!$result->successful()) {
            throw ServiceException::unavailable($result->exitCode());
        }

        return ServiceStatus::fromShow($unit, $result->stdout());
    }

    public function start(string $service): void
    {
        $this->perform(ServiceAction::Start, $service);
    }

    public function stop(string $service): void
    {
        $this->perform(ServiceAction::Stop, $service);
    }

    public function restart(string $service): void
    {
        $this->perform(ServiceAction::Restart, $service);
    }

    public function reload(string $service): void
    {
        $this->perform(ServiceAction::Reload, $service);
    }

    public function enable(string $service): void
    {
        $this->perform(ServiceAction::Enable, $service);
    }

    public function disable(string $service): void
    {
        $this->perform(ServiceAction::Disable, $service);
    }

    private function perform(ServiceAction $action, string $service): void
    {
        $unit = ServicePolicy::unit($service);

        if (!$this->policy->permits($unit, $action)) {
            $this->audit?->record('system.service.refused', AuditOutcome::Refused, $unit, ['action' => $action->value]);

            throw ServiceException::notPermitted($unit, $action);
        }

        $result = $this->executor->run($this->command([$action->value, '--', $unit]));

        $this->audit?->record(
            'system.service.changed',
            $result->successful() ? AuditOutcome::Succeeded : AuditOutcome::Failed,
            $unit,
            ['action' => $action->value, 'exit_code' => $result->exitCode(), 'duration_ms' => (int) \round($result->duration() * 1000)],
        );

        if ($result->exitCode() === ServiceException::EXIT_NOT_FOUND) {
            throw ServiceException::notFound($unit, $action);
        }

        if (!$result->successful()) {
            throw ServiceException::actionFailed($unit, $action, $result->exitCode(), $result->timedOut());
        }
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): Command
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            throw ServiceException::unsupportedPlatform();
        }

        return new Command($this->systemctl, ['--no-pager', '--no-ask-password', ...$arguments], timeout: $this->timeout);
    }
}
