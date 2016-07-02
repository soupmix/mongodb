<?php

namespace Soupmix;
/*
MongoDB Adapter
*/

class MongoDB implements Base
{
    protected $conn = null;

    private $dbName = null;

    private $database = null;

    public function __construct($config, \MongoDB\Client $client)
    {
        $this->dbName = $config['db_name'];
        $this->conn = $client;
        $this->database = $this->conn->{$this->dbName};
    }

    public function getConnection()
    {
        return $this->conn;
    }

    public function create($collection, $fields)
    {
        return $this->database->createCollection($collection);
    }

    public function drop($collection)
    {
        return $this->database->dropCollection($collection);
    }

    public function truncate($collection)
    {
        $this->database->dropCollection($collection);
        return $this->database->createCollection($collection);
    }

    public function createIndexes($collection, $indexes)
    {
        $collection = $this->database->selectCollection($collection);
        return $collection->createIndexes($indexes);
    }

    public function insert($collection, $values)
    {
        $collection = $this->database->selectCollection($collection);
        $result = $collection->insertOne($values);
        $docId = $result->getInsertedId();
        if (is_object($docId)) {
            return (string) $docId;
        }
        return null;
        
    }

    public function get($collection, $docId)
    {
        $collection = $this->database->selectCollection($collection);
        if (gettype($docId) == "array") {
            return $this->multiGet($collection, $docId);
        }
        return $this->singleGet($collection, $docId);
    }

    private function singleGet($collection, $docId)
    {
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        $filter = ['_id' => new \MongoDB\BSON\ObjectID($docId)];
        $result = $collection->findOne($filter, $options);
        if ($result!==null) {
            $result['id'] = (string) $result['_id'];
            unset($result['_id']);
        }
        return $result;
    }

    private function multiGet($collection, $docIds)
    {
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        $idList = [];
        foreach ($docIds as $itemId) {
            $idList[]=['_id'=>new \MongoDB\BSON\ObjectID($itemId)];
        }
        $filter = ['$or'=>$idList];
        $cursor = $collection->find($filter, $options);
        $iterator = new \IteratorIterator($cursor);
        $iterator->rewind();
        $results=[];
        while ($doc = $iterator->current()) {
            if (isset($doc['_id'])) {
                $doc['id'] = (string) $doc['_id'];
                unset($doc['_id']);
            }
            $results[$doc['id']] = $doc;
            $iterator->next();
        }
        return $results;
    }

    public function update($collection, $filters, $values)
    {
        $collection = $this->database->selectCollection($collection);
        if (isset($filters['id'])) {
            $filters['_id'] = new \MongoDB\BSON\ObjectID($filters['id']);
            unset($filters['id']);
        }
        $query_filters = [];
        if ($filters != null) {
            $query_filters = ['$and' => self::buildFilter($filters)];
        }
        $values_set = ['$set' => $values];
        try{
            $result = $collection->updateMany($query_filters, $values_set);

        } catch (\Exception $e){
            throw new \Exception($e->getMessage());
        }


        return $result->getModifiedCount();
    }

    public function delete($collection, $filter)
    {
        $collection = $this->database->selectCollection($collection);
        $filter = self::buildFilter($filter)[0];

        if (isset($filter['id'])) {
            $filter['_id'] = new \MongoDB\BSON\ObjectID($filter['id']);
            unset($filter['id']);
        }
        $result = $collection->deleteMany($filter);

        return $result->getDeletedCount();
    }

    public function find($collection, $filters, $fields = null, $sort = null, $start = 0, $limit = 25)
    {
        $collection = $this->database->selectCollection($collection);
        if (isset($filters['id'])) {
            $filters['_id'] = new \MongoDB\BSON\ObjectID($filters['id']);
            unset($filters['id']);
        }
        $query_filters = [];
        if ($filters != null) {
            $query_filters = ['$and' => self::buildFilter($filters)];
        }
        $count = $collection->count($query_filters);
        if ($count > 0) {
            $results = [];
            $options = [
                'limit' => (int) $limit,
                'skip' => (int) $start,
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];
            if ($fields!==null) {
                $projection = [];
                foreach ($fields as $field) {
                    if ($field=='id') {
                        $field = '_id';
                    }
                    $projection[$field] = true;
                }
                $options['projection'] = $projection;
            }
            if ($sort!==null) {
                foreach ($sort as $sort_key => $sort_dir) {
                    $sort[$sort_key] = ($sort_dir=='desc') ? -1 : 1;
                    if ($sort_key=='id') {
                        $sort['_id'] = $sort[$sort_key];
                        unset($sort['id']);
                    }
                }
                $options['sort'] = $sort;
            }

            $cursor = $collection->find($query_filters, $options);
            $iterator = new \IteratorIterator($cursor);
            $iterator->rewind();
            while ($doc = $iterator->current()) {
                if (isset($doc['_id'])) {
                    $doc['id'] = (string) $doc['_id'];
                    unset($doc['_id']);
                }
                $results[] = $doc;
                $iterator->next();
            }
            return ['total' => $count, 'data' => $results];
        }
        return ['total' => 0, 'data' => null];
    }

    public function query($collection)
    {
        return new MongoDBQueryBuilder($collection, $this);
    }

    public static function buildFilter($filter)
    {
        $filters = [];
        foreach ($filter as $key => $value) {
            
            if (strpos($key, '__')!==false) {
                $filters[] = self::buildFilterForKeys($key, $value);
                //$filters = self::mergeFilters($filters, $tmpFilters);
            } elseif (strpos($key, '__') === false && is_array($value)) {
                $filters[]['$or'] = self::buildFilterForOr($value);
            } else {
                $filters[][$key] = $value;
            }
        }
        return $filters;
    }

    public static function buildFilterForOr($orValues)
    {
        $filters = [];
        foreach ($orValues as $filter) {
            $subKey = array_keys($filter)[0];
            $subValue = $filter[$subKey];
            if (strpos($subKey, '__')!==false) {
                $filters[] = self::buildFilterForKeys($subKey, $subValue);
               // $filters = self::mergeFilters($filters, $tmpFilters);
            } else {
                $filters[][$subKey] = $subValue;
            }
        }
        return $filters;
    }

    private static function mergeFilters ($filters, $tmpFilters){

        foreach ($tmpFilters as $fKey => $fVals) {
            if (isset($filters[$fKey])) {
                foreach ($fVals as $fVal) {
                    $filters[$fKey][] = $fVal;
                }
            } else {
                $filters[$fKey] = $fVals;
            }
        }
        return $filters;
    }

    private static function buildFilterForKeys($key, $value)
    {
        preg_match('/__(.*?)$/i', $key, $matches);
        $operator = $matches[1];
        switch ($operator) {
            case '!in':
                $operator = 'nin';
                break;
            case 'not':
                $operator = 'ne';
                break;
            case 'wildcard':
                $operator = 'regex';
                $value = str_replace(array('?'), array('.'), $value);
                break;
            case 'prefix':
                $operator = 'regex';
                $value = $value.'*';
                break;
        }
        $key = str_replace($matches[0], '', $key);
        $filters= [$key => ['$'.$operator => $value]];
        return $filters;
    }

}
