<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use App\Message\IssueInvoiceMessage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(
    name: 'app:invoices:issue',
    description: 'Émet (brouillon → émise) les factures d\'un mois',
)]
final class IssueMonthlyInvoicesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('period', InputArgument::OPTIONAL, 'Mois concerné (YYYY-MM), par défaut le mois courant')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les brouillons sans rien émettre')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Traite immédiatement, sans passer par Redis (dev/tests)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $arg = $input->getArgument('period');
        $period = $arg
            ? \DateTimeImmutable::createFromFormat('!Y-m', (string) $arg)
            : new \DateTimeImmutable('first day of this month midnight');

        if ($period === false) {
            $io->error('Période invalide : utilisez le format YYYY-MM (ex. 2026-11).');

            return Command::INVALID;
        }

        $ids = $this->em->createQuery(
            'SELECT i.id FROM ' . Invoice::class . ' i WHERE i.status = :s AND i.period = :p ORDER BY i.id'
        )
            ->setParameter('s', InvoiceStatus::Draft)
            ->setParameter('p', $period, Types::DATE_IMMUTABLE)
            ->getSingleColumnResult();

        $io->writeln(sprintf('%d facture(s) en brouillon pour %s.', count($ids), $period->format('m/Y')));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $stamps = $input->getOption('sync') ? [new TransportNamesStamp(['sync'])] : [];
        foreach ($ids as $id) {
            $this->bus->dispatch(new Envelope(new IssueInvoiceMessage((int) $id), $stamps));
        }

        $io->success($input->getOption('sync') ? 'Factures émises.' : 'Messages envoyés dans la file.');

        return Command::SUCCESS;
    }
}
