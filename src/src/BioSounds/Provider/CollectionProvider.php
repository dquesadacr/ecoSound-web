<?php

namespace BioSounds\Provider;

use BioSounds\Entity\Collection;
use BioSounds\Exception\Database\NotFoundException;
use BioSounds\Utils\Auth;

class CollectionProvider extends BaseProvider
{
    public function getCollectionPagesByPermission(int $projectId): array
    {
        $sql = "SELECT c.* FROM collection c ";
        if (!Auth::isUserLogged()) {
            $sql .= 'WHERE c.public = 1 AND project_id = :projectId ';
        } elseif (!Auth::isUserAdmin()) {
            $sql .= 'WHERE ( c.public = 1 OR c.collection_id IN (SELECT up.collection_id FROM user_permission up, permission p WHERE up.permission_id = p.permission_id AND (p.name = "Access" OR p.name = "View" OR p.name = "Review" OR p.name = "Manage") AND up.user_id = ' . Auth::getUserID() . ')) AND project_id = :projectId ';
        } else {
            $sql .= 'WHERE project_id = :projectId ';
        }
        $sql = $sql . 'ORDER BY c.name ';
        $this->database->prepareQuery($sql);
        $result = $this->database->executeSelect([':projectId' => $projectId]);

        $data = [];
        foreach ($result as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view']);
        }
        return $data;
    }

    /**
     * @param string $order
     * @return Collection[]
     * @throws \Exception
     */
    public function getList(string $order = 'name'): array
    {
        $data = [];
        $this->database->prepareQuery("SELECT * FROM collection ORDER BY $order");
        $result = $this->database->executeSelect();

        foreach ($result as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view']);
        }

        return $data;
    }

    /**
     * @param int $id
     * @return Collection|null
     * @throws \Exception
     */
    public function get(int $id): ?Collection
    {
        $this->database->prepareQuery('SELECT * FROM collection WHERE collection_id = :id');

        if (empty($result = $this->database->executeSelect([':id' => $id]))) {
            throw new NotFoundException($id);
        }

        $result = $result[0];

        return (new Collection())
            ->setId($result['collection_id'])
            ->setName($result['name'])
            ->setUserId($result['user_id'])
            ->setDoi($result['doi'])
            ->setNote($result['note'])
            ->setSphere($result['sphere'] == null ? '' : $result['sphere'])
            ->setProject($result['project_id'])
            ->setCreationDate($result['creation_date'])
            ->setPublic($result['public'])
            ->setView($result['view'])
            ->setProject($result['project_id']);
    }

    /**
     * @param int $id
     * @return Collection|null
     * @throws \Exception
     */
    public function getByProject(int $project_id, int $user_id): ?array
    {
        $this->database->prepareQuery('SELECT c.*,u.permission_id FROM collection c LEFT JOIN user_permission u ON u.collection_id = c.collection_id AND u.user_id = :user_id WHERE c.project_id = :project_id ');
        $results = $this->database->executeSelect([':project_id' => $project_id, ':user_id' => $user_id]);
        $data = [];
        foreach ($results as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view'])
                ->setPermission($item['permission_id'] == null ? 0 : $item['permission_id']);
        }

        return $data;
    }

    public function getWithSite(int $project_id, int $site_id): ?array
    {
        $this->database->prepareQuery('SELECT c.*,MAX(IF(site_id = :site_id, 1, 0)) AS site_id FROM collection c LEFT JOIN site_collection sc ON sc.collection_id = c.collection_id WHERE c.project_id = :project_id GROUP BY c.collection_id');
        $results = $this->database->executeSelect([':project_id' => $project_id, ':site_id' => $site_id]);
        $data = [];
        foreach ($results as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view'])
                ->setPermission($item['site_id']);
        }
        return $data;
    }

    /**
     * @param string $order
     * @return Collection[]
     * @throws \Exception
     */
    public function getAccessedList(int $userId): array
    {
        $data = [];
        $this->database->prepareQuery('SELECT * FROM collection WHERE collection_id IN ( SELECT up.collection_id FROM user_permission up, permission p WHERE up.permission_id = p.permission_id AND (p.name = "Access" OR p.name = "View" OR p.name = "Review" OR p.name= "Manage") AND up.user_id = :userId) ORDER BY name');

        $result = $this->database->executeSelect([':userId' => $userId]);

        foreach ($result as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view']);
        }

        return $data;
    }

    public function getManageList(int $userId): array
    {
        $data = [];
        $this->database->prepareQuery('SELECT * FROM collection WHERE collection_id IN ( SELECT up.collection_id FROM user_permission up, permission p WHERE up.permission_id = p.permission_id AND p.name= "Manage" AND up.user_id = :userId) ORDER BY name');

        $result = $this->database->executeSelect([':userId' => $userId]);

        foreach ($result as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view']);
        }

        return $data;
    }

    public function getPublicList(int $userId): array
    {
        $data = [];
        $this->database->prepareQuery('SELECT * FROM collection WHERE collection_id IN ( SELECT up.collection_id FROM user_permission up, permission p WHERE up.permission_id = p.permission_id AND ( p.name= "Manage" OR p.name= "View" OR p.name= "Review") AND up.user_id = :userId) OR public = 1 ORDER BY name');

        $result = $this->database->executeSelect([':userId' => $userId]);

        foreach ($result as $item) {
            $data[] = (new Collection())
                ->setId($item['collection_id'])
                ->setName($item['name'])
                ->setUserId($item['user_id'])
                ->setDoi($item['doi'])
                ->setNote($item['note'])
                ->setSphere($item['sphere'] == null ? '' : $item['sphere'])
                ->setProject($item['project_id'])
                ->setCreationDate($item['creation_date'])
                ->setPublic($item['public'])
                ->setView($item['view']);
        }

        return $data;
    }

    /**
     * @param int $id
     * @throws \Exception
     */
    public function delete(int $id): void
    {
        $this->database->prepareQuery('DELETE FROM ' . Collection::TABLE_NAME . ' WHERE collection_id = :id');
        $this->database->executeDelete([':id' => $id]);
        $this->database->prepareQuery('DELETE FROM site_collection WHERE collection_id = :id');
        $this->database->executeDelete([':id' => $id]);
    }
}
