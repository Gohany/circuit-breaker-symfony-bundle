<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class CircuitBreakerDebugCommand extends Command
{
    protected static $defaultName = 'circuitbreaker:debug';

    /** @var array<string,array<string,mixed>> */
    private array $resolved;

    /**
     * @param array<string,array<string,mixed>> $resolved
     */
    public function __construct(array $resolved)
    {
        parent::__construct();
        $this->resolved = $resolved;
    }

    protected function configure(): void
    {
        $this->setDescription('List registered circuit breakers and their resolved wiring.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        foreach ($this->resolved as $name => $cfg) {
            $rows[] = [
                (string) $name,
                (string) ($cfg['breaker_service'] ?? ''),
                (string) ($cfg['state_store_service'] ?? ''),
                (string) ($cfg['history_store_service'] ?? ''),
                (string) ($cfg['probe_gate_service'] ?? ''),
                (string) ($cfg['policy_service'] ?? ''),
                (string) ($cfg['classifier_service'] ?? ''),
            ];
        }

        $io->title('Gohany Circuit Breaker (bundle)');
        $io->section('Resolved circuits');
        $io->table(
            ['name', 'breaker', 'state_store', 'history_store', 'probe_gate', 'policy', 'classifier'],
            $rows
        );

        $io->note('Registry keys: ' . implode(', ', array_keys($this->resolved)));

        return Command::SUCCESS;
    }
}
