<?php

namespace App\DataFixtures;

use App\Entity\BillOfMaterials;
use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Part;
use App\Entity\ProductionOrder;
use App\Entity\Routing;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\OperationStatus;
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
        $suppliers    = $this->loadSuppliers($manager);
        $users        = $this->loadUsers($manager);
        $supervisor   = array_values(array_filter($users, fn(User $u) => $u->getRole() === Role::Supervisor))[0];
        $workstations = $this->loadWorkstations($manager);
        $machines     = $this->loadMachines($manager, $workstations);
        $parts        = $this->loadParts($manager, $suppliers);
        $routings     = $this->loadRoutings($manager, $parts, $supervisor);
        $operations   = $this->loadOperations($manager, $routings, $workstations, $machines);
        $this->loadUserWorkstations($users, $workstations);
        $this->loadBoms($manager, $parts);
        $this->loadProductionOrders($manager, $operations);

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

    /** @return User[] */
    private function loadUsers(ObjectManager $manager): array
    {
        // format: firstname, lastname, email, role, plainPassword
        $data = [
            ['Marie',   'Dupont',    'admin@atelier.fr',           Role::Admin,      'AdminPass1234!'],
            ['Jean',    'Martin',    'jean.martin@atelier.fr',     Role::Worker,     'WorkerJean1!'],
            ['Sophie',  'Leclerc',   'sophie.leclerc@atelier.fr',  Role::Worker,     'WorkerSophie2!'],
            ['Pierre',  'Bernard',   'client@exemple.fr',          Role::Customer,   'ClientPierre!'],
            ['Luc',     'Moreau',    'commercial@atelier.fr',      Role::Seller,     'SellerLuc!'],
            ['Alice',   'Renard',    'supervisor@atelier.fr',      Role::Supervisor, 'SupervisorAlice!'],
            ['Thomas',  'Petit',     'thomas.petit@atelier.fr',    Role::Worker,     'WorkerThomas3!'],
            ['Emma',    'Lefebvre',  'emma.lefebvre@atelier.fr',   Role::Worker,     'WorkerEmma4!'],
        ];

        $users = [];
        foreach ($data as [$first, $last, $email, $role, $plainPassword]) {
            $u = (new User())
                ->setFirstname($first)
                ->setLastname($last)
                ->setEmail($email)
                ->setRole($role);
            $u->setPassword($this->hasher->hashPassword($u, $plainPassword));
            $manager->persist($u);
            $users[] = $u;
        }

        return $users;
    }

    /** @return Workstation[] */
    private function loadWorkstations(ObjectManager $manager): array
    {
        $data = [
            ['WS-001', 'Poste de découpe',            8],
            ['WS-002', 'Poste d\'assemblage',          6],
            ['WS-003', 'Poste de finition',            4],
            ['WS-004', 'Poste de soudure',             3],
            ['WS-005', 'Poste de contrôle qualité',    5],
            ['WS-006', 'Poste d\'emballage',          10],
            ['WS-007', 'Poste de maintenance',         2],
            ['WS-008', 'Poste de programmation CNC',   4],
        ];

        $workstations = [];
        foreach ($data as [$ref, $label, $capacity]) {
            $ws = (new Workstation())->setReference($ref)->setLabel($label)->setCapacity($capacity);
            $manager->persist($ws);
            $workstations[] = $ws;
        }

        return $workstations;
    }

    private function loadUserWorkstations(array $users, array $workstations): void
    {
        // users order: 0=admin, 1=jean, 2=sophie, 3=client, 4=seller, 5=alice(supervisor), 6=thomas, 7=emma
        [, $jean, $sophie, , , $alice, $thomas, $emma] = $users;
        [$ws1, $ws2, $ws3, $ws4, $ws5, $ws6, $ws7, $ws8] = $workstations;

        $links = [
            [$jean,   [$ws1, $ws4]],
            [$sophie, [$ws2, $ws3, $ws5]],
            [$alice,  [$ws1, $ws2, $ws3, $ws5]],
            [$thomas, [$ws1, $ws2, $ws4, $ws7]],
            [$emma,   [$ws3, $ws5, $ws6]],
        ];

        foreach ($links as [$user, $wsList]) {
            foreach ($wsList as $ws) {
                $user->addWorkstation($ws);
            }
        }
    }

    /** @return Machine[] */
    private function loadMachines(ObjectManager $manager, array $workstations): array
    {
        [$ws1, $ws2, $ws3, $ws4] = $workstations;

        // format: ref, label, compatible workstations[]
        $data = [
            ['M-001', 'Fraiseuse CNC',        [$ws1]],
            ['M-002', 'Tour automatique',     [$ws1, $ws4]],
            ['M-003', 'Robot d\'assemblage',  [$ws2]],
            ['M-004', 'Presse hydraulique',   [$ws2, $ws4]],
            ['M-005', 'Cabine de peinture',   [$ws3]],
        ];

        $machines = [];
        foreach ($data as [$ref, $label, $wsList]) {
            $m = (new Machine())->setReference($ref)->setLabel($label);
            foreach ($wsList as $ws) {
                $m->addWorkstation($ws);
            }
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
            // ref,       label,                        type,                        price,  qty, supplier,    catalogPrice
            ['REF-001', 'Barre acier 20mm',             PieceType::RawMaterial,      null,   150, $acier,      12.50],
            ['REF-002', 'Plaque aluminium 5mm',         PieceType::RawMaterial,      null,    80, $acier,      18.00],
            ['REF-003', 'Vis M8 x 20mm',                PieceType::Purchased,        null,  2000, $visserie,    0.15],
            ['REF-004', 'Écrou M8',                     PieceType::Purchased,        null,  2000, $visserie,    0.08],
            ['REF-005', 'Peinture époxy rouge (bidon)', PieceType::Purchased,        null,    20, $plastiques, 24.00],
            ['REF-006', 'Plateau de jeu traité',        PieceType::Intermediate,     null,    45, null,         null],
            ['REF-007', 'Pied de table usiné',          PieceType::Intermediate,     null,    60, null,         null],
            ['REF-008', 'Table de ping-pong Pro 25',    PieceType::Finished,        599.0,    25, null,         null],
            ['REF-009', 'Table de ping-pong Compact',   PieceType::Finished,        349.0,    40, null,         null],
            ['REF-010', 'Set raquettes + balles',       PieceType::Finished,         24.0,    30, null,         null],
        ];

        $parts = [];
        foreach ($data as [$ref, $label, $type, $price, $qty, $supplier, $catalogPrice]) {
            $p = (new Part())
                ->setReference($ref)
                ->setLabel($label)
                ->setType($type)
                ->setSalePrice($price)
                ->setCatalogPrice($catalogPrice)
                ->setStockQuantity($qty)
                ->setSupplier($supplier);
            $manager->persist($p);
            $parts[] = $p;
        }

        return $parts;
    }

    /** @return Routing[] */
    private function loadRoutings(ObjectManager $manager, array $parts, User $supervisor): array
    {
        [5 => $corpsVanne, 6 => $couvercle, 7 => $v100, 8 => $v50, 9 => $kit] = $parts;

        $data = [
            ['RT-001', 'Fabrication plateau de jeu',      $corpsVanne],
            ['RT-002', 'Fabrication pieds de table',      $couvercle],
            ['RT-003', 'Assemblage table Pro 25',         $v100],
            ['RT-004', 'Assemblage table Compact',        $v50],
            ['RT-005', 'Fabrication set raquettes',       $kit],
        ];

        $routings = [];
        foreach ($data as [$ref, $label, $part]) {
            $r = (new Routing())->setReference($ref)->setLabel($label)->setPart($part)->setSupervisor($supervisor);
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
            [1, 'Découpe bois aux dimensions',    $rtCorps,    $ws1,       $fraiseuse,       0.50],
            [2, 'Ponçage surface plateau',        $rtCorps,    $ws1,       $tour,            1.00],
            [3, 'Application peinture époxy',     $rtCorps,    $ws1,       $fraiseuse,       0.75],
            [1, 'Découpe tubes acier pieds',      $rtCouvercle,$ws1,       $fraiseuse,       0.30],
            [2, 'Perçage fixation roulettes',     $rtCouvercle,$ws1,       $fraiseuse,       0.50],
            [1, 'Assemblage plateau sur pieds',   $rtV100,     $ws2,       $robot,           0.50],
            [2, 'Fixation roulettes',             $rtV100,     $ws2,       $presse,          0.25],
            [3, 'Peinture table complète',        $rtV100,     $ws3,       $cabinePeinture,  1.00],
            [4, 'Contrôle qualité table Pro',     $rtV100,     $ws3,       null,             0.25],
            [1, 'Assemblage table Compact',       $rtV50,      $ws2,       $robot,           0.40],
            [2, 'Peinture table Compact',         $rtV50,      $ws3,       $cabinePeinture,  0.75],
            [3, 'Contrôle qualité table Compact', $rtV50,      $ws3,       null,             0.20],
            [1, 'Préparation raquettes et balles', $rtKit,      $ws2,       null,             0.30],
            [2, 'Conditionnement set',            $rtKit,      $ws3,       null,             0.20],
        ];

        $operations = [];
        foreach ($data as [$rank, $label, $routing, $ws, $machine, $unitTime]) {
            $op = (new Operation())
                ->setRank($rank)
                ->setLabel($label)
                ->addRouting($routing)
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

    private function loadProductionOrders(ObjectManager $manager, array $operations): void
    {
        $data = [
            // operation index, plannedDate,  plannedQty, actualQty, actualDuration, status
            [0,  '-30 days',   50,  50,   28.0, OperationStatus::COMPLETED],
            [1,  '-30 days',   50,  50,   52.0, OperationStatus::COMPLETED],
            [2,  '-29 days',   50,  50,   38.0, OperationStatus::COMPLETED],
            [3,  '-28 days',   60,  60,   20.0, OperationStatus::COMPLETED],
            [4,  '-28 days',   60,  60,   32.0, OperationStatus::COMPLETED],
            [5,  '+7 days',    20, null,   null, OperationStatus::PENDING],
            [6,  '+7 days',    20, null,   null, OperationStatus::PENDING],
            [7,  '+8 days',    20, null,   null, OperationStatus::PENDING],
            [8,  '+9 days',    20, null,   null, OperationStatus::PENDING],
            [9,  '-15 days',   30,  30,   13.0, OperationStatus::COMPLETED],
            [9,  '+3 days',    30, null,   null, OperationStatus::IN_PROGRESS],
            [10, '-14 days',   30,  30,   24.0, OperationStatus::COMPLETED],
            [10, '+3 days',    30, null,   null, OperationStatus::IN_PROGRESS],
            [11, '-14 days',   30,  30,    7.0, OperationStatus::COMPLETED],
            [11, '+4 days',    30, null,   null, OperationStatus::IN_PROGRESS],
            [13, '+14 days',   50, null,   null, OperationStatus::PENDING],
        ];

        foreach ($data as [$opIdx, $dateOffset, $plannedQty, $actualQty, $actualDuration, $status]) {
            $o = (new ProductionOrder())
                ->setOperation($operations[$opIdx])
                ->setPlannedDate(new \DateTime($dateOffset))
                ->setPlannedQuantity($plannedQty)
                ->setActualQuantity($actualQty)
                ->setActualDuration($actualDuration)
                ->setStatus($status);
            $manager->persist($o);
        }
    }
}
