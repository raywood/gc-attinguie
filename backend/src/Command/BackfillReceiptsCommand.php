<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Payment;
use App\Service\ReceiptIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:receipts:backfill', description: 'Crée les reçus et QR des paiements historiques sans doublon')]
final class BackfillReceiptsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReceiptIssuer $issuer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans créer les reçus');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT p.id FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE r.id IS NULL ORDER BY p.id'
        );
        $output->writeln(count($ids) . ' paiement(s) sans reçu.');
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }
        $created = 0;
        foreach ($ids as $id) {
            $payment = $this->em->find(Payment::class, (int) $id);
            if ($payment !== null) {
                $this->issuer->issueForPayment($payment);
                ++$created;
            }
        }
        $output->writeln(sprintf('%d reçu(s) créés. Relancer est sans risque de doublon.', $created));
        return Command::SUCCESS;
    }
}
