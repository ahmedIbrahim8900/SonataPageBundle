<?php

/*
 * This file is part of the Sonata project.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Entity;

use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\SnapshotInterface;
use Sonata\PageBundle\Model\SnapshotManagerInterface;

use Doctrine\ORM\EntityManager;

use Sonata\PageBundle\Model\SnapshotPageProxy;

/**
 * This class manages SnapshotInterface persistency with the Doctrine ORM
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class SnapshotManager implements SnapshotManagerInterface
{
    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var array
     */
    protected $children = array();

    /**
     * @var string
     */
    protected $class;

    /**
     * @var array
     */
    protected $templates = array();

    /**
     * Constructor
     *
     * @param EntityManager $entityManager An entity manager instance
     * @param string        $class         Namespace of entity class
     * @param array         $templates     An array of templates
     */
    public function __construct(EntityManager $entityManager, $class, $templates = array())
    {
        $this->entityManager = $entityManager;
        $this->class         = $class;
        $this->templates     = $templates;
    }

    /**
     * @return SnapshotInterface
     */
    public function create()
    {
        return new $this->class;
    }

    /**
     * {@inheritdoc}
     */
    public function save(SnapshotInterface $snapshot)
    {
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        return $snapshot;
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    public function getConnection()
    {
        return $this->entityManager->getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function enableSnapshots(array $snapshots)
    {
        if (count($snapshots) == 0) {
            return;
        }

        $now = new \DateTime;
        $pageIds = $snapshotIds = array();
        foreach ($snapshots as $snapshot) {
            $pageIds[] = $snapshot->getPage()->getId();
            $snapshotIds[] = $snapshot->getId();

            $snapshot->setPublicationDateStart($now);
            $snapshot->setPublicationDateEnd(null);

            $this->entityManager->persist($snapshot);
        }

        $this->entityManager->flush();
        //@todo: strange sql and low-level pdo usage: use dql or qb
        $sql = sprintf("UPDATE %s SET publication_date_end = '%s' WHERE id NOT IN(%s) AND page_id IN (%s) AND publication_date_end IS NULL",
            $this->getTableName(),
            $now->format('Y-m-d H:i:s'),
            implode(',', $snapshotIds),
            implode(',', $pageIds)
        );

        $this->getConnection()->query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function findBy(array $criteria)
    {
        return $this->getRepository()->findBy($criteria);
    }

    /**
     * {@inheritdoc}
     */
    public function findEnableSnapshot(array $criteria)
    {
        $date = new \Datetime;
        $parameters = array(
            'publicationDateStart' => $date,
            'publicationDateEnd'   => $date,
        );

        $query = $this->getRepository()
            ->createQueryBuilder('s')
            ->andWhere('s.publicationDateStart <= :publicationDateStart AND ( s.publicationDateEnd IS NULL OR s.publicationDateEnd >= :publicationDateEnd )');

        if (isset($criteria['site'])) {
            $query->andWhere('s.site = :site');
            $parameters['site'] = $criteria['site'];
        }

        if (isset($criteria['pageId'])) {
            $query->andWhere('s.page = :page');
            $parameters['page'] = $criteria['pageId'];
        } elseif (isset($criteria['url'])) {
            $query->andWhere('s.url = :url');
            $parameters['url'] = $criteria['url'];
        } elseif (isset($criteria['routeName'])) {
            $query->andWhere('s.routeName = :routeName');
            $parameters['routeName'] = $criteria['routeName'];
        } elseif (isset($criteria['pageAlias'])) {
            $query->andWhere('s.pageAlias = :pageAlias');
            $parameters['pageAlias'] = $criteria['pageAlias'];
        } elseif (isset($criteria['name'])) {
            $query->andWhere('s.name = :name');
            $parameters['name'] = $criteria['name'];
        } else {
            throw new \RuntimeException('please provide a `pageId`, `url`, `routeName` or `name` as criteria key');
        }

        $query->setMaxResults(1);
        $query->setParameters($parameters);

        return $query->getQuery()->getOneOrNullResult();
    }

    /**
     * {@inheritdoc}
     */
    public function findOneBy(array $criteria)
    {
        return $this->getRepository()->findOneBy($criteria);
    }

    /**
     * return a page with the given routeName
     *
     * @param string $routeName
     *
     * @return PageInterface|false
     */
    public function getPageByName($routeName)
    {
        $snapshots = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from($this->class, 's')
            ->where('s.routeName = :routeName')
            ->setParameters(array(
                'routeName' => $routeName
            ))
            ->getQuery()
            ->execute();

        $snapshot = count($snapshots) > 0 ? $snapshots[0] : false;

        if ($snapshot) {
            return new SnapshotPageProxy($this, $snapshot);
        }

        return false;
    }

    /**
     * @param array $templates
     */
    public function setTemplates($templates)
    {
        $this->templates = $templates;
    }

    /**
     * @return array
     */
    public function getTemplates()
    {
        return $this->templates;
    }

    /**
     * @param string $code
     *
     * @return mixed
     *
     * @throws \RunTimeException
     */
    public function getTemplate($code)
    {
        if (!isset($this->templates[$code])) {
            throw new \RunTimeException(sprintf('No template references with the code : %s', $code));
        }

        return $this->templates[$code];
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanup(PageInterface $page, $keep)
    {
        if (!is_numeric($keep)) {
            throw new \RuntimeException(sprintf('Please provide an integer value, %s given', gettype($keep)));
        }

        $tableName = $this->getTableName();

        return $this->getConnection()->exec(sprintf(
            'DELETE FROM %s
            WHERE
                page_id = %d
                AND id NOT IN (
                    SELECT id
                    FROM (
                        SELECT id, publication_date_end
                        FROM %s
                        WHERE page_id = %d
                        ORDER BY publication_date_end DESC
                    )
                    %s
            )',
            $tableName,
            $page->getId(),
            $tableName,
            $page->getId(),
            sprintf($this->getConnection()->getDatabasePlatform()->getName() === 'oracle' ? 'WHERE rownum <= %d' : 'LIMIT %d', $keep)
        ));
    }

    /**
     * @return \Doctrine\ORM\EntityRepository
     */
    protected function getRepository()
    {
        return $this->entityManager->getRepository($this->class);
    }

    /**
     * Gets the table name
     *
     * @return string
     */
    protected function getTableName()
    {
        return $this->entityManager->getClassMetadata($this->class)->table['name'];
    }
}