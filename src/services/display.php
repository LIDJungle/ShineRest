<?php
class Display {
    protected $c;
    protected $db;
    protected $log;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        $this->db = $c->db;
        $this->log = $c->logger;
        return;
    }

    /*
     * Returns player params from database.
     *
     */
    public function getDisplayParam($displayId) {
        try {
            $sql = $this->db->prepare("SELECT * FROM `display` WHERE id = ?");
            $sql->execute(array($displayId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            $data = $rows[0];
            $data['w'] = $data['dim_w'];
            $data['h'] = $data['dim_h'];
            $data['cr'] = $data['crate'];
            $data['coid'] = $data['ownerId'];
            $data['status'] = 'success';
        } catch (PDOException $ex){
            $data['status'] = 'error';
            $data['message'] = $ex->getMessage();
            $this->log->error("Database Error: ".$ex->getMessage());
        }
        return json_encode($data);
    }
}