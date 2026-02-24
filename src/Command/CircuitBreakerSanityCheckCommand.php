<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Command;

use Gohany\Circuitbreaker\Integration\Sanity\Output\OutputSideEffectDispatcher;
use Gohany\Circuitbreaker\Integration\Sanity\SanityCheckRunner;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class CircuitBreakerSanityCheckCommand extends Command
{
    protected static $defaultName = 'circuitbreaker:sanity';

    private ContainerInterface $container;

    /** @var array<string,array<string,mixed>> */
    private array $resolved;

    /**
     * @param array<string,array<string,mixed>> $resolved
     */
    public function __construct(ContainerInterface $container, array $resolved)
    {
        parent::__construct();
        $this->container = $container;
        $this->resolved = $resolved;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run a circuit breaker sanity check using upstream Integration\\Sanity.')
            ->addOption('circuit', null, InputOption::VALUE_REQUIRED, 'Circuit name from bundle config', 'default')
            ->addOption('no-sleep', null, InputOption::VALUE_NONE, 'Skip sleeps (faster, less realistic)')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Sleep duration (ms) used by the sanity runner', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $circuit = (string) $input->getOption('circuit');
        if (!isset($this->resolved[$circuit])) {
            $io->error('Unknown circuit "' . $circuit . '". Known: ' . implode(', ', array_keys($this->resolved)));
            return Command::FAILURE;
        }

        $cfg = $this->resolved[$circuit];

        $stateStore = $this->container->get((string) $cfg['state_store_service']);
        $historyStore = $this->container->get((string) $cfg['history_store_service']);
        $probeGate = $this->container->get((string) $cfg['probe_gate_service']);
        $policy = $this->container->get((string) $cfg['policy_service']);
        $classifier = $this->container->get((string) $cfg['classifier_service']);
        $sideEffectsInner = $this->container->get((string) $cfg['side_effect_dispatcher_service']);

        if (!($policy instanceof DefaultHttpCircuitPolicy)) {
            $io->error('Sanity check currently requires policy to be an instance of ' . DefaultHttpCircuitPolicy::class . '. Got: ' . (is_object($policy) ? get_class($policy) : gettype($policy)));
            $io->note('If you use a decorator policy, ensure it extends ' . DefaultHttpCircuitPolicy::class . ' (or update the bundle command to support your policy type).');
            return Command::FAILURE;
        }

        $sleepMs = (int) $input->getOption('sleep-ms');
        $noSleep = (bool) $input->getOption('no-sleep');

        $out = new SymfonySanityCheckOutput($output);
        $sideEffects = new OutputSideEffectDispatcher($out, $sideEffectsInner);

        $runner = new SanityCheckRunner(static function (int $ms) use ($noSleep, $sleepMs): void {
            if ($noSleep) {
                return;
            }
            $ms = $sleepMs > 0 ? $sleepMs : $ms;
            usleep($ms * 1000);
        });

        $result = $runner->runSingle(
            $stateStore,
            $historyStore,
            $probeGate,
            $policy,
            $classifier,
            $sideEffects,
            $out
        );

        if ($result->failures !== []) {
            $io->error('Sanity check failures: ' . implode('; ', $result->failures));
            return Command::FAILURE;
        }

        $io->success('Sanity check passed.');
        return Command::SUCCESS;
    }
}
