<?php
class Presentation {
    protected $c;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        return;
    }

    public function load($id) {
        try {
            $sql = $this->c->db->prepare("  SELECT p.json, p.name, p.origId, p.version, p.id, p.tags
                                            FROM presentations as p
                                            inner join(
                                                    SELECT origId, MAX(version) as ver FROM presentations group by origId
                                                    ) max on p.origId = max.origId AND p.version = max.ver
                                            WHERE p.origId = ?");
            $sql->execute(array($id));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            $row = $rows[0];
            $data = array('json' => $row['json'], 'name' => $row['name'], 'id' => $row['origId'], 'version' => $row['version'], 'tags' => $row['tags']);
            $data['stat'] = 'success';
        } catch (PDOException $ex){
            $data['stat'] = 'error';
            $data['message'] = $ex->getMessage();
            $this->c->logger->error("Database Error: ".$ex->getMessage());
        }
        return $data;
    }
}