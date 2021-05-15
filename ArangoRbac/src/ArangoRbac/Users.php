<?php

namespace ArangoRbac;

class RbacUserNotProvidedException extends \Exception {};

class Users extends Entity {
    const COLLECTION_NAME   = 'User';
    const HAS               = 'HasRole';
    const OWNED_BY          = 'User';
    const OWNS              = 'Role';
    const INHERITS_FROM     = 'InheritsUser';
    const VIEW_NAME         = 'v_users';
    protected $connection;

    public function __construct(\ArangoDBClient\Connection $connection) {
        parent::__construct($connection);
    }

    /**
     * @param string|int $id
     * @return null|array of [$roleId, $Title, $Description]
     * @throws RbacUserNotProvidedException
     */
    public function allRoles($userId = null) {
        if(!isset($userId) || empty($userId)) {
            throw new RbacUserNotProvidedException('User not provided or empty while retreiving allRoles');
        } else {
            $ownedById = $this->returnId($userId, static::COLLECTION_NAME);
            $ownedById = $ownedById ? $ownedById : $userId;
            
            $statement  = new \ArangoDBClient\Statement($this->connection, []);
            $query      = "WITH @@collection, @@ownedCollection
                            \nFOR d, e IN 0..1000000 OUTBOUND
                                \n@_id
                                \nINBOUND @@inheritsCollection, INBOUND @@ownedInheritsCollection, @@edgeCollection
                                \nFILTER IS_DOCUMENT(d) AND IS_SAME_COLLECTION(@owned, d)
                                \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::COLLECTION_NAME);
            $statement->bind('@ownedCollection', static::OWNS);
            $statement->bind('owned', static::OWNS);
            $statement->bind('_id', $ownedById);
            $statement->bind('@edgeCollection', static::HAS);
            $statement->bind('@inheritsCollection', static::INHERITS_FROM);
            $statement->bind('@ownedInheritsCollection', 'InheritsRole');
            $result = $statement->execute();
            $response = $result->getAll();

            return $response;
        }
    }

    /**
     * @param string $role
     * @param string|int $userId
     * @return bool $hasRole ?
     */
    public function hasRole($role, $userId) {
        if(!isset($userId) || empty($userId)) {
            throw new RbacUserNotProvidedException('User not provided or empty while assigning a role');
        } else {
            $ownedById = $this->returnId($userId, static::COLLECTION_NAME);
            $ownedById = $ownedById ? $ownedById : $userId;

            $ownedId = $this->returnId($role, static::OWNS);
            $ownedId = $ownedId ? $ownedId : $role;
            
            $statement  = new \ArangoDBClient\Statement($this->connection, []);
            $query      = "WITH @@collection, @@ownedCollection
                            \nFOR d
                            \nIN OUTBOUND SHORTEST_PATH
                                \n@v1 TO @v2
                                \n@@edgeCollection, INBOUND @@inheritsCollection, INBOUND @@ownedInheritsCollection
                            \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::COLLECTION_NAME);
            $statement->bind('@ownedCollection',static::OWNS);
            $statement->bind('v1', $ownedById);
            $statement->bind('v2', $ownedId);
            $statement->bind('@edgeCollection', static::HAS);
            $statement->bind('@inheritsCollection', static::INHERITS_FROM);
            $statement->bind('@ownedInheritsCollection', 'InheritsRole');
            $result = $statement->execute();
            $response = $result->getAll();

            $hasRole = count($response) > 0;

            return $hasRole;
        }
    }

    /**
     * Assign a a role to a user
     * @param string|int $role Title|Path|ID
     * @param string|int $userId Title|Path|ID
     * @return bool $success - false if association already exists
     * @throws RbacUserNotProvidedException
     */
    public function assign($role, $userId = null, $returnId = false) {
        if(!isset($userId) || empty($userId)) {
            throw new RbacUserNotProvidedException('User not provided or empty while assigning a role');
        } else {
            $assignation = new \ArangoDBClient\Edge();        

            $ownedById = $this->returnId($userId, 'User');
            $ownedById = $ownedById ? $ownedById : $userId;

            $ownedId = $this->returnId($role, 'Role');
            $ownedId = $ownedId ? $ownedId : $role;

            $assignation->setFrom($ownedById);
            $assignation->setTo($ownedId);
            
            $statement  = new \ArangoDBClient\Statement($this->connection, []);
            $query      = "FOR d IN @@collection\nFILTER d._from == @_from AND d._to == @_to\nLIMIT 2\nRETURN d._id";
            $statement->setQuery($query);
            $statement->bind('@collection', static::HAS);
            $statement->bind('_from', $ownedById);
            $statement->bind('_to', $ownedId);
            $result = $statement->execute();
            $response = $result->getAll();

            $edgeExists = count($response) > 0;

            if($edgeExists) {
                return false;
            }

            $id = $this->edgeHandler->saveEdge(static::HAS, $ownedById, $ownedId, $assignation);
            if($returnId) {
                return $id;
            } else {
                return true;
            }
        }
    }

    /**
     * @param string $userId, $permission
     * @return bool $hasPermission ?
     * @throws RbacUserNotProvidedException
     */
    public function hasPermission($userId, $permission) {
        if(!isset($userId) || empty($userId)) {
            throw new RbacUserNotProvidedException('No user ID provided to User hasPermission check');
        } else {
            $ownedById = $this->returnId($userId, static::COLLECTION_NAME);
            $ownedById = $ownedById ? $ownedById : $userId;

            $ownedId = $this->returnId($permission, 'Permission');
            $ownedId = $ownedId ? $ownedId : $permission;
            
            $statement  = new \ArangoDBClient\Statement($this->connection, []);
            $query      = "WITH @@collection, @@ownedCollection, @@ownedOwnedCollection
                            \nFOR d
                            \nIN OUTBOUND SHORTEST_PATH
                                \n@v1 TO @v2
                                \n@@edgeCollection, @@ownedEdgeCollection, INBOUND @@inheritsCollection, INBOUND @@ownedInheritsCollection
                            \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::COLLECTION_NAME);
            $statement->bind('@ownedCollection', static::OWNS);
            $statement->bind('@ownedOwnedCollection', 'Permission');
            $statement->bind('v1', $ownedById);
            $statement->bind('v2', $ownedId);
            $statement->bind('@edgeCollection', static::HAS);
            $statement->bind('@inheritsCollection', static::INHERITS_FROM);
            $statement->bind('@ownedInheritsCollection', 'InheritsRole');
            $statement->bind('@ownedEdgeCollection', 'HasPermission');
            $result = $statement->execute();
            $response = $result->getAll();

            $hasPermission = count($response) > 0;

            return $hasPermission;
        }
    }

    /**
     * @param string|int $id
     * @return int $count - total count of roles assigned to user
     * @throws RbacUserNotProvidedException
     */
    public function roleCount($userId) {
        return count($this->allRoles($userId));
    }

    /**
     * Unassign a a role from a user
     * @param string|int $role Title|Path|ID
     * @param string|int $id
     * @return bool $success - false if association already exists
     * @throws RbacUserNotProvidedException
     */
    public function unassign($role, $userId) {
        $ownedById = $this->returnId($userId, static::COLLECTION_NAME);
        $ownedById = $ownedById ? $ownedById : $userId;

        $ownedId = $this->returnId($role, static::OWNS);
        $ownedId = $ownedId ? $ownedId : $role;
        
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection\nFILTER d._from == @_from AND d._to == @_to\nLIMIT 100\nRETURN d._id";
        $statement->setQuery($query);
        $statement->bind('@collection', static::HAS);
        $statement->bind('_from', $ownedById);
        $statement->bind('_to', $ownedId);
        $result = $statement->execute();
        $response = $result->getAll();

        $edgeExists = count($response) > 0;

        if($edgeExists) {
            // There should only be one, but:
            foreach($response as $edge) {
                $id = $edge->getId();
                $this->edgeHandler->removeById(static::HAS, $id);
            }
            return true;
        } else {
            return false;
        }
    }
}