<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }

    public function ofEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function existsByEmail(string $email): bool
    {
        return $this->em->getRepository(User::class)->count(['email' => $email]) > 0;
    }

    public function ofId(Uuid $id): ?User
    {
        return $this->em->find(User::class, $id);
    }
}
