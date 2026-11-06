<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Business;
use App\Entity\Invoice;
use App\Enum\BusinessStatus;
use App\Enum\InvoiceStatus;
use App\Service\BillingProfileResolver;
use App\Service\InvoiceGenerator;
use App\Service\InvoiceIssuer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Traitement explicite, idempotent, avec verrou DB pour interdire deux exécutions simultanées. */
#[AsCommand(name: 'app:billing:run-month', description: 'Préparer/émettre les factures mensuelles actives (simulation par défaut)')]
final class RunMonthlyBillingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BillingProfileResolver $profiles,
        private readonly InvoiceGenerator $generator,
        private readonly InvoiceIssuer $issuer,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('period', InputArgument::OPTIONAL, 'Mois YYYY-MM (par défaut mois courant en Côte d’Ivoire)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Écrit dans la base et émet les factures')
            ->addOption('allow-past', null, InputOption::VALUE_NONE, 'Autoriser volontairement un traitement d’un mois passé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
        $current = $now->modify('first day of this month')->setTime(0, 0);
        $raw = $input->getArgument('period');
        $period = $raw === null ? $current : \DateTimeImmutable::createFromFormat('!Y-m', (string) $raw, new \DateTimeZone('Africa/Abidjan'));
        if (!$period || ($raw !== null && $period->format('Y-m') !== $raw)) {
            $io->error('Mois invalide : utiliser YYYY-MM.'); return Command::INVALID;
        }
        if ($period > $current) { $io->error('Impossible d’émettre des factures pour un mois futur.'); return Command::INVALID; }
        if ($period < $current && $input->getOption('execute') && !$input->getOption('allow-past')) {
            $io->error('Mois passé : --allow-past requis, après validation humaine.'); return Command::INVALID;
        }
        $ids = $this->em->getRepository(Business::class)->createQueryBuilder('b')
            ->select('b.id')->where('b.status = :active')->setParameter('active', BusinessStatus::Active)
            ->orderBy('b.id', 'ASC')->getQuery()->getSingleColumnResult();
        $eligible = [];
        foreach ($ids as $id) {
            $business = $this->em->find(Business::class, (int) $id);
            if ($business && $this->profiles->frequencyAt($business, $period) === 'monthly') $eligible[] = $business;
        }
        $io->writeln(sprintf('%s : %d commerce(s) actif(s), %d en périodicité mensuelle.', $period->format('Y-m'), count($ids), count($eligible)));
        if (!$input->getOption('execute')) {
            $io->success('Simulation uniquement. Ajouter --execute après contrôle de la sauvegarde et des montants.');
            return Command::SUCCESS;
        }
        $db = $this->em->getConnection();
        $monthKey = (int) $period->format('Ym');
        // Verrou au niveau de la session PostgreSQL pour un mois donné.
        $locked = $db->fetchOne('SELECT pg_try_advisory_lock(826042, :k)', ['k' => $monthKey]);
        if (!in_array($locked, [true, 't', '1', 1], true)) {
            $io->error('Un traitement pour ce mois est déjà en cours.'); return Command::FAILURE;
        }
        $created = 0; $issued = 0; $skipped = 0; $errors = [];
        try {
            foreach ($eligible as $business) {
                try {
                    $this->em->wrapInTransaction(function () use ($business, $period, $now, &$created, &$issued, &$skipped): void {
                        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['business' => $business, 'period' => $period]);
                        if (!$invoice) {
                            $invoice = $this->generator->generate($business, $period);
                            if ($invoice) ++$created;
                        }
                        if (!$invoice) { ++$skipped; return; }
                        if ($invoice->getStatus() !== InvoiceStatus::Draft) { ++$skipped; return; }
                        // Pour le mois en cours, échéance en fin de mois ; pour les reprises tardives, pas de date passée.
                        $due = $period->modify('last day of this month');
                        if ($due < $now->setTime(0, 0)) $due = null;
                        $this->issuer->issue($invoice, $now, $due);
                        ++$issued;
                    });
                } catch (\Throwable $e) {
                    $errors[] = sprintf('Commerce #%d : %s', $business->getId(), $e->getMessage());
                    // Après une erreur Doctrine pendant une transaction, EM peut être fermé : arrêter sans risquer la suite.
                    break;
                }
            }
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock(826042, :k)', ['k' => $monthKey]);
        }
        $io->writeln(sprintf('Créées : %d · Émises : %d · Sans action : %d', $created, $issued, $skipped));
        foreach ($errors as $error) $io->error($error);
        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
