<?php declare(strict_types=1);

namespace WSystem\EInvoice\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WSystem\EInvoice\Service\InvoiceService;

#[AsCommand(name: 'wsystem:einvoice:generate', description: 'Create and validate the e-invoice of an order (by order number)')]
class GenerateCommand extends Command
{
    public function __construct(private readonly InvoiceService $invoices)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('orderNumber', InputArgument::REQUIRED, 'Order number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createCLIContext();
        $order = $this->invoices->loadByNumber((string) $input->getArgument('orderNumber'), $context);
        if ($order === null) {
            $output->writeln('<error>Order not found</error>');

            return Command::FAILURE;
        }
        $r = $this->invoices->generate($order, $context);
        if ($r['error'] !== '') {
            $output->writeln('<error>' . $r['error'] . '</error>');

            return Command::FAILURE;
        }
        $output->writeln($r['valid'] ? 'valid' : 'INVALID');
        foreach ($r['errors'] as $e) {
            $output->writeln(' - ' . $e['rule_id'] . ': ' . $e['message'] . ' ' . $e['fix']);
        }

        return $r['valid'] ? Command::SUCCESS : Command::FAILURE;
    }
}
