<?php

namespace ArangoDBClient;

class EdgeCollection extends Collection
{
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->setType('edge');
    }
}
