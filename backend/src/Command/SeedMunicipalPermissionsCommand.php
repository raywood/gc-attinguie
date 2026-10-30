<?php
declare(strict_types=1);
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** N'applique jamais les autorisations silencieusement. --apply = consentement explicite. */
#[AsCommand(name: 'app:rbac:seed-municipal', description: 'Préparer les permissions et la matrice municipale (lecture seule sauf --apply)')]
final class SeedMunicipalPermissionsCommand extends Command
{
    private const RULES = [
        'commerces.read' => ['Consulter les commerces', 'commerces'],
        'commerces.write' => ['Gérer les commerces', 'commerces'],
        'factures.read' => ['Consulter les factures', 'factures'],
        'factures.write' => ['Créer et émettre des factures', 'factures'],
        'encaissements.read' => ['Consulter les encaissements et reçus', 'encaissements'],
        'encaissements.write' => ['Enregistrer des encaissements', 'encaissements'],
        'finance.read' => ['Consulter le tableau financier', 'finance'],
        'finance.export' => ['Exporter les états financiers', 'finance'],
        'recouvrement.read' => ['Consulter les impayés', 'recouvrement'],
    ];
    private const ROLE_RULES = [
        'ROLE_ADMIN' => ['commerces.read','commerces.write','factures.read','factures.write','encaissements.read','encaissements.write','finance.read','finance.export','recouvrement.read'],
        'ROLE_REGISSEUR' => ['commerces.read','commerces.write','factures.read','factures.write','encaissements.read','encaissements.write','finance.read','finance.export','recouvrement.read'],
        'ROLE_COMPTABLE' => ['factures.read','encaissements.read','finance.read','finance.export','recouvrement.read'],
        'ROLE_AGENT' => ['commerces.read','recouvrement.read'],
    ];

    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Créer les permissions et AJOUTER les associations prévues ; n’enlève pas les droits existants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $output->writeln($apply ? '<info>APPLICATION DEMANDÉE</info>' : '<comment>SIMULATION UNIQUEMENT — aucune écriture</comment>');
        foreach (self::ROLE_RULES as $role => $codes) {
            $output->writeln($role . ' : ' . implode(', ', $codes));
        }
        if (!$apply) { $output->writeln('Relancer avec --apply après vérification de la matrice.'); return Command::SUCCESS; }
        $roles = array_fill_keys($this->db->fetchFirstColumn('SELECT code FROM security_roles'), true);
        foreach (array_keys(self::ROLE_RULES) as $code) {
            if (!isset($roles[$code])) {
                $output->writeln('<error>Rôle absent du catalogue V3 : ' . $code . '. Migration V3 requise.</error>');
                return Command::FAILURE;
            }
        }
        $count = $this->db->transactional(function (Connection $db): int {
            foreach (self::RULES as $code => [$label, $module]) {
                $db->executeStatement(<<<'SQL'
INSERT INTO security_permissions (code, label, module, description, created_at)
VALUES (:code,:label,:module,'Permission applicative V3.1', NOW())
ON CONFLICT (code) DO NOTHING
SQL, compact('code','label','module'));
            }
            $links = 0;
            foreach (self::ROLE_RULES as $role => $codes) {
                foreach ($codes as $code) {
                    $links += $db->executeStatement(<<<'SQL'
INSERT INTO security_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM security_roles r CROSS JOIN security_permissions p
WHERE r.code = :role AND p.code = :code
ON CONFLICT (role_id, permission_id) DO NOTHING
SQL, ['role' => $role, 'code' => $code]);
                }
            }
            $db->insert('admin_user_audit', [
                'actor_user_id' => null, 'target_user_id' => null,
                'action' => 'rbac_seed_applied',
                'details' => json_encode(['roleCodes' => array_keys(self::ROLE_RULES), 'createdLinks' => $links], JSON_THROW_ON_ERROR),
                'created_at' => gmdate('Y-m-d H:i:sP'),
            ]);
            return $links;
        });
        $output->writeln('<info>Permission(s) vérifiées ; ' . $count . ' association(s) ajoutée(s). Aucune association existante supprimée.</info>');
        return Command::SUCCESS;
    }
}
