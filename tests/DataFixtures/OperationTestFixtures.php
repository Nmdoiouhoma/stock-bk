<?php

namespace App\Tests\DataFixtures;

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

class OperationTestFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADMIN_EMAIL      = 'admin@optest.com';
    public const SUPERVISOR_EMAIL = 'supervisor@optest.com';
    public const WORKER_EMAIL     = 'worker@optest.com';
    public const TEST_PASSWORD    = 'test_password';

    public const WS_REF       = 'WSTEST-001';
    public const WS2_REF      = 'WSTEST-002';
    public const MACHINE_REF  = 'MTEST-001';
    public const PART_REF     = 'PTOP-001';
    public const ROUTING_REF  = 'RTEST-001';

    public const REF_ADMIN       = 'op-test-admin';
    public const REF_SUPERVISOR  = 'op-test-supervisor';
    public const REF_WORKER      = 'op-test-worker';
    public const REF_WORKSTATION = 'op-test-workstation';
    public const REF_WORKSTATION2 = 'op-test-workstation2';
    public const REF_MACHINE     = 'op-test-machine';
    public const REF_ROUTING     = 'op-test-routing';
    public const REF_OPERATION1  = 'op-test-operation1';
    public const REF_OPERATION2  = 'op-test-operation2';

    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public static function getGroups(): array
    {
        return ['operation_test'];
    }

    public function load(ObjectManager $manager): void
    {
        $admin      = $this->createUser(Role::Admin,       self::ADMIN_EMAIL,      $manager);
        $supervisor = $this->createUser(Role::Supervisor,  self::SUPERVISOR_EMAIL, $manager);
        $worker     = $this->createUser(Role::Worker,      self::WORKER_EMAIL,     $manager);

        $workstation = (new Workstation())
            ->setReference(self::WS_REF)
            ->setLabel('Poste de test');
        $manager->persist($workstation);

        $workstation2 = (new Workstation())
            ->setReference(self::WS2_REF)
            ->setLabel('Poste de test 2');
        $manager->persist($workstation2);

        $machine = (new Machine())
            ->setReference(self::MACHINE_REF)
            ->setLabel('Machine de test')
            ->addWorkstation($workstation);
        $manager->persist($machine);

        $part = (new Part())
            ->setReference(self::PART_REF)
            ->setLabel('Article de test')
            ->setType(PieceType::Finished)
            ->setSalePrice(100.0)
            ->setStockQuantity(10);
        $manager->persist($part);

        $routing = (new Routing())
            ->setReference(self::ROUTING_REF)
            ->setLabel('Gamme de test')
            ->setPart($part)
            ->setSupervisor($supervisor);
        $manager->persist($routing);

        $manager->flush();

        $op1 = (new Operation())
            ->setLabel('OPTEST 001')
            ->setUnitTime(10.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(1);
        $manager->persist($op1);

        $op2 = (new Operation())
            ->setLabel('OPTEST 002')
            ->setUnitTime(20.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(2);
        $manager->persist($op2);

        $manager->flush();

        $this->addReference(self::REF_ADMIN,        $admin);
        $this->addReference(self::REF_SUPERVISOR,   $supervisor);
        $this->addReference(self::REF_WORKER,       $worker);
        $this->addReference(self::REF_WORKSTATION,  $workstation);
        $this->addReference(self::REF_WORKSTATION2, $workstation2);
        $this->addReference(self::REF_MACHINE,      $machine);
        $this->addReference(self::REF_ROUTING,      $routing);
        $this->addReference(self::REF_OPERATION1,   $op1);
        $this->addReference(self::REF_OPERATION2,   $op2);
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
