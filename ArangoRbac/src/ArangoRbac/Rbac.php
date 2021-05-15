<?php

namespace ArangoRbac;

class HttpForbiddenException extends \Exception
{
    public function __construct($message, $code = 403, $previous = null) {
        parent::__construct($message, $code, $previous);
        header('HTTP/1.1 403 Forbidden');
        die("<strong>Forbidden</strong>: You do not have permission to access this resource.
        Additional information:
            <ul>
                <li>Code: $code
                <li>Message: $message
            </ul>");
    }
}

class Rbac {
    /** \ArangoDBClient\Connection */
    protected $connection;

    public function __construct(\ArangoDBClient\Connection $connection) {
        $this->connection = $connection;
        $this->Users = new Users($connection);
        $this->Roles = new Roles($connection);
        $this->Permissions = new Permissions($connection);
    }

    /**
     * @param string|int $role Title|Path|ID
     * @param string|int $permission Title|Path|ID
     * @return bool $success
     */
    public function assign($role, $permission) {
        $this->Roles->assign($role, $permission);
    }

    /**
     * @param string|int $permission Title|Path|ID
     * @param string|int $userID ID
     * @return bool $hasPermission
     */
    public function check($permission, $userID) {
        return $this->Users->hasPermission($userID, $permission);
    }

    /**
     * @param string|int $permission Title|Path|ID
     * @param string|int $userID ID
     * @return bool $hasPermission
     * @throws HttpForbiddenException
     */
    public function enforce($permission, $userID) {
        if($this->check($permission, $userID)) {
            return true;
        } else {
            throw new HttpForbiddenException("You do not have permission: $permission.");
        }
    }

    /**
     * Caution: removes all roles, permissions and assignments from database
     * @param bool $ensure - safeguarding variable to prevent accidents
     * @return mixed $result - { Users, Roles, Permissions, UserEdges, RoleEdges, PermissionEdges }
     * @throws ResetException
     */
    public function reset($ensure = false, $returnDocs = false) {
        $result = new \stdClass;
        $result->Users = $this->Users->reset($ensure, $returnDocs);
        $result->Roles = $this->Roles->reset($ensure, $returnDocs);
        $result->Permissions = $this->Permissions->reset($ensure, $returnDocs);

        $result->UserEdges = $this->Users->resetAssignments($ensure, $returnDocs);
        $result->RoleEdges = $this->Roles->resetAssignments($ensure, $returnDocs);
        $result->PermissionEdges = $this->Permissions->resetAssignments($ensure, $returnDocs);

        return $result;
    }
}
