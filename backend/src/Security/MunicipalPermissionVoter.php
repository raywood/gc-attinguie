<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/** Attribut IsGranted('finance.read'), IsGranted('commerces.write'), etc. */
final class MunicipalPermissionVoter extends Voter
{
    public function __construct(private readonly MunicipalPermissionResolver $resolver) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject === null && preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3}$/D', $attribute) === 1;
    }

    protected function voteOnAttribute(string $attribute,mixed $subject,TokenInterface $token,?Vote $vote = null): bool
    {
        $user = $token->getUser();
        return $user instanceof User && $user->getId() !== null
            && $this->resolver->allows($user->getId(), $attribute);
    }
}