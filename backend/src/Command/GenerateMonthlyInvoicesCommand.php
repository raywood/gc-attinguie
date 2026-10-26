<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Business;
use App\Enum\BusinessStatus;
use App\Message\GenerateInvoiceMessage;
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
    name: 'app:invoices:generate',
    description: 'Met en file la génération des factures mensuelles de tous les commerces actifs',
)]
final class GenerateMonthlyInvoicesCommand extends Command
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
            ->addArgument('period', InputArgument::OPTIONAL, 'Mois à facturer (YYYY-MM), par défaut le mois courant')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les commerces sans rien envoyer')
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

        $ids = $this->em->createQuery('SELECT b.id FROM ' . Business::class . ' b WHERE b.status = :s ORDER BY b.id')
            ->setParameter('s', BusinessStatus::Active)
            ->getSingleColumnResult();

        $io->writeln(sprintf('%d commerce(s) actif(s) pour %s.', count($ids), $period->format('m/Y')));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $stamps = $input->getOption('sync') ? [new TransportNamesStamp(['sync'])] : [];
        foreach ($ids as $id) {
            $this->bus->dispatch(new Envelope(
                new GenerateInvoiceMessage((int) $id, $period->format('Y-m-d')),
                $stamps,
            ));
        }

        $io->success($input->getOption('sync') ? 'Factures générées.' : 'Messages envoyés dans la file.');

        return Command::SUCCESS;
    }
}
