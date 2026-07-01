<?php

namespace App\Tests\DataFixtures;

use App\Entity\Order;
use App\Entity\Part;
use App\Entity\Quote;
use App\Entity\QuoteLine;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PieceType;
use App\Enum\QuoteStatus;
use App\Enum\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class QuoteTestFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADMIN_EMAIL     = 'admin@qtest.com';
    public const SELLER_EMAIL    = 'seller@qtest.com';
    public const CUSTOMER1_EMAIL = 'customer1@qtest.com';
    public const CUSTOMER2_EMAIL = 'customer2@qtest.com';
    public const WORKER_EMAIL    = 'worker@qtest.com';
    public const TEST_PASSWORD   = 'test_password';

    public const REF_ADMIN       = 'qt-admin';
    public const REF_SELLER      = 'qt-seller';
    public const REF_CUSTOMER1   = 'qt-customer1';
    public const REF_CUSTOMER2   = 'qt-customer2';
    public const REF_WORKER      = 'qt-worker';
    public const REF_PART1       = 'qt-part1';        // Finished, salePrice=100
    public const REF_PART2       = 'qt-part2';        // Finished, salePrice=200
    public const REF_PART3       = 'qt-part3';        // Intermediate (non-finished)
    public const REF_QUOTE1      = 'qt-quote1';       // customer1, PENDING,   1 line (part1)
    public const REF_QUOTE2      = 'qt-quote2';       // customer1, ACCEPTED,  1 line (part1)
    public const REF_QUOTE3      = 'qt-quote3';       // customer1, EXPIRED,   1 line (part1)
    public const REF_QUOTE4      = 'qt-quote4';       // customer2, PENDING,   1 line (part1)
    public const REF_QUOTE5      = 'qt-quote5';       // customer1, PENDING,   NO LINES
    public const REF_ORDER1      = 'qt-order1';       // from quote2, PENDING
    public const REF_QUOTE1_LINE = 'qt-quote1-line';  // part1, qty=2
    public const REF_QUOTE2_LINE = 'qt-quote2-line';  // part1, qty=1 — already in order1
    public const REF_QUOTE3_LINE = 'qt-quote3-line';  // part1, qty=1 — expired quote
    public const REF_QUOTE4_LINE = 'qt-quote4-line';  // part1, qty=1 — customer2 (different client)

    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public static function getGroups(): array
    {
        return ['quote_test'];
    }

    public function load(ObjectManager $manager): void
    {
        $admin     = $this->createUser(Role::Admin,      self::ADMIN_EMAIL,     $manager);
        $seller    = $this->createUser(Role::Seller,     self::SELLER_EMAIL,    $manager);
        $customer1 = $this->createUser(Role::Customer,   self::CUSTOMER1_EMAIL, $manager);
        $customer2 = $this->createUser(Role::Customer,   self::CUSTOMER2_EMAIL, $manager);
        $worker    = $this->createUser(Role::Worker,     self::WORKER_EMAIL,    $manager);

        $part1 = (new Part())->setReference('QTEST-PART-001')->setLabel('Pièce finie A')
            ->setType(PieceType::Finished)->setSalePrice(100.0)->setStockQuantity(50);
        $part2 = (new Part())->setReference('QTEST-PART-002')->setLabel('Pièce finie B')
            ->setType(PieceType::Finished)->setSalePrice(200.0)->setStockQuantity(30);
        $part3 = (new Part())->setReference('QTEST-PART-003')->setLabel('Pièce intermédiaire')
            ->setType(PieceType::Intermediate)->setStockQuantity(10);

        $manager->persist($part1);
        $manager->persist($part2);
        $manager->persist($part3);

        // quote1 — customer1, PENDING, 1 line (part1)
        $quote1Line = (new QuoteLine())->setPart($part1)->setQuantity(2)->setUnitPrice('100.00');
        $quote1 = $this->makeQuote('QTEST-DEV-001', $customer1, '+30 days', QuoteStatus::PENDING, '200.00');
        $quote1->addLine($quote1Line);
        $manager->persist($quote1);

        // quote2 — customer1, ACCEPTED, 1 line (part1)
        $quote2Line = (new QuoteLine())->setPart($part1)->setQuantity(1)->setUnitPrice('100.00');
        $quote2 = $this->makeQuote('QTEST-DEV-002', $customer1, '+15 days', QuoteStatus::ACCEPTED, '100.00');
        $quote2->addLine($quote2Line);
        $manager->persist($quote2);

        // quote3 — customer1, EXPIRED, 1 line (part1)
        $quote3Line = (new QuoteLine())->setPart($part1)->setQuantity(1)->setUnitPrice('100.00');
        $quote3 = $this->makeQuote('QTEST-DEV-003', $customer1, '-5 days', QuoteStatus::EXPIRED, '100.00');
        $quote3->addLine($quote3Line);
        $manager->persist($quote3);

        // quote4 — customer2, PENDING, 1 line (part1)
        $quote4Line = (new QuoteLine())->setPart($part1)->setQuantity(1)->setUnitPrice('100.00');
        $quote4 = $this->makeQuote('QTEST-DEV-004', $customer2, '+20 days', QuoteStatus::PENDING, '100.00');
        $quote4->addLine($quote4Line);
        $manager->persist($quote4);

        // quote5 — customer1, PENDING, NO LINES
        $quote5 = $this->makeQuote('QTEST-DEV-005', $customer1, '+45 days', QuoteStatus::PENDING, '0.00');
        $manager->persist($quote5);

        $manager->flush();

        // order1 — groups quote2Line (qty=1, unitPrice=100 → total=100)
        $order1 = (new Order())
            ->setCreatedAt(new \DateTimeImmutable())
            ->setStatus(OrderStatus::PENDING)
            ->setTotalAmount('100.00');
        foreach ($quote2->getLines() as $ql) {
            $order1->addLine($ql);
        }
        $manager->persist($order1);

        $manager->flush();

        $this->addReference(self::REF_ADMIN,       $admin);
        $this->addReference(self::REF_SELLER,      $seller);
        $this->addReference(self::REF_CUSTOMER1,   $customer1);
        $this->addReference(self::REF_CUSTOMER2,   $customer2);
        $this->addReference(self::REF_WORKER,      $worker);
        $this->addReference(self::REF_PART1,       $part1);
        $this->addReference(self::REF_PART2,       $part2);
        $this->addReference(self::REF_PART3,       $part3);
        $this->addReference(self::REF_QUOTE1,      $quote1);
        $this->addReference(self::REF_QUOTE2,      $quote2);
        $this->addReference(self::REF_QUOTE3,      $quote3);
        $this->addReference(self::REF_QUOTE4,      $quote4);
        $this->addReference(self::REF_QUOTE5,      $quote5);
        $this->addReference(self::REF_ORDER1,      $order1);
        $this->addReference(self::REF_QUOTE1_LINE, $quote1Line);
        $this->addReference(self::REF_QUOTE2_LINE, $quote2Line);
        $this->addReference(self::REF_QUOTE3_LINE, $quote3Line);
        $this->addReference(self::REF_QUOTE4_LINE, $quote4Line);
    }

    private function makeQuote(
        string $reference,
        User $client,
        string $deadlineOffset,
        QuoteStatus $status,
        string $totalAmount,
    ): Quote {
        return (new Quote())
            ->setReference($reference)
            ->setClient($client)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setDeadline(new \DateTimeImmutable($deadlineOffset))
            ->setStatus($status)
            ->setTotalAmount($totalAmount);
    }

    private function createUser(Role $role, string $email, ObjectManager $manager): User
    {
        $user = (new User())->setFirstname('Test')->setLastname('User')->setEmail($email)->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, self::TEST_PASSWORD));
        $manager->persist($user);

        return $user;
    }
}
