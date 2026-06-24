<?php

namespace App\Tests\DataFixtures;

use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Part;
use App\Entity\ProductionOrder;
use App\Entity\Routing;
use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\OperationStatus;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProductionOrderTestFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADMIN_EMAIL      = 'admin@potest.com';
    public const SUPERVISOR_EMAIL = 'supervisor@potest.com';
    public const WORKER_EMAIL     = 'worker@potest.com';
    public const TEST_PASSWORD    = 'test_password';

    public const REF_OPERATION  = 'po-test-operation';
    public const REF_OPERATION2 = 'po-test-operation2';
    public const REF_ORDER1     = 'po-test-order1';
    public const REF_ORDER2     = 'po-test-order2';
    public const REF_PART       = 'po-test-part';
    public const INITIAL_STOCK  = 10;

    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public static function getGroups(): array
    {
        return ['production_order_test'];
    }

    public function load(ObjectManager $manager): void
    {
        $supervisor = $this->createUser(Role::Supervisor, self::SUPERVISOR_EMAIL, $manager);
        $this->createUser(Role::Admin,  self::ADMIN_EMAIL,  $manager);
        $this->createUser(Role::Worker, self::WORKER_EMAIL, $manager);

        $workstation = (new Workstation())
            ->setReference('WSPO-001')
            ->setLabel('Poste production order test');
        $manager->persist($workstation);

        $machine = (new Machine())
            ->setReference('MPO-001')
            ->setLabel('Machine production order test')
            ->addWorkstation($workstation);
        $manager->persist($machine);

        $part = (new Part())
            ->setReference('PTPO-001')
            ->setLabel('Article production order test')
            ->setType(PieceType::Finished)
            ->setSalePrice(100.0)
            ->setStockQuantity(self::INITIAL_STOCK);
        $manager->persist($part);

        $routing = (new Routing())
            ->setReference('RPO-001')
            ->setLabel('Gamme production order test')
            ->setPart($part)
            ->setSupervisor($supervisor);
        $manager->persist($routing);

        $manager->flush();

        $operation = (new Operation())
            ->setLabel('OPPO 001')
            ->setUnitTime(5.0)
            ->addRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(1);
        $manager->persist($operation);

        $operation2 = (new Operation())
            ->setLabel('OPPO 002')
            ->setUnitTime(5.0)
            ->addRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(2);
        $manager->persist($operation2);

        $manager->flush();

        $order1 = (new ProductionOrder())
            ->setPlannedDate(new \DateTime('2026-07-01'))
            ->setPlannedQuantity(100)
            ->setStatus(OperationStatus::IN_PROGRESS)
            ->setOperation($operation);
        $manager->persist($order1);

        $order2 = (new ProductionOrder())
            ->setPlannedDate(new \DateTime('2026-07-15'))
            ->setPlannedQuantity(200)
            ->setStatus(OperationStatus::IN_PROGRESS)
            ->setOperation($operation);
        $manager->persist($order2);

        $manager->flush();

        $this->addReference(self::REF_OPERATION,  $operation);
        $this->addReference(self::REF_OPERATION2, $operation2);
        $this->addReference(self::REF_ORDER1,     $order1);
        $this->addReference(self::REF_ORDER2,     $order2);
        $this->addReference(self::REF_PART,       $part);
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
