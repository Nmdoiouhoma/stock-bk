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
            ->andWhere('o.routing = :r')
            ->setParameter('r', $routing)
            ->getQuery()
            ->getSingleScalarResult();

        if ($result === null) {
            return null;
        }

        return (int) $result;
    }

    public function findNeighbor(\App\Entity\Operation $operation, string $direction): ?Operation
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.routing = :r')
            ->setParameter('r', $operation->getRouting())
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
