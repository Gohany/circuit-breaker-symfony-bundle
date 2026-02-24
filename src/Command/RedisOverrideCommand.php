<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Command;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideAdmin;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RedisOverrideCommand extends Command
{
    protected static $defaultName = 'circuitbreaker:redis:override';

    private RedisOverrideAdmin $admin;

    public function __construct(RedisOverrideAdmin $admin)
    {
        parent::__construct();
        $this->admin = $admin;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Force allow/deny overrides in Redis (requires Redis wiring enabled in bundle config).')
            ->addArgument('action', InputArgument::REQUIRED, 'allow|deny')
            ->addArgument('key', InputArgument::REQUIRED, 'Circuit key name (e.g. payments_http:stripe.com)')
            ->addOption('dimensions', null, InputOption::VALUE_REQUIRED, 'JSON dimensions (optional)', '{}')
            ->addOption('ttl-ms', null, InputOption::VALUE_REQUIRED, 'TTL in ms (0 = no expiry)', '60000')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason (stored in override meta)', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $action = strtolower((string) $input->getArgument('action'));
        if (!in_array($action, ['allow', 'deny'], true)) {
            $io->error('Invalid action. Expected allow|deny.');
            return Command::FAILURE;
        }

        $keyName = (string) $input->getArgument('key');
        $dimsRaw = (string) $input->getOption('dimensions');
        $dims = json_decode($dimsRaw, true);
        if ($dimsRaw !== '' && $dims === null && json_last_error() !== JSON_ERROR_NONE) {
            $io->error('Invalid JSON for --dimensions: ' . json_last_error_msg());
            return Command::FAILURE;
        }
        if (!is_array($dims)) {
            $dims = [];
        }

        $ttlMs = (int) $input->getOption('ttl-ms');
        $reason = (string) $input->getOption('reason');

        $key = new CircuitKey($keyName, $dims);
        $meta = ['reason' => $reason];

        if ($action === 'allow') {
            $this->admin->forceAllow($key, $ttlMs, $meta);
            $io->success('Forced ALLOW for key=' . $keyName . ' ttl_ms=' . $ttlMs);
            return Command::SUCCESS;
        }

        $this->admin->forceDeny($key, $ttlMs, $meta);
        $io->success('Forced DENY for key=' . $keyName . ' ttl_ms=' . $ttlMs);
        return Command::SUCCESS;
    }
}
