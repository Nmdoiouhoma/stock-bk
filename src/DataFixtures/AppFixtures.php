<?php

namespace App\DataFixtures;

use App\Entity\BillOfMaterials;
use App\Entity\Completion;
use App\Entity\Forecast;
use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Part;
use App\Entity\Routing;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\ForecastStatus;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $hasher;

    public function __construct(UserPasswordHasherInterface $hasher)
    {
        $this->hasher = $hasher;
    }

    public function load(ObjectManager $manager): void
    {
        $suppliers   = $this->loadSuppliers($manager);
        $this->loadUsers($manager);
        $workstations = $this->loadWorkstations($manager);
        $machines    = $this->loadMachines($manager, $workstations);
        $parts       = $this->loadParts($manager, $suppliers);
        $routings    = $this->loadRoutings($manager, $parts);
        $operations  = $this->loadOperations($manager, $routings, $workstations, $machines);
        $this->loadBoms($manager, $parts);
        $this->loadForecasts($manager, $operations);
        $this->loadCompletions($manager, $operations);

        $manager->flush();
    }

    /** @return Supplier[] */
    private function loadSuppliers(ObjectManager $manager): array
    {
        $data = [
            ['Acier & Métaux SARL',       'contact@acier-metaux.fr',      '01 23 45 67 89'],
            ['Plastiques Industriels SA',  'commandes@plastiques-ind.fr',  '01 98 76 54 32'],
            ['Composants Tech',            'info@composants-tech.fr',      '03 45 67 89 01'],
            ['Visserie Express',           'vente@visserie-express.fr',    '04 56 78 90 12'],
        ];

        $suppliers = [];
        foreach ($data as [$name, $email, $phone]) {
            $s = (new Supplier())->setName($name)->setContactEmail($email)->setPhone($phone);
            $manager->persist($s);
            $suppliers[] = $s;
        }

        return $suppliers;
    }

    private function loadUsers(ObjectManager $manager): void
    {
        // format: firstname, lastname, email, role, plainPassword
        $data = [
            ['Marie',   'Dupont',    'admin@atelier.fr',        Role::Admin,    'AdminPass123!'],
            ['Jean',    'Martin',    'jean.martin@atelier.fr',  Role::Worker,   'WorkerJean1!'],
            ['Sophie',  'Leclerc',   'sophie.leclerc@atelier.fr', Role::Worker,  'WorkerSophie2!'],
            ['Pierre',  'Bernard',   'client@exemple.fr',       Role::Customer, 'ClientPierre!'],
            ['Luc',     'Moreau',    'commercial@atelier.fr',   Role::Seller,   'SellerLuc!'],
        ];

        foreach ($data as [$first, $last, $email, $role, $plainPassword]) {
            $u = (new User())
                ->setFirstname($first)
                ->setLastname($last)
                ->setEmail($email)
                ->setRole($role);
            $u->setPassword($this->hasher->hashPassword($u, $plainPassword));
            $manager->persist($u);
        }
    }

    /** @return Workstation[] */
    private function loadWorkstations(ObjectManager $manager): array
    {
        $data = [
            ['WS-001', 'Poste de découpe',    8],
            ['WS-002', 'Poste d\'assemblage', 6],
            ['WS-003', 'Poste de finition',   4],
        ];

        $workstations = [];
        foreach ($data as [$ref, $label, $capacity]) {
            $ws = (new Workstation())->setReference($ref)->setLabel($label)->setCapacity($capacity);
            $manager->persist($ws);
            $workstations[] = $ws;
        }

        return $workstations;
    }

    /** @return Machine[] */
    private function loadMachines(ObjectManager $manager, array $workstations): array
    {
        [$ws1, $ws2, $ws3] = $workstations;

        $data = [
            ['M-001', 'Fraiseuse CNC',       $ws1],
            ['M-002', 'Tour automatique',    $ws1],
            ['M-003', 'Robot d\'assemblage', $ws2],
            ['M-004', 'Presse hydraulique',  $ws2],
            ['M-005', 'Cabine de peinture',  $ws3],
        ];

        $machines = [];
        foreach ($data as [$ref, $label, $ws]) {
            $m = (new Machine())->setReference($ref)->setLabel($label)->setWorkstation($ws);
            $manager->persist($m);
            $machines[] = $m;
        }

        return $machines;
    }

    /** @return Part[] */
    private function loadParts(ObjectManager $manager, array $suppliers): array
    {
        [$acier, $plastiques, , $visserie] = $suppliers;

        $data = [
            // ref,       label,                        type,                        price,  qty, min, supplier,    catalogPrice
            ['REF-001', 'Barre acier 20mm',             PieceType::RawMaterial,      null,   150,  50, $acier,      12.50],
            ['REF-002', 'Plaque aluminium 5mm',         PieceType::RawMaterial,      null,    80,  30, $acier,      18.00],
            ['REF-003', 'Vis M8 x 20mm',                PieceType::Purchased,        null,  2000, 500, $visserie,    0.15],
            ['REF-004', 'Écrou M8',                     PieceType::Purchased,        null,  2000, 500, $visserie,    0.08],
            ['REF-005', 'Peinture époxy rouge (bidon)', PieceType::Purchased,        null,    20,   5, $plastiques, 24.00],
            ['REF-006', 'Corps de vanne usiné',         PieceType::Intermediate,     null,    45,  10, null,         null],
            ['REF-007', 'Couvercle aluminium',          PieceType::Intermediate,     null,    60,  10, null,         null],
            ['REF-008', 'Vanne industrielle V100',      PieceType::Finished,        450.0,    25,   5, null,         null],
            ['REF-009', 'Vanne compacte V50',           PieceType::Finished,        280.0,    40,  10, null,         null],
            ['REF-010', 'Kit maintenance V100',         PieceType::Finished,         85.0,    30,   8, null,         null],
        ];

        $parts = [];
        foreach ($data as [$ref, $label, $type, $price, $qty, $min, $supplier, $catalogPrice]) {
            $p = (new Part())
                ->setReference($ref)
                ->setLabel($label)
                ->setType($type)
                ->setSalePrice($price)
                ->setCatalogPrice($catalogPrice)
                ->setStockQuantity($qty)
                ->setStockMin($min)
                ->setSupplier($supplier);
            $manager->persist($p);
            $parts[] = $p;
        }

        return $parts;
    }

    /** @return Routing[] */
    private function loadRoutings(ObjectManager $manager, array $parts): array
    {
        [5 => $corpsVanne, 6 => $couvercle, 7 => $v100, 8 => $v50, 9 => $kit] = $parts;

        $data = [
            ['RT-001', 'Gamme usinage corps vanne', $corpsVanne],
            ['RT-002', 'Gamme usinage couvercle',   $couvercle],
            ['RT-003', 'Gamme assemblage V100',      $v100],
            ['RT-004', 'Gamme assemblage V50',       $v50],
            ['RT-005', 'Gamme kit maintenance',      $kit],
        ];

        $routings = [];
        foreach ($data as [$ref, $label, $part]) {
            $r = (new Routing())->setReference($ref)->setLabel($label)->setPart($part);
            $manager->persist($r);
            $routings[] = $r;
        }

        return $routings;
    }

    /** @return Operation[] */
    private function loadOperations(
        ObjectManager $manager,
        array $routings,
        array $workstations,
        array $machines
    ): array {
        [$ws1, $ws2, $ws3] = $workstations;
        [$fraiseuse, $tour, $robot, $presse, $cabinePeinture] = $machines;
        [$rtCorps, $rtCouvercle, $rtV100, $rtV50, $rtKit] = $routings;

        $data = [
            // rank, label,                      routing,     workstation, machine,         unitTime
            [1, 'Découpe barre acier',            $rtCorps,    $ws1,       $fraiseuse,       0.50],
            [2, 'Tournage extérieur',             $rtCorps,    $ws1,       $tour,            1.00],
            [3, 'Perçage et filetage',            $rtCorps,    $ws1,       $fraiseuse,       0.75],
            [1, 'Découpe plaque aluminium',       $rtCouvercle,$ws1,       $fraiseuse,       0.30],
            [2, 'Fraisage contour',               $rtCouvercle,$ws1,       $fraiseuse,       0.50],
            [1, 'Pré-assemblage corps',           $rtV100,     $ws2,       $robot,           0.50],
            [2, 'Montage couvercle et vissage',   $rtV100,     $ws2,       $presse,          0.25],
            [3, 'Peinture et finition',           $rtV100,     $ws3,       $cabinePeinture,  1.00],
            [4, 'Contrôle qualité V100',          $rtV100,     $ws3,       null,             0.25],
            [1, 'Assemblage V50',                 $rtV50,      $ws2,       $robot,           0.40],
            [2, 'Peinture V50',                   $rtV50,      $ws3,       $cabinePeinture,  0.75],
            [3, 'Contrôle qualité V50',           $rtV50,      $ws3,       null,             0.20],
            [1, 'Préparation kit maintenance',    $rtKit,      $ws2,       null,             0.30],
            [2, 'Conditionnement et étiquetage',  $rtKit,      $ws3,       null,             0.20],
        ];

        $operations = [];
        foreach ($data as [$rank, $label, $routing, $ws, $machine, $unitTime]) {
            $op = (new Operation())
                ->setRank($rank)
                ->setLabel($label)
                ->setRouting($routing)
                ->setWorkstation($ws)
                ->setMachine($machine)
                ->setUnitTime($unitTime);
            $manager->persist($op);
            $operations[] = $op;
        }

        return $operations;
    }

    private function loadBoms(ObjectManager $manager, array $parts): void
    {
        [$barreAcier, $plaqueAlu, $vis, $ecrou, $peinture, $corps, $couvercle, $v100, $v50] = $parts;

        $data = [
            // parent,  child,       qty, unit
            [$v100,  $corps,      1,    'pce'],
            [$v100,  $couvercle,  1,    'pce'],
            [$v100,  $vis,        4,    'pce'],
            [$v100,  $ecrou,      4,    'pce'],
            [$v100,  $peinture,   1,    'kg'],
            [$v50,   $corps,      1,    'pce'],
            [$v50,   $vis,        2,    'pce'],
            [$v50,   $peinture,   1,    'kg'],
            [$corps, $barreAcier, 1,    'pce'],
            [$couvercle, $plaqueAlu, 1, 'pce'],
        ];

        foreach ($data as [$parent, $child, $qty, $unit]) {
            $bom = (new BillOfMaterials())
                ->setParentPart($parent)
                ->setChildPart($child)
                ->setQuantity($qty)
                ->setUnit($unit);
            $manager->persist($bom);
        }
    }

    private function loadForecasts(ObjectManager $manager, array $operations): void
    {
        $statuses = [ForecastStatus::PENDING, ForecastStatus::IN_PROGRESS, ForecastStatus::COMPLETED];

        $data = [
            // operation index, plannedDate,   qty,  status
            [5,  '+7 days',   20, ForecastStatus::PENDING],
            [6,  '+7 days',   20, ForecastStatus::PENDING],
            [7,  '+8 days',   20, ForecastStatus::PENDING],
            [8,  '+9 days',   20, ForecastStatus::PENDING],
            [9,  '+3 days',   30, ForecastStatus::IN_PROGRESS],
            [10, '+3 days',   30, ForecastStatus::IN_PROGRESS],
            [11, '+4 days',   30, ForecastStatus::IN_PROGRESS],
            [12, '-5 days',   15, ForecastStatus::COMPLETED],
            [12, '-2 days',   10, ForecastStatus::COMPLETED],
            [13, '+14 days',  50, ForecastStatus::PENDING],
        ];

        foreach ($data as [$opIdx, $dateOffset, $qty, $status]) {
            $f = (new Forecast())
                ->setOperation($operations[$opIdx])
                ->setPlannedDate(new \DateTime($dateOffset))
                ->setPlannedQuantity($qty)
                ->setStatus($status);
            $manager->persist($f);
        }
    }

    private function loadCompletions(ObjectManager $manager, array $operations): void
    {
        $data = [
            // operation index, date,        actualQty, actualDuration
            [0,  '-30 days',   50,  28.0],
            [1,  '-30 days',   50,  52.0],
            [2,  '-29 days',   50,  38.0],
            [3,  '-28 days',   60,  20.0],
            [4,  '-28 days',   60,  32.0],
            [9,  '-15 days',   30,  13.0],
            [10, '-14 days',   30,  24.0],
            [11, '-14 days',   30,   7.0],
        ];

        foreach ($data as [$opIdx, $dateOffset, $qty, $duration]) {
            $c = (new Completion())
                ->setOperation($operations[$opIdx])
                ->setDate(new \DateTime($dateOffset))
                ->setActualQuantity($qty)
                ->setActualDuration($duration);
            $manager->persist($c);
        }
    }
}
