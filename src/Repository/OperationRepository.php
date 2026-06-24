<?php

namespace App\Repository;

use App\Entity\Operation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Operation::class);
    }

    public function getMaxRankForRouting(\App\Entity\Routing $routing): ?int
    {
        $result = $this->createQueryBuilder('o')
            ->select('MAX(o.rank)')
            ->join('o.routings', 'r')
            ->andWhere('r = :routing')
            ->setParameter('routing', $routing)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : null;
    }

    public function findNeighbor(\App\Entity\Operation $operation, string $direction): ?Operation
    {
        $routing = $operation->getRoutings()->first();
        if (!$routing) {
            return null;
        }

        $qb = $this->createQueryBuilder('o')
            ->join('o.routings', 'r')
            ->andWhere('r = :routing')
            ->setParameter('routing', $routing)
            ->setMaxResults(1);

        if ($direction === 'up') {
            $qb->andWhere('o.rank < :rank')
                ->setParameter('rank', $operation->getRank())
                ->orderBy('o.rank', 'DESC');
        } else {
            $qb->andWhere('o.rank > :rank')
                ->setParameter('rank', $operation->getRank())
                ->orderBy('o.rank', 'ASC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
