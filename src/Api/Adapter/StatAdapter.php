<?php declare(strict_types=1);

namespace Analytics\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Analytics\Api\Representation\StatRepresentation;
use Analytics\Entity\Stat;

/**
 * The Stat table.
 *
 * Get data about stats. May use data from Hit for complex queries.
 *
 * @todo Move some functions into a view helper (or remove them).
 */
class StatAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'type' => 'type',
        'url' => 'url',
        'entity_id' => 'entityId',
        'entity_name' => 'entityName',
        // The name of the column in the table is not the name in the entity.
        'hits' => 'totalHits',
        'anonymous' => 'totalHitsAnonymous',
        'identified' => 'totalHitsIdentified',
        'hits_anonymous' => 'totalHitsAnonymous',
        'hits_identified' => 'totalHitsIdentified',
        'total_hits' => 'totalHits',
        'total_hits_anonymous' => 'totalHitsAnonymous',
        'total_hits_identified' => 'totalHitsIdentified',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'type' => 'type',
        'url' => 'url',
        'entity_id' => 'entityId',
        'entity_name' => 'entityName',
        'hits' => 'totalHits',
        'anonymous' => 'totalHitsAnonymous',
        'identified' => 'totalHitsIdentified',
        'hits_anonymous' => 'totalHitsAnonymous',
        'hits_identified' => 'totalHitsIdentified',
        'total_hits' => 'totalHits',
        'total_hits_anonymous' => 'totalHitsAnonymous',
        'total_hits_identified' => 'totalHitsIdentified',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $statusColumns = [
        'hits' => 'hits',
        'anonymous' => 'hits_anonymous',
        'identified' => 'hits_identified',
    ];

    public function getResourceName()
    {
        return 'stats';
    }

    public function getEntityClass()
    {
        return Stat::class;
    }

    public function getRepresentationClass()
    {
        return StatRepresentation::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['type']) && $query['type'] !== '' && $query['type'] !== []) {
            if (is_array($query['type'])) {
                $qb->andWhere($expr->in(
                    'omeka_root.type',
                    $this->createNamedParameter($qb, $query['type'])
                ));
            } else {
                $qb->andWhere($expr->eq(
                    'omeka_root.type',
                    $this->createNamedParameter($qb, $query['type'])
                ));
            }
        }

        if (isset($query['url']) && $query['url'] !== '' && $query['url'] !== []) {
            if (is_array($query['url'])) {
                $qb->andWhere($expr->in(
                    'omeka_root.url',
                    $this->createNamedParameter($qb, $query['url'])
                ));
            } else {
                $qb->andWhere($expr->eq(
                    'omeka_root.url',
                    $this->createNamedParameter($qb, $query['url'])
                ));
            }
        }

        // The query may use "resource_type" or "entity_name".
        if (isset($query['resource_type']) && $query['resource_type'] !== '' && $query['resource_type'] !== []) {
            $query['entity_name'] = $query['resource_type'];
        }
        if (isset($query['entity_name']) && $query['entity_name'] !== '' && $query['entity_name'] !== []) {
            if (is_array($query['entity_name'])) {
                $qb->andWhere($expr->in(
                    'omeka_root.entityName',
                    $this->createNamedParameter($qb, $query['entity_name'])
                ));
            } else {
                $qb->andWhere($expr->eq(
                    'omeka_root.entityName',
                    $this->createNamedParameter($qb, $query['entity_name'])
                ));
            }
        }

        // The query may use "resource_id" or "entity_id".
        if (isset($query['resource_id']) && $query['resource_id'] !== '' && $query['resource_id'] !== []) {
            $query['entity_id'] = $query['resource_id'];
        }
        if (isset($query['entity_id'])
            && $query['entity_id'] !== ''
            && $query['entity_id'] !== []
        ) {
            $ids = is_array($query['entity_id']) ? $query['entity_id'] : [$query['entity_id']];
            $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
            if (count($ids) > 1) {
                $qb->andWhere($expr->in(
                    'omeka_root.entityId',
                    $this->createNamedParameter($qb, $ids)
                ));
            } elseif (count($ids) === 1) {
                $qb->andWhere($expr->eq(
                    'omeka_root.entityId',
                    $this->createNamedParameter($qb, reset($ids))
                ));
            } else {
                // Issue in query, so no output.
                $qb->andWhere($expr->eq('omeka_root.entityId', -1));
            }
        }

        if (isset($query['has_resource']) && $query['has_resource'] !== '') {
            $query['has_entity'] = (bool) $query['has_resource'];
        }
        if (isset($query['has_entity']) && $query['has_entity'] !== '') {
            $qb
                ->andWhere(
                    (bool) $query['has_entity']
                        ? $expr->neq('omeka_root.entityName', $this->createNamedParameter($qb, ''))
                        : $expr->eq('omeka_root.entityName', $this->createNamedParameter($qb, ''))
                );
        }

        if (isset($query['query']) && $query['query'] !== '' && $query['query'] !== []) {
            $entityName = empty($query['entity_name']) ? 'resources' : $query['entity_name'];
            // For now, it is not posible to search in mixed resources.
            if ($entityName === 'resources') {
                $api = $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'It is not possible to query stats on all resources types at the same time for now.' // @translate
                );
            } else {
                $qb->andWhere($expr->eq(
                    'omeka_root.entityName',
                    $this->createNamedParameter($qb, $entityName)
                ));
                $queryQuery = $query['query'];
                if (is_string($queryQuery)) {
                    parse_str($query['query'], $queryQuery);
                }
                if ($queryQuery) {
                    // TODO Use a sub query-builder to avoid issues with big bases.
                    $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                    $subIds = array_keys($api->search($entityName, $queryQuery, ['returnScalar' => 'id'])->getContent());
                    if ($subIds) {
                        $subIdsAlias = $this->createAlias();
                        $qb
                            ->andWhere($expr->in(
                                'omeka_root.entityId',
                                ":$subIdsAlias"
                            ))
                            ->setParameter($subIdsAlias, $subIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);
                    } else {
                        $qb->andWhere($expr->eq(
                            'omeka_root.entityId',
                            $this->createNamedParameter($qb, 0)
                        ));
                    }
                }
            }
        }

        if (isset($query['is_download']) && $query['is_download'] !== '') {
            if ($query['is_download']) {
                $qb->andWhere($expr->like(
                    'omeka_root.url',
                    $this->createNamedParameter($qb, '/files/%')
                ));
            } else {
                $qb->andWhere($expr->notLike(
                    'omeka_root.url',
                    $this->createNamedParameter($qb, '/files/%')
                ));
            }
        }

        if (isset($query['file_type']) && $query['file_type'] !== '' && $query['file_type'] !== []) {
            if (is_array($query['file_type'])) {
                $exprs = [];
                foreach ($query['file_type'] as $fileType) {
                    $exprs[] = $expr->like(
                        'omeka_root.url',
                        $this->createNamedParameter($qb, '/files/' . $fileType . '/%')
                    );
                }
                $orX = new \Doctrine\ORM\Query\Expr\Orx($exprs);
                $qb->andWhere($orX);
            } else {
                $qb->andWhere($expr->notLike(
                    'omeka_root.url',
                    $this->createNamedParameter($qb, '/files/' . $query['file_type'] . '/%')
                ));
            }
        }

        if (isset($query['not_zero']) && is_scalar($query['not_zero'])) {
            // Check the column, because this is the user value.
            $column = $this->statusColumns[$query['not_zero']] ?? 'hits';
            // Here, the columns are classified.
            $classifiedColumns = [
                'hits' => 'totalHits',
                'anonymous' => 'totalHitsAnonymous',
                'identified' => 'totalHitsIdentified',
                'hits_anonymous' => 'totalHitsAnonymous',
                'hits_identified' => 'totalHitsIdentified',
            ];
            $column = $classifiedColumns[$column] ?? 'totalHits';
            $qb->andWhere("omeka_root.$column != 0");
        }

        // TODO For Stat, since/until use the modified date. Add a way to use the created date.

        if (isset($query['since']) && strlen((string) $query['since'])) {
            // Adapted from Omeka classic.
            // Accept an ISO 8601 date, set the tiemzone to the server's default
            // timezone, and format the date to be MySQL timestamp compatible.
            $date = new \DateTime((string) $query['since'], new \DateTimeZone(date_default_timezone_get()));
            // Don't return result when date is badly formatted.
            if (!$date) {
                $qb->andWhere($expr->eq(
                    'omeka_root.modified',
                    $this->createNamedParameter($qb, 'since_error')
                ));
            } else {
                // Select all dates that are greater than the passed date.
                $qb->andWhere($expr->gte(
                    'omeka_root.modified',
                    $this->createNamedParameter($qb, $date->format('Y-m-d H:i:s'))
                ));
            }
        }

        if (isset($query['until']) && strlen((string) $query['until'])) {
            $date = new \DateTime((string) $query['until'], new \DateTimeZone(date_default_timezone_get()));
            // Don't return result when date is badly formatted.
            if (!$date) {
                $qb->andWhere($expr->eq(
                    'omeka_root.modified',
                    $this->createNamedParameter($qb, 'until_error')
                ));
            } else {
                // Select all dates that are lower than the passed date.
                $qb->andWhere($expr->lte(
                    'omeka_root.modified',
                    $this->createNamedParameter($qb, $date->format('Y-m-d H:i:s'))
                ));
            }
        }
    }

    public function sortQuery(QueryBuilder $qb, array $query): void
    {
        // "sort_field" is used to get multiple orders without overriding core.
        if (isset($query['sort_field']) && is_array($query['sort_field'])) {
            foreach ($query['sort_field'] as $by => $order) {
                parent::sortQuery($qb, [
                    'sort_by' => $by,
                    'sort_order' => $order,
                ]);
            }
        }
        parent::sortQuery($qb, $query);
    }

    public function validateRequest(Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (empty($data['o:url']) && empty($data['url'])) {
            $errorStore->addError('o:url', 'The stat requires a url.'); // @translate
        }
        if (empty($data['o:type']) && empty($data['type'])) {
            $errorStore->addError('o:url', 'The stat requires a type.'); // @translate
        } else {
            $type = $data['o:type'] ?? $data['type'];
            if (!in_array($type, [Stat::TYPE_PAGE, Stat::TYPE_RESOURCE, Stat::TYPE_DOWNLOAD])) {
                $errorStore->addError('o:url', 'The stat requires a type: "page", "resource", or "download".'); // @translate
            }
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        /** @var \Analytics\Entity\Stat $entity */
        $data = $request->getContent();
        $isUpdate = $request->getOperation() === Request::UPDATE;

        $updatableKeys = [
            'o:total_hits',
            'o:total_hits_anonymous',
            'o:total_hits_identified',
        ];
        $intKeys = $updatableKeys + [
            'o:entity_id',
            'o:user_id',
        ];

        // This is quicker than using inflector.
        $keyMethods = [
            // Since it's a creation, id is set automatically and not updatable.
            // 'o:id' => 'setId',
            'o:type' => 'setType',
            'o:url' => 'setUrl',
            'o:entity_id' => 'setEntityId',
            'o:entity_name' => 'setEntityName',
            'o:total_hits' => 'setTotalHits',
            'o:total_hits_anonymous' => 'setTotalHitsAnonymous',
            'o:total_hits_identified' => 'setTotalHitsIdentified',
            // 'o:created' => 'setCreated',
            // 'o:modified' => 'setModified',
        ];
        foreach ($data as $key => $value) {
            $keyName = substr($key, 0, 2) === 'o:' ? $key : 'o:' . $key;
            if (!isset($keyMethods[$keyName])) {
                continue;
            }
            if ($isUpdate && !in_array($keyName, $updatableKeys)) {
                continue;
            }
            if (in_array($keyName, $intKeys)) {
                $value = (int) $value;
            }
            $method = $keyMethods[$keyName];
            $entity->$method($value);
        }

        $this->updateTimestamps($request, $entity);
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        $type = $entity->getType();
        $url = $entity->getUrl();
        if (!in_array($type, [Stat::TYPE_PAGE, Stat::TYPE_RESOURCE, Stat::TYPE_DOWNLOAD])) {
            $errorStore->addError('o:type', 'A stat must have a type ("page", "resource" or "download").'); // @translate
        }
        if (!$url) {
            $errorStore->addError('o:url', 'A stat must have a url.'); // @translate
        } elseif ($type && !$this->isUnique($entity, ['type' => $type, 'url' => $url])) {
            $errorStore->addError('o:url', 'The type should be unique for the url.'); // @translate
        }
    }

    /**
     * Increase total and anonymous/identified hits.
     */
    public function increaseHits(Stat $stat): void
    {
        $stat->setTotalHits($stat->getTotalHits() + 1);
        $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity()
            ? $stat->setTotalHitsIdentified($stat->getTotalHitsIdentified() + 1)
            : $stat->setTotalHitsAnonymous($stat->getTotalHitsAnonymous() + 1);
    }
}
