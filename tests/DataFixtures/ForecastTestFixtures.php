<?php

namespace App\Tests\DataFixtures;

use App\Entity\Forecast;
use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Part;
use App\Entity\Routing;
use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\ForecastStatus;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ForecastTestFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADMIN_EMAIL      = 'admin@fctest.com';
    public const SUPERVISOR_EMAIL = 'supervisor@fctest.com';
    public const WORKER_EMAIL     = 'worker@fctest.com';
    public const TEST_PASSWORD    = 'test_password';

    public const REF_OPERATION  = 'fc-test-operation';
    public const REF_OPERATION2 = 'fc-test-operation2';
    public const REF_FORECAST1  = 'fc-test-forecast1';
    public const REF_FORECAST2  = 'fc-test-forecast2';
    public const REF_PART       = 'fc-test-part';
    public const INITIAL_STOCK  = 10;

    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public static function getGroups(): array
    {
        return ['forecast_test'];
    }

    public function load(ObjectManager $manager): void
    {
        $supervisor = $this->createUser(Role::Supervisor, self::SUPERVISOR_EMAIL, $manager);
        $this->createUser(Role::Admin,  self::ADMIN_EMAIL,  $manager);
        $this->createUser(Role::Worker, self::WORKER_EMAIL, $manager);

        $workstation = (new Workstation())
            ->setReference('WSFC-001')
            ->setLabel('Poste forecast test');
        $manager->persist($workstation);

        $machine = (new Machine())
            ->setReference('MFC-001')
            ->setLabel('Machine forecast test')
            ->addWorkstation($workstation);
        $manager->persist($machine);

        $part = (new Part())
            ->setReference('PTFC-001')
            ->setLabel('Article forecast test')
            ->setType(PieceType::Finished)
            ->setSalePrice(100.0)
            ->setStockQuantity(self::INITIAL_STOCK);
        $manager->persist($part);

        $routing = (new Routing())
            ->setReference('RFC-001')
            ->setLabel('Gamme forecast test')
            ->setPart($part)
            ->setSupervisor($supervisor);
        $manager->persist($routing);

        $manager->flush();

        $operation = (new Operation())
            ->setLabel('OPFC 001')
            ->setUnitTime(5.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(1);
        $manager->persist($operation);

        $operation2 = (new Operation())
            ->setLabel('OPFC 002')
            ->setUnitTime(5.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setRank(2);
        $manager->persist($operation2);

        $manager->flush();

        $forecast1 = (new Forecast())
            ->setPlannedDate(new \DateTime('2026-07-01'))
            ->setPlannedQuantity(100)
            ->setStatus(ForecastStatus::IN_PROGRESS)
            ->setOperation($operation);
        $manager->persist($forecast1);

        $forecast2 = (new Forecast())
            ->setPlannedDate(new \DateTime('2026-07-15'))
            ->setPlannedQuantity(200)
            ->setStatus(ForecastStatus::IN_PROGRESS)
            ->setOperation($operation);
        $manager->persist($forecast2);

        $manager->flush();

        $this->addReference(self::REF_OPERATION,  $operation);
        $this->addReference(self::REF_OPERATION2, $operation2);
        $this->addReference(self::REF_FORECAST1,  $forecast1);
        $this->addReference(self::REF_FORECAST2,  $forecast2);
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
