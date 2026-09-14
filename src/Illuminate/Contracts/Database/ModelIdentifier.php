<?php

namespace Illuminate\Contracts\Database;

class ModelIdentifier
{
    public $class;
    public $id;
    public $relations;
    public $connection;
    public $collectionClass;

    public function __construct($class, $id, array $relations, $connection)
    {
        $this->class = $class;
        $this->id = $id;
        $this->relations = $relations;
        $this->connection = $connection;
    }

    public function useCollectionClass(?string $collectionClass)
    {
        $this->collectionClass = $collectionClass;
        return $this;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }
}
