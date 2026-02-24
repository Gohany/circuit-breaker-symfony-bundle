<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Command;

use Gohany\Circuitbreaker\Integration\Sanity\Output\SanityCheckOutputInterface;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;

final class SymfonySanityCheckOutput implements SanityCheckOutputInterface
{
    private OutputInterface $out;
    private bool $stylesConfigured = false;

    public function __construct(OutputInterface $out)
    {
        $this->out = $out;
    }

    public function heading(string $title): void
    {
        $this->ensureStyles();
        $this->out->writeln('');
        $this->out->writeln('<cb_heading>' . $title . '</cb_heading>');
    }

    public function info(string $message): void
    {
        $this->ensureStyles();
        $this->out->writeln('<cb_info>' . $message . '</cb_info>');
    }

    public function step(string $title): void
    {
        $this->ensureStyles();
        $this->out->writeln('');
        $this->out->writeln('<cb_step>== ' . $title . ' ==</cb_step>');
    }

    public function pass(string $message): void
    {
        $this->ensureStyles();
        $this->out->writeln('<cb_pass>PASS</cb_pass> ' . $message);
    }

    public function fail(string $message): void
    {
        $this->ensureStyles();
        $this->out->writeln('<cb_fail>FAIL</cb_fail> ' . $message);
    }

    public function sideEffect(SideEffectRequest $request): void
    {
        $this->ensureStyles();

        $this->out->writeln(
            '<cb_info>side_effect</cb_info> '
            . $request->type
            . ' key=' . $request->key->name
            . ' outcome=' . ($request->outcome !== null ? ($request->outcome->success ? 'success' : 'failure') : 'null')
        );
    }

    private function ensureStyles(): void
    {
        if ($this->stylesConfigured) {
            return;
        }
        $this->stylesConfigured = true;

        $formatter = $this->out->getFormatter();
        if ($formatter->hasStyle('cb_heading')) {
            return;
        }

        $formatter->setStyle('cb_heading', new OutputFormatterStyle('cyan', null, ['bold']));
        $formatter->setStyle('cb_step', new OutputFormatterStyle('blue', null, ['bold']));
        $formatter->setStyle('cb_pass', new OutputFormatterStyle('green', null, ['bold']));
        $formatter->setStyle('cb_fail', new OutputFormatterStyle('red', null, ['bold']));
        $formatter->setStyle('cb_info', new OutputFormatterStyle('gray'));
    }
}
