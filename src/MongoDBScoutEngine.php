<?php

namespace QRStuff\Scout\MongoDB;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Searchable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection as MongoDBCollection;
use MongoDB\Database;
use MongoDB\Driver\CursorInterface;

class MongoDBScoutEngine extends Engine implements UpdatesIndexSettings
{
    private Database $database;

    private bool $softDelete;

    private const TYPEMAP = ['root' => 'object', 'document' => 'bson', 'array' => 'bson'];

    public function __construct(
        Database $database,
        bool $softDelete
    ) {
        $this->softDelete = $softDelete;
        $this->database = $database;
    }

    // <editor-fold desc="Laravel\Scout\Engines\Engine">

    /**
     * @param  EloquentCollection|Model[]  $models
     */
    public function update($models): void
    {
        assert($models instanceof EloquentCollection, new \TypeError(sprintf('Argument #1 ($models) must be of type %s, %s given', EloquentCollection::class, get_debug_type($models))));

        if ($models->isEmpty()) {
            return;
        }

        if ($this->softDelete && self::usesSoftDelete($models)) {
            $models->each->pushSoftDeleteMetadata();
        }

        $bulk = [];
        foreach ($models as $model) {
            assert($model instanceof Model && method_exists($model, 'toSearchableArray'), new \LogicException(sprintf('Model "%s" must use "%s" trait', get_class($model), Searchable::class)));

            $searchableData = $model->toSearchableArray();
            $searchableData = self::serialize($searchableData);

            // Skip/remove the model if it doesn't provide any searchable data
            if (! $searchableData) {
                $bulk[] = [
                    'deleteOne' => [
                        ['_id' => $model->getScoutKey()],
                    ],
                ];

                continue;
            }

            unset($searchableData['_id']);

            $searchableData = array_replace($searchableData, $model->scoutMetadata());

            if (isset($searchableData['__soft_deleted'])) {
                $searchableData['__soft_deleted'] = (bool) $searchableData['__soft_deleted'];
            }

            $bulk[] = [
                'updateOne' => [
                    ['_id' => $model->getScoutKey()],
                    ['$set' => $searchableData],
                    ['upsert' => true],
                ],
            ];
        }

        $this->getIndexableCollection($models)->bulkWrite($bulk);
    }

    /**
     * @param  EloquentCollection|Model[]  $models
     */
    public function delete($models): void
    {
        assert($models instanceof EloquentCollection, new \TypeError(sprintf('Argument #1 ($models) must be of type %s, %s given', Collection::class, get_debug_type($models))));

        if ($models->isEmpty()) {
            return;
        }

        $collection = $this->getIndexableCollection($models);
        $ids = $models->map(function (Model $model) {
            return $model->getScoutKey();
        })->all();
        $collection->deleteMany(['_id' => ['$in' => $ids]]);
    }

    public function search(Builder $builder): array
    {
        return $this->performSearch($builder);
    }

    /**
     * @param  int  $perPage
     * @param  int  $page
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        assert(is_int($perPage), new \TypeError(sprintf('Argument #2 ($perPage) must be of type int, %s given', get_debug_type($perPage))));
        assert(is_int($page), new \TypeError(sprintf('Argument #3 ($page) must be of type int, %s given', get_debug_type($page))));

        $builder = clone $builder;
        $builder->take($perPage);

        return $this->performSearch($builder, $perPage * ($page - 1));
    }

    /**
     * @param  array  $results
     * @return Collection|void
     */
    public function mapIds($results)
    {
        assert(is_array($results), new \TypeError(sprintf('Argument #1 ($results) must be of type array, %s given', get_debug_type($results))));

        return new Collection(array_column($results, '_id'));
    }

    /**
     * @param  array  $results
     * @param  Model  $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        return $this->performMap($builder, $results, $model, false);
    }

    /**
     * @param  array  $results
     * @param  Model  $model
     * @return LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        return $this->performMap($builder, $results, $model, true);
    }

    /**
     * @param  array  $results
     */
    public function getTotalCount($results): int
    {
        if (! $results) {
            return 0;
        }

        return $results[0]->__count;
    }

    /**
     * @param  Model  $model
     */
    public function flush($model): void
    {
        assert($model instanceof Model, new \TypeError(sprintf('Argument #1 ($model) must be of type %s, %s given', Model::class, get_debug_type($model))));

        $collection = $this->getIndexableCollection($model);

        $collection->deleteMany([]);
    }

    /**
     * @param  string  $name
     */
    public function createIndex($name, array $options = []): void
    {
        assert(is_string($name), new \TypeError(sprintf('Argument #1 ($name) must be of type string, %s given', get_debug_type($name))));

        $this->database->createCollection($name);
    }

    /**
     * @param  string  $name
     */
    public function deleteIndex($name): void
    {
        assert(is_string($name), new \TypeError(sprintf('Argument #1 ($name) must be of type string, %s given', get_debug_type($name))));

        $this->database->dropCollection($name);
    }

    // </editor-fold>

    // <editor-fold desc="Laravel\Scout\Contracts\UpdatesIndexSettings">

    public function updateIndexSettings(string $name, array $settings = []): void
    {
        $searchableAttributes = $settings['searchableAttributes'] ?? [];
        if ($searchableAttributes) {
            $collection = $this->database->selectCollection($name);
            $collection->createIndex(array_fill_keys($searchableAttributes, 'text'));
        }

        $filterableAttributes = $settings['filterableAttributes'] ?? [];
        if ($filterableAttributes) {
            $collection = $this->database->selectCollection($name);
            foreach ($filterableAttributes as $fields) {
                $collection->createIndex($fields);
            }
        }
    }

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        $settings['filterableAttributes'][] = ['__soft_deleted' => 1];

        return $settings;
    }

    // </editor-fold>

    /**
     * @param  EloquentCollection|Model  $model
     */
    private function getIndexableCollection($model): MongoDBCollection
    {
        if ($model instanceof EloquentCollection) {
            $model = $model->first();
        }

        assert($model instanceof Model);
        assert(method_exists($model, 'indexableAs'), sprintf('Model "%s" must use "%s" trait', get_class($model), Searchable::class));

        return $this->database->selectCollection($model->indexableAs());
    }

    /**
     * @param  EloquentCollection|Model  $model
     */
    private function getSearchableCollection($model): MongoDBCollection
    {
        if ($model instanceof EloquentCollection) {
            $model = $model->first();
        }

        assert($model instanceof Model);
        assert(method_exists($model, 'searchableAs'), sprintf('Model "%s" must use "%s" trait', get_class($model), Searchable::class));

        return $this->database->selectCollection($model->searchableAs());
    }

    /**
     * @return ($lazy is true ? LazyCollection : Collection)<mixed>
     */
    private function performMap(Builder $builder, array $results, Model $model, bool $lazy)
    {
        if (! $results) {
            $collection = $model->newCollection();

            return $lazy ? LazyCollection::make($collection) : $collection;
        }

        $objectIds = array_column($results, '_id');
        $objectIdPositions = array_flip($objectIds);

        /** @var Model|Searchable $model */
        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->{$lazy ? 'cursor' : 'get'}()
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds);
            })
            ->map(function ($model) use ($results, $objectIdPositions) {
                /** @var Model|Searchable $model */
                $result = $results[$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if ($key[0] === '_' && $key !== '_id') {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(function ($model) use ($objectIdPositions) {
                /** @var Model|Searchable $model */
                return $objectIdPositions[$model->getScoutKey()];
            })
            ->values();
    }

    private function performSearch(Builder $builder, ?int $offset = null): array
    {
        $collection = $this->getSearchableCollection($builder->model);

        if ($builder->callback) {
            $cursor = call_user_func(
                $builder->callback,
                $collection,
                $builder->query,
                $offset
            );
            assert($cursor instanceof CursorInterface, new \LogicException(sprintf('The search builder closure must return a MongoDB cursor, %s returned', get_debug_type($cursor))));
            $cursor->setTypeMap(self::TYPEMAP);

            return $cursor->toArray();
        }

        $match = [];
        if ($builder->query) {
            $match[] = ['$text' => ['$search' => $builder->query]];
        }

        $and = [];
        foreach ($builder->wheres as $field => $value) {
            if ($field === '__soft_deleted') {
                $value = (bool) $value;
            }

            $and[] = [$field => $value];
        }

        foreach ($builder->whereIns as $field => $value) {
            if ($field === '__soft_deleted') {
                $value = array_map(fn ($x) => (bool) $x, (array) $value);
            }

            $and[] = [$field => ['$in' => $value]];
        }

        foreach ($builder->whereNotIns as $field => $value) {
            if ($field === '__soft_deleted') {
                $value = array_map(fn ($x) => (bool) $x, (array) $value);
            }

            $and[] = [$field => ['$nin' => $value]];
        }

        $sort = [];
        foreach ($builder->orders as $order) {
            $sort[] = [$order['column'] => $order['direction'] === 'asc' ? 1 : -1];
        }

        if ($and) {
            $match[] = ['$and' => $and];
        }

        $pipeline = [];
        if ($match) {
            $pipeline[] = ['$match' => ['$and' => $match]];
        }

        if ($sort) {
            $pipeline[] = ['$sort' => $sort];
        }

        $pagination = [];
        if ($offset) {
            $pagination[] = ['$skip' => $offset];
        }

        if ($builder->limit) {
            $pagination[] = ['$limit' => $builder->limit];
        }

        $pipeline[] = [
            '$facet' => [
                'results' => $pagination,
                'count' => [['$count' => 'count']],
            ],
        ];
        $pipeline[] = ['$unwind' => ['path' => '$results']];
        $pipeline[] = ['$unwind' => ['path' => '$count']];
        $pipeline[] = ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => ['$results', ['__count' => '$count.count']]]]];

        $cursor = $collection->aggregate($pipeline);
        $cursor->setTypeMap(self::TYPEMAP);

        return $cursor->toArray();
    }

    /**
     * @param  mixed  $value
     * @return array|UTCDateTime|\Serializable
     */
    private static function serialize($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if ($value instanceof \Serializable || ! is_iterable($value)) {
            return $value;
        }

        // Convert Laravel Collections and other Iterators to arrays
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        // Recursively serialize arrays
        return array_map(\Closure::fromCallable([self::class, 'serialize']), $value);
    }

    /**
     * @param  Model|EloquentCollection  $model
     */
    private static function usesSoftDelete($model): bool
    {
        if ($model instanceof EloquentCollection) {
            $model = $model->first();
        }

        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
