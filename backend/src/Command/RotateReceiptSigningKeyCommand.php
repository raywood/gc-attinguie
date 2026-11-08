<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Receipt;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:receipts:rotate-key')]
final class RotateReceiptSigningKeyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(RECEIPT_SIGNING_KEY)%')]
        private readonly string $oldKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key-file', InputArgument::REQUIRED)
            ->addOption('apply', null, InputOption::VALUE_NONE)
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, '', '5');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $path = (string) $input->getArgument('key-file');
        $content = @file_get_contents($path);
        $newKey = $content === false ? '' : trim($content);
        $expected = filter_var(
            $input->getOption('expected-count'),
            FILTER_VALIDATE_INT
        );
        $apply = (bool) $input->getOption('apply');

        if (!preg_match('/^[a-f0-9]{64}$/D', $newKey)
            || strlen($this->oldKey) < 32
            || hash_equals($this->oldKey, $newKey)
            || $expected === false
            || $expected < 1) {
            $output->writeln('<error>Clés ou paramètres invalides.</error>');
            return Command::FAILURE;
        }

        try {
            $count = $this->em->getConnection()->transactional(
                function (Connection $db) use ($newKey, $expected, $apply): int {
                    // Verrouillage exclusif pendant la rotation de test.
                    $db->executeStatement(
                        'LOCK TABLE receipts IN ACCESS EXCLUSIVE MODE'
                    );

                    $receipts = $this->em
                        ->getRepository(Receipt::class)
                        ->findBy([], ['id' => 'ASC']);

                    if (count($receipts) !== $expected) {
                        throw new \RuntimeException(
                            'Nombre de reçus différent du nombre attendu.'
                        );
                    }

                    $updates = [];

                    foreach ($receipts as $receipt) {
                        $payload = $receipt->signaturePayload();
                        $oldSignature = hash_hmac(
                            'sha256',
                            $payload,
                            $this->oldKey
                        );

                        if (!hash_equals(
                            $receipt->getSignature(),
                            $oldSignature
                        )) {
                            throw new \RuntimeException(
                                'Signature existante invalide : arrêt.'
                            );
                        }

                        $updates[] = [
                            'id' => $receipt->getId(),
                            'old' => $receipt->getSignature(),
                            'new' => hash_hmac('sha256', $payload, $newKey),
                        ];
                    }

                    if ($apply) {
                        // Refuser la rotation si le trigger de protection
                        // n'est pas présent et actif.
                        $enabled = $db->fetchOne(
                            "SELECT tgenabled
                            FROM pg_trigger
                            WHERE tgrelid = 'receipts'::regclass
                            AND tgname = 'gc_receipts_no_update'
                            AND NOT tgisinternal"
                        );

                        if ($enabled !== 'O') {
                            throw new \RuntimeException(
                                'Trigger de protection absent ou désactivé.'
                            );
                        }

                        // Exception temporaire : reçus de test uniquement.
                        $db->executeStatement(
                            'ALTER TABLE receipts DISABLE TRIGGER gc_receipts_no_update'
                        );

                        foreach ($updates as $row) {
                            $affected = $db->executeStatement(
                                'UPDATE receipts SET signature = :new
                                 WHERE id = :id AND signature = :old',
                                $row
                            );

                            if ($affected !== 1) {
                                throw new \RuntimeException(
                                    'Conflit : rotation annulée.'
                                );
                            }
                        }

                        // Réactivation dans la même transaction.
                        $db->executeStatement(
                            'ALTER TABLE receipts ENABLE TRIGGER gc_receipts_no_update'
                        );

                        $enabled = $db->fetchOne(
                            "SELECT tgenabled
                            FROM pg_trigger
                            WHERE tgrelid = 'receipts'::regclass
                            AND tgname = 'gc_receipts_no_update'"
                        );

                        if ($enabled !== 'O') {
                            throw new \RuntimeException(
                                'Échec de réactivation du trigger.'
                            );
                        }
                    }

                    return count($updates);
                }
            );

            $output->writeln(sprintf(
                '<info>%s : %d signatures vérifiées%s.</info>',
                $apply ? 'ROTATION EFFECTUÉE' : 'SIMULATION OK',
                $count,
                $apply ? ' et remplacées' : ', aucune modification'
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(
                '<error>Rotation refusée : '
                . $e->getMessage()
                . '</error>'
            );
            return Command::FAILURE;
        }
    }
}
