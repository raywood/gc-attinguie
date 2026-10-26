<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Une ligne du formulaire de saisie des commerces. */
final class BusinessRowInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom du commerce est obligatoire.')]
        #[Assert\Length(max: 180, maxMessage: '180 caractères maximum.')]
        public readonly ?string $name = null,

        #[Assert\NotNull(message: 'Choisissez un type d’activité.')]
        #[Assert\Positive(message: 'Type d’activité invalide.')]
        public readonly ?int $activityTypeId = null,

        #[Assert\Length(max: 255, maxMessage: '255 caractères maximum.')]
        public readonly ?string $address = null,

        #[Assert\Length(max: 120, maxMessage: '120 caractères maximum.')]
        public readonly ?string $district = null,

        #[Assert\Length(max: 100, maxMessage: '100 caractères maximum.')]
        public readonly ?string $managerLastName = null,

        #[Assert\Length(max: 100, maxMessage: '100 caractères maximum.')]
        public readonly ?string $managerFirstName = null,

        #[Assert\Length(max: 30, maxMessage: '30 caractères maximum.')]
        public readonly ?string $managerPhone = null,
    ) {
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        $str = static function (mixed $v): ?string {
            if (!is_scalar($v)) {
                return null;
            }
            $v = trim((string) $v);

            return $v === '' ? null : $v;
        };

        $typeId = $d['activityTypeId'] ?? null;

        return new self(
            name: $str($d['name'] ?? null),
            activityTypeId: is_numeric($typeId) ? (int) $typeId : null,
            address: $str($d['address'] ?? null),
            district: $str($d['district'] ?? null),
            managerLastName: $str($d['managerLastName'] ?? null),
            managerFirstName: $str($d['managerFirstName'] ?? null),
            managerPhone: $str($d['managerPhone'] ?? null),
        );
    }

    public function hasManager(): bool
    {
        return $this->managerLastName !== null;
    }

    /** Si un champ « gérant » est rempli, le nom et le prénom deviennent obligatoires. @return array<string, string> */
    public function managerErrors(): array
    {
        if ($this->managerLastName === null && $this->managerFirstName === null && $this->managerPhone === null) {
            return [];
        }

        $errors = [];
        if ($this->managerLastName === null) {
            $errors['managerLastName'] = 'Nom du gérant obligatoire.';
        }
        if ($this->managerFirstName === null) {
            $errors['managerFirstName'] = 'Prénom du gérant obligatoire.';
        }

        return $errors;
    }

    #[Assert\Callback]
    public function validateManager(ExecutionContextInterface $context): void
    {
        foreach ($this->managerErrors() as $field => $message) {
            $context->buildViolation($message)->atPath($field)->addViolation();
        }
    }
}
