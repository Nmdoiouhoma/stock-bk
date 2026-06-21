<?php

namespace App\Tests\DataFixtures;

use App\Entity\Completion;
use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Part;
use App\Entity\Routing;
use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CompletionTestFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADMIN_EMAIL      = 'admin@cmptest.com';
    public const SUPERVISOR_EMAIL = 'supervisor@cmptest.com';
    public const WORKER_EMAIL     = 'worker@cmptest.com';
    public const TEST_PASSWORD    = 'test_password';

    public const REF_OPERATION   = 'cmp-test-operation';
    public const REF_OPERATION2  = 'cmp-test-operation2';
    public const REF_COMPLETION1 = 'cmp-test-completion1';
    public const REF_COMPLETION2 = 'cmp-test-completion2';

    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public static function getGroups(): array
    {
        return ['completion_test'];
    }

    public function load(ObjectManager $manager): void
    {
        $supervisor = $this->createUser(Role::Supervisor, self::SUPERVISOR_EMAIL, $manager);
        $this->createUser(Role::Admin,  self::ADMIN_EMAIL,  $manager);
        $this->createUser(Role::Worker, self::WORKER_EMAIL, $manager);

        $workstation = (new Workstation())
            ->setReference('WSCMP-001')
            ->setLabel('Poste completion test');
        $manager->persist($workstation);

        $machine = (new Machine())
            ->setReference('MCMP-001')
            ->setLabel('Machine completion test')
            ->addWorkstation($workstation);
        $manager->persist($machine);

        $part = (new Part())
            ->setReference('PTCMP-001')
            ->setLabel('Article completion test')
            ->setType(PieceType::Finished)
            ->setSalePrice(100.0)
            ->setStockQuantity(10);
        $manager->persist($part);

        $routing = (new Routing())
            ->setReference('RCMP-001')
            ->setLabel('Gamme completion test')
            ->setPart($part)
            ->setSupervisor($supervisor);
        $manager->persist($routing);

        $manager->flush();

        $operation = (new Operation())
            ->setLabel('OPCMP 001')
            ->setUnitTime(5.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(1);
        $manager->persist($operation);

        $operation2 = (new Operation())
            ->setLabel('OPCMP 002')
            ->setUnitTime(5.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(2);
        $manager->persist($operation2);

        $manager->flush();

        $completion1 = (new Completion())
            ->setDate(new \DateTime('2026-06-01'))
            ->setActualQuantity(80)
            ->setActualDuration(4.5)
            ->setOperation($operation);
        $manager->persist($completion1);

        $completion2 = (new Completion())
            ->setDate(new \DateTime('2026-06-15'))
            ->setActualQuantity(120)
            ->setOperation($operation);
        $manager->persist($completion2);

        $manager->flush();

        $this->addReference(self::REF_OPERATION,   $operation);
        $this->addReference(self::REF_OPERATION2,  $operation2);
        $this->addReference(self::REF_COMPLETION1, $completion1);
        $this->addReference(self::REF_COMPLETION2, $completion2);
    }

    private function createUser(Role $role, string $email, ObjectManager $manager): User
    {
        $user = (new User())
            ->setFirstname('Test')
            ->setLastname('User')
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, self::TEST_PASSWORD));
        $manager->persist($user);

        return $user;
    }
}
